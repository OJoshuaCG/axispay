{{--
    Stripe connection page, api_key method: which permissions the restricted
    key needs and how to create it. Shown in the "View required permissions"
    modal and, collapsed, inside the connect / update keys forms.

    The permission lists come from StripeKeyPermissions, the same catalog
    ApiKeyFlow validates against (ADR-0047): never hard-code one here.

    Below md the required permissions reflow to a definition list (320px);
    from md up they are a table.

    Props: mode (string, translated panel mode, e.g. "test mode").
--}}
@php
    use App\Modules\Gateways\Stripe\Connection\StripeKeyPermissions;

    $required = StripeKeyPermissions::required();
    $dangerous = StripeKeyPermissions::dangerous();
@endphp
<div class="flex flex-col gap-stack-md text-sm text-fg" data-stripe-key-permissions>
    <p>{{ __('gateways.permissions_help.intro') }}</p>

    <section class="flex flex-col gap-stack-xs" aria-labelledby="stripe-key-steps-heading">
        <h3 id="stripe-key-steps-heading" class="font-semibold">{{ __('gateways.permissions_help.steps_heading') }}</h3>
        <ol class="list-decimal space-y-1 ps-5">
            @foreach (range(1, 7) as $step)
                <li class="break-words">{{ __('gateways.permissions_help.steps.'.$step, ['mode' => $mode]) }}</li>
            @endforeach
        </ol>
    </section>

    <section class="flex flex-col gap-stack-xs" aria-labelledby="stripe-key-required-heading">
        <h3 id="stripe-key-required-heading" class="font-semibold">{{ __('gateways.permissions_help.table_heading') }}</h3>

        {{-- Phones: one entry per permission. --}}
        <dl class="flex flex-col gap-stack-sm md:hidden">
            @foreach ($required as $permission)
                <div class="rounded-md border border-line p-inset-sm" data-permission="{{ $permission }}">
                    <dt class="flex flex-wrap items-baseline gap-x-2">
                        <span class="font-semibold">{{ __('gateways.permissions_help.resources.'.$permission) }}</span>
                        <span class="text-fg-secondary">{{ __('gateways.permissions_help.level.'.StripeKeyPermissions::level($permission)) }}</span>
                    </dt>
                    <dd class="break-words">{{ __('gateways.permissions_help.reasons.'.$permission) }}</dd>
                    <dd class="break-all font-mono text-fg-secondary">{{ $permission }}</dd>
                </div>
            @endforeach
        </dl>

        {{-- Wider screens: a table, scrolling inside its own box if needed. --}}
        <div class="hidden overflow-x-auto md:block">
            <table class="w-full text-start">
                <thead>
                    <tr class="border-b border-line text-fg-secondary">
                        <th scope="col" class="py-2 pe-3 text-start font-medium">{{ __('gateways.permissions_help.columns.resource') }}</th>
                        <th scope="col" class="py-2 pe-3 text-start font-medium">{{ __('gateways.permissions_help.columns.level') }}</th>
                        <th scope="col" class="py-2 text-start font-medium">{{ __('gateways.permissions_help.columns.why') }}</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($required as $permission)
                        <tr class="border-b border-line align-top" data-permission="{{ $permission }}">
                            <th scope="row" class="py-2 pe-3 text-start font-normal">
                                <span class="block font-semibold">{{ __('gateways.permissions_help.resources.'.$permission) }}</span>
                                <span class="block break-all font-mono text-fg-secondary">
                                    <span class="sr-only">{{ __('gateways.permissions_help.columns.identifier') }}:</span>
                                    {{ $permission }}
                                </span>
                            </th>
                            <td class="py-2 pe-3">{{ __('gateways.permissions_help.level.'.StripeKeyPermissions::level($permission)) }}</td>
                            <td class="py-2 break-words">{{ __('gateways.permissions_help.reasons.'.$permission) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </section>

    <div role="note" class="flex flex-col gap-stack-xs rounded-md border border-line bg-warning-subtle p-inset-sm" data-dangerous-permissions>
        <p class="font-semibold">{{ __('gateways.permissions_help.dangerous.heading') }}</p>
        <ul class="list-disc space-y-1 ps-5">
            @foreach ($dangerous as $permission)
                <li class="break-words" data-permission="{{ $permission }}">
                    {{ __('gateways.permissions_help.resources.'.$permission) }}
                    ({{ __('gateways.permissions_help.level.'.StripeKeyPermissions::level($permission)) }})
                    · <span class="break-all font-mono">{{ $permission }}</span>
                </li>
            @endforeach
        </ul>
        <p>{{ __('gateways.permissions_help.dangerous.body') }}</p>
    </div>

    <p class="text-fg-secondary">{{ __('gateways.permissions_help.labels_note') }}</p>

    <p>
        <a href="https://docs.stripe.com/keys#create-restricted-api-secret-key" target="_blank" rel="noopener noreferrer" class="inline-flex min-h-touch items-center text-link underline">
            {{ __('gateways.permissions_help.docs_link') }}
            <span class="sr-only">{{ __('gateways.permissions_help.new_tab') }}</span>
        </a>
    </p>
</div>
