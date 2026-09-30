{{--
    Page view of App\Modules\Webhooks\Filament\Pages\PrePaymentValidationSettings.

    It renders the action modals itself. Filament's page layout skips them
    on a page that has a table (components/page/index.blade.php), expecting
    the table view to render them; this page shows its table only once
    validation is configured or has calls, so without this container the
    modals of "Configure", "How it works" and the rest had nowhere to open.
    Rendered before the content, so it is always the one container used
    (the table's own is then skipped). The wrapper keeps the zero-height
    container out of the page's grid gap.
--}}
<x-filament-panels::page>
    <div class="min-w-0">
        <x-filament-actions::modals />

        {{ $this->content }}
    </div>
</x-filament-panels::page>
