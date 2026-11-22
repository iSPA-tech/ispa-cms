<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Throwable;
use Exception;
use App\Models\User;
use App\Enums\UserStatusEnum;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Fluent;
use Cms\Core\Models\Upload;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Hash;
use Illuminate\Database\Eloquent\Model;
use Cms\Core\Interfaces\SettingInterface;
use Cms\Core\Interfaces\AuditLogInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\TokenRevoker;
use Laravel\Sanctum\PersonalAccessToken;
use Cms\Core\Events\PasswordChangeNotification;
use Cms\Core\Http\Requests\Account\PutUpdateAccountRequest;
use Cms\Core\Http\Requests\Account\PostChangePasswordRequest;
use Cms\Core\Http\Requests\Account\DisableAuthenticatorRequest;
use Cms\Core\Http\Requests\Account\RegenerateRecoveryCodesRequest;
use Cms\Core\Http\Requests\Account\ConfirmAuthenticatorEnrollmentRequest;

/**
 * Handle authenticated admin account interactions such as updating profile info,
 * retrieving activity history, reading enriched account metadata, signing out,
 * and changing passwords.
 *
 * @see AdminMfaService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 *
 * @since   1.0.0  (2021-12-11)
 * @last_updated 8.0.0 - 2025-09-06, 10:00 (+07:00)
 */
class AccountController extends AdminController
{
    /**
     * AccountController constructor.
     *
     * @param AuditLogInterface $log Audit log repository used to query activity history.
     * @param SettingInterface $setting Settings repository for reading user/admin preferences.
     * @param  AdminMfaService $adminMfaService
     *
     * @author malayvuong
     * @since  1.0.0 - 2021-12-11, 15:17
     * @last_updated 6.62.6 - 2023-01-15, 16:15
     */
    public function __construct(
        protected AuditLogInterface $log,
        protected SettingInterface  $setting,
        protected AdminMfaService   $adminMfaService,
    )
    {
        parent::__construct();
    }

