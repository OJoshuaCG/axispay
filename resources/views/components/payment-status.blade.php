{{--
    Payment status badge. Presentation comes from ONE lookup: App\Enums\PaymentStatus
    (label, badge variant, icon), so every screen shows a status the same way.

    Usage:
        <x-payment-status status="captured" />
        <x-payment-status :status="App\Enums\PaymentStatus::Disputed" />

    Labels are translated (payments.status.*). A value outside the enum follows
    the ComponentMisuse policy: throws in local/testing; elsewhere logs and
    renders a neutral "Unknown status" badge.
--}}
@props([
    'status',
])

@php
    $paymentStatus = $status instanceof \App\Enums\PaymentStatus
        ? $status
        : \App\Enums\PaymentStatus::tryFrom((string) $status);

    if ($paymentStatus === null) {
        \App\Support\ComponentMisuse::report('Unknown payment status "'.$status.'".', ['component' => 'payment-status', 'status' => $status]);
    }
@endphp

@if ($paymentStatus)
    <x-badge :variant="$paymentStatus->badgeVariant()" :icon="$paymentStatus->icon()" :attributes="$attributes">
        {{ $paymentStatus->label() }}
    </x-badge>
@else
    <x-badge variant="neutral" icon="question-mark-circle" :attributes="$attributes">
        {{ __('payments.status.unknown') }}
    </x-badge>
@endif
