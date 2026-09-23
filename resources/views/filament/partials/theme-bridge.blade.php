{{--
    Dark-mode bridge for the Filament panels (docs/frontend/theming.md).

    Filament decides the effective theme (localStorage `theme` = light | dark |
    system, the same key and values as resources/js/theme.js) and toggles the
    `.dark` class on <html>. Our semantic tokens resolve through
    `color-scheme`, which is driven by `data-theme`. This script mirrors the
    class into `data-theme` before first paint (it runs right after Filament's
    own head script, via the HEAD_END render hook) and on every later change
    (theme switcher, OS change, Livewire navigation), so both agree.
--}}
<script>
    (function () {
        var root = document.documentElement;
        var sync = function () {
            var theme = root.classList.contains('dark') ? 'dark' : 'light';
            if (root.getAttribute('data-theme') !== theme) {
                root.setAttribute('data-theme', theme);
            }
        };
        sync();
        new MutationObserver(sync).observe(root, { attributes: true, attributeFilter: ['class'] });
    })();
</script>
