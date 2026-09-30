<?php

declare(strict_types=1);

use App\Modules\Legal\Enums\LegalDocumentKind;
use Illuminate\Support\Facades\Blade;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\get;

/*
 * The payment page's layout and theme (ADR-0056 part C,
 * docs/frontend/checkout-design.md): two cards while the link can be paid,
 * one card for outcomes and for pages without a merchant, the theme choice
 * with its pre-paint on every pay-host page, a visible "Total to pay" with
 * the total split so it wraps at 320px, and the two-tier footer.
 */

const LAYOUT_UA = ['User-Agent' => 'Mozilla/5.0 (Layout test)'];

const CHECKOUT_CARD = 'rounded-xl border border-line bg-page p-inset-md shadow-sm sm:p-inset-lg';

it('lays out an active link as two cards: the details on the left, the form on the right, never sticky', function (): void {
    [, $link] = Checkout::scenario();

    $html = (string) get(payUrl('/l/'.$link->public_token), LAYOUT_UA)->assertOk()->getContent();

    expect($html)->toContain('lg:grid lg:grid-cols-5 lg:items-start')
        ->and(substr_count($html, CHECKOUT_CARD))->toBe(2)
        ->and($html)->toContain('lg:col-span-2')
        ->toContain('lg:col-span-3')
        ->and(str_contains($html, 'sticky'))->toBeFalse()
        // The body is the canvas; the cards are the page surface.
        ->and($html)->toContain('<body class="min-h-dvh bg-canvas font-sans text-fg">')
        // DOM order: the summary (left) before the form (right).
        ->and(strpos($html, 'lg:col-span-2') < strpos($html, 'id="checkout-form"'))->toBeTrue()
        ->and(strpos($html, 'data-checkout-root') < strpos($html, 'id="checkout-form"'))->toBeTrue();
});

it('shows a visible "Total to pay" label and splits the total so the code can wrap', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['amount_minor' => 150_000_000, 'currency' => 'MXN']));

    $html = (string) get(payUrl('/l/'.$link->public_token), LAYOUT_UA)->assertOk()->getContent();

    expect($html)->toContain('<dt class="text-sm text-fg-secondary">Total a pagar</dt>')
        ->and(str_contains($html, '<dt class="sr-only">Total a pagar</dt>'))->toBeFalse()
        ->and($html)->toContain('amount inline-flex flex-wrap items-baseline gap-x-2 text-3xl font-semibold sm:text-4xl')
        ->toContain('<span class="whitespace-nowrap">1,500,000.00</span> <span class="text-xl text-fg-secondary">MXN</span>');
});

it('keeps a separate hairline group for the card form only when payer fields are collected', function (): void {
    [, $withFields] = Checkout::scenario(static fn ($f) => $f->state(['payer_fields_config' => ['email' => 'required']]));
    [, $withoutFields] = Checkout::scenario();

    $form = static function (string $token): string {
        $html = (string) get(payUrl('/l/'.$token), LAYOUT_UA)->assertOk()->getContent();

        return substr($html, (int) strpos($html, '<form id="checkout-form"'));
    };

    expect($form($withFields->public_token))->toContain('class="flex flex-col gap-stack-md border-t border-line pt-stack-lg"')
        ->and($form($withoutFields->public_token))->not->toContain('class="flex flex-col gap-stack-md border-t border-line pt-stack-lg"');
});

it('shows an outcome as one card: the status first, then the summary without its own card', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->canceled());

    $html = (string) get(payUrl('/l/'.$link->public_token), LAYOUT_UA)->assertOk()->getContent();

    expect(substr_count($html, CHECKOUT_CARD))->toBe(1)
        ->and(str_contains($html, 'lg:grid-cols-5'))->toBeFalse()
        ->and($html)->toContain(CHECKOUT_CARD.' mx-auto flex w-full max-w-narrow flex-col gap-stack-lg')
        ->and(strpos($html, 'data-status-panel') < strpos($html, 'aria-label="Pago a Tienda Demo"'))->toBeTrue()
        // Informative pages show no amount (plan 11.2).
        ->and(str_contains($html, 'Total a pagar'))->toBeFalse();
});

