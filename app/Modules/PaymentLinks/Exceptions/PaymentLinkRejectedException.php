<?php

declare(strict_types=1);

namespace App\Modules\PaymentLinks\Exceptions;

use App\Modules\Shared\Http\Errors\ApiException;

/**
 * A payment link request refused by a field or business rule (plan 10.4,
 * 10.5). Carries the API error code and `param`; the panel translates it by
 * code.
 */
final class PaymentLinkRejectedException extends ApiException {}
