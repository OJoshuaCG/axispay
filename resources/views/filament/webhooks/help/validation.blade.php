{{--
    "How it works" of the pre-payment validation page. Built by
    App\Modules\Webhooks\Filament\Support\IntegrationHelp::validationData():
    every number comes from `axispay.pre_payment_validation.*` and the
    request example is ValidationPayload::sample(), exactly what "Test
    validation" sends.
--}}
@php
    $h = 'webhooks.help.validation.';
    $list = 'flex flex-col gap-stack-xs ps-5 text-sm text-fg';
    $title = 'text-base font-semibold text-fg';
@endphp

<div class="flex min-w-0 flex-col gap-stack-lg" data-help="validation">
    <p class="text-sm text-fg">{{ __($h.'intro') }}</p>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-flow">
        <h3 id="help-validation-flow" class="{{ $title }}">{{ __($h.'flow_title') }}</h3>
        <ol class="{{ $list }} list-decimal">
            <li>{{ __($h.'flow.pay') }}</li>
            <li>{{ __($h.'flow.authorize') }}</li>
            <li>{{ __($h.'flow.call', ['seconds' => $timeout]) }}</li>
            <li>
                {{ __($h.'flow.answer') }}
                <ul class="mt-stack-xs flex list-disc flex-col gap-stack-xs ps-5">
                    <li>{{ __($h.'flow.approve') }}</li>
                    <li>{{ __($h.'flow.reject') }}</li>
                    <li>{{ __($h.'flow.failure') }}</li>
                </ul>
            </li>
        </ol>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-setup">
        <h3 id="help-validation-setup" class="{{ $title }}">{{ __($h.'setup_title') }}</h3>
        <ol class="{{ $list }} list-decimal">
            @foreach (['mode', 'configure', 'secret', 'implement', 'test', 'links'] as $step)
                <li>{{ __($h.'setup.'.$step) }}</li>
            @endforeach
        </ol>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-request">
        <h3 id="help-validation-request" class="{{ $title }}">{{ __($h.'request_title') }}</h3>
        <p class="text-sm text-fg">{{ __($h.'request_intro') }}</p>
        @include('filament.webhooks.help.terms', ['items' => [
            'webhook-id' => __($h.'headers.webhook-id'),
            'webhook-timestamp' => __($h.'headers.webhook-timestamp', ['minutes' => $toleranceMinutes]),
            'webhook-signature' => __($h.'headers.webhook-signature', ['hours' => $previousSecretHours]),
            $kindHeader => __($h.'headers.kind', ['kind' => $kind]),
            'user-agent' => __($h.'headers.user-agent', ['agent' => $userAgent]),
        ]])
        @include('filament.webhooks.help.code', [
            'label' => __('webhooks.help.code_label', ['name' => __($h.'request_title')]),
            'code' => "POST /axispay/validate HTTP/1.1\ncontent-type: application/json\naccept: application/json\nwebhook-id: {$callId}\nwebhook-timestamp: {$timestamp}\nwebhook-signature: v1,K5oZfzN95Z9UVu1EsfQmfVNQhnkZ2pj9o9NDN/H/pI4=\n{$kindHeader}: {$kind}\nuser-agent: {$userAgent}",
        ])
        <p class="text-sm text-fg">{{ __($h.'body_intro') }}</p>
        @include('filament.webhooks.help.terms', ['items' => [
            'type' => __($h.'fields.type', ['type' => $type]),
            'test' => __($h.'fields.test'),
            'attempt_number' => __($h.'fields.attempt_number'),
            'data.payment_link' => __($h.'fields.payment_link'),
            'data.charge' => __($h.'fields.charge'),
            'data.card' => __($h.'fields.card'),
            'data.payer' => __($h.'fields.payer'),
        ]])
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-example">
        <h3 id="help-validation-example" class="{{ $title }}">{{ __($h.'example_title') }}</h3>
        @include('filament.webhooks.help.code', ['code' => $request, 'label' => __($h.'example_title')])
        <p class="text-sm text-fg-secondary">{{ __($h.'example_note') }}</p>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-response">
        <h3 id="help-validation-response" class="{{ $title }}">{{ __($h.'response_title') }}</h3>
        <p class="text-sm text-fg">{{ __($h.'response_intro') }}</p>
        @include('filament.webhooks.help.terms', ['items' => [
            'decision' => __($h.'response.decision'),
            'reason_code' => __($h.'response.reason_code', ['max' => $reasonCodeMax]),
            'payer_message' => __($h.'response.payer_message', ['max' => $payerMessageMax]),
            'cancel_link' => __($h.'response.cancel_link'),
        ]])
        <p class="text-sm font-medium text-fg">{{ __($h.'approve_title') }}</p>
        @include('filament.webhooks.help.code', ['code' => $approveExample, 'label' => __('webhooks.help.code_label', ['name' => __($h.'approve_title')])])
        <p class="text-sm font-medium text-fg">{{ __($h.'reject_title') }}</p>
        @include('filament.webhooks.help.code', ['code' => $rejectExample, 'label' => __('webhooks.help.code_label', ['name' => __($h.'reject_title')])])
        <p class="text-sm text-fg-secondary">{{ __($h.'response_note') }}</p>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-limits">
        <h3 id="help-validation-limits" class="{{ $title }}">{{ __($h.'limits_title') }}</h3>
        <ul class="{{ $list }} list-disc">
            <li>{{ __($h.'limits.connect', ['seconds' => $connectTimeout]) }}</li>
            <li>{{ __($h.'limits.total', ['seconds' => $timeout]) }}</li>
            <li>{{ __($h.'limits.size', ['kb' => $maxKb]) }}</li>
            <li>{{ __($h.'limits.retry') }}</li>
            <li>{{ __($h.'limits.url', ['ports' => $ports]) }}</li>
        </ul>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-policy">
        <h3 id="help-validation-policy" class="{{ $title }}">{{ __($h.'policy_title') }}</h3>
        <p class="text-sm text-fg">{{ __($h.'policy_intro') }}</p>
        <dl class="flex flex-col gap-stack-sm">
            @foreach ($policies as $policy)
                <div class="flex min-w-0 flex-col gap-stack-xs rounded-md border border-line p-3">
                    <dt class="flex flex-wrap items-center gap-stack-sm text-sm font-medium text-fg">
                        <span>{{ $policy['label'] }}</span>
                        <code class="font-numeric break-all rounded-sm bg-sunken px-1 text-xs text-fg-secondary">{{ $policy['value'] }}</code>
                    </dt>
                    <dd class="text-sm text-fg-secondary">{{ $policy['explanation'] }}</dd>
                </div>
            @endforeach
        </dl>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-payer">
        <h3 id="help-validation-payer" class="{{ $title }}">{{ __($h.'payer_title') }}</h3>
        <ul class="{{ $list }} list-disc">
            <li>{{ __($h.'payer.charged') }}</li>
            <li>{{ __($h.'payer.blocked') }}</li>
        </ul>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-links">
        <h3 id="help-validation-links" class="{{ $title }}">{{ __($h.'links_title') }}</h3>
        <ul class="{{ $list }} list-disc">
            @foreach (['per_link', 'default', 'removed'] as $item)
                <li>{{ __($h.'links.'.$item) }}</li>
            @endforeach
        </ul>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-test">
        <h3 id="help-validation-test" class="{{ $title }}">{{ __($h.'test_title') }}</h3>
        <ul class="{{ $list }} list-disc">
            <li>{{ __($h.'test.what') }}</li>
            <li>{{ __($h.'test.result') }}</li>
            <li>{{ __($h.'test.log', ['days' => $retentionDays]) }}</li>
        </ul>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-validation-alert">
        <h3 id="help-validation-alert" class="{{ $title }}">{{ __($h.'alert_title') }}</h3>
        <p class="text-sm text-fg">{{ __($h.'alert', ['count' => $alertAfter, 'minutes' => $alertIntervalMinutes]) }}</p>
    </section>
</div>
