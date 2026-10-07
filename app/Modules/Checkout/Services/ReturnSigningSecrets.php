<?php

declare(strict_types=1);

namespace App\Modules\Checkout\Services;

use App\Modules\Checkout\Models\ReturnSigningSecret;
use Carbon\CarbonImmutable;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * The secret that signs the return proof of the current tenant and mode
 * (ADR-0064). It is created the first time it is needed, and rotated without
 * downtime like the validation secret: the previous one keeps signing for 24
 * hours (`axispay.checkout.return_previous_secret_hours`), so a merchant that
 * has not yet deployed the new secret still verifies every return.
 */
final class ReturnSigningSecrets
{
    public const string PREFIX = 'rsec_';

    private const int SECRET_BYTES = 32;

    /**
     * The secrets that sign a return now: the current one first, then the
     * previous one while its overlap lasts.
     *
     * @return list<string>
     */
    public function signingSecrets(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now();
        $row = $this->current();
        $secrets = [$row->secret];

        if ($row->previous_secret !== null && $row->previous_secret_expires_at !== null && $row->previous_secret_expires_at->greaterThan($now)) {
            $secrets[] = $row->previous_secret;
        }

        return $secrets;
    }

    /** The row of the current tenant and mode, created on first use. */
    public function current(): ReturnSigningSecret
    {
        $existing = ReturnSigningSecret::query()->first();

        if ($existing !== null) {
            return $existing;
        }

        try {
            $row = new ReturnSigningSecret;
            $row->forceFill(['secret' => self::generate()])->save();

            return $row->refresh();
        } catch (UniqueConstraintViolationException) {
            // Two first uses at once: the other one won, use its secret.
            return ReturnSigningSecret::query()->firstOrFail();
        }
    }

    /**
     * Makes a new secret current, keeps the one it replaces signing for the
     * overlap, and returns the new plaintext (shown once by the caller).
     * Rotating again inside the overlap drops the oldest secret.
     */
    public function rotate(): string
    {
        $secret = self::generate();
        $overlapHours = max(0, config()->integer('axispay.checkout.return_previous_secret_hours'));

        DB::transaction(function () use ($secret, $overlapHours): void {
            $row = ReturnSigningSecret::query()->lockForUpdate()->find($this->current()->id);
            $row ??= $this->current();

            $row->forceFill([
                'previous_secret' => $row->secret,
                'previous_secret_expires_at' => CarbonImmutable::now()->addHours($overlapHours),
                'secret' => $secret,
            ])->save();
        });

        return $secret;
    }

    /** `rsec_` + 64 hex characters (256 random bits). */
    public static function generate(): string
    {
        return self::PREFIX.bin2hex(random_bytes(self::SECRET_BYTES));
    }
}
