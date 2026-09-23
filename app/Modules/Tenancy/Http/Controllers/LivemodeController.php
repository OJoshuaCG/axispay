<?php

declare(strict_types=1);

namespace App\Modules\Tenancy\Http\Controllers;

use App\Modules\Tenancy\Actions\SwitchLivemode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Target of the tenant panel's test/live selector (POST /mode).
 */
final class LivemodeController
{
    public function __invoke(Request $request, SwitchLivemode $switch): RedirectResponse
    {
        $request->validate([
            'livemode' => ['required', 'boolean'],
            'redirect' => ['nullable', 'string', 'max:2048'],
        ]);

        $switch->handle($request->boolean('livemode'));

        $redirect = $request->input('redirect');

        // Same-origin relative paths only (no "//host" or "/\host").
        $target = is_string($redirect) && preg_match('#^/(?![/\\\\])#', $redirect) === 1 ? $redirect : '/';

        return redirect()->to($target);
    }
}
