<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use App\Models\User;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\ModuleCommunityClient;
use Cms\Core\Services\Modules\Install\ModuleListingClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;

/**
 * Installable module catalog and install preflight (Webmaster only, enforced by the route middleware).
 *
 * Hub failures return a 502 with errorCode MODULE_CATALOG_UNAVAILABLE (MODULE_HUB_NOT_RECOGNISED when the hub refuses
 * this site's credentials with 401); they never look like an empty catalog.
 *
 * Ratings, questions and private feedback go to the hub through {@see ModuleCommunityClient}, which asserts
 * the signed-in Webmaster only by a pseudonymous reference and a display name. Only a module installed on this site
 * may be rated (checked here before the hub is asked); the hub then requires it in the site's latest status report.
 * Hub refusals map to fixed codes: MODULE_COMMUNITY_INSTALL_REQUIRED (409), MODULE_COMMUNITY_NOT_FOUND (404),
 * MODULE_COMMUNITY_RATE_LIMITED (429), MODULE_COMMUNITY_INVALID (422); the hub's own text is never passed on.
 */
final class ModuleCatalogController extends AdminController
{
    /** The module is not installed on this website, so it cannot be rated. */
    private const NOT_INSTALLED = 'MODULE_COMMUNITY_NOT_INSTALLED';

    /**
     * List the modules published on the platform hub with this site's installed state.
     *
     * @return mixed
     */
    public function index(ModuleCatalog $catalog)
    {
        try {
            return ires()->data($catalog->entries());
        } catch (PlatformHubException $e) {
            return $this->hubError($e);
        }
    }

    /**
     * Explain whether and how one module version could be installed.
     *
     * @return mixed
     */
    public function preflight(InstallPreflight $preflight, string $name, string $version)
    {
        try {
            return ires()->data($preflight->check($name, $version)->toArray());
        } catch (PlatformHubException $e) {
            return $this->hubError($e);
        }
    }

    /**
     * The store listing of one module: validated listing, versions with technical sheets and the release notes above
     * the installed version.
     *
     * A hub that predates listings answers `available = false` with 200, so the store tab keeps working; every other
     * hub failure is the 502 MODULE_CATALOG_UNAVAILABLE answer, never a made-up listing.
     *
     * @return mixed
     */
    public function listing(ModuleCatalog $catalog, ModuleListingClient $listings, string $name)
    {
        try {
            $data = $listings->listing($name);
            $installed = $data['available'] ? $catalog->installedState($name)['installed_version'] : null;
            $data['installed_version'] = $installed;
            $data['release_notes'] = $data['available'] ? ModuleListingClient::releaseNotesAbove($data['versions'], $installed) : [];

            return ires()->data($data);
        } catch (PlatformHubException $e) {
            return $this->hubError($e);
        }
    }

    /**
     * Serve one cached store image by its local hash (404 for a hash no fetched listing produced).
     *
     * @return mixed
     */
    public function media(ModuleListingClient $listings, string $hash)
    {
        try {
            $file = $listings->media($hash);
        } catch (PlatformHubException $e) {
            return $this->hubError($e);
        }
        if ($file === null) {
            return ires()->error()->code(404)->errorCode('MODULE_MEDIA_NOT_FOUND')->message('Module image not found.');
        }

        return response()->file($file['path'], [
            'Content-Type' => $file['type'],
            'Cache-Control' => 'private, max-age=86400',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Disposition' => 'inline',
        ]);
    }

    /**
     * This site's rating of the module, whether it may rate now and the default display name of the signed-in user.
     *
     * `installed` is the site's own view (the module folder is present); `reason` is MODULE_COMMUNITY_NOT_INSTALLED
     * when it is not, otherwise the hub's refusal (MODULE_COMMUNITY_INSTALL_REQUIRED: the latest status report does
     * not show the module) or null.
     *
     * @return mixed
     */
    public function myRating(Request $request, ModuleCatalog $catalog, ModuleCommunityClient $community, string $name)
    {
        try {
            $mine = $community->mine($name);
        } catch (PlatformHubException $e) {
            return $this->hubError($e);
        }
        $installed = $catalog->installedState($name)['installed'] !== 'none';
        $user = $request->user();

        return ires()->data([
            'available' => $mine['available'],
            'installed' => $installed,
            'rating' => $mine['rating'],
            'can_rate' => $installed && $mine['can_rate'],
            'reason' => $installed ? $mine['reason'] : self::NOT_INSTALLED,
            'default_name' => ModuleCommunityClient::defaultName($user instanceof User ? $user->name : null),
        ]);
    }

    /**
     * One page of the module's visible ratings with the average and count.
     *
     * @return mixed
     */
    public function ratings(Request $request, ModuleCommunityClient $community, string $name)
    {
        try {
            return ires()->data($community->ratings($name, self::pageOf($request)));
        } catch (PlatformHubException $e) {
            return $this->hubError($e);
        }
    }

    /**
     * One page of the module's visible questions with their answers.
     *
     * @return mixed
     */
    public function questions(Request $request, ModuleCommunityClient $community, string $name)
    {
        try {
            return ires()->data($community->questions($name, self::pageOf($request)));
        } catch (PlatformHubException $e) {
            return $this->hubError($e);
        }
    }

