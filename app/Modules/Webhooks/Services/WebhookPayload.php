<?php

declare(strict_types=1);

namespace App\Modules\Webhooks\Services;

use App\Modules\Shared\Ids\PrefixedId;
use App\Modules\Shared\Ids\ResourceType;
use App\Modules\Shared\Time\IsoDateTime;
use App\Modules\Webhooks\Enums\WebhookEventType;
use Carbon\CarbonImmutable;
use stdClass;

/**
 * The body of an outgoing webhook (plan 15.3), encoded once and frozen in
 * `webhook_events.payload`:
 *
 *     {"id":"evt_...","type":"payment.succeeded","api_version":"v1",
 *      "livemode":true,"created_at":"...Z","data":{"object":{...}, ...}}
 *
 * `data.object` is the event's main object as the API shows it; the other
 * facts of the business event (`late_payment`, `failure_count`, the related
 * `payment` of `payment_link.paid`...) are siblings of `object` in `data`.
 */
final class WebhookPayload
{
    public const string API_VERSION = 'v1';

    private function __construct() {}

    /**
     * @param  stdClass  $data  the business event's data, decoded as objects so `{}` stays `{}`
     */
    public static function encode(string $eventUlid, WebhookEventType $type, bool $livemode, CarbonImmutable $createdAt, stdClass $data): string
    {
        $key = $type->objectKey();
        $object = $data->{$key} ?? new stdClass;
        $rest = (array) $data;
        unset($rest[$key]);

        return json_encode([
            'id' => PrefixedId::encode(ResourceType::Event, $eventUlid),
            'type' => $type->value,
            'api_version' => self::API_VERSION,
            'livemode' => $livemode,
            'created_at' => IsoDateTime::format($createdAt),
            'data' => ['object' => $object, ...$rest],
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** The `ping` test event (plan 15.1). */
    public static function ping(string $eventUlid, bool $livemode, CarbonImmutable $createdAt): string
    {
        $data = new stdClass;
        $data->ping = (object) ['object' => 'ping', 'message' => 'Test event.'];

        return self::encode($eventUlid, WebhookEventType::Ping, $livemode, $createdAt, $data);
    }
}
