{{--
    Browser UI color per scheme (<meta name="theme-color">): the page
    background (--color-page: neutral-0 light, neutral-900 dark), painted
    before the CSS loads. The ONE place these values live: every layout
    includes this component, and resources/js/theme.js reads them from the
    `data-theme-color` metas.
--}}
<meta name="theme-color" content="#ffffff" media="(prefers-color-scheme: light)" data-theme-color="light">
<meta name="theme-color" content="#0d1017" media="(prefers-color-scheme: dark)" data-theme-color="dark">
