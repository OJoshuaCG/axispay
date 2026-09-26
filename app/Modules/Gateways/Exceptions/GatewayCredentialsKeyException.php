<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

use RuntimeException;

/**
 * GATEWAY_CREDENTIALS_KEY is missing, malformed, equal to APP_KEY, or the
 * version a ciphertext needs is not configured. Never carries key material.
 */
final class GatewayCredentialsKeyException extends RuntimeException {}
