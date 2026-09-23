<?php

declare(strict_types=1);

namespace App\Modules\PlatformAdmin\Actions;

use App\Modules\Identity\Services\OpaqueTokens;
use App\Modules\PlatformAdmin\Exceptions\ImpersonationNotAllowedException;
use App\Modules\PlatformAdmin\Models\ImpersonationSession;
use App\Modules\Tenancy\Scopes\TenantScope;
use Illuminate\Support\Facades\DB;

/**
 * Redeems the hand-off token on the app host exactly once. The lookup runs
 * before any tenant context exists (the PlatformAdmin module is on the
 * scope-bypass whitelist); the token is cleared as soon as it is used.
 */
final readonly class ConsumeImpersonationToken
{
    public function __construct(private OpaqueTokens $tokens) {}

    public function handle(string $token): ImpersonationSession
    {
        if ($token === '' || strlen($token) > 128) {
            throw ImpersonationNotAllowedException::invalidToken();
        }

        return DB::transaction(function () use ($token): ImpersonationSession {
            $session = ImpersonationSession::query()
                ->withoutGlobalScope(TenantScope::class)
                ->where('token_hash', $this->tokens->hash($token))
                ->lockForUpdate()
                ->first();

            if ($session === null || $session->consumed_at !== null || ! $session->isActive()) {
                throw ImpersonationNotAllowedException::invalidToken();
            }

            $session->forceFill(['consumed_at' => now(), 'token_hash' => null])->save();

            return $session;
        });
    }
}
