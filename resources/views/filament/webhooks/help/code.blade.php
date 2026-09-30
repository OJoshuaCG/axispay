{{--
    A code or JSON example of the "How it works" dialogs. Monospace, and it
    scrolls horizontally inside the block only, so the dialog never scrolls
    sideways at 320px. Focusable, so the scroll works from the keyboard.

    Props: code (string), label (string: its accessible name)
--}}
<pre
    class="font-numeric max-w-full overflow-x-auto whitespace-pre rounded-md border border-line bg-sunken p-3 text-xs text-fg"
    tabindex="0"
    aria-label="{{ $label }}"
    data-help-code
><code>{{ $code }}</code></pre>
