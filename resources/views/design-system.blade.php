{{--
    Design-system preview (local only). The single place to eyeball token and
    component changes in both themes and every locale. Class names are written
    out literally so Tailwind's scanner can find them.

    i18n: prose and sample copy come from lang/{locale}/design-system.php, so
    the page doubles as the long-string (Spanish) wrapping check. Class names,
    token names and variant names are code and stay untranslated.
--}}
@php
    $scales = [
        'Blue' => [
            ['50', 'bg-blue-50'], ['100', 'bg-blue-100'], ['200', 'bg-blue-200'], ['300', 'bg-blue-300'], ['400', 'bg-blue-400'],
            ['500', 'bg-blue-500'], ['600', 'bg-blue-600'], ['700', 'bg-blue-700'], ['800', 'bg-blue-800'], ['900', 'bg-blue-900'],
        ],
        'Green' => [
            ['50', 'bg-green-50'], ['100', 'bg-green-100'], ['200', 'bg-green-200'], ['300', 'bg-green-300'], ['400', 'bg-green-400'],
            ['500', 'bg-green-500'], ['600', 'bg-green-600'], ['700', 'bg-green-700'], ['800', 'bg-green-800'], ['900', 'bg-green-900'],
        ],
        'Neutral' => [
            ['0', 'bg-neutral-0'], ['50', 'bg-neutral-50'], ['100', 'bg-neutral-100'], ['200', 'bg-neutral-200'], ['300', 'bg-neutral-300'], ['400', 'bg-neutral-400'], ['450', 'bg-neutral-450'],
            ['500', 'bg-neutral-500'], ['550', 'bg-neutral-550'], ['600', 'bg-neutral-600'], ['700', 'bg-neutral-700'], ['800', 'bg-neutral-800'], ['900', 'bg-neutral-900'],
        ],
        __('design-system.primitives.status_group') => [
            ['amber-50', 'bg-amber-50'], ['amber-400', 'bg-amber-400'], ['amber-700', 'bg-amber-700'],
            ['red-50', 'bg-red-50'], ['red-400', 'bg-red-400'], ['red-700', 'bg-red-700'], ['red-800', 'bg-red-800'], ['red-900', 'bg-red-900'],
        ],
    ];

    // [utility, CSS variable]
    $semantic = [
        'Surfaces' => [['bg-page', '--color-page'], ['bg-surface', '--color-surface'], ['bg-surface-alt', '--color-surface-alt'], ['bg-surface-pressed', '--color-surface-pressed']],
        'Borders' => [['bg-line', '--color-line'], ['bg-line-strong', '--color-line-strong']],
        'Text' => [['bg-fg', '--color-fg'], ['bg-fg-secondary', '--color-fg-secondary'], ['bg-fg-muted', '--color-fg-muted'], ['bg-focus-ring', '--color-focus-ring']],
        'Primary' => [['bg-primary', '--color-primary'], ['bg-primary-hover', '--color-primary-hover'], ['bg-primary-active', '--color-primary-active'], ['bg-primary-subtle', '--color-primary-subtle']],
        'Accent' => [['bg-accent', '--color-accent'], ['bg-accent-fill', '--color-accent-fill'], ['bg-accent-hover', '--color-accent-hover'], ['bg-accent-active', '--color-accent-active'], ['bg-accent-subtle', '--color-accent-subtle']],
        'Status' => [
            ['bg-success', '--color-success'], ['bg-success-subtle', '--color-success-subtle'],
            ['bg-warning', '--color-warning'], ['bg-warning-subtle', '--color-warning-subtle'],
            ['bg-error', '--color-error'], ['bg-error-subtle', '--color-error-subtle'],
            ['bg-info', '--color-info'], ['bg-info-subtle', '--color-info-subtle'],
        ],
        'Destructive fills' => [['bg-error-fill', '--color-error-fill'], ['bg-error-fill-hover', '--color-error-fill-hover'], ['bg-error-fill-active', '--color-error-fill-active'], ['bg-on-error', '--color-on-error']],
        'Money' => [['bg-amount-positive', '--color-amount-positive'], ['bg-amount-negative', '--color-amount-negative']],
        'Links & feature' => [['bg-link', '--color-link'], ['bg-link-visited', '--color-link-visited'], ['bg-feature', '--color-feature'], ['bg-feature-subtle', '--color-feature-subtle']],
    ];

    $typeScale = [
        ['text-5xl', 'Display 5xl'], ['text-4xl', 'Display 4xl'], ['text-3xl', 'Display 3xl'], ['text-2xl', 'Heading 2xl'],
        ['text-xl', 'Heading xl'], ['text-lg', 'Body large'], ['text-base', 'Body base'], ['text-sm', 'Body small'], ['text-xs', 'Caption xs'],
    ];

    $weights = [['font-normal', '400 Normal'], ['font-medium', '500 Medium'], ['font-semibold', '600 Semibold'], ['font-bold', '700 Bold']];

    $spacing = [
        ['w-stack-xs', 'stack-xs'], ['w-stack-sm', 'stack-sm'], ['w-stack-md', 'stack-md'], ['w-stack-lg', 'stack-lg'], ['w-stack-xl', 'stack-xl'],
        ['w-inset-sm', 'inset-sm'], ['w-inset-md', 'inset-md'], ['w-inset-lg', 'inset-lg'],
        ['w-gutter', 'gutter (fluid)'], ['w-section', 'section (fluid)'], ['w-touch', 'touch (44px)'],
    ];

    $radii = [['rounded-xs', 'xs'], ['rounded-sm', 'sm'], ['rounded-md', 'md'], ['rounded-lg', 'lg (card)'], ['rounded-xl', 'xl'], ['rounded-2xl', '2xl'], ['rounded-full', 'full']];

    $shadows = [['shadow-xs', 'xs'], ['shadow-sm', 'sm'], ['shadow-md', 'md'], ['shadow-lg', 'lg'], ['shadow-xl', 'xl']];

    $icons = ['credit-card', 'banknotes', 'lock-closed', 'shield-check', 'arrow-path', 'check-circle', 'exclamation-triangle', 'x-circle', 'information-circle', 'magnifying-glass', 'user', 'cog-6-tooth'];

    $buttonVariants = ['primary', 'secondary', 'accent', 'ghost', 'danger'];