    /**
     * Rate a module installed on this site (stars 1-5, optional text), or edit this site's rating.
     *
     * @return mixed
     */
    public function rate(Request $request, ModuleCatalog $catalog, ModuleCommunityClient $community, string $name)
    {
        $data = $request->validate([
            'stars' => ['required', 'integer', 'between:1,5'],
            'body' => ['nullable', 'string', 'max:' . ModuleCommunityClient::MAX_BODY],
            'author_name' => ['nullable', 'string', 'max:' . ModuleCommunityClient::MAX_NAME],
        ]);
        if ($refused = $this->refuseName($data['author_name'] ?? null)) {
            return $refused;
        }
        if ($catalog->installedState($name)['installed'] === 'none') {
            return ires()->error()->code(409)->errorCode(self::NOT_INSTALLED)
                ->message('Only a module installed on this website can be rated.');
        }

        try {
            $answer = $community->rate($name, $this->webmaster($request), (int) $data['stars'], self::clean($data['body'] ?? null), $data['author_name'] ?? null);
        } catch (PlatformHubException $e) {
            return $this->communityError($e);
        }
        // The cards show the average from the cached catalog; drop it so the new rating shows on the next load.
        $catalog->forget();

        return ires()->data($answer);
    }

    /**
     * Ask a public question about a module (no install needed).
     *
     * @return mixed
     */
    public function ask(Request $request, ModuleCommunityClient $community, string $name)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:' . ModuleCommunityClient::MAX_BODY],
            'author_name' => ['nullable', 'string', 'max:' . ModuleCommunityClient::MAX_NAME],
        ]);

        if ($refused = $this->refuseName($data['author_name'] ?? null)) {
            return $refused;
        }

        try {
            $answer = $community->ask($name, $this->webmaster($request), (string) self::clean($data['body']), $data['author_name'] ?? null);
        } catch (PlatformHubException $e) {
            return $this->communityError($e);
        }

        return ires()->created($answer);
    }

    /**
     * Send private feedback about a module to its publisher.
     *
     * @return mixed
     */
    public function feedback(Request $request, ModuleCommunityClient $community, string $name)
    {
        $data = $request->validate([
            'kind' => ['required', 'string', Rule::in(ModuleCommunityClient::FEEDBACK_KINDS)],
            'body' => ['required', 'string', 'max:' . ModuleCommunityClient::MAX_FEEDBACK_BODY],
            'author_name' => ['nullable', 'string', 'max:' . ModuleCommunityClient::MAX_NAME],
        ]);

        if ($refused = $this->refuseName($data['author_name'] ?? null)) {
            return $refused;
        }

        try {
            $answer = $community->feedback($name, $this->webmaster($request), $data['kind'], (string) self::clean($data['body']), $data['author_name'] ?? null);
        } catch (PlatformHubException $e) {
            return $this->communityError($e);
        }

        return ires()->created($answer);
    }

    /**
     * A plain refusal of a display name that pretends to be iSPA or holds an e-mail address or a phone number
     * (422 with a fixed code), or null when the name is fine. Nothing is sent to the hub for a refused name.
     */
    private function refuseName(?string $name): mixed
    {
        return match (ModuleCommunityClient::nameProblem($name)) {
            ModuleCommunityClient::NAME_ISPA => ires()->error()->code(422)->errorCode(ModuleCommunityClient::NAME_ISPA)
                ->message('Names that look like iSPA are reserved for the iSPA team.'),
            ModuleCommunityClient::NAME_CONTACT => ires()->error()->code(422)->errorCode(ModuleCommunityClient::NAME_CONTACT)
                ->message('A display name must not contain an e-mail address or a phone number.'),
            default => null,
        };
    }

    /**
     * The signed-in Webmaster (the route middleware already refused everyone else).
     */
    private function webmaster(Request $request): User
    {
        $user = $request->user();
        abort_unless($user instanceof User, 403);

        return $user;
    }

    /**
     * Map a hub refusal of a community write to a fixed code; anything else is the usual 502.
     */
    private function communityError(PlatformHubException $e): mixed
    {
        if ($e->reason() === PlatformHubException::HTTP_STATUS) {
            [$status, $code, $message] = match ($e->status()) {
                403 => [409, ModuleCommunityClient::REASON_INSTALL_REQUIRED, 'The platform hub does not see this module installed in the latest status report of this website.'],
                404 => [404, 'MODULE_COMMUNITY_NOT_FOUND', 'The platform hub does not offer this module.'],
                422 => [422, 'MODULE_COMMUNITY_INVALID', 'The platform hub refused the text.'],
                429 => [429, 'MODULE_COMMUNITY_RATE_LIMITED', 'Too many posts from this website. Try again later.'],
                default => [null, null, null],
            };
            if ($status !== null) {
                return ires()->error()->code($status)->errorCode($code)->message($message);
            }
        }

        return $this->hubError($e);
    }

    /**
     * Text without control characters (new lines and tabs kept), trimmed; null when empty.
     */
    private static function clean(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }
        $value = trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace(["\r\n", "\r"], "\n", $value)));

        return $value === '' ? null : $value;
    }

    /**
     * The requested page number (1 when absent or not a positive integer).
     */
    private static function pageOf(Request $request): int
    {
        $page = $request->query('page');

        return is_string($page) && ctype_digit($page) && strlen($page) <= 6 ? max(1, (int) $page) : 1;
    }

    /**
     * Build the error answer for a hub failure; the reason is a fixed code, never the hub's text.
     */
    private function hubError(PlatformHubException $e): mixed
    {
        Log::warning('Module catalog could not be loaded from the platform hub.', ['reason' => $e->reason(), 'status' => $e->status()]);
        if ($e->siteNotRecognised()) {
            return ires()->error()->code(502)->errorCode(PlatformHubException::SITE_NOT_RECOGNISED_CODE)
                ->message('iSPA Hub does not recognise the connection of this website.');
        }

        return ires()->error()
            ->code(502)
            ->errorCode('MODULE_CATALOG_UNAVAILABLE')
            ->message('The module catalog is not available from the platform hub (' . $e->reason() . ').');
    }
}
