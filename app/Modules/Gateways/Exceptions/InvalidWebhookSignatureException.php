<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

use RuntimeException;

/**
 * An incoming webhook failed signature verification or is malformed (plan
 * 14.2 step 2): answered with 400 and counted, never processed.
 */
final class InvalidWebhookSignatureException extends RuntimeException {}
