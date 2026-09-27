{{--
    Checkout header (ADR-0038): the merchant's name as text (never
    truncated), and the language switcher. Phase 8 adds the logo slot
    (h-10 max-w-40 object-contain, alt = merchant name; the name stays).

    Props: merchant (display name)
--}}
@props(['merchant'])

<header {{ $attributes->class('flex flex-wrap items-center justify-between gap-x-4 gap-y-stack-sm pb-stack-lg') }}>
    <p class="min-w-0 text-lg font-semibold break-words text-fg">{{ $merchant }}</p>
    <x-language-switcher />
</header>
