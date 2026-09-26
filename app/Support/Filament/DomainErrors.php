<?php

declare(strict_types=1);

namespace App\Support\Filament;

use App\Modules\Shared\Contracts\UserFacingError;
use App\Modules\Shared\Http\Errors\ApiException;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Halt;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Shows domain refusals in the panels, in the viewer's language: API error
 * codes are translated from lang/{en,es}/api_errors.php (the English API
 * message is the fallback), other refusals carry their own message.
 */
final class DomainErrors
{
    private function __construct() {}

    public static function message(Throwable $e): string
    {
        if ($e instanceof UserFacingError) {
            return $e->userMessage();
        }

        if ($e instanceof ApiException) {
            $key = 'api_errors.'.$e->errorCode->value;

            return trans()->has($key) ? (string) trans($key) : $e->getMessage();
        }

        return (string) trans('api_errors.internal_error');
    }

    /**
     * Stops the action: as an error on the form field the refusal names when
     * it is one of `$fields`, otherwise as a notification.
     *
     * @param  list<string>  $fields  form fields named like the API parameters
     * @param  array<string, string>  $messages  wording for this form by API error code, where the panel's rules differ from the API's
     */
    public static function fail(Action $action, Throwable $e, array $fields = [], array $messages = []): never
    {
        $param = $e instanceof ApiException ? $e->param : null;
        $message = $e instanceof ApiException ? ($messages[$e->errorCode->value] ?? self::message($e)) : self::message($e);

        if ($param !== null && in_array($param, $fields, true)) {
            throw ValidationException::withMessages([self::fieldPath($action, $param) => $message]);
        }

        self::stop($message);
    }

    /** Stops the action with a danger notification. */
    public static function stop(string $message): never
    {
        Notification::make()->danger()->title($message)->send();

        throw new Halt;
    }

    /** State path of a field of the action's form (Filament mounts it under the action's nesting index). */
    public static function fieldPath(Action $action, string $field): string
    {
        return 'mountedActions.'.($action->getNestingIndex() ?? 0).'.data.'.$field;
    }
}
