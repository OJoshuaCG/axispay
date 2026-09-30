{{--
    "How it works" of the webhook endpoints (list and detail pages). Built by
    App\Modules\Webhooks\Filament\Support\IntegrationHelp::webhooksData():
    every number comes from `axispay.webhooks.*` and the example from
    WebhookPayload, the encoder of the real events.
--}}
@php
    $h = 'webhooks.help.webhooks.';
    $list = 'flex flex-col gap-stack-xs ps-5 text-sm text-fg';
    $title = 'text-base font-semibold text-fg';
@endphp

<div class="flex min-w-0 flex-col gap-stack-lg" data-help="webhooks">
    <p class="text-sm text-fg">{{ __($h.'intro') }}</p>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-webhooks-flow">
        <h3 id="help-webhooks-flow" class="{{ $title }}">{{ __($h.'flow_title') }}</h3>
        <ol class="{{ $list }} list-decimal">
            @foreach (['pay', 'result', 'record', 'queue', 'post'] as $step)
                <li>{{ __($h.'flow.'.$step) }}</li>
            @endforeach
        </ol>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-webhooks-setup">
        <h3 id="help-webhooks-setup" class="{{ $title }}">{{ __($h.'setup_title') }}</h3>
        <ol class="{{ $list }} list-decimal">
            <li>{{ __($h.'setup.mode', ['max' => $maxEndpoints]) }}</li>
            @foreach (['create', 'secret', 'implement', 'test', 'live'] as $step)
                <li>{{ __($h.'setup.'.$step) }}</li>
            @endforeach
        </ol>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-webhooks-events">
        <h3 id="help-webhooks-events" class="{{ $title }}">{{ __($h.'events_title') }}</h3>
        @include('filament.webhooks.help.terms', ['items' => collect($events)->mapWithKeys(fn (array $event): array => [$event['type'] => $event['description']])->all()])
        <p class="text-sm text-fg-secondary">{{ __($h.'events_note') }}</p>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-webhooks-request">
        <h3 id="help-webhooks-request" class="{{ $title }}">{{ __($h.'request_title') }}</h3>
        <p class="text-sm text-fg">{{ __($h.'request_intro') }}</p>
        @include('filament.webhooks.help.terms', ['items' => [
            'webhook-id' => __($h.'headers.webhook-id'),
            'webhook-timestamp' => __($h.'headers.webhook-timestamp'),
            'webhook-signature' => __($h.'headers.webhook-signature', ['hours' => $previousSecretHours]),
            'user-agent' => __($h.'headers.user-agent', ['agent' => $userAgent]),
        ]])
        @include('filament.webhooks.help.code', [
            'label' => __('webhooks.help.code_label', ['name' => __($h.'request_title')]),
            'code' => "POST /webhooks/axispay HTTP/1.1\ncontent-type: application/json\nwebhook-id: {$eventId}\nwebhook-timestamp: {$timestamp}\nwebhook-signature: v1,K5oZfzN95Z9UVu1EsfQmfVNQhnkZ2pj9o9NDN/H/pI4=\nuser-agent: {$userAgent}",
        ])
        <p class="text-sm text-fg">{{ __($h.'body_intro') }}</p>
        @include('filament.webhooks.help.terms', ['items' => collect(['id', 'type', 'api_version', 'livemode', 'created_at', 'data.object'])->mapWithKeys(fn (string $field): array => [$field => __($h.'fields.'.str_replace('.', '_', $field))])->all()])
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-webhooks-example">
        <h3 id="help-webhooks-example" class="{{ $title }}">{{ __($h.'example_title') }}</h3>
        @include('filament.webhooks.help.code', ['code' => $example, 'label' => __($h.'example_title')])
        <p class="text-sm text-fg-secondary">{{ __($h.'example_note') }}</p>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-webhooks-verify">
        <h3 id="help-webhooks-verify" class="{{ $title }}">{{ __($h.'verify_title') }}</h3>
        <ol class="{{ $list }} list-decimal">
            @foreach (['secret', 'content', 'hmac', 'compare'] as $step)
                <li>{{ __($h.'verify.'.$step) }}</li>
            @endforeach
            <li>{{ __($h.'verify.timestamp', ['minutes' => $toleranceMinutes]) }}</li>
        </ol>
        <p class="text-sm text-fg">
            {{ __($h.'verify_libraries') }}
            <a href="https://www.standardwebhooks.com/" target="_blank" rel="noopener noreferrer" class="font-medium text-link underline">standardwebhooks.com</a>
        </p>
        <p class="text-sm font-medium text-fg">{{ __($h.'verify_example_title') }}</p>
        @include('filament.webhooks.help.code', ['code' => $verifyExample, 'label' => __($h.'verify_example_title')])
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-webhooks-delivery">
        <h3 id="help-webhooks-delivery" class="{{ $title }}">{{ __($h.'delivery_title') }}</h3>
        <ul class="{{ $list }} list-disc">
            <li>{{ __($h.'delivery.timeout', ['connect' => $connectTimeout, 'total' => $timeout]) }}</li>
            <li>{{ __($h.'delivery.success') }}</li>
            <li>
                {{ __($h.'delivery.retries', ['attempts' => $attempts, 'total' => $retryTotal]) }}
                <ul class="mt-stack-xs flex list-disc flex-col gap-stack-xs ps-5 text-fg-secondary" data-help-retry-schedule>
                    @foreach ($schedule as $attempt)
                        <li>{{ $attempt }}</li>
                    @endforeach
                </ul>
            </li>
            <li>{{ __($h.'delivery.disable', ['days' => $disableDays]) }}</li>
            <li>{{ __($h.'delivery.duplicates') }}</li>
            <li>{{ __($h.'delivery.order') }}</li>
        </ul>
    </section>

    <section class="flex min-w-0 flex-col gap-stack-sm" aria-labelledby="help-webhooks-url">
        <h3 id="help-webhooks-url" class="{{ $title }}">{{ __($h.'url_title') }}</h3>
        <ul class="{{ $list }} list-disc">
            <li>{{ __($h.'url.https', ['ports' => $ports]) }}</li>
            <li>{{ __($h.'url.public') }}</li>
            <li>{{ __($h.'url.credentials') }}</li>
            <li>{{ $httpInTest ? __($h.'url.http_on', ['ports' => $httpPorts]) : __($h.'url.http_off') }}</li>
        </ul>
    </section>
</div>
