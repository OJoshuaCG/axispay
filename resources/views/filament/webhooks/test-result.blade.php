{{--
    The result of "Send test event" (plan 15.1) or "Test validation" (plan
    15.8.1), built by App\Modules\Webhooks\Filament\Support\TestResultPresenter.
    The outcome is a word and an icon, never only a color. The answer excerpt
    is the merchant's own text, shown escaped in a scrollable block.

    Props: result (array{outcome: string, color: string, icon: string,
           next_step: ?string, rows: list<array{label: string, value: string, mono: bool}>,
           errors: list<string>, warnings: list<string>, excerpt: ?string})
--}}
@php
    $result = isset($result) && is_array($result) ? $result : [];
    $rows = is_array($result['rows'] ?? null) ? $result['rows'] : [];
    $errors = is_array($result['errors'] ?? null) ? $result['errors'] : [];
    $warnings = is_array($result['warnings'] ?? null) ? $result['warnings'] : [];
    $excerpt = is_string($result['excerpt'] ?? null) ? $result['excerpt'] : null;
    $nextStep = is_string($result['next_step'] ?? null) ? $result['next_step'] : null;
@endphp

<div class="flex flex-col gap-stack-sm" data-test-result>
    <div>
        <x-filament::badge
            :color="$result['color'] ?? 'gray'"
            :icon="$result['icon'] ?? null"
            data-test-result-outcome
        >{{ $result['outcome'] ?? '' }}</x-filament::badge>
    </div>

    @if ($nextStep !== null)
        <p class="text-sm text-fg" data-test-result-next-step>{{ $nextStep }}</p>
    @endif

    @if ($rows !== [])
        <dl class="grid grid-cols-1 gap-stack-xs sm:grid-cols-2">
            @foreach ($rows as $row)
                <div class="flex flex-col">
                    <dt class="text-sm text-fg-secondary">{{ $row['label'] }}</dt>
                    <dd @class(['text-sm text-fg', 'font-numeric break-all' => $row['mono'] ?? false, 'break-words' => ! ($row['mono'] ?? false)])>{{ $row['value'] }}</dd>
                </div>
            @endforeach
        </dl>
    @endif

    @if ($errors !== [])
        <div class="flex flex-col gap-stack-xs" data-test-result-errors>
            <p class="text-sm font-medium text-fg">{{ __('webhooks.test_result.errors') }}</p>
            <ul class="flex flex-col gap-stack-xs">
                @foreach ($errors as $message)
                    <li class="flex items-start gap-1.5 text-sm text-fg">
                        <x-icon name="x-circle" variant="mini" size="sm" class="mt-0.5 shrink-0 text-error" />
                        <span>{{ $message }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    @if ($warnings !== [])
        <div class="flex flex-col gap-stack-xs" data-test-result-warnings>
            <p class="text-sm font-medium text-fg">{{ __('webhooks.test_result.warnings') }}</p>
            <ul class="flex flex-col gap-stack-xs">
                @foreach ($warnings as $message)
                    <li class="flex items-start gap-1.5 text-sm text-fg">
                        <x-icon name="exclamation-triangle" variant="mini" size="sm" class="mt-0.5 shrink-0 text-warning" />
                        <span>{{ $message }}</span>
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="flex flex-col gap-1">
        <span class="text-sm font-medium text-fg">{{ __('webhooks.test_result.excerpt') }}</span>
        @if ($excerpt !== null && $excerpt !== '')
            <pre
                class="font-numeric max-h-60 overflow-auto whitespace-pre-wrap break-all rounded-md border border-line bg-sunken p-3 text-sm text-fg"
                tabindex="0"
                aria-label="{{ __('webhooks.test_result.excerpt') }}"
                data-test-result-excerpt
            >{{ $excerpt }}</pre>
        @else
            <p class="text-sm text-fg-secondary">{{ __('webhooks.test_result.no_excerpt') }}</p>
        @endif
    </div>
</div>
