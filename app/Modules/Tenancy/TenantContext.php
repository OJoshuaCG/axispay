<?php

declare(strict_types=1);

namespace App\Modules\Tenancy;

use App\Modules\Shared\Ids\Ulid;
use App\Modules\Tenancy\Events\PlatformContextEntered;
use App\Modules\Tenancy\Exceptions\MissingTenantContextException;
use Illuminate\Contracts\Events\Dispatcher;
use InvalidArgumentException;

/**
 * The current tenant and mode (plan 6.1). Registered as a *scoped* binding, so
 * Laravel resets it for every request and every queued job; it must never be a
 * singleton (Octane is not used for the same reason, plan 5).
 *
 * Reading tenant data without a context throws (fail-closed). The platform
 * context lifts the tenant filter and is always entered through
 * runAsPlatform(), which is audited.
 */
final class TenantContext
{
    private ?string $tenantId = null;

    private ?bool $livemode = null;

    private bool $platformMode = false;

    public function __construct(private readonly Dispatcher $events) {}

    public function set(string $tenantId, bool $livemode): void
    {
        if (! Ulid::isValid($tenantId)) {
            throw new InvalidArgumentException('The tenant ID must be a canonical ULID.');
        }

        $this->tenantId = $tenantId;
        $this->livemode = $livemode;
    }

    public function clear(): void
    {
        $this->tenantId = null;
        $this->livemode = null;
        $this->platformMode = false;
    }

    public function hasTenant(): bool
    {
        return $this->tenantId !== null;
    }

    public function idOrNull(): ?string
    {
        return $this->tenantId;
    }

    public function idOrFail(?string $model = null): string
    {
        return $this->tenantId ?? throw new MissingTenantContextException($model);
    }

    public function livemode(): bool
    {
        return $this->livemode ?? throw new MissingTenantContextException;
    }

    public function livemodeOrNull(): ?bool
    {
        return $this->livemode;
    }

    public function isPlatformMode(): bool
    {
        return $this->platformMode;
    }

    /**
     * Run a callback without the tenant filter. Always audited through the
     * PlatformContextEntered event; the reason is mandatory and recorded.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAsPlatform(string $reason, callable $callback): mixed
    {
        $reason = trim($reason);

        if ($reason === '') {
            throw new InvalidArgumentException('Entering the platform context requires a reason.');
        }

        $this->events->dispatch(new PlatformContextEntered($reason, $this->tenantId));

        $previous = $this->platformMode;
        $this->platformMode = true;

        try {
            return $callback();
        } finally {
            $this->platformMode = $previous;
        }
    }

    /**
     * Run a callback inside a specific tenant context and restore the previous
     * state afterwards (console loops, jobs, cross-surface entry points).
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    public function runAsTenant(string $tenantId, bool $livemode, callable $callback): mixed
    {
        [$previousTenant, $previousMode, $previousPlatform] = [$this->tenantId, $this->livemode, $this->platformMode];

        $this->set($tenantId, $livemode);
        $this->platformMode = false;

        try {
            return $callback();
        } finally {
            $this->tenantId = $previousTenant;
            $this->livemode = $previousMode;
            $this->platformMode = $previousPlatform;
        }
    }
}
