<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Data;

use App\Modules\Webhooks\Models\ValidationEndpoint;
use SensitiveParameter;

/**
 * A validation endpoint and, when one was just generated, its signing
 * secret in plaintext: returned ONCE by ConfigureValidationEndpoint (on
 * creation; null on an update) and RotateValidationEndpointSecret. The panel
 * shows it and never keeps it in its state.
 */
final readonly class IssuedValidationEndpoint
{
    public function __construct(
        public ValidationEndpoint $endpoint,
        #[SensitiveParameter]
        public ?string $secret,
        public bool $created,
    ) {}
}
