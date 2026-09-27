<?php

declare(strict_types=1);

namespace App\Modules\ProviderEvents\Enums;

/**
 * Lifecycle of an incoming gateway event (plan 7.6, 14.2).
 */
enum ProviderEventStatus: string
{
    case Received = 'received';
    case Processed = 'processed';
    case Ignored = 'ignored';
    case Failed = 'failed';
    case Unroutable = 'unroutable';

    /** `last_error` of a payment event the platform did not originate (plan 14.4). */
    public const string FOREIGN_OBJECT = 'foreign_object';
}
