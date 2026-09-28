<?php

declare(strict_types=1);

use App\Modules\Access\Enums\SystemRole;
use App\Modules\Checkout\Data\CheckoutResult;
use App\Modules\Checkout\Enums\CheckoutOutcome;
use App\Modules\Checkout\Http\CheckoutErrorPages;
use App\Modules\Checkout\Http\CheckoutResponses;
use App\Modules\Checkout\Notifications\CheckoutBlockedNotification;
use App\Modules\PayerFields\Data\PayerCountries;
use App\Modules\PaymentLinks\Filament\Resources\PaymentLinks\Pages\ViewPaymentLink;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Enums\ReviewReason;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Tenancy\Models\Tenant;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Session\TokenMismatchException;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\Support\CheckoutTestHelpers as Checkout;

use function Pest\Laravel\freezeTime;
use function Pest\Laravel\getJson;

/**
 * ADR-0051, Phase 4 iterations 10 and 11 (UI and i18n), the parts the
 * server decides: the blocked-link e-mail (tenant language, subject, plural,
 * action), plural waits, Retry-After of a card-testing pause, the link's
 * language on request limits, the pay host's own error pages and outcomes,
 * and the panel's review reasons, decline labels, countries and brands.
 */
function i18nMailText(MailMessage $mail): string
{
    return implode(' ', array_map(static fn (mixed $line): string => is_string($line) ? $line : '', $mail->introLines));
}

// Mail (i18n 1, 2, 6, 13) -----------------------------------------------------

it('sends the blocked-link e-mail in the tenant\'s language, with one "mode", a plural wait and a link to the panel', function (string $locale, string $subject, string $hours): void {
    Notification::fake();
    config(['axispay.checkout.turnstile_after_failures' => 100, 'axispay.checkout.rate_limits.link_attempts' => 100, 'axispay.checkout.rate_limits.ip_attempts' => 100]);
    [$tenant, $link] = Checkout::scenario();
    Tenant::query()->whereKey($tenant->id)->update(['default_locale' => $locale]);
    $owner = tenantUser($tenant, [SystemRole::Owner]);

    foreach (range(1, 10) as $i) {
        Checkout::pay($link, 'ctoken_decline_'.$i);
    }

    Notification::assertSentTo($owner, CheckoutBlockedNotification::class, static function (CheckoutBlockedNotification $notification) use ($owner, $locale, $subject, $hours, $link): bool {
        app()->setLocale((string) $notification->locale);
        $mail = $notification->toMail($owner);

        return $notification->locale === $locale
            && $mail->subject === $subject
            && str_contains(i18nMailText($mail), $hours)
            && $mail->actionUrl === route('filament.app.resources.payment-links.view', ['record' => $link->id]);
    });
})->with([
    'Spanish tenant' => ['es', 'Se bloqueó un link de pago (modo de prueba)', '24 horas'],
    'English tenant' => ['en', 'A payment link was blocked (test mode)', '24 hours'],
]);

it('writes one hour in the singular', function (): void {
    config(['axispay.checkout.long_block_hours' => 1]);
    app()->setLocale('es');

    $mail = (new CheckoutBlockedNotification('01K6AAAAAAAAAAAAAAAAAAAAAA', false))->toMail(new stdClass);

    expect(i18nMailText($mail))->toContain('durante 1 hora,');
});

// Waits and Retry-After (i18n 4, 6) --------------------------------------------

it('pluralises the card-testing pause and sends its real wait as Retry-After', function (int $minutes, string $text): void {
    [, $link] = Checkout::scenario();
    app()->setLocale('es');

    $response = app(CheckoutResponses::class)->result(new CheckoutResult(CheckoutOutcome::RateLimited, minutes: $minutes), $link);

    expect($response->getStatusCode())->toBe(429)
        ->and($response->headers->get('Retry-After'))->toBe((string) ($minutes * 60))
        ->and(data_get($response->getData(true), 'message'))->toContain($text);
})->with([
    'one minute' => [1, 'en 1 minuto.'],
    'thirty minutes' => [30, 'en 30 minutos.'],
]);

