{{--
    Brand page of the tenant panel (ADR-0056 part B): how the merchant's logo
    looks at the top of the payment page, on a light and on a dark page,
    whatever theme the viewer uses now. Fixed neutral primitives on purpose:
    the preview must look the same in both themes. In dark, without a dark
    variant, the light logo sits on the light plate the payment page uses
    (`logo-plate` is neutral-50 in dark).

    The image is inlined (data: URI): the logo is only served on the pay host.

    Props: dark (bool), src (?string: data URI), width, height (?int),
           plate (bool: the light logo shown on the plate in dark),
           fallback (bool: say why the plate is there)
--}}
<figure class="flex flex-col gap-stack-sm">
    <figcaption class="text-sm font-medium text-fg">{{ $dark ? __('branding.tenant.preview_dark') : __('branding.tenant.preview_light') }}</figcaption>
    <div @class([
        'flex min-h-32 items-center justify-center rounded-lg border border-line p-inset-md',
        'bg-neutral-0 text-neutral-900' => ! $dark,
        'bg-neutral-900 text-neutral-50' => $dark,
    ])>
        @if ($src !== null)
            <img
                src="{{ $src }}"
                alt="{{ __('branding.tenant.preview_alt', ['theme' => $dark ? __('branding.tenant.preview_dark') : __('branding.tenant.preview_light')]) }}"
                @if ($width !== null) width="{{ $width }}" height="{{ $height }}" @endif
                @class([
                    'h-auto w-auto max-h-20 max-w-full object-contain',
                    'rounded-lg p-inset-sm bg-neutral-50' => $plate,
                ])
            />
        @else
            <span class="text-center text-sm">{{ __('branding.tenant.preview_empty') }}</span>
        @endif
    </div>
    @if ($fallback)
        <p class="text-sm text-fg-secondary">{{ __('branding.tenant.dark_fallback') }}</p>
    @endif
</figure>
