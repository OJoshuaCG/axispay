{{--
    A list of technical names (headers, JSON fields) and what each means.
    The name is shown in monospace and wraps on narrow screens.

    Props: items (array<string, string>: name => meaning)
--}}
<dl class="flex flex-col gap-stack-sm">
    @foreach ($items as $name => $meaning)
        <div class="flex min-w-0 flex-col gap-stack-xs">
            <dt><code class="font-numeric break-all rounded-sm bg-sunken px-1 text-sm text-fg">{{ $name }}</code></dt>
            <dd class="text-sm text-fg-secondary">{{ $meaning }}</dd>
        </div>
    @endforeach
</dl>
