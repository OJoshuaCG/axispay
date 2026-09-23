<?php

declare(strict_types=1);

namespace App\Modules\Identity\Http\Controllers;

use App\Modules\Identity\Actions\AcceptInvitation;
use App\Modules\Identity\Data\AcceptInvitationData;
use App\Modules\Identity\Exceptions\EmailNotAvailableException;
use App\Modules\Identity\Exceptions\InvitationNotPendingException;
use App\Modules\Identity\Services\InvitationLookup;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rules\Password;
use Symfony\Component\HttpFoundation\Response;

/**
 * Invitation acceptance on the app host (plan 17.3). Both routes require a
 * valid signature; the token itself is single-use and expires with the
 * invitation. No business logic here: AcceptInvitation does the work.
 */
final class InvitationController
{
    public function show(string $token, InvitationLookup $lookup): View|Response
    {
        $invitation = $lookup->findByToken($token);

        if ($invitation === null || ! $invitation->isPending()) {
            return response()->view('identity.invitation-invalid', status: 410);
        }

        return view('identity.accept-invitation', ['email' => $invitation->email]);
    }

    public function store(Request $request, string $token, AcceptInvitation $accept): RedirectResponse|Response
    {
        $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'password' => ['required', 'string', 'confirmed', Password::defaults()],
        ]);

        try {
            $user = $accept->handle(new AcceptInvitationData(
                token: $token,
                name: $request->string('name')->toString(),
                password: $request->string('password')->toString(),
            ));
        } catch (InvitationNotPendingException|EmailNotAvailableException) {
            return response()->view('identity.invitation-invalid', status: 410);
        }

        $request->session()->regenerate();
        Auth::guard('web')->login($user);

        return redirect()->to(route('filament.app.pages.dashboard'));
    }
}
