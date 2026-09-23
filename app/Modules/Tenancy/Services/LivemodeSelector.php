<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Services;

use Illuminate\Contracts\Session\Session;

/**
 * The test/live selector of the tenant panel (plan 6.3): stored in the
 * session, test mode by default so nobody lands in live data by accident.
 */
final readonly class LivemodeSelector
{
    private const string SESSION_KEY = 'paylink.livemode';

    public function __construct(private Session $session) {}

    public function current(): bool
    {
        return $this->session->get(self::SESSION_KEY) === true;
    }

    public function set(bool $livemode): void
    {
        $this->session->put(self::SESSION_KEY, $livemode);
    }
}
