<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Http\Controllers;

use App\Modules\Gateways\Actions\CreateOnboardingLink;
use App\Modules\Gateways\Exceptions\GatewayConnectionException;
use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Identity\Exceptions\ReauthenticationRequiredException;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * Stripe's `refresh_url` (plan 12.3.1 step 4): the link expired or was
 * already used, so a new one is generated. A new link is a sensitive action
 * (plan 17.3); outside the re-authentication window the user is sent back
 * to the Stripe page to confirm and continue.
 */
final class OnboardingRefreshController
{
    public function __invoke(Request $request, string $connection, CreateOnboardingLink $links): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 403);

        $model = GatewayConnection::query()->findOrFail($connection);

        try {
            return redirect()->away($links->handle($user, $model));
        } catch (ReauthenticationRequiredException) {
            $flash = 'gateways.onboarding.confirm_to_continue';
        } catch (GatewayConnectionException $e) {
            $flash = 'gateways.errors.'.$e->error->value;
        }

        return redirect()->to(StripeConnection::getUrl(panel: 'app'))->with('gateways.flash', $flash);
    }
}
