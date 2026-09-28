<?php

declare(strict_types=1);

use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\PayerFields\Data\PayerData;
use App\Modules\PaymentLinks\Models\PaymentLink;
use App\Modules\Payments\Actions\ClaimLinkAttempt;
use App\Modules\Payments\Actions\ReleaseLinkAfterAttempt;
use App\Modules\Payments\Data\AttemptClaim;
use App\Modules\Payments\Data\AttemptClaimRequest;
use App\Modules\Payments\Enums\PaymentAttemptStatus;
use App\Modules\Payments\Models\PaymentAttempt;
use App\Modules\Payments\Services\AttemptLease;
use App\Modules\Shared\Money\CurrencyCode;
use App\Modules\Shared\Money\Money;
use App\Modules\Tenancy\TenantContext;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ApiTestHelpers;

/**
 * MariaDB 11.8 snapshot isolation (ADR-0051): a transaction that made a
 * plain read and then locks a row another transaction changed since is
 * refused with "record has changed since last read" (ER_CHECKREAD, 1020).
 * Reproduced deterministically: right after the read that precedes the
 * locks, a second connection changes the attempt and commits. The claim and
 * the link-then-attempt lockers read before their transaction, so they
 * succeed.
 *
 * No RefreshDatabase: the second connection must see committed rows.
 */
beforeEach(function (): void {
    expect(DB::connection()->getDatabaseName())->toEndWith('_testing');
    Artisan::call('migrate:fresh', ['--force' => true]);
    config(['database.connections.mariadb_other' => config('database.connections.mariadb')]);
});

afterEach(function (): void {
    DB::purge('mariadb_other');
    Artisan::call('migrate:fresh', ['--force' => true]);
});

/**
 * @return array{0: PaymentLink, 1: PaymentAttempt}
 */
function snapshotAttempt(): array
{
    $tenant = ApiTestHelpers::readyTenant();
    $link = ApiTestHelpers::link($tenant, false, static fn ($f) => $f->state(['currency' => 'MXN', 'amount_minor' => 150_000]));

    $attempt = app(TenantContext::class)->runAsTenant($tenant->id, false, static fn (): PaymentAttempt => PaymentAttempt::factory()->inStatus(PaymentAttemptStatus::RequiresPaymentMethod)->createOne([
        'payment_link_id' => $link->id,
        'gateway_connection_id' => GatewayConnection::query()->current()->firstOrFail()->id,
        'amount_minor' => 150_000,
        'currency' => 'MXN',
        'original_amount_minor' => 150_000,
        'original_currency' => 'MXN',
    ]));

    return [$link, $attempt];
}

/**
 * After every query matching `$match` on the default connection (the reads
 * that precede the locks, wherever they run), another connection changes
 * the attempt and commits.
 */
function changeAttemptAfter(string $match, PaymentAttempt $attempt): void
{
    DB::listen(static function (QueryExecuted $query) use ($match, $attempt): void {
        if ($query->connectionName === 'mariadb_other' || ! str_contains($query->sql, $match) || str_contains($query->sql, 'for update')) {
            return;
        }

        PaymentAttempt::on('mariadb_other')->withoutGlobalScopes()->whereKey($attempt->id)->update(['user_agent' => 'changed-'.bin2hex(random_bytes(4))]);
    });
}

it('claims an attempt that another process changes right after the claim read it (1020)', function (): void {
    [$link, $attempt] = snapshotAttempt();
    changeAttemptAfter('`payment_link_id` = ?', $attempt);

    $claim = app(TenantContext::class)->runAsTenant($link->tenant_id, false, static fn () => app(ClaimLinkAttempt::class)->handle(
        PaymentLink::query()->findOrFail($link->id),
        new AttemptClaimRequest(GatewayConnection::query()->current()->firstOrFail(), Money::ofMinor(150_000, CurrencyCode::MXN), new PayerData([]), '203.0.113.7', 'test', null),
    ));

    expect($claim)->toBeInstanceOf(AttemptClaim::class)
        ->and($claim instanceof AttemptClaim ? $claim->attempt->id : null)->toBe($attempt->id);
})->group('concurrency');

it('locks link then attempt after another process changed the attempt right after its link was read (1020)', function (): void {
    [$link, $attempt] = snapshotAttempt();
    $token = app(TenantContext::class)->runAsTenant($link->tenant_id, false, static fn (): ?string => app(AttemptLease::class)->acquire($attempt->id));
    expect($token)->toBeString();
    changeAttemptAfter('select `payment_link_id`', $attempt);

    app(TenantContext::class)->runAsTenant($link->tenant_id, false, static fn () => app(ReleaseLinkAfterAttempt::class)->handle($attempt->id, (string) $token));

    expect(PaymentAttempt::query()->withoutGlobalScopes()->findOrFail($attempt->id)->user_agent)->toStartWith('changed-');
})->group('concurrency');
