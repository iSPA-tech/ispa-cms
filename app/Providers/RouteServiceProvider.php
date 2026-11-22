<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Support\Providers\RouteServiceProvider as ServiceProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;

class RouteServiceProvider extends ServiceProvider
{
    /**
     * The path to the "home" route for your application.
     *
     * Typically, users are redirected here after authentication.
     *
     * @var string
     */
    public const HOME = '/';

    /**
     * The controller namespace for the application.
     *
     * @var string|null
     */
    protected $namespace = 'App\\Http\\Controllers';

    /**
     * Define your route model bindings, pattern filters, and other route configuration.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        $this->routes(function () {
            Route::prefix('api')
                ->middleware('api')
                ->namespace($this->namespace)
                ->group(base_path('routes/api.php'));

            Route::middleware('web')
                ->namespace($this->namespace)
                ->group(base_path('routes/web.php'));
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        RateLimiter::for('api', static function (Request $request) {
            //  Server-side rendering runs on the same host as the API, so every SSR
            //  request would otherwise share a single 127.0.0.1 bucket. Read-only
            //  loopback calls that opt in explicitly are exempt from this limiter.
            //  REMOTE_ADDR is read from the raw server bag on purpose: unlike
            //  $request->ip() it cannot be rewritten through X-Forwarded-For.
            //  The glossary resolver is also read-only; POST only carries its
            //  bounded batch. Route-level throttles (throttle:10,1,
            //  throttle:500,1, ...) and the named limiters below still apply.
            $internalRead = $request->isMethod('GET')
                || ($request->isMethod('POST') && $request->is('api/v2/wiki/resolve'));

            if ($request->server->get('REMOTE_ADDR') === '127.0.0.1'
                && $request->header((string) config('ispa-cms.internal_read_header', 'X-Internal-Read')) === '1'
                && $internalRead) {
                return Limit::none();
            }

            // The mail gateway talks from a single IP; it is protected by the shared gateway key, the IP allow-list
            // and the per-session `gateway-session` limiter instead.
            if ($request->is('api/v2/mail/gateway/*')) {
                return Limit::none();
            }

            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        //  View tracking ingest. A single per-IP limit is wrong here: the beacon
        //  can arrive through the SSR frontend or from behind carrier grade NAT,
        //  where thousands of distinct readers share one source address, and a
        //  flat cap would throttle all of them together. Two tiers instead —
        //  a tight one per browser, and a loose one per address so a genuinely
        //  abusive source is still stopped without punishing shared exits.
        //  The per-address tier only means anything once the real client IP
        //  reaches PHP; behind Cloudflare that needs real_ip in nginx.
        RateLimiter::for('tracking-ingest', static function (Request $request): array {
            $visitor = trim((string) $request->input('visitor', ''));
            $identity = $visitor !== ''
                ? 'visitor:' . hash('sha256', $visitor)
                : 'address:' . $request->ip();

            return [
                Limit::perMinute((int) config('tracking.ingest.per_visitor_per_minute', 30))
                    ->by('tracking-ingest-identity:' . $identity),
                Limit::perMinute((int) config('tracking.ingest.per_address_per_minute', 600))
                    ->by('tracking-ingest-address:' . $request->ip()),
            ];
        });

        // Content answer pilot: per authenticated user, configurable.
        RateLimiter::for('agent-answers', static function (Request $request) {
            return Limit::perMinute(max(1, (int) config('core.agent_answers.rate_limit_per_minute', 10)))
                ->by('agent-answers:' . ($request->user()?->getAuthIdentifier() ?? $request->ip()));
        });
        RateLimiter::for('qr-public', static function (Request $request) {
            return Limit::perMinute(2)->by($request->ip());
        });

        RateLimiter::for('qr-admin', static function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('web-login', static function (Request $request): array {
            $identity = mb_strtolower(trim((string) $request->input('username', $request->input('email', ''))));
            $ip = (string) $request->ip();

            return [
                Limit::perMinute(8)->by('web-login:credential:' . hash('sha256', $ip . '|' . $identity)),
                Limit::perMinute(40)->by('web-login:address:' . $ip),
            ];
        });

        RateLimiter::for('bulk', static function (Request $request) {
            $key = $request->user()?->id ?: $request->ip();

            return Limit::perMinute(20)
                ->by($key)
                ->response(function (Request $request, array $headers) {
                    return ires()
                        ->tooManyRequests('Too many requests.')
                        ->headers($headers)
                        ->toResponse($request);
                });
        });

        RateLimiter::for('game-auth-login', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.auth_login_per_minute', 20))
                ->by($this->gameRateKey($request, 'auth_login', ['device_id', 'install_id']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many login attempts.'));
        });

        RateLimiter::for('game-auth-refresh', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.auth_refresh_per_minute', 20))
                ->by($this->gameRateKey($request, 'auth_refresh'))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many refresh attempts.'));
        });

        RateLimiter::for('game-device-bind', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.device_bind_per_minute', 20))
                ->by($this->gameRateKey($request, 'device_bind', ['device_id', 'install_id']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many device bind attempts.'));
        });

        RateLimiter::for('game-member-bind', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.member_bind_per_minute', 10))
                ->by($this->gameRateKey($request, 'member_bind', ['member_account_ref', 'member_provider']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many member bind attempts.'));
        });

        RateLimiter::for('game-bootstrap-import', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.bootstrap_import_per_minute', 10))
                ->by($this->gameRateKey($request, 'bootstrap_import', ['bootstrap_source_hash']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many bootstrap import attempts.'));
        });

        RateLimiter::for('game-bootstrap-cutover', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.bootstrap_cutover_per_minute', 10))
                ->by($this->gameRateKey($request, 'bootstrap_cutover', ['bootstrap_id']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many cutover confirmation attempts.'));
        });

        RateLimiter::for('game-ticket-create', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.ticket_create_per_minute', 20))
                ->by($this->gameRateKey($request, 'ticket_create', ['gate_id']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many run ticket requests.'));
        });

        RateLimiter::for('game-run-submit', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.run_submit_per_minute', 20))
                ->by($this->gameRateKey($request, 'run_submit', ['run_ticket_id']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many run submit attempts.'));
        });

        RateLimiter::for('game-mission-claim', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.mission_claim_per_minute', 30))
                ->by($this->gameRateKey($request, 'mission_claim', ['missionId']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many mission claim attempts.'));
        });

        RateLimiter::for('game-shop-purchase', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.shop_purchase_per_minute', 30))
                ->by($this->gameRateKey($request, 'shop_purchase', ['offer_id']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many shop purchase attempts.'));
        });

        RateLimiter::for('push-notification-token-write', static function (Request $request): Limit {
            return Limit::perMinute((int) config('pushnotification.rate_limits.mobile_per_minute', 120))->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('push-notification-admin-write', static function (Request $request): Limit {
            return Limit::perMinute((int) config('pushnotification.rate_limits.admin_per_minute', 500))->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('mustard-seed-checkin', static function (Request $request): Limit {
            return Limit::perMinute(20)->by(($request->user()?->id ?: $request->ip()) . '|checkin');
        });

        RateLimiter::for('mustard-seed-planting', static function (Request $request): Limit {
            return Limit::perMinute(30)->by(($request->user()?->id ?: $request->ip()) . '|plant|' . (string) $request->input('idempotency_key', 'none'));
        });

        RateLimiter::for('mustard-seed-review-vote', static function (Request $request): Limit {
            return Limit::perMinute(40)->by(($request->user()?->id ?: $request->ip()) . '|review|' . (string) $request->input('report_uuid', $request->input('report_id', 'none')));
        });

        RateLimiter::for('mustard-seed-engagement', static function (Request $request): Limit {
            return Limit::perMinute(60)->by(($request->user()?->id ?: $request->ip()) . '|engagement|' . (string) $request->input('event_type', $request->path()));
        });

        RateLimiter::for('mustard-seed-provider-webhook', static function (Request $request): Limit {
            return Limit::perMinute(120)->by($request->ip() . '|provider|' . (string) $request->input('provider_event_id', $request->input('ssv_transaction_id', 'none')));
        });

        RateLimiter::for('game-admin-write', function (Request $request) {
            return Limit::perMinute((int) config('gamecore.rate_limits.admin_write_per_minute', 30))
                ->by($this->gameRateKey($request, 'admin_write', ['bootstrapId', 'transactionId', 'candidateUuid', 'recordUuid']))
                ->response(fn (Request $request, array $headers) => $this->gameTooManyRequests($request, $headers, 'Too many admin write attempts.'));
        });
    }

    protected function gameTooManyRequests(Request $request, array $headers, string $message)
    {
        return ires()
            ->tooManyRequests($message)
            ->headers($headers)
            ->toResponse($request);
    }

    protected function gameRateKey(Request $request, string $action, array $businessKeys = []): string
    {
        $segments = ['game', $action];

        $user = $request->user();
        if ($user && method_exists($user, 'getAuthIdentifier')) {
            $segments[] = 'player:' . $user->getAuthIdentifier();
        }

        if ($token = $request->bearerToken()) {
            $segments[] = 'token:' . sha1($token);
        }

        foreach ($businessKeys as $key) {
            $value = $request->input($key);

            if ($value === null) {
                $value = $request->route($key);
            }

            if ($value !== null && $value !== '') {
                $segments[] = $key . ':' . Str::limit((string) $value, 120, '');
            }
        }

        if (!$request->user()) {
            $segments[] = 'ip:' . $request->ip();
        }

        return implode('|', $segments);
    }
}
