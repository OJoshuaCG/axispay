<?php

declare(strict_types=1);

namespace App\Modules\Identity\Enums;

/**
 * Derived state of a user invitation (plan 7.2). Not stored: it follows from
 * `accepted_at`, `revoked_at` and `expires_at` on `user_invitations`.
 */
enum InvitationStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Expired = 'expired';
    case Revoked = 'revoked';

    /** A new link can be issued for a pending or expired invitation (ADR-0043). */
    public function canBeResent(): bool
    {
        return $this === self::Pending || $this === self::Expired;
    }

    /** Only a link that still works can be revoked. */
    public function canBeRevoked(): bool
    {
        return $this === self::Pending;
    }

    public function label(): string
    {
        return __('identity.invitations.status.'.$this->value);
    }

    public function color(): string
    {
        return match ($this) {
            self::Pending => 'info',
            self::Accepted => 'success',
            self::Expired => 'warning',
            self::Revoked => 'gray',
        };
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
