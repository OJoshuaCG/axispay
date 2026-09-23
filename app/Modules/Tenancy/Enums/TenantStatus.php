<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Enums;

/**
 * Tenant lifecycle (plan 21.3). Transitions only happen through the
 * ChangeTenantStatus action. The full behavior matrix (link creation, API
 * access, 30-day read-only window after closing) is enforced in Phase 9; this
 * enum exposes the guard hooks the later phases call.
 */
enum TenantStatus: string
{
    case PendingOnboarding = 'pending_onboarding';
    case Active = 'active';
    case Grace = 'grace';
    case Suspended = 'suspended';
    case Closed = 'closed';

    /**
     * @return list<self>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::PendingOnboarding => [self::Active, self::Closed],
            self::Active => [self::Grace, self::Suspended, self::Closed],
            self::Grace => [self::Active, self::Suspended, self::Closed],
            self::Suspended => [self::Active, self::Grace, self::Closed],
            self::Closed => [],
        };
    }

    public function canTransitionTo(self $target): bool
    {
        return in_array($target, $this->allowedTransitions(), true);
    }

    /** Plan 21.3: only `active` and `grace` may create links (API and panel). */
    public function allowsLinkCreation(): bool
    {
        return $this === self::Active || $this === self::Grace;
    }

    /**
     * Phase 1 guard: a closed tenant loses panel access. The 30-day read-only
     * window after closing is part of the Phase 9 behavior matrix.
     */
    public function allowsPanelAccess(): bool
    {
        return $this !== self::Closed;
    }

    /** ADR-013: a suspended tenant keeps the panel in read-only mode. */
    public function isPanelReadOnly(): bool
    {
        return $this === self::Suspended || $this === self::Closed;
    }

    /** Statuses that show a notice banner in the tenant panel. */
    public function showsPanelBanner(): bool
    {
        return in_array($this, [self::PendingOnboarding, self::Grace, self::Suspended], true);
    }

    /** Closing needs a second, explicit confirmation (plan 21.3). */
    public function requiresDoubleConfirmation(): bool
    {
        return $this === self::Closed;
    }

    public function label(): string
    {
        return __('tenancy.status.'.$this->value);
    }

    /**
     * @return array<string, string> value => translated label
     */
    public static function options(): array
    {
        $options = [];

        foreach (self::cases() as $case) {
            $options[$case->value] = $case->label();
        }

        return $options;
    }
}
