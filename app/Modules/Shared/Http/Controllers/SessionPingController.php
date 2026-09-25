<?php

declare(strict_types=1);

namespace App\Modules\Shared\Http\Controllers;

use Symfony\Component\HttpFoundation\Response;

/**
 * Keep-alive of the panel session (ADR-0040). The `web` group starts and saves
 * the session, which is all a ping needs: it counts as activity for the
 * 2-hour inactivity limit (plan 17.3). It reads nothing and returns no body.
 * The panels' script sends it only while the tab is visible and the person
 * has interacted with the page since the previous ping.
 */
final class SessionPingController
{
    public function __invoke(): Response
    {
        return response()->noContent()->header('Cache-Control', 'no-store, private');
    }
}
