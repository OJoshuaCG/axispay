<?php

declare(strict_types=1);

use App\Modules\Legal\Enums\LegalDocumentFormat;
use App\Modules\Legal\Enums\LegalDocumentKind;
use App\Modules\Legal\Models\PlatformLegalDocument;
use App\Modules\Legal\Services\PlatformLegalDocuments;
use App\Modules\Payments\Models\PayerDetails;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\get;

/*
 * Legal texts on the pay host (ADR-0056): the merchant's "Privacy notice ·
 * Terms" under the order summary (a text in a dialog with a real page
 * behind it, a link in a new tab), payer fields collected with either kind
 * of privacy notice, and the platform's /legal page linked from "Powered by"
 * only while it has a document.
 */

const LEGAL_UA = ['User-Agent' => 'Mozilla/5.0 (Legal test)'];

function platformLegalDocument(LegalDocumentKind $kind, ?string $body = null, ?string $url = null): void
{
    (new PlatformLegalDocument)->forceFill([
        'kind' => $kind,
        'format' => $url !== null ? LegalDocumentFormat::Url : LegalDocumentFormat::Text,
        'body' => $url !== null ? null : $body,
        'url' => $url,
    ])->save();
    app(PlatformLegalDocuments::class)->forget();
}

// --- The checkout's legal links --------------------------------------------------

it('links a text document to its page and renders it in a labelled dialog', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Checkout::legalDocument($tenant, LegalDocumentKind::Terms, body: "# Términos\n\nPaga a tiempo.");

    $html = (string) get(payUrl('/l/'.$link->public_token), LEGAL_UA)->assertOk()->getContent();

    expect($html)
        ->toContain('href="/l/'.$link->public_token.'/legal/terms" data-legal-open="terms" aria-haspopup="dialog"')
        ->toContain('<dialog id="legal-terms" data-legal-dialog aria-labelledby="legal-terms-title"')
        ->toContain('<h2 id="legal-terms-title"')
        // The dialog's heading is h2, so the text's "#" starts at h3.
        ->toContain('<h3>Términos</h3>')
        ->toContain('<p>Paga a tiempo.</p>')
        // In the order summary, before the form.
        ->and(strpos($html, 'data-legal-open="terms"') < strpos($html, 'id="checkout-form"'))->toBeTrue();
});

it('opens a link document in a new tab without referrer or opener, and has no dialog for it', function (): void {
    [, $link] = Checkout::scenario();

    $html = (string) get(payUrl('/l/'.$link->public_token), LEGAL_UA)->assertOk()->getContent();

    expect($html)->toContain('href="https://demo.test/privacidad" target="_blank" rel="noopener noreferrer"')
        ->and(str_contains($html, 'id="legal-privacy"'))->toBeFalse()
        ->and(str_contains($html, 'data-legal-open="terms"'))->toBeFalse();
});

it('shows no legal links when the merchant published none', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Checkout::removeLegalDocument($tenant, LegalDocumentKind::Privacy);

    get(payUrl('/l/'.$link->public_token), LEGAL_UA)->assertOk()
        ->assertDontSee('data-legal-open', false)
        ->assertDontSee('aria-label="'.__('checkout.legal.nav').'"', false);
});

it('keeps the legal links on the informative states', function (): void {
    [$tenant, $link] = Checkout::scenario(static fn ($f) => $f->canceled());
    Checkout::legalDocument($tenant, LegalDocumentKind::Terms, body: 'Términos.');

    get(payUrl('/l/'.$link->public_token), LEGAL_UA)->assertOk()
        ->assertSee('data-legal-open="terms"', false)
        ->assertSee(__('checkout.states.canceled.heading'));
});

// --- Payer fields and the privacy notice (ADR-0051 rule kept) ------------------------

it('collects the payer fields with a text privacy notice, and links it from the fields', function (): void {
    [$tenant, $link] = Checkout::scenario(static fn ($f) => $f->state(['payer_fields_config' => ['email' => 'required']]));
    Checkout::legalDocument($tenant, LegalDocumentKind::Privacy, body: 'Tratamos tus datos con cuidado.');

    $html = (string) get(payUrl('/l/'.$link->public_token), LEGAL_UA)->assertOk()->getContent();
    $form = substr($html, (int) strpos($html, '<form id="checkout-form"'));

    expect($form)->toContain('payer[email]')
        ->toContain('data-legal-open="privacy"');

    Checkout::pay($link, body: ['payer' => ['email' => 'ana@example.com']])->assertOk()->assertJson(['outcome' => 'paid']);

    expect(Checkout::inTenant($link, static fn () => PayerDetails::query()->count()))->toBe(1);
});

it('collects nothing and links nothing without a privacy notice, even with terms', function (): void {
    [$tenant, $link] = Checkout::scenario(static fn ($f) => $f->state(['payer_fields_config' => ['email' => 'required']]));
    Checkout::removeLegalDocument($tenant, LegalDocumentKind::Privacy);
    Checkout::legalDocument($tenant, LegalDocumentKind::Terms, body: 'Términos.');

    get(payUrl('/l/'.$link->public_token), LEGAL_UA)->assertOk()
        ->assertDontSee('payer[email]', false)
        ->assertDontSee('data-legal-open="privacy"', false)
        ->assertSee('data-legal-open="terms"', false);
});

// --- The document's own page -----------------------------------------------------------

