<?php

declare(strict_types=1);

namespace App\Modules\Identity\Services;

use App\Modules\Identity\Models\User;
use App\Modules\PlatformAdmin\Models\PlatformAdmin;
use App\Modules\Tenancy\TenantContext;

/**
 * Finds the accounts an operator wants to recover from the console. There is
 * no tenant context on the command line, so tenant users are looked up in the
 * platform context, which is audited (`platform_context.entered`) with the
 * given purpose.
 */
final readonly class RecoverableAccounts
{
    public function __construct(private TenantContext $context) {}

    /**
     * @return list<User|PlatformAdmin> platform admin first, then tenant user
     */
    public function findByEmail(string $email, string $purpose): array
    {
        $email = UserDirectory::normalize($email);

        $admin = PlatformAdmin::query()->where('email', $email)->first();
        $user = $this->context->runAsPlatform(
            $purpose,
            static fn (): ?User => User::query()->with('tenant')->where('email', $email)->first(),
        );

        return array_values(array_filter([$admin, $user]));
    }
}
