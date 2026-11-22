<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Collection;
use Laravel\Telescope\EntryType;
use Laravel\Telescope\IncomingEntry;
use Laravel\Telescope\Telescope;
use Laravel\Telescope\TelescopeApplicationServiceProvider;

class TelescopeServiceProvider extends TelescopeApplicationServiceProvider
{
    /**
     * Register any application services.
     *
     * @return void
     */
    public function register(): void
    {
        Telescope::night();

        $this->hideSensitiveRequestDetails();

        // Agent answers: drop the whole batch. RequestWatcher stores the response (the answer) and
        // QueryWatcher the interpolated search bindings (the question); hiding parameters is not enough.
        Telescope::filterBatch(static function (Collection $entries): bool {
            return ! $entries->contains(static function (IncomingEntry $entry): bool {
                $uri = (string) ($entry->content['uri'] ?? '');

                return $entry->type === EntryType::REQUEST
                    && rtrim((string) parse_url($uri, PHP_URL_PATH), '/') === '/api/v2/public/agent/answers';
            });
        });

        Telescope::filter(function (IncomingEntry $entry) {
            // One-tap hub connect: the site's outgoing token exchange is never recorded, even locally.
            if ($entry->type === EntryType::CLIENT_REQUEST
                && str_ends_with((string) parse_url((string) ($entry->content['uri'] ?? ''), PHP_URL_PATH), '/platform/site-connect/token')) {
                return false;
            }
            if ($this->app->environment('local')) {
                return true;
            }

            return $entry->isReportableException() ||
                   $entry->isFailedRequest() ||
                   $entry->isFailedJob() ||
                   $entry->isScheduledTask() ||
                   $entry->hasMonitoredTag();
        });
    }

    /**
     * Prevent sensitive request details from being logged by Telescope.
     *
     * @return void
     */
    protected function hideSensitiveRequestDetails(): void
    {
        Telescope::hideRequestParameters([
            'flow_token',
            // Google migration secrets must never be recorded, even on failed requests.
            'service_account',
            'app_password',
            // One-tap hub connect (incoming token exchange on the hub, outgoing HTTP client call on the site).
            'code',
            'code_verifier',
            'secret',
        ]);
        Telescope::hideResponseParameters(['secret', 'data.secret']);

        if ($this->app->environment('local')) {
            return;
        }

        Telescope::hideRequestParameters(['_token']);

        Telescope::hideRequestHeaders([
            'cookie',
            'x-csrf-token',
            'x-xsrf-token',
        ]);
    }

    /**
     * Register the Telescope gate.
     *
     * This gate determines who can access Telescope in non-local environments.
     *
     * @return void
     */
    protected function gate(): void
    {
        Gate::define('viewTelescope', static function ($user) {
            // Only the addresses in TELESCOPE_ALLOWED_EMAILS (comma separated). Read through config: with a cached config
            // env() returns null.
            $allowed = array_filter(array_map('trim', explode(',', (string) config('telescope.allowed_emails', ''))));

            return in_array($user->email, $allowed, true);
        });
    }
}
