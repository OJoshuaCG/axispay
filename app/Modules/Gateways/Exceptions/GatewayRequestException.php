<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

/**
 * The gateway refused the request (invalid parameters, missing resource...).
 * A bug or invalid data on our side: do not retry (plan 12.6).
 */
final class GatewayRequestException extends GatewayException {}
