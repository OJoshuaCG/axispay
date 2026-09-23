{{--
    Test/live selector of the tenant panel (plan 6.3). A visible badge shows
    the current mode; the button switches to the other one through
    POST /mode (SwitchLivemode, audited). Color is never the only signal: the
    label names the mode.
--}}
@php
    $livemode = app(\App\Modules\Tenancy\TenantContext::class)->livemodeOrNull() ?? false;
@endphp

<form method="POST" action="{{ route('app.livemode.update') }}" class="inline-flex">
    @csrf
    <input type="hidden" name="livemode" value="{{ $livemode ? '0' : '1' }}">
    <input type="hidden" name="redirect" value="{{ $returnPath }}">
    <button
        type="submit"
        class="pl-mode-switch"
        data-mode="{{ $livemode ? 'live' : 'test' }}"
        title="{{ $livemode ? __('tenancy.mode.switch_to_test') : __('tenancy.mode.switch_to_live') }}"
    >
        {{-- Short label below `sm` so the topbar fits at 320px; full label from `sm` up. --}}
        <span class="sm:hidden" aria-hidden="true">{{ $livemode ? __('tenancy.mode.live_short') : __('tenancy.mode.test_short') }}</span>
        <span class="sr-only sm:not-sr-only">{{ $livemode ? __('tenancy.mode.live') : __('tenancy.mode.test') }}</span>
        <span class="sr-only">{{ $livemode ? __('tenancy.mode.switch_to_test') : __('tenancy.mode.switch_to_live') }}</span>
    </button>
</form>
