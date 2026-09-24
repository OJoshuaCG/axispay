<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use Illuminate\Contracts\Session\Session;

/**
 * Reads and writes the impersonation marker of the current tenant-panel
 * session (plan 17.4). The PlatformAdmin module starts and ends
 * impersonations; this class only knows what the session carries.
 */
final class ImpersonationState
{
    private const string SESSION_KEY = 'axispay.impersonation';

    public function __construct(private readonly Session $session) {}

    public function start(string $impersonationId, string $platformAdminId): void
    {
        $this->session->put(self::SESSION_KEY, [
            'id' => $impersonationId,
            'platform_admin_id' => $platformAdminId,
        ]);
    }

    public function forget(): void
    {
        $this->session->forget(self::SESSION_KEY);
    }

    public function isActive(): bool
    {
        return $this->impersonationId() !== null;
    }

    public function impersonationId(): ?string
    {
        return $this->value('id');
    }

    public function platformAdminId(): ?string
    {
        return $this->value('platform_admin_id');
    }

    private function value(string $key): ?string
    {
        $state = $this->session->get(self::SESSION_KEY);

        if (! is_array($state)) {
            return null;
        }

        $value = $state[$key] ?? null;

        return is_string($value) ? $value : null;
    }
}