@endphp

<x-layouts.app :title="__('design-system.title')">
    <x-slot:header>
        <header class="sticky top-0 z-sticky border-b border-line bg-page pt-safe-top">
            <div class="mx-auto flex w-full max-w-wide flex-wrap items-center justify-between gap-x-4 gap-y-2 px-gutter py-stack-sm">
                <p class="min-w-0 font-semibold break-words">{{ \App\Modules\Shared\Support\Brand::displayName() }} &middot; {{ __('design-system.title') }}</p>
                <x-site-controls theme-labels />
            </div>
        </header>
    </x-slot:header>

    <div class="pb-safe-bottom">
    <div class="mx-auto flex w-full max-w-wide flex-col gap-section px-gutter py-section">
        <div class="flex flex-col gap-stack-sm">
            <h1 class="text-4xl font-semibold break-words">{{ __('design-system.title') }}</h1>
            <p class="max-w-narrow text-lg text-fg-secondary">
                {{ __('design-system.lead') }}
            </p>
        </div>

        {{-- Colors: primitives --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="primitives">
            <div>
                <h2 id="primitives" class="text-3xl font-semibold">{{ __('design-system.primitives.title') }}</h2>
                <p class="text-fg-secondary">{{ __('design-system.primitives.lead') }}</p>
            </div>

            @foreach ($scales as $scaleName => $steps)
                <div class="flex flex-col gap-stack-sm">
                    <h3 class="text-sm font-semibold tracking-wider text-fg-secondary uppercase">{{ $scaleName }}</h3>
                    <div class="grid grid-cols-3 gap-2 sm:grid-cols-6 lg:grid-cols-11">
                        @foreach ($steps as [$label, $class])
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="{{ $class }} h-14 rounded-md border border-line"></div>
                                <span class="font-mono text-xs break-all text-fg-secondary">{{ $label }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>

        {{-- Colors: semantic --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="semantic">
            <div>
                <h2 id="semantic" class="text-3xl font-semibold">{{ __('design-system.semantic.title') }}</h2>
                <p class="text-fg-secondary">{{ __('design-system.semantic.lead') }}</p>
            </div>

            @foreach ($semantic as $groupName => $tokens)
                <div class="flex flex-col gap-stack-sm">
                    <h3 class="text-sm font-semibold tracking-wider text-fg-secondary uppercase">{{ $groupName }}</h3>
                    <div class="grid grid-cols-2 gap-3 sm:grid-cols-4">
                        @foreach ($tokens as [$class, $token])
                            <div class="flex min-w-0 flex-col gap-1">
                                <div class="{{ $class }} h-14 rounded-md border border-line"></div>
                                <span class="font-mono text-xs break-all text-fg">{{ $class }}</span>
                                <span class="font-mono text-xs break-all text-fg-secondary">{{ $token }}</span>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endforeach
        </section>

        {{-- Typography --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="typography">
            <h2 id="typography" class="text-3xl font-semibold">{{ __('design-system.typography.title') }}</h2>

            {{-- Font families (ADR-0042): Mukta for text, Geist Mono for currency and numeric data. --}}
            <div class="grid gap-stack-md md:grid-cols-2">
                <div class="flex min-w-0 flex-col gap-stack-sm rounded-lg border border-line bg-surface p-inset-md">
                    <span class="font-mono text-xs break-all text-fg-secondary">font-sans &middot; Mukta 400/500/600/700</span>
                    <p class="text-2xl font-semibold break-words">{{ __('design-system.typography.sans_heading') }}</p>
                    <p class="break-words">{{ __('design-system.typography.sans_sample') }}</p>
                    <p class="break-words text-fg-secondary">Aa Bb Cc Ññ Áá Éé Üü ¿? ¡! 0123456789</p>
                </div>
                <div class="flex min-w-0 flex-col gap-stack-sm rounded-lg border border-line bg-surface p-inset-md">
                    <span class="font-mono text-xs break-all text-fg-secondary">font-numeric &middot; Geist Mono 400/600</span>
                    <p class="text-sm break-words text-fg-secondary">{{ __('design-system.typography.numeric_lead') }}</p>
                    <dl class="grid grid-cols-[minmax(0,1fr)_auto] items-baseline gap-x-6 gap-y-1">
                        <dt class="break-words text-fg-secondary">{{ __('design-system.typography.subtotal') }}</dt>
                        <dd class="text-end"><x-amount :value="1250.5" currency="USD" :signed="false" /></dd>
                        <dt class="break-words text-fg-secondary">{{ __('design-system.typography.fee') }}</dt>
                        <dd class="text-end"><x-amount :value="-36.26" currency="USD" /></dd>
                        <dt class="font-semibold break-words">{{ __('design-system.typography.total') }}</dt>
                        <dd class="text-end"><x-amount :value="1214.24" currency="USD" :signed="false" class="text-xl font-semibold" /></dd>
                    </dl>
                </div>
            </div>

            <div class="flex flex-col gap-stack-md">
                @foreach ($typeScale as [$class, $label])
                    <div class="flex flex-col gap-1 border-b border-line pb-stack-sm sm:flex-row sm:items-baseline sm:gap-6">
                        <span class="w-28 shrink-0 font-mono text-xs text-fg-secondary">{{ $class }}</span>
                        <span class="{{ $class }} min-w-0 break-words">{{ $label }} &mdash; {{ __('design-system.typography.sample') }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-wrap gap-6">
                @foreach ($weights as [$class, $label])
                    <span class="{{ $class }} text-xl">{{ $label }}</span>
                @endforeach
            </div>

            <div class="flex flex-col gap-2">
                <p class="text-fg">{{ __('design-system.typography.primary_text') }}</p>
                <p class="text-fg-secondary">{{ __('design-system.typography.secondary_text') }}</p>
                <p class="text-fg-muted">{{ __('design-system.typography.muted_text') }}</p>
                {{-- Placeholders keep the sentence whole for translators; only escaped fragments are injected. --}}
                <p>{!! __('design-system.typography.links', [
                    'prose' => '<a href="#typography">'.e(__('design-system.typography.prose_link')).'</a>',
                    'visited' => '<a href="'.e(url('/')).'">'.e(__('design-system.typography.visited_link')).'</a>',
                ]) !!}</p>
                <p class="font-mono text-sm">font-mono: 4242 4242 4242 4242</p>
            </div>
        </section>

        {{-- Spacing, radius, shadows --}}
        <section class="grid gap-section lg:grid-cols-3" aria-label="{{ __('design-system.foundations.label') }}">
            <div class="flex flex-col gap-stack-md">
                <h2 class="text-2xl font-semibold">{{ __('design-system.foundations.spacing') }}</h2>
                @foreach ($spacing as [$class, $label])
                    <div class="flex items-center gap-3">
                        <div class="{{ $class }} h-4 shrink-0 rounded-xs bg-primary"></div>
                        <span class="font-mono text-xs text-fg-secondary">{{ $label }}</span>
                    </div>
                @endforeach
            </div>

            <div class="flex flex-col gap-stack-md">
                <h2 class="text-2xl font-semibold">{{ __('design-system.foundations.radius') }}</h2>
                <div class="grid grid-cols-3 gap-4">
                    @foreach ($radii as [$class, $label])
                        <div class="flex flex-col items-center gap-1">
                            <div class="{{ $class }} size-16 border border-line-strong bg-surface-alt"></div>
                            <span class="font-mono text-xs text-fg-secondary">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>

            <div class="flex flex-col gap-stack-md">
                <h2 class="text-2xl font-semibold">{{ __('design-system.foundations.shadows') }}</h2>
                <div class="grid grid-cols-3 gap-4">
                    @foreach ($shadows as [$class, $label])
                        <div class="flex flex-col items-center gap-2">
                            <div class="{{ $class }} size-16 rounded-lg bg-surface"></div>
                            <span class="font-mono text-xs text-fg-secondary">{{ $label }}</span>
                        </div>
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Buttons --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="buttons">
            <h2 id="buttons" class="text-3xl font-semibold">{{ __('design-system.buttons.title') }}</h2>

            @foreach ($buttonVariants as $variant)
                <div class="flex flex-wrap items-center gap-3">
                    <span class="w-full font-mono text-xs text-fg-secondary sm:w-24">{{ $variant }}</span>
                    <x-button :variant="$variant" size="sm">{{ __('design-system.buttons.small') }}</x-button>
                    <x-button :variant="$variant">{{ __('design-system.buttons.medium') }}</x-button>
                    <x-button :variant="$variant" size="lg">{{ __('design-system.buttons.large') }}</x-button>
                    <x-button :variant="$variant" icon="credit-card">{{ __('design-system.buttons.with_icon') }}</x-button>
                    <x-button :variant="$variant" disabled>{{ __('design-system.buttons.disabled') }}</x-button>
                    <x-button :variant="$variant" loading>{{ __('design-system.buttons.processing') }}</x-button>
                </div>
            @endforeach

            <div class="flex flex-wrap items-center gap-3">
                <span class="w-full font-mono text-xs text-fg-secondary sm:w-24">{{ __('design-system.buttons.as_link') }}</span>
                <x-button href="#buttons" icon-trailing="arrow-right">{{ __('design-system.buttons.link_button') }}</x-button>
                <x-button href="#buttons" variant="secondary" disabled>{{ __('design-system.buttons.disabled_link') }}</x-button>
            </div>

            <div class="flex flex-wrap items-center gap-3">
                <span class="w-full font-mono text-xs text-fg-secondary sm:w-24">{{ __('design-system.buttons.icon_only') }}</span>
                <x-button icon="arrow-path" :label="__('design-system.buttons.retry_payment')" size="sm" />
                <x-button icon="arrow-path" :label="__('design-system.buttons.retry_payment')" />
                <x-button icon="arrow-path" :label="__('design-system.buttons.retry_payment')" size="lg" />
                <x-button icon="trash" :label="__('design-system.buttons.delete_card')" variant="ghost" />
                <x-button icon="cog-6-tooth" :label="__('design-system.buttons.settings')" variant="secondary" />
            </div>

            <div class="flex flex-col gap-stack-sm">
                <h3 class="text-lg font-semibold">{{ __('design-system.buttons.guard_title') }}</h3>
                <p class="max-w-narrow text-sm text-fg-secondary">
                    {{ __('design-system.buttons.guard_lead') }}
                </p>
                <form method="get" action="{{ url('/design-system') }}" data-prevent-double-submit class="flex flex-wrap items-end gap-3">
                    <input type="hidden" name="submitted" value="1">
                    <x-button type="submit" icon="lock-closed">{{ __('design-system.buttons.pay_now') }}</x-button>
                    <x-button type="button" loading :loading-label="__('design-system.buttons.processing_payment')">{{ __('design-system.buttons.pay_now') }}</x-button>
                </form>
            </div>
        </section>

        {{-- Form controls --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="inputs">
            <h2 id="inputs" class="text-3xl font-semibold">{{ __('design-system.inputs.title') }}</h2>
            <div class="grid max-w-content gap-stack-lg md:grid-cols-2">
                <x-input name="ds-email" type="email" :label="__('design-system.inputs.email')" :placeholder="__('design-system.inputs.email_placeholder')" autocomplete="email" required />
                <x-input name="ds-card" :label="__('design-system.inputs.card_number')" :hint="__('design-system.inputs.card_hint')" inputmode="numeric" autocomplete="cc-number" />
                <x-input name="ds-amount" :label="__('design-system.inputs.amount')" value="12.00" inputmode="decimal" class="amount" :error="__('design-system.inputs.amount_error')" />
                <x-input name="ds-disabled" :label="__('design-system.inputs.disabled')" :value="__('design-system.inputs.not_editable')" disabled />
                <x-input name="ds-price" :label="__('design-system.inputs.price')" inputmode="decimal" autocomplete="off" align="end" placeholder="0.00" class="amount" :hint="__('design-system.inputs.price_hint')">
                    <x-slot:prefix>$</x-slot:prefix>
                    <x-slot:suffix>USD</x-slot:suffix>
                </x-input>
                <x-input name="ds-fee" :label="__('design-system.inputs.fee')" value="2.90" readonly align="end" class="amount" wrapper-class="md:col-span-2 md:max-w-sm">
                    <x-slot:suffix>%</x-slot:suffix>
                </x-input>
            </div>
        </section>

        {{-- Badges --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="badges">
            <h2 id="badges" class="text-3xl font-semibold">{{ __('design-system.badges.title') }}</h2>
            <div class="flex flex-wrap items-center gap-3">
                <x-badge>{{ __('design-system.badges.draft') }}</x-badge>
                <x-badge variant="success" icon="check">{{ __('design-system.badges.paid') }}</x-badge>
                <x-badge variant="warning" icon="clock">{{ __('design-system.badges.pending') }}</x-badge>
                <x-badge variant="error" icon="x-mark">{{ __('design-system.badges.declined') }}</x-badge>
                <x-badge variant="info" icon="information-circle">{{ __('design-system.badges.refunded') }}</x-badge>
            </div>
        </section>

        {{-- Money --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="money">
            <h2 id="money" class="text-3xl font-semibold">{{ __('design-system.money.title') }}</h2>

            <div class="flex flex-col gap-stack-sm">
                <h3 class="text-lg font-semibold">{{ __('design-system.money.amounts') }}</h3>
                <p class="text-sm text-fg-secondary">{{ __('design-system.money.amounts_lead') }}</p>
                <dl class="grid max-w-narrow grid-cols-[minmax(0,1fr)_auto] gap-x-6 gap-y-2 text-base">
                    <dt class="break-words text-fg-secondary">{{ __('design-system.money.payment') }}</dt>
                    <dd class="text-end"><x-amount :value="1250.5" currency="USD" /></dd>
                    <dt class="break-words text-fg-secondary">{{ __('design-system.money.refund') }}</dt>
                    <dd class="text-end"><x-amount :value="-42" currency="USD" /></dd>
                    <dt class="break-words text-fg-secondary">{{ __('design-system.money.fee_forced') }}</dt>
                    <dd class="text-end"><x-amount :value="-3.9" currency="EUR" locale="de_DE" /></dd>
                    <dt class="break-words text-fg-secondary">{{ __('design-system.money.minor_units') }}</dt>
                    <dd class="text-end"><x-amount :value="129900" currency="CLP" locale="es_CL" minor /></dd>
                    <dt class="break-words text-fg-secondary">{{ __('design-system.money.balance') }}</dt>
                    <dd class="text-end"><x-amount :value="10987.65" currency="USD" :signed="false" /></dd>
                </dl>
            </div>

            <div class="flex flex-col gap-stack-sm">
                <h3 class="text-lg font-semibold">{{ __('design-system.money.dates') }}</h3>
                <p class="text-base">
                    <span class="text-fg-secondary">{{ __('design-system.money.created') }}:</span>
                    {{-- Carbon follows the app locale (updated on LocaleUpdated). --}}
                    <time datetime="{{ now()->toIso8601String() }}">{{ now()->isoFormat('LLLL') }}</time>
                </p>
            </div>

            <div class="flex flex-col gap-stack-sm">
                <h3 class="text-lg font-semibold">{{ __('design-system.money.status') }}</h3>
                <div class="flex flex-wrap items-center gap-3">
                    @foreach (\App\Enums\PaymentStatus::cases() as $status)
                        <x-payment-status :status="$status" />
                    @endforeach
                </div>
            </div>
        </section>

        {{-- Alerts --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="alerts">
            <h2 id="alerts" class="text-3xl font-semibold">{{ __('design-system.alerts.title') }}</h2>
            {{-- Preview only: role="note" keeps screen readers from announcing all four on load. --}}
            <div class="grid max-w-content gap-stack-md">
                <x-alert variant="success" :title="__('design-system.alerts.success_title')" role="note">{{ __('design-system.alerts.success_body') }}</x-alert>
                <x-alert variant="warning" :title="__('design-system.alerts.warning_title')" role="note">{{ __('design-system.alerts.warning_body') }}</x-alert>
                <x-alert variant="error" :title="__('design-system.alerts.error_title')" role="note">{{ __('design-system.alerts.error_body') }}</x-alert>
                <x-alert variant="info" role="note">{{ __('design-system.alerts.info_body') }}</x-alert>
            </div>
        </section>

        {{-- Cards --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="cards">
            <h2 id="cards" class="text-3xl font-semibold">{{ __('design-system.cards.title') }}</h2>
            <div class="grid gap-stack-lg md:grid-cols-3">
                <x-card>
                    <p class="font-semibold">{{ __('design-system.cards.default') }}</p>
                    <p class="text-sm text-fg-secondary">{{ __('design-system.cards.default_body') }}</p>
                </x-card>

                <x-card elevated padding="lg">
                    <p class="font-semibold">{{ __('design-system.cards.elevated') }}</p>
                    <p class="text-sm text-fg-secondary">{{ __('design-system.cards.elevated_body') }}</p>
                </x-card>

                <x-card as="article">
                    <x-slot:header>{{ __('design-system.cards.with_slots') }}</x-slot:header>
                    <p class="text-sm text-fg-secondary">{{ __('design-system.cards.with_slots_body') }}</p>
                    <x-slot:footer class="flex flex-wrap justify-end gap-2">
                        <x-button variant="ghost" size="sm">{{ __('design-system.cards.cancel') }}</x-button>
                        <x-button size="sm">{{ __('design-system.cards.confirm') }}</x-button>
                    </x-slot:footer>
                </x-card>
            </div>
        </section>

        {{-- Icons --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="icons">
            <h2 id="icons" class="text-3xl font-semibold">{{ __('design-system.icons.title') }}</h2>
            <div class="flex flex-wrap items-end gap-6">
                @foreach (['xs', 'sm', 'md', 'lg', 'xl'] as $size)
                    <div class="flex flex-col items-center gap-1">
                        <x-icon name="credit-card" :size="$size" />
                        <span class="font-mono text-xs text-fg-secondary">{{ $size }}</span>
                    </div>
                @endforeach
            </div>
            <div class="grid grid-cols-3 gap-4 sm:grid-cols-6 lg:grid-cols-12">
                @foreach ($icons as $icon)
                    <div class="flex min-w-0 flex-col items-center gap-1 text-fg-secondary">
                        <x-icon :name="$icon" size="lg" class="text-fg" />
                        <span class="max-w-full text-center font-mono text-xs break-all">{{ $icon }}</span>
                    </div>
                @endforeach
            </div>
            <p class="flex items-center gap-2 text-sm">
                <x-icon name="lock-closed" variant="mini" size="sm" :label="__('design-system.icons.secure')" class="text-success" />
                {{ __('design-system.icons.meaningful') }}
            </p>
        </section>

        {{-- Feature / brand --}}
        <section class="flex flex-col gap-stack-lg" aria-labelledby="feature">
            <h2 id="feature" class="text-3xl font-semibold">{{ __('design-system.feature.title') }}</h2>
            <div class="flex items-center gap-4 rounded-lg bg-feature-subtle p-inset-lg">
                <x-icon name="sparkles" size="xl" class="text-feature" />
                <p class="min-w-0">{{ __('design-system.feature.body') }}</p>
            </div>
        </section>
    </div>
    </div>
</x-layouts.app>
