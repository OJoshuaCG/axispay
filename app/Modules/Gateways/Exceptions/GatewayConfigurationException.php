<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Exceptions;

use RuntimeException;

/**
 * A platform gateway setting is missing or invalid (for example the Stripe
 * secret of a mode). Never carries the configured value.
 */
final class GatewayConfigurationException extends RuntimeException {}
