<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Actions;

use App\Modules\Webhooks\Enums\WebhookEndpointRefusal;
use App\Modules\Webhooks\Exceptions\WebhookEndpointNotAllowedException;

/**
 * Validation shared by the endpoint actions.
 *
 * @internal
 */
final class WebhookEndpointInput
{
    private function __construct() {}

    public static function description(?string $description): ?string
    {
        $description = $description !== null ? trim($description) : null;

        if ($description === null || $description === '') {
            return null;
        }

        if (mb_strlen($description) > config()->integer('axispay.webhooks.description_max')) {
            throw new WebhookEndpointNotAllowedException(WebhookEndpointRefusal::DescriptionTooLong);
        }

        return $description;
    }
}
