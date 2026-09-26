<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Enums;

/**
 * Why a connection action was refused (panel messages: lang gateways.errors).
 */
enum ConnectionError: string
{
    case MethodDisabled = 'method_disabled';
    case AlreadyConnected = 'already_connected';
    case CountryNotAllowed = 'country_not_allowed';
    case NotOnboarding = 'not_onboarding';
    case NotApiKey = 'not_api_key';
    case RiskNotAcknowledged = 'risk_not_acknowledged';
    case GatewayUnavailable = 'gateway_unavailable';
    case GatewayRefused = 'gateway_refused';

    public function message(): string
    {
        return __('gateways.errors.'.$this->value);
    }
}
