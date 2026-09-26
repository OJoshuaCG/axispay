<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

/**
 * Network error, timeout, rate limit or 5xx (plan 12.6): retry later with the
 * SAME idempotency key.
 */
final class GatewayUnavailableException extends GatewayException {}