it('serves a text document on its own page, with raw HTML and unsafe links removed', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Checkout::legalDocument($tenant, LegalDocumentKind::Privacy, body: "# Aviso\n\n<script>alert(1)</script>\n\n<b>Hola</b> [clic](javascript:alert(1)) ![logo](https://evil.example/x.png) [sitio](https://tienda.example)");

    $response = get(payUrl('/l/'.$link->public_token.'/legal/privacy'), LEGAL_UA)->assertOk();
    $html = (string) $response->getContent();

    expect($response->headers->get('Content-Security-Policy'))->toContain("frame-ancestors 'none'")
        ->and($response->headers->get('X-Robots-Tag'))->toBe('noindex, nofollow')
        ->and($html)
        ->toContain('<h1 id="legal-title"')
        // On its own page the text's "#" is an h2, under the page's h1.
        ->toContain('<h2>Aviso</h2>')
        ->toContain('<a rel="noopener noreferrer" target="_blank" href="https://tienda.example">sitio</a>')
        // Images become their text alternative; HTML tags are stripped.
        ->toContain('Hola <a>clic</a> logo')
        ->toContain('href="/l/'.$link->public_token.'"');

    foreach (['<script>alert(1)</script>', '<b>Hola</b>', 'javascript:alert', 'https://evil.example/x.png'] as $unsafe) {
        expect(str_contains($html, $unsafe))->toBeFalse($unsafe);
    }
});

it('serves a link document\'s page with a link out', function (): void {
    [, $link] = Checkout::scenario();

    get(payUrl('/l/'.$link->public_token.'/legal/privacy'), LEGAL_UA)->assertOk()
        ->assertSee(__('checkout.legal.external', ['merchant' => 'Tienda Demo']))
        ->assertSee('href="https://demo.test/privacidad" target="_blank" rel="noopener noreferrer"', false);
});

it('answers the uniform 404 for an unset document, an unknown kind or an invalid token', function (string $path): void {
    [, $link] = Checkout::scenario();

    get(payUrl(str_replace('{token}', $link->public_token, $path)), LEGAL_UA)
        ->assertNotFound()
        ->assertSee(__('checkout.states.not_found.heading'));
})->with([
    'terms not set' => '/l/{token}/legal/terms',
    'unknown kind' => '/l/{token}/legal/cookies',
    'invalid token' => '/l/not-a-real-token/legal/privacy',
]);

it('never shows another tenant\'s document under a link (isolation, rule 3)', function (): void {
    [$a, $linkA] = Checkout::scenario();
    [$b, $linkB] = Checkout::scenario();
    Checkout::legalDocument($a, LegalDocumentKind::Terms, body: 'Términos de A.');
    Checkout::legalDocument($b, LegalDocumentKind::Terms, body: 'Términos de B.');
    Checkout::removeLegalDocument($b, LegalDocumentKind::Privacy);

    get(payUrl('/l/'.$linkB->public_token.'/legal/terms'), LEGAL_UA)->assertOk()
        ->assertSee('Términos de B.')
        ->assertDontSee('Términos de A.');

    // B has no privacy notice: A's is never used instead.
    get(payUrl('/l/'.$linkB->public_token.'/legal/privacy'), LEGAL_UA)->assertNotFound();

    get(payUrl('/l/'.$linkA->public_token), LEGAL_UA)->assertOk()->assertDontSee('Términos de B.');
});

// --- The platform's /legal page ----------------------------------------------------------

it('answers 404 on /legal and links nothing from "Powered by" while the platform has no document', function (): void {
    [, $link] = Checkout::scenario();

    get(payUrl('/legal'), LEGAL_UA)->assertNotFound()->assertSee(__('checkout.states.not_found.heading'));
    get(payUrl('/l/'.$link->public_token), LEGAL_UA)->assertOk()->assertDontSee('data-platform-legal', false);
});

it('shows the platform documents on /legal with anchors, and "Powered by" links there', function (): void {
    [, $link] = Checkout::scenario();
    platformLegalDocument(LegalDocumentKind::Privacy, body: "# Datos\n\nLa plataforma trata datos.");
    platformLegalDocument(LegalDocumentKind::Terms, url: 'https://axispay.example/terminos');

    $response = get(payUrl('/legal'), LEGAL_UA)->assertOk();

    $html = (string) $response->getContent();

    expect($response->headers->get('Content-Security-Policy'))->toContain("default-src 'self'")
        ->and(str_contains($html, 'Tienda Demo'))->toBeFalse()
        ->and($html)
        ->toContain('<section id="privacy"')
        ->toContain('<section id="terms"')
        ->toContain('href="#privacy"')
        // Under the section's h2, the text's "#" is an h3.
        ->toContain('<h3>Datos</h3>')
        ->toContain('href="https://axispay.example/terminos" target="_blank" rel="noopener noreferrer"');

    // In a new tab (the payer never leaves the payment), said to screen readers.
    get(payUrl('/l/'.$link->public_token), LEGAL_UA)->assertOk()
        ->assertSee('href="/legal" class="inline-flex min-h-touch', false)
        ->assertSee('target="_blank" rel="noopener noreferrer" data-platform-legal', false)
        ->assertSee('data-platform-legal>', false)
        ->assertSee('<span class="sr-only"> (se abre en una pestaña nueva)</span></a>', false);

    // The uniform 404 page links there too.
    get(payUrl('/l/unknown-token'), LEGAL_UA)->assertNotFound()->assertSee('data-platform-legal', false);
});
