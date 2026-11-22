<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Admin\UpdateTokenSettingsRequest;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Security\TokenAudience;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Webmaster-only admin API of the API token settings: show, update, preview and apply to existing
 * tokens. Access is enforced by the `admin.webmaster` route middleware. Responses carry setting values, warnings and
 * counts only, never a token, a hash or a uuid. Settings are site settings and not secrets.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 */
class SecurityTokenSettingsController extends AdminController
{
    /**
     * @param TokenSettings $settings Token settings store.
     */
    public function __construct(private readonly TokenSettings $settings)
    {
        parent::__construct();
    }

    /**
     * Show the current settings with the option lists, the warnings for "never" and the recommended hint.
     *
     * @return mixed Admin API response.
     */
    public function show(): mixed
    {
        return ires()->data($this->payload($this->settings->all()));
    }

    /**
     * Save a partial change. "Never expires" is refused unless `confirm_never_expire` is true.
     *
     * @param UpdateTokenSettingsRequest $request Validated change.
     * @return mixed Admin API response with the saved settings.
     */
    public function update(UpdateTokenSettingsRequest $request): mixed
    {
        if ($request->setsNever() && ! $request->boolean('confirm_never_expire')) {
            return $this->needsConfirmation();
        }

        $changed = $this->settings->update($request->safe()->only(['lifetime', 'idle_days', 'max_live_tokens', 'mode', 'unused_days']));
        if ($request->has('notify_new_device') && $request->boolean('notify_new_device') !== $this->settings->notifyNewDevice()) {
            $this->settings->setNotifyNewDevice($request->boolean('notify_new_device'));
            $changed[] = 'notify_new_device';
        }
        event(new AuditLogEvent(
            'security.token_settings.updated',
            ['changed' => $changed],
            Setting::class,
            (int) Setting::query()->where('setting_key', TokenSettings::SETTING_KEY)->value('id'),
            'fa fa-key',
            'warning',
        ));

        return ires()->data($this->payload($this->settings->all()) + ['changed' => $changed]);
    }

    /**
     * Count what a change would do to existing tokens, without saving anything.
     *
     * @param UpdateTokenSettingsRequest $request Candidate change.
     * @return mixed Admin API response with counts per audience.
     */
    public function preview(UpdateTokenSettingsRequest $request): mixed
    {
        $candidate = $this->settings->normalize(
            $this->settings->merge($this->settings->all(), $request->safe()->only(['lifetime', 'idle_days', 'max_live_tokens', 'mode', 'unused_days']))
        );
        $now = Carbon::now();
        $audiences = [];
        foreach (TokenAudience::userAudiences() as $audience) {
            $minutes = TokenSettings::LIFETIME_OPTIONS[$candidate['lifetime'][$audience]];
            $audiences[$audience] = [
                'live' => $this->liveTokens($audience)->count(),
                'never_expiring' => $this->liveTokens($audience)->whereNull('expires_at')->count(),
                'would_expire_now' => $minutes === null ? 0 : $this->liveTokens($audience)->where('created_at', '<=', $now->copy()->subMinutes($minutes))->count(),
            ];
        }

        return ires()->data([
            'audiences' => $audiences,
            'without_audience' => $this->accountTokens()->whereNull('audience')->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', $now))->count(),
            'warnings' => $this->warnings($candidate),
        ]);
    }

