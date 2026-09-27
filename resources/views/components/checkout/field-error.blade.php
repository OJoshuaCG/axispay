{{--
    Error line of a checkout field, filled by the page script from the
    server's validation answer (the checkout answers JSON, so errors are not
    rendered with the page). Linked from the input with aria-describedby.

    Props: id (the element id, `<input id>-error`)
--}}
@props(['id'])

<p id="{{ $id }}" data-field-error {{ $attributes->class('hidden flex items-start gap-1.5 text-sm text-error') }}>
    <x-icon name="exclamation-circle" variant="mini" size="sm" class="mt-0.5" />
    <span class="min-w-0 break-words" data-field-error-text></span>
</p>
