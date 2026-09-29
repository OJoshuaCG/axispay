{{--
    Branding page (ADR-0053): preview of the uploaded favicon, each generated
    size at its real pixel size, on a light and a dark tile (browser tabs and
    home screens use both). Fixed neutral primitives on purpose, like the logo
    preview.

    Props: urls (array<int, string>: size => URL; empty = default favicon).
--}}
@if ($urls === [])
    <p class="text-sm text-fg-secondary">{{ __('branding.favicon.default_in_use') }}</p>
@else
    <ul class="flex flex-wrap gap-stack-md">
        @foreach ($urls as $size => $url)
            <li class="flex min-w-0 flex-col gap-stack-xs">
                <span class="text-sm font-medium text-fg">{{ __('branding.favicon.size.'.$size, ['px' => $size]) }}</span>
                <span class="flex flex-wrap gap-stack-sm">
                    <span class="flex items-center justify-center rounded-lg border border-line bg-neutral-0 p-inset-sm">
                        <img src="{{ $url }}" width="{{ $size }}" height="{{ $size }}" alt="{{ __('branding.favicon.preview_alt', ['size' => $size]) }}" class="block max-w-full" />
                    </span>
                    <span class="flex items-center justify-center rounded-lg border border-line bg-neutral-900 p-inset-sm">
                        <img src="{{ $url }}" width="{{ $size }}" height="{{ $size }}" alt="" class="block max-w-full" />
                    </span>
                </span>
            </li>
        @endforeach
    </ul>
@endif
