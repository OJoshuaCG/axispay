<?php

declare(strict_types=1);

namespace App\Modules\ApiKeys\Services;

use App\Modules\ApiKeys\Models\ApiKey;
use App\Modules\Audit\Data\Actor;
use App\Modules\Audit\Enums\ActorType;
use LogicException;

/**
 * The API key of the current request, set by AuthenticateApiKey. Scoped like
 * TenantContext: reset for every request and job.
 */
final class CurrentApiKey
{
    private ?ApiKey $key = null;

    public function set(ApiKey $key): void
    {
        $this->key = $key;
    }

    public function get(): ?ApiKey
    {
        return $this->key;
    }

    public function getOrFail(): ApiKey
    {
        return $this->key ?? throw new LogicException('No API key authenticated this request.');
    }

    /** The audit actor of API requests (plan 7.1 `actor_type = api_key`). */
    public function actor(): Actor
    {
        return new Actor(ActorType::ApiKey, $this->getOrFail()->id);
    }
}