it('offers the language and theme controls, with the theme applied before first paint, on every pay-host page', function (string $path, int $status): void {
    [, $link] = Checkout::scenario();

    $response = get(payUrl(str_replace('{token}', $link->public_token, $path)), LAYOUT_UA)->assertStatus($status);
    $html = (string) $response->getContent();

    preg_match("/'nonce-([A-Za-z0-9]+)'/", (string) $response->headers->get('Content-Security-Policy'), $nonce);

    expect($html)->toContain('data-theme-toggle')
        ->toContain('data-theme-option="light"')
        ->toContain('data-theme-option="dark"')
        ->toContain('data-theme-option="system"')
        ->toContain('role="radiogroup"')
        // The shared pre-paint, nonce'd like every script of the pay host.
        ->toContain("window.localStorage.getItem('theme')")
        ->toMatch('/<script\s+nonce="'.($nonce[1] ?? 'missing').'"\s*>\s*\(function \(\) \{\s*var theme = null;/')
        // Icon-only options at every width: the labels stay the accessible names.
        ->and(str_contains($html, 'md:not-sr-only'))->toBeFalse();
})->with([
    'active link' => ['/l/{token}', 200],
    'not found' => ['/l/unknown-token', 404],
]);

it('shows the controls only, in one card, on the 404 page', function (): void {
    $html = (string) get(payUrl('/l/unknown-token'), LAYOUT_UA)->assertNotFound()->getContent();

    expect(substr_count($html, CHECKOUT_CARD))->toBe(1)
        ->and(str_contains($html, 'data-merchant-logo'))->toBeFalse()
        ->and(str_contains($html, 'text-center text-2xl font-semibold'))->toBeFalse()
        // The footer's platform tier only: no merchant help line.
        ->and(str_contains($html, 'mailto:'))->toBeFalse();
});

it('has a two-tier footer: the merchant help line, then "Powered by" · "Processed by Stripe"', function (): void {
    [, $link] = Checkout::scenario();

    $html = (string) get(payUrl('/l/'.$link->public_token), LAYOUT_UA)->assertOk()->getContent();
    $footer = substr($html, (int) strpos($html, '<footer'));

    expect($footer)->toContain('href="mailto:soporte@demo.test"')
        ->toContain('¿Dudas sobre tu pago? Escribe a <span class="break-all">soporte@demo.test</span>')
        ->toContain('Con la tecnología de')
        ->toContain('Procesado por Stripe')
        ->toContain('<span aria-hidden="true" class="text-fg-muted">·</span>')
        ->toContain('lg:flex-row lg:justify-between')
        // No rule above it, and the merchant's privacy notice is under the summary, not here.
        ->and(str_contains($footer, 'border-t'))->toBeFalse()
        ->and(str_contains($footer, 'demo.test/privacidad'))->toBeFalse()
        ->and(str_contains($footer, 'Soporte:'))->toBeFalse();
});

it('renders the help line in English', function (): void {
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['locale' => 'en']));

    get(payUrl('/l/'.$link->public_token), LAYOUT_UA)->assertOk()
        ->assertSee('Questions about your payment? Email <span class="break-all">soporte@demo.test</span>', false);
});

it('opens the merchant\'s text documents in a sheet dialog focused on its title', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Checkout::legalDocument($tenant, LegalDocumentKind::Terms, body: 'Términos.');

    $html = (string) get(payUrl('/l/'.$link->public_token), LAYOUT_UA)->assertOk()->getContent();
    $dialog = substr($html, (int) strpos($html, '<dialog id="legal-terms"'));
    $dialog = substr($dialog, 0, (int) strpos($dialog, '</dialog>'));

    expect($dialog)->toContain('open:flex')
        ->toContain('h-dvh')
        ->toContain('sm:max-w-narrow')
        ->toContain('sm:max-h-5/6')
        ->toContain('backdrop:bg-neutral-900/60')
        ->toContain('dialog-enter')
        ->toContain('<h2 id="legal-terms-title" tabindex="-1" autofocus')
        // The merchant, small, above the title.
        ->toContain('Tienda Demo')
        ->toContain('method="dialog"')
        // Never a plain `flex` that would show a closed dialog.
        ->and(preg_match('/<dialog[^>]*class="[^"]*(?<![:\w-])flex(?![\w-])/', $dialog))->toBe(0);
});

it('splits a formatted amount at its last non-breaking space and keeps the plain form by default', function (): void {
    $split = Blade::render('<x-amount :value="150000000" currency="MXN" minor :signed="false" split code-class="text-xl" />');
    $plain = Blade::render('<x-amount :value="150000000" currency="MXN" minor :signed="false" />');

    expect($split)->toContain('<span class="whitespace-nowrap">1,500,000.00</span> <span class="text-xl">MXN</span>')
        ->toContain('amount inline-flex flex-wrap items-baseline gap-x-2')
        ->and($plain)->toContain("1,500,000.00\u{00A0}MXN")
        ->toContain('amount whitespace-nowrap');
});
