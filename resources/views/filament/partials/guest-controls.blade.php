{{--
    Language switcher and theme control on the simple pages that have no
    signed-in header: sign-in and any other guest page. When someone is
    signed in (2FA set-up and challenge, e-mail verification), Filament's
    simple header renders the user menu, and the same controls come through
    panel-controls (USER_MENU_BEFORE) instead, so a simple page always shows
    exactly ONE control bar. PanelDefaults decides which one via $show.

    `pl-simple-controls` (panel theme) gives both bars the same placement:
    a right-aligned row above the card, full width because the simple layout
    is a centred flex column.
--}}
@if ($show ?? true)
    <div class="pl-simple-controls">
        <x-language-switcher />
        @include('filament.partials.theme-control')
    </div>
@endif