it('answers a request limit in the link\'s language (i18n 5)', function (): void {
    freezeTime();
    [, $link] = Checkout::scenario(static fn ($f) => $f->state(['locale' => 'en']));

    foreach (range(1, 90) as $i) {
        getJson(payUrl('/l/'.$link->public_token.'/status'));
    }

    $message = getJson(payUrl('/l/'.$link->public_token.'/status'))->assertStatus(429)->json('message');
    $message = is_string($message) ? $message : '';

    expect($message)->toStartWith('Too many requests. Try again in');
});

// Pay-host error pages (i18n 3, 7) ------------------------------------------------

it('answers an expired session to the page script as session_expired, never framework text', function (): void {
    app()->setLocale('es');
    $request = Request::create(payUrl('/l/tok/attempts'), 'POST', server: ['HTTP_ACCEPT' => 'application/json']);

    $response = CheckoutErrorPages::render(new TokenMismatchException('CSRF token mismatch.'), $request);

    expect($response?->getStatusCode())->toBe(419)
        ->and($response instanceof JsonResponse ? $response->getData(true) : null)->toBe(['outcome' => 'session_expired', 'message' => 'Recarga la página para continuar.']);
});

it('renders the pay host\'s own error pages, and leaves other hosts alone', function (int $status, string $heading): void {
    app()->setLocale('es');
    config(['app.debug' => false]);
    $exception = $status === 419 ? new TokenMismatchException : ($status === 500 ? new RuntimeException('boom') : new HttpException($status, '', null, $status === 503 ? ['Retry-After' => '120'] : []));

    $response = CheckoutErrorPages::render($exception, Request::create(payUrl('/l/tok')));

    $content = (string) $response?->getContent();

    expect($response?->getStatusCode())->toBe($status)
        ->and(str_contains($content, $heading) && str_contains($content, 'Con la tecnología de'))->toBeTrue()
        ->and(str_contains($content, 'boom'))->toBeFalse()
        ->and(CheckoutErrorPages::render($exception, Request::create(appUrl('/'))))->toBeNull();

    if ($status === 503) {
        expect($response?->headers->get('Retry-After'))->toBe('120');
    }
})->with([
    '419' => [419, 'Tu sesión expiró'],
    '404' => [404, 'No encontramos este enlace'],
    '429' => [429, 'Demasiadas solicitudes'],
    '500' => [500, 'Algo salió mal'],
    '503' => [503, 'Volvemos en un momento'],
]);

it('translates Laravel\'s own mail and error strings into Spanish', function (): void {
    app()->setLocale('es');

    expect(__('Hello!'))->toBe('¡Hola!')
        ->and(__('Regards,'))->toBe('Saludos,')
        ->and(__('Page Expired'))->toBe('Página expirada')
        ->and(__('Server Error'))->toBe('Error del servidor')
        ->and(__('Service Unavailable'))->toBe('Servicio no disponible');
});

// Panel (i18n 8, 9, 10) --------------------------------------------------------

it('shows the review reason, a translated decline label with its raw code, the country name and the brand name', function (): void {
    [$tenant, $link] = Checkout::scenario();
    Checkout::inTenant($link, static fn () => PaymentAttempt::factory()->inStatus(PaymentAttemptStatus::Canceled)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => Checkout::connectionOf($link)->id,
        'needs_review' => true,
        'review_reason' => ReviewReason::SucceededAfterClose,
        'last_decline_code' => 'insufficient_funds',
        'card_brand' => 'amex',
        'card_last4' => '0005',
        'card_country' => 'MX',
        'failure_count' => 1,
    ]));
    actingAsTenantUser(tenantUser($tenant, [SystemRole::Owner]));
    app()->setLocale('es');

    Livewire::test(ViewPaymentLink::class, ['record' => $link->getRouteKey()])
        ->assertSee(__('payments.review_reason.succeeded_after_close'))
        ->assertDontSee(__('payments.review_reason.closed_without_gateway'))
        ->assertSee('Fondos insuficientes')
        ->assertSee('insufficient_funds')
        ->assertSee('México')
        ->assertSee('American Express •••• 0005');
});

// Payer countries (i18n 12) ------------------------------------------------------

it('sorts the phone countries as the payer\'s language sorts, Mexico first', function (): void {
    $names = array_values(PayerCountries::options('es'));
    $rest = array_slice($names, 1);
    $sorted = $rest;
    (new Collator('es'))->sort($sorted);

    expect($names[0])->toBe('México')
        ->and($rest)->toBe($sorted);
});
