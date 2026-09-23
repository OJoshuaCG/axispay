<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Support\Locales;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Persist the viewer's language choice (POST from <x-language-switcher>).
 */
class LocaleController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $request->validate([
            'locale' => ['required', 'string', Rule::in(array_keys(Locales::supported()))],
            'redirect' => ['nullable', 'string', 'max:2048'],
        ]);

        $redirect = $request->input('redirect');

        return redirect()
            ->to($this->safeRedirect(is_string($redirect) ? $redirect : null))
            ->withCookie(Locales::cookie($request->string('locale')->toString()));
    }

    /**
     * Only same-origin relative paths are accepted (no "//host" or "/\host"
     * tricks), and any ?lang= is dropped so it cannot override the new choice.
     */
    private function safeRedirect(?string $target): string
    {
        if ($target === null || ! preg_match('#^/(?![/\\\\])#', $target)) {
            return '/';
        }

        $path = parse_url($target, PHP_URL_PATH) ?: '/';
        parse_str((string) parse_url($target, PHP_URL_QUERY), $query);
        unset($query[Locales::QUERY]);

        return $query === [] ? $path : $path.'?'.http_build_query($query);
    }
}
