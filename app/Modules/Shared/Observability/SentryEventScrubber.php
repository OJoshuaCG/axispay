<?php

declare(strict_types=1);

namespace App\Modules\Shared\Observability;

use App\Modules\Shared\Logging\Redactor;
use Sentry\Breadcrumb;
use Sentry\Event;
use Sentry\EventHint;

/**
 * `before_send` hook for the Sentry SDK (config/sentry.php). Applies the same
 * Redactor as the logs to everything an event carries: request data, extra,
 * contexts, tags, breadcrumbs, the message and exception values. Works the
 * same with GlitchTip, which speaks the Sentry protocol.
 */
final class SentryEventScrubber
{
    private const array SDK_CONTEXTS = ['os', 'runtime', 'trace'];

    public static function beforeSend(Event $event, ?EventHint $hint = null): Event
    {
        $redactor = new Redactor;

        $event->setRequest($redactor->redactArray($event->getRequest()));
        $event->setExtra($redactor->redactArray($event->getExtra()));

        $tags = [];

        foreach ($event->getTags() as $name => $value) {
            $tags[$name] = $redactor->isSensitiveKey($name) ? Redactor::MASK : $redactor->redactString($value);
        }

        $event->setTags($tags);

        foreach ($event->getContexts() as $name => $context) {
            // SDK-generated environment contexts carry keys such as "name"
            // (e.g. os.name) that are not PII; only their values are scanned.
            $scrubbed = in_array($name, self::SDK_CONTEXTS, true)
                ? array_map($redactor->redactValue(...), $context)
                : $redactor->redactArray($context);

            $event->setContext((string) $name, $scrubbed);
        }

        $message = $event->getMessage();

        if ($message !== null) {
            $event->setMessage($redactor->redactString($message));
        }

        foreach ($event->getExceptions() as $exception) {
            $exception->setValue($redactor->redactString($exception->getValue()));
        }

        $event->setBreadcrumb(array_map(
            static function (Breadcrumb $breadcrumb) use ($redactor): Breadcrumb {
                $message = $breadcrumb->getMessage();
                $scrubbed = $message !== null ? $breadcrumb->withMessage($redactor->redactString($message)) : $breadcrumb;

                foreach ($redactor->redactArray($breadcrumb->getMetadata()) as $name => $value) {
                    $scrubbed = $scrubbed->withMetadata((string) $name, $value);
                }

                return $scrubbed;
            },
            $event->getBreadcrumbs(),
        ));

        return $event;
    }
}