    /**
     * Update the authenticated user's core profile fields (name, email, username, mobile)
     * and synchronize meta-attributes in a single transaction.
     *
     * Request payload may include a `meta` array where each item contains:
     * - `class_id` (int)  : Meta class id
     * - `attr_id`  (int)  : Meta attribute id
     * - `type`     (string: string|date|url|select|relation|...)
     * - `value`    (mixed) : Raw value or an object with `id` for relation types
     *
     * @param PutUpdateAccountRequest $request Validated request with profile and metadata.
     *
     * @return mixed                         Standardized application response.
     *
     * @throws Throwable
     * @since  6.5.3  - 2022-03-04, 17:55
     * @last_updated 8.0.0 - 2025-09-06, 10:00 (+07:00)
     * @author malayvuong
     */
    public function update(PutUpdateAccountRequest $request): mixed
    {
        $user = user();

        DB::beginTransaction();
        try {
            $user->name = $request->input('name');
            // The sign-in name, e-mail and mobile change only with their permission (the form disables them otherwise);
            // without it the submitted value is ignored and the current one stays.
            foreach (['email', 'username', 'mobile'] as $field) {
                if (PutUpdateAccountRequest::mayChange($user, $field) && $request->exists($field)) {
                    $user->{$field} = $request->input($field);
                }
            }
            $changed = array_keys($user->getDirty());

            $user->save();
            $this->syncAvatar($user, $request);

            //  Save Meta data
            $list = [];
            foreach ($request->input('meta', []) as $value) {
                if (is_array($value) && !empty($value['value'])) {
                    $valueId = $value['value']['id'] ?? null;
                    if (!is_array($value['value']) || in_array($value['type'], ['string', 'date', 'url'])) {
                        $valueId = setMetaValue($value, $value['type'] === 'date')?->id ?? null;
                    }

                    $list[] = [
                        'meta_class_id' => $value['class_id'],
                        'meta_attribute_id' => $value['attr_id'],
                        'value_type' => get_value_class($value['type']),
                        'value_id' => $valueId,
                    ];
                }
            }

            $user->meta()->delete();
            $user->meta()->createMany($list);
        } catch (Exception $e) {
            Db::rollBack();
            Log::debug($e->getMessage());

            return ires()->error()->message(__('mgs.er.update', ['name' => __('text.account')]));
        }

        DB::commit();

        // The audit row names the changed fields, never their values (an e-mail or a phone number is personal data).
        if ($changed !== []) {
            event(new AuditLogEvent('account-update', ['fields' => array_values(array_intersect(['name', 'email', 'username', 'mobile'], $changed))], User::class, (int) $user->id, 'fa fa-user', 'text-blue-600'));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('text.account')]));
    }

    /**
     * Get paginated activity logs for the authenticated user.
     *
     * Accepts an optional `pageSize` query parameter (default: 20).
     * Each activity record may include a `target` (id, name) relation.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  6.5.3 - 2022-03-04, 13:11
     * @last_updated 8.0.0 - 2025-09-06, 10:00 (+07:00)
     */
    public function activities(): mixed
    {
        $activities = $this->log->model()->where([
            'user_id' => auth()->id(),
        ])->with('target')->latest()->paginate(request('pageSize', 20));

        $activities->getCollection()->transform(function ($activity) {
            $activity->setRelation('target', $this->buildActivityTargetSummary($activity->target));

            return $activity;
        });

        return ires()->data(collect($activities)->toCamel());
    }

    /**
     * Normalize morph targets into a compact summary that always exposes `id` and `name`.
     */
    protected function buildActivityTargetSummary(mixed $target): ?Fluent
    {
        if (!$target instanceof Model) {
            return null;
        }

        return new Fluent([
            'id' => $target->getKey(),
            'name' => $this->resolveActivityTargetLabel($target),
        ]);
    }

    /**
     * Resolve a human-readable label for an audit-log target without assuming a `name` column exists.
     */
    protected function resolveActivityTargetLabel(Model $target): string
    {
        foreach ([
            'name',
            'title',
            'subject',
            'order_code',
            'code',
            'display_name',
            'slug',
            'uuid',
            'email',
            'username',
        ] as $attribute) {
            $value = $target->getAttribute($attribute);

            if (is_scalar($value) && trim((string) $value) !== '') {
                return trim((string) $value);
            }
        }

        return '#' . $target->getKey();
    }

    /**
     * Get enriched account information for the authenticated user.
     *
     * The response includes:
     * - roles and permissions (flattened for convenience)
     * - `access_token`: always null (the endpoint no longer mints a token; the caller already holds its own)
     * - user meta mapped to `current_meta`
     * - admin UI settings and default language
     * - last successful login and last password change/reset audit entries
     * - a queued `ispa_manager_session` cookie
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0
     * @last_updated 8.0.0 - 2025-09-06, 10:00 (+07:00)
     */
    public function info()
    {
        $user = user();
        $user->loadMissing('avatar');
        $roles = user()?->roles()->with('permissions')->get();
        $roleReturn = [];
        foreach ($roles as $role) {
            $permissions = collect($role->permissions)->map(function ($value): array {
                return [
                    'name' => trim((string) $value->name),
                    'group' => trim((string) $value->group),
                    'title' => trim((string) ($value->title ?? '')),
                ];
            });
            $roleReturn[] = ['name' => $role->name, 'custom' => $role->custom, 'permissions' => $permissions];
        }
        $user->setAttribute('roles', $roleReturn);

        $user->setAttribute('ggToken', config('ispa-cms.ggApi'));

        //  A plain token cannot be recovered and minting one per call grew the token table; none is returned
        $user->setAttribute('access_token', null);

        $user->load('meta');

        $meta = [];
        foreach ($user->meta as $value) {
            $meta[$value->attribute->name . '_' . $value->attribute->id] = [
                'class_id' => $value->meta_class_id,
                'type' => $value->attribute->type,
                'id' => $value->meta_attribute_id,
                'value' => $value->value ?? '',
            ];
        }

        $user->setAttribute('current_meta', $meta);
        $user->setAttribute('avatar', $user->avatar ? $user->avatar->toArray() : null);

        //  Get user settings
        $settings = [
            'editor' => config('ispa-cms.text_editor', 'ckeditor'),
        ];
        $user->setAttribute('admin_settings', $settings);
        $user->setAttribute('mfa', $this->adminMfaService->buildAccountSummary($user));

        //  Get default language user setting
        $user->setAttribute('default_language', get_user_setting('language', config('app.locale')));

        //  Set global cookie
        cookie()->queue('ispa_manager_session', true, 60 * 24 * 30, null, null, null, true, false, 'None');

        //  Get last login
        $lastLogin = $this->log->model()->where([
            'target_type' => User::class,
            'target_id' => auth()->id(),
            'action' => 'action.admin-signIn-success',
        ])->latest()->first();
        $user->setAttribute('last_login', $lastLogin);
        //  Get last change password / Reset password
        $lastChangePassword = $this->log->model()->where([
            'target_type' => User::class,
            'target_id' => auth()->id(),
        ])->where(fn($q) => $q->where('action', 'action.change-password')->orWhere('action', 'action.resetPassword'))->latest()->first();
        $user->setAttribute('last_change_password', $lastChangePassword);

        return ires()->data(collect($user)->toCamel());
    }

    /**
     * Retrieve the current MFA status summary for the authenticated user.
     *
     * @return mixed Standardized response containing the MFA account summary.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function mfaStatus(): mixed
    {
        return ires()->data($this->adminMfaService->buildAccountSummary(user()));
    }

    /**
     * Begin TOTP authenticator enrollment for the authenticated user.
     *
     * Generates a new authenticator secret and returns the provisioning data
     * (QR code URI, secret) needed for the user to complete setup.
     *
     * @return mixed Standardized response with enrollment data or an error message.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function startAuthenticatorEnrollment(): mixed
    {
        try {
            return ires()->data($this->adminMfaService->startAuthenticatorEnrollment(user()))
                ->message(__('mgs.ok.mfa-enrollment-started'));
        } catch (Exception $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        }
    }

    /**
     * Confirm TOTP authenticator enrollment by verifying the user-supplied 6-digit code.
     *
     * On success, the authenticator factor is activated and recovery codes are returned.
     *
     * @param ConfirmAuthenticatorEnrollmentRequest $request Validated request containing the TOTP code.
     * @return mixed Standardized response with confirmation data or an error message.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function confirmAuthenticatorEnrollment(ConfirmAuthenticatorEnrollmentRequest $request): mixed
    {
        try {
            return ires()->data(
                $this->adminMfaService->confirmAuthenticatorEnrollment(user(), (string)$request->input('code'))
            )->message(__('mgs.ok.mfa-enrollment-confirmed'));
        } catch (Exception $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        }
    }

    /**
     * Disable the TOTP authenticator for the authenticated user after password verification.
     *
     * Requires the user's current password for confirmation before removing the MFA factor.
     *
     * @param DisableAuthenticatorRequest $request Validated request containing the current password.
     * @return mixed Standardized response with updated MFA status or an error message.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function disableAuthenticator(DisableAuthenticatorRequest $request): mixed
    {
        try {
            return ires()->data(
                $this->adminMfaService->disableAuthenticator(user(), (string)$request->input('current_password'))
            )->message(__('mgs.ok.mfa-disabled'));
        } catch (Exception $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        }
    }

    /**
     * Regenerate MFA recovery codes for the authenticated user after password verification.
     *
     * Replaces all existing recovery codes with a fresh set. Requires the current password.
     *
     * @param RegenerateRecoveryCodesRequest $request Validated request containing the current password.
     * @return mixed Standardized response with new recovery codes or an error message.
     */
    public function regenerateRecoveryCodes(RegenerateRecoveryCodesRequest $request): mixed
    {
        try {
            return ires()->data(
                $this->adminMfaService->regenerateRecoveryCodes(user(), (string)$request->input('current_password'))
            )->message(__('mgs.ok.mfa-recovery-codes-regenerated'));
        } catch (Exception $e) {
            return ires()->failed()->code(200)->message($e->getMessage());
        }
    }

    /**
     * Revoke exactly the presented access token (through TokenRevoker) and sign the user out.
     *
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0
     * @last_updated 8.0.0 - 2025-09-06, 10:00 (+07:00)
     */
    public function signOut(): mixed
    {
        $token = user()?->currentAccessToken();
        if ($token instanceof PersonalAccessToken && app(TokenRevoker::class)->revokeToken($token, null) > 0) {
            return ires()->message(__('mgs.ok.sign-out'));
        }

        return ires()->error()->message(__('mgs.er.sign-out'));
    }

    /**
     * Issue a new auth session as a target user for "Sign in as" support.
     *
     * Requires `user.signInAs` permission.
     *
     * @param Request $request
     * @return mixed
     *
     * @author malayvuong
     * @since  1.0.0
     * @last_updated 8.0.0 - 2025-09-06, 10:00 (+07:00)
     */
    public function signInAs(Request $request): mixed
    {
        if (!user()?->can('user.signInAs')) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $uuid = trim((string)$request->input('uuid', ''));
        if ($uuid === '') {
            return ires()->error()->message(__('mgs.er.user-not-valid'));
        }

        $target = User::query()
            ->where('uuid', $uuid)
            ->with('avatar', 'roles.permissions')
            ->first();

        if (!$target) {
            return ires()->error()->message(__('mgs.er.user-not-valid'));
        }

        if ((int)$target->id === (int)user()?->id) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (user('level', 99) > (int)($target->level ?? 0)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($target->status !== UserStatusEnum::USER_ACTIVE->value) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $payload = $this->adminMfaService->buildAuthPayload($target);
        event(
            new AuditLogEvent(
                'admin-signIn-as',
                ['actor_id' => user()?->id, 'target_uuid' => $target->uuid],
                User::class,
                $target->id,
                'fa fa-sign-in',
                'text-green-600'
            )
        );

        return ires()->data($payload)->message(__('mgs.ok.sign-in'));
    }

    /**
     * Change the authenticated user's password.
     *
     * Validates current password, prevents reusing the same password, persists the new
     * password, writes an audit log entry, and (optionally) dispatches a notification
     * email if the user enabled `email_change_password` in settings.
     *
     * @param PostChangePasswordRequest $request Validated request (current & new passwords).
     *
     * @return mixed
     *
     * @fires AuditLog
     * @fires PasswordChangeNotification
     *
     * @author phien
     * @since  1.0.0
     * @last_updated 8.0.0 - 2025-09-06, 10:00 (+07:00)
     */
    public function changePassword(PostChangePasswordRequest $request): mixed
    {
        if (!Hash::check(request('current_password'), user()->password)) {
            return ires()->failed()->message(__('auth.password'));
        }

        if (request('password') === request('current_password')) {
            return ires()->failed()->message(__('mgs.er.password-same-current'));
        }

        user()->password = Hash::make(request('password'));

        if (!user()?->save()) {
            return ires()->error()->message(__('mgs.er.save-password'));
        }

        // A new password ends every other API token of the account; the token of this request stays
        $current = user()?->currentAccessToken();
        app(TokenRevoker::class)->revokeForUser(user(), $current instanceof PersonalAccessToken ? $current : null);

        //  Save audit log
        event(new AuditLogEvent('change-password', [], User::class, auth()->id(), 'fa fa-lock', 'text-green-600'));

        //  Todo: Send email notify to user with setting
        $setting = user()?->settings()->where('setting_key', 'email_change_password')->first();
        if (isset($setting->setting_value) && $setting?->setting_value['value'] === true && user('email') !== null) {
            event(new PasswordChangeNotification($request->userAgent(), $request->ip()), user());
        }

        return ires()->message(__('mgs.ok.save-password'));
    }

    protected function syncAvatar(User $user, PutUpdateAccountRequest $request): void
    {
        if (!$request->exists('avatar')) {
            return;
        }

        Upload::query()
            ->where('target_type', User::class)
            ->where('target_id', $user->id)
            ->where('category', 'avatar')
            ->update([
                'target_type' => null,
                'target_id' => null,
                'category' => null,
                'main' => false,
            ]);

        $avatarId = collect($request->input('avatar', []))
            ->filter(static fn($item) => is_array($item) && !empty($item['id']))
            ->pluck('id')
            ->first();

        if (!$avatarId) {
            return;
        }

        Upload::query()
            ->whereKey($avatarId)
            ->update([
                'target_type' => User::class,
                'target_id' => $user->id,
                'category' => 'avatar',
                'type' => 'avatar',
                'main' => true,
            ]);
    }
}