    /**
     * Set the expiry of live tokens of one audience from `created_at + lifetime`. The token that carries the request is
     * never touched. Tokens already past their new expiry are left as they are unless `revoke_past` is true.
     *
     * @param Request $request Body: audience, confirm_never_expire, revoke_past.
     * @return mixed Admin API response with counts.
     */
    public function applyToExisting(Request $request): mixed
    {
        $data = $request->validate([
            'audience' => ['required', 'string', 'in:' . implode(',', TokenAudience::userAudiences())],
            'revoke_past' => ['sometimes', 'boolean'],
        ]);
        $audience = $data['audience'];
        $minutes = $this->settings->lifetimeMinutes($audience);
        if ($minutes === null) {
            // "Never" sets no limit. Applying it could only remove the expiry some tokens carry (the v3 sign-in gives some of them
            // a day), which weakens a session limit and never tightens one, so there is nothing to apply.
            return ires()->error()->code(422)->errorCode('NOTHING_TO_APPLY')
                ->message('This lifetime sets no limit, so there is nothing to apply to existing tokens.');
        }

        $current = $request->user()?->currentAccessToken();
        $spareId = $current instanceof PersonalAccessToken ? (int) $current->getKey() : null;
        $query = $this->liveTokens($audience);
        if ($spareId !== null) {
            $query->whereKeyNot($spareId);
        }

        $set = 0;
        $past = 0;
        $revoked = 0;
        $cutoff = Carbon::now()->subMinutes($minutes);
        $query->orderBy('id')->chunkById(200, function ($tokens) use ($minutes, $cutoff, $request, &$set, &$past, &$revoked): void {
            foreach ($tokens as $token) {
                if ($token->created_at->lessThanOrEqualTo($cutoff)) {
                    $past++;
                    if ($request->boolean('revoke_past')) {
                        $token->delete();
                        $revoked++;
                    }
                } else {
                    $token->forceFill(['expires_at' => $token->created_at->copy()->addMinutes($minutes)])->save();
                    $set++;
                }
            }
        });

        event(new AuditLogEvent(
            'security.token_settings.applied',
            ['audience' => $audience, 'set' => $set, 'already_past' => $past, 'revoked' => $revoked, 'spared' => $spareId !== null ? 1 : 0],
            Setting::class,
            0,
            'fa fa-key',
            'warning',
        ));

        return ires()->data(['audience' => $audience, 'set' => $set, 'already_past' => $past, 'revoked' => $revoked]);
    }

    /**
     * Settings payload shared by show and update.
     *
     * @param array<string, mixed> $settings Normalized settings.
     * @return array<string, mixed>
     */
    private function payload(array $settings): array
    {
        return [
            'settings' => $settings + ['notify_new_device' => $this->settings->notifyNewDevice()],
            'audiences' => TokenAudience::userAudiences(),
            'options' => [
                'lifetime' => array_keys(TokenSettings::LIFETIME_OPTIONS),
                'idle_days' => TokenSettings::IDLE_DAY_OPTIONS,
                'unused_days' => TokenSettings::UNUSED_DAY_OPTIONS,
                'modes' => TokenSettings::MODES,
                'max_live_tokens' => ['min' => TokenSettings::MIN_LIVE_TOKENS, 'max' => TokenSettings::MAX_LIVE_TOKENS],
            ],
            'recommended' => TokenSettings::RECOMMENDED_LIFETIME,
            'warnings' => $this->warnings($settings),
        ];
    }

    /**
     * One warning for every audience whose tokens never expire.
     *
     * @param array<string, mixed> $settings Normalized settings.
     * @return list<array{code: string, audience: string}>
     */
    private function warnings(array $settings): array
    {
        return array_map(
            static fn (string $audience): array => ['code' => 'TOKEN_NEVER_EXPIRES', 'audience' => $audience],
            $this->settings->neverAudiences($settings),
        );
    }

    /**
     * 422 asking for the explicit confirmation of "never expires".
     *
     * @return mixed
     */
    private function needsConfirmation(): mixed
    {
        return ires()->error()->code(422)->errorCode('NEVER_EXPIRE_CONFIRMATION_REQUIRED')
            ->message('Confirm that these tokens never expire.');
    }

    /**
     * Tokens owned by accounts.
     *
     * @return Builder<ApiAccessToken>
     */
    private function accountTokens(): Builder
    {
        return ApiAccessToken::query()->where('tokenable_type', (new \App\Models\User())->getMorphClass());
    }

    /**
     * Live (not yet expired) account tokens of one audience.
     *
     * @param string $audience One of TokenAudience::userAudiences().
     * @return Builder<ApiAccessToken>
     */
    private function liveTokens(string $audience): Builder
    {
        return $this->accountTokens()->where('audience', $audience)
            ->where(fn (Builder $q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', Carbon::now()));
    }
}
