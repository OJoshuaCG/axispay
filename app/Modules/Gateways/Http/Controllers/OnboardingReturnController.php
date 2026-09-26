<?php

declare(strict_types=1);

namespace App\Modules\Gateways\Http\Controllers;

use App\Modules\Gateways\Actions\SyncGatewayConnection;
use App\Modules\Gateways\Exceptions\GatewayException;
use App\Modules\Gateways\Filament\Pages\StripeConnection;
use App\Modules\Gateways\Models\GatewayConnection;
use App\Modules\Identity\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

/**
 * Stripe's `return_url` (plan 12.3.1 step 3). Coming back does NOT mean the
 * onboarding is complete: the account is re-read and the page shows what
 * Stripe reports. The connection comes from the tenant-scoped URL binding,
 * so another tenant's connection is a 404 (plan 6.6).
 */
final class OnboardingReturnController
{
    public function __invoke(Request $request, string $connection, SyncGatewayConnection $sync): RedirectResponse
    {
        $user = $request->user('web');
        abort_unless($user instanceof User, 403);

        $model = GatewayConnection::query()->findOrFail($connection);
        Gate::forUser($user)->authorize('manage', $model);

        try {
            $sync->handle($model);
            $flash = 'gateways.onboarding.returned';
        } catch (GatewayException $e) {
            Log::warning('The gateway account could not be synced after onboarding.', ['connection_id' => $model->id, 'exception' => $e::class]);
            $flash = 'gateways.onboarding.sync_failed';
        }

        return redirect()->to(StripeConnection::getUrl(panel: 'app'))->with('gateways.flash', $flash);
    }
}
