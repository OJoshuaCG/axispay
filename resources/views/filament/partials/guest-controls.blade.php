{{--
    Language switcher and theme control on the sign-in and other simple
    (guest) pages, right-aligned above the card. w-full: the simple layout is
    a centred flex column, so without it the row shrinks and centres.
--}}
<div class="flex w-full flex-wrap items-center justify-end gap-2 px-gutter pt-4">
    <x-language-switcher />
    @include('filament.partials.theme-control')
</div>
