<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Services\UpdateBatch\UpdateBatches;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Jobs\Modules\RebuildAdminJob;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\InstallRecorder;
use Cms\Core\Services\Modules\Install\InstallRunDispatcher;
use Cms\Core\Services\Modules\Install\ModuleInstaller;
use Cms\Core\Services\Modules\Install\ModuleInstallPolicy;
use Cms\Core\Services\Modules\Install\ModuleUninstaller;
use Cms\Core\Services\Modules\Install\PreflightResult;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Platform\PlatformHubException;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

/**
 * Starts runtime module installs from the platform hub or from an uploaded package, and lists recent ones (Webmaster only).
 *
 * The `admin.webmaster` route middleware refuses other admins; the controller checks `isWebmaster()` again. Errors
 * carry a machine `errorCode` and an English message, like ModuleController and ModuleCatalogController.
 */
final class ModuleInstallController extends AdminController
{
    /** Seconds the start lock is held at most. */
    private const LOCK_SECONDS = 60;

    /** Rows returned by the list. */
    private const LIST_LIMIT = 20;

    /**
     * Start an install: preflight again, check the confirmation, refuse a second running install, record and queue it.
     *
     * Body: `{ name, version, confirm_name, confirm_irreversible, confirm_third_party, backup }`. Answers 202 with the
     * install (uuid-first).
     * `confirm_third_party` must be `true` when the preflight warns MODULE_WARN_THIRD_PARTY_FIRST_INSTALL (a
     * module not published by iSPA, first from its publisher on this site); else 422
     * MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED with `data.publisher` and `data.impact`. The confirmation is stored on
     * the row (`third_party_confirmed_by`, `third_party_confirmed_at`) and audited as `module.third_party_confirmed`.
     * `backup` (, default true) is the person's choice to take the safety backup first; false records
     * `backup_skipped` and the installer takes no dump. The preflight's `backup.recommended` is only the dialog default.
     * A package for an installed runtime module that is newer than the installed version queues kind `upgrade`
     *; `kind` and `previous_version` are on the answer and in the list.
     *
     * @return mixed
     */
    public function store(Request $request, InstallPreflight $preflight)
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can use this endpoint.')->errorCode('WEBMASTER_ONLY');
        }
        if (($refused = app(UpdateBatches::class)->refusal()) !== null) {
            return $refused;
        }

        $data = $request->validate([
            'name' => ['required', 'string', 'regex:/^[A-Z][A-Za-z0-9]{1,63}$/'],
            'version' => ['required', 'string', 'regex:/^\d+\.\d+\.\d+$/'],
            'confirm_name' => ['nullable', 'string', 'max:64'],
            'confirm_irreversible' => ['nullable', 'boolean'],
            'confirm_third_party' => ['nullable', 'boolean'],
            'backup' => ['nullable', 'boolean'],
        ]);
        $name = (string) $data['name'];
        $version = (string) $data['version'];

        $lock = Cache::lock($this->lockKey(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $this->running();
        }

        try {
            if (app(InstallRunDispatcher::class)->hasUnfinished()) {
                return $this->running();
            }

            try {
                $result = $preflight->check($name, $version);
            } catch (PlatformHubException $e) {
                Log::warning('Module install preflight could not reach the platform hub.', ['reason' => $e->reason(), 'status' => $e->status()]);

                return ires()->error()
                    ->code(502)
                    ->errorCode('MODULE_CATALOG_UNAVAILABLE')
                    ->message('The module catalog is not available from the platform hub (' . $e->reason() . ').');
            }

            $confirmed = $request->input('confirm_irreversible') === true;
            $refused = $this->refusal($result, $confirmed, $data['confirm_name'] ?? null, $name)
                ?? $this->thirdPartyRefusal($result, $request->input('confirm_third_party') === true);
            if ($refused !== null) {
                return $refused;
            }

            $thirdParty = $this->thirdParty($preflight, $result, $name, null, false);
            $install = app(InstallRecorder::class)->record($user, $name, $version, ModuleInstall::SOURCE_HUB, $result, $confirmed, null, $this->backupChosen($request), $thirdParty);
        } finally {
            $lock->release();
        }

        $this->auditThirdParty($install, $user);
        app(InstallRunDispatcher::class)->dispatch($install);

        return ires()->accepted($install->refresh()->toApiArray(), 'Module install queued.');
    }

    /**
     * Start an install from an uploaded package: verify its signature first (same PackageVerifier as the hub path),
     * check the zip against the signed manifest, run the upload preflight and the same confirmation and
     * one-running-install rules as {@see self::store()}, stage both files under `<runtime>/.staging/<install uuid>/`
     * and queue the same InstallModuleJob (row `source = upload`; the job skips only the hub download).
     *
     * Multipart body: `package` (zip, at most `core.module_packages.max_zip_bytes`), `signature` (sig.json, at most
     * 2 MiB), optional `confirm_name`, `confirm_irreversible`, `confirm_third_party`, `backup` (default true, see
     * {@see self::store()}). A package signed with a hub countersign key, or a module the hub names as a partner's,
     * needs `confirm_third_party` like the hub path.
     * Answers 202 with the install (uuid-first).
     *
     * @return mixed
     */
    public function upload(Request $request, InstallPreflight $preflight, PackageVerifier $verifier)
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can use this endpoint.')->errorCode('WEBMASTER_ONLY');
        }
        if (($refused = app(UpdateBatches::class)->refusal()) !== null) {
            return $refused;
        }

        $request->validate([
            'package' => ['required', 'file'],
            'signature' => ['required', 'file'],
            'confirm_name' => ['nullable', 'string', 'max:64'],
            'confirm_irreversible' => ['nullable', 'boolean'],
            'confirm_third_party' => ['nullable', 'boolean'],
            'backup' => ['nullable', 'boolean'],
        ]);
        /** @var UploadedFile $package */
        $package = $request->file('package');
        /** @var UploadedFile $signature */
        $signature = $request->file('signature');

        $maxZip = (int) config('core.module_packages.max_zip_bytes', 50 * 1024 * 1024);
        if ((int) $package->getSize() > $maxZip || (int) $signature->getSize() > InstallPreflight::SIGNATURE_MAX_BYTES) {
            return ires()->error()
                ->code(422)
                ->errorCode('MODULE_UPLOAD_TOO_LARGE')
                ->message('The package may be at most ' . $maxZip . ' bytes and the signature file at most ' . InstallPreflight::SIGNATURE_MAX_BYTES . ' bytes.');
        }

        // Signature first: name and version come only from the verified manifest.
        try {
            $manifest = $verifier->verify((string) file_get_contents($signature->getRealPath()));
            ModuleInstaller::checkZip($package->getRealPath(), $manifest);
        } catch (PackageVerificationException $e) {
            return ires()->error()
                ->code(422)
                ->errorCode($e->errorCode)
                ->message('The uploaded package could not be verified (' . $e->errorCode . ').');
        }

        $lock = Cache::lock($this->lockKey(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $this->running();
        }

        try {
            if (app(InstallRunDispatcher::class)->hasUnfinished()) {
                return $this->running();
            }

            $result = $preflight->checkUpload($manifest);
            // Multipart fields arrive as strings ("1", "true").
            $confirmed = $request->boolean('confirm_irreversible');
            $refused = $this->refusal($result, $confirmed, $request->input('confirm_name'), $manifest->name)
                ?? $this->thirdPartyRefusal($result, $request->boolean('confirm_third_party'));
            if ($refused !== null) {
                return $refused;
            }

            $thirdParty = $this->thirdParty($preflight, $result, $manifest->name, $manifest->keyId, true);
            $install = $this->recordUpload($user, $manifest, $result, $confirmed, $package, $signature, $this->backupChosen($request), $thirdParty);
            if (! $install instanceof ModuleInstall) {
                return $install;
            }
        } finally {
            $lock->release();
        }

        $this->auditThirdParty($install, $user);
        app(InstallRunDispatcher::class)->dispatch($install);

        return ires()->accepted($install->refresh()->toApiArray(), 'Module install queued.');
    }

    /**
     * The latest 20 installs, newest first.
     *
     * @return mixed
     */
    public function index(Request $request)
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can use this endpoint.')->errorCode('WEBMASTER_ONLY');
        }

        $rows = ModuleInstall::query()->orderByDesc('id')->limit(self::LIST_LIMIT)->get();

        return ires()->data($rows->map(static fn (ModuleInstall $row): array => $row->toApiArray())->values()->all());
    }

    /**
     * What an uninstall of one runtime module would do: its version, whether it is enabled, the data tables it
     * declares (`uninstall.tables`; an empty list means "also drop its tables" is not offered) and the enabled
     * modules that require it (these block the uninstall).
     *
     * @return mixed
     */
    public function uninstallInfo(Request $request, string $name, ModuleUninstaller $uninstaller)
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can use this endpoint.')->errorCode('WEBMASTER_ONLY');
        }

        try {
            $facts = $uninstaller->inspect($name);
        } catch (PackageVerificationException $e) {
            return $this->uninstallRefused($e);
        }

        return ires()->data([
            'name' => $facts['name'],
            'version' => $facts['version'],
            'enabled' => $facts['enabled'],
            'uninstall_tables' => $facts['tables'],
            'dependents' => $facts['dependents'],
        ]);
    }

    /**
     * Start the removal of a runtime module: check the rules again, record an install row of kind
     * `uninstall` and queue the same InstallModuleJob.
     *
     * Body: `{ confirm_name, drop_data }`. `confirm_name` must equal the module name; `drop_data` (default false) also
     * drops the module's tables and is refused unless the module declares `uninstall.tables`. Answers 202 with the
     * install row (uuid-first, `kind = uninstall`). Errors: MODULE_CONFIRMATION_REQUIRED (422), MODULE_HAS_DEPENDENTS
     * (409, with `dependents`), MODULE_CORE_PROTECTED, MODULE_NOT_RUNTIME, MODULE_NOT_INSTALLED,
     * MODULE_UNINSTALL_NO_TABLES, MODULE_INSTALL_DISABLED, MODULE_INSTALL_RUNNING (409).
     *
     * @return mixed
     */
    public function uninstall(Request $request, string $name, ModuleUninstaller $uninstaller, BackgroundTaskRecorder $recorder)
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can use this endpoint.')->errorCode('WEBMASTER_ONLY');
        }
        if (($refused = app(UpdateBatches::class)->refusal()) !== null) {
            return $refused;
        }

        $data = $request->validate([
            'confirm_name' => ['required', 'string', 'max:64'],
            'drop_data' => ['nullable', 'boolean'],
        ]);
        if ($data['confirm_name'] !== $name) {
            return ires()->error()
                ->code(422)
                ->errorCode('MODULE_CONFIRMATION_REQUIRED')
                ->message('Type the module name to confirm the uninstall.');
        }
        $installState = app(ModuleInstallPolicy::class)->state();
        if ($installState !== ModuleInstallPolicy::STATE_ALLOWED) {
            return ires()->error()
                ->code(422)
                ->errorCode(match ($installState) {
                    ModuleInstallPolicy::STATE_FORBIDDEN_BY_SERVER => InstallPreflight::FORBIDDEN_BY_SERVER,
                    ModuleInstallPolicy::STATE_FORBIDDEN_BY_HUB => InstallPreflight::FORBIDDEN_BY_HUB,
                    default => InstallPreflight::INSTALL_DISABLED,
                })
                ->message('Module installs are switched off on this site.');
        }
        $dropData = $request->boolean('drop_data');

        $lock = Cache::lock($this->lockKey(), self::LOCK_SECONDS);
        if (! $lock->get()) {
            return $this->running();
        }

        try {
            if (app(InstallRunDispatcher::class)->hasUnfinished()) {
                return $this->running();
            }

            try {
                $facts = $uninstaller->check($name, $dropData);
            } catch (PackageVerificationException $e) {
                return $this->uninstallRefused($e, $uninstaller);
            }

            $install = DB::transaction(function () use ($recorder, $user, $facts, $dropData): ModuleInstall {
                $task = $recorder->start(ModuleInstall::TASK_TYPE, 'Core', (int) $user->id);

                return ModuleInstall::query()->create([
                    'name' => $facts['name'],
                    'version' => (string) ($facts['version'] ?? '0.0.0'),
                    'source' => ModuleInstall::SOURCE_LOCAL,
                    'kind' => ModuleInstall::KIND_UNINSTALL,
                    'status' => ModuleInstall::STATUS_QUEUED,
                    'step_message' => 'Waiting for a queue worker.',
                    'background_task_uuid' => $task->uuid,
                    'requested_by' => (int) $user->id,
                    'confirmed_irreversible' => $dropData,
                    'warnings' => [],
                ]);
            });
        } finally {
            $lock->release();
        }

        app(InstallRunDispatcher::class)->dispatch($install);

        return ires()->accepted($install->refresh()->toApiArray(), 'Module uninstall queued.');
    }

    /**
     * Error answer of an uninstall refusal; MODULE_HAS_DEPENDENTS also lists the dependents' names.
     */
    private function uninstallRefused(PackageVerificationException $e, ?ModuleUninstaller $uninstaller = null): mixed
    {
        $message = (string) preg_replace('/^[A-Z_]+: /', '', $e->getMessage());
        $status = match ($e->errorCode) {
            ModuleUninstaller::ERROR_NOT_INSTALLED => 404,
            ModuleUninstaller::ERROR_HAS_DEPENDENTS, ModuleUninstaller::ERROR_TABLE_NOT_OWNED => 409,
            default => 422,
        };
        $answer = ires()->error()->code($status)->errorCode($e->errorCode)->message(ucfirst($message));
        if ($e->errorCode === ModuleUninstaller::ERROR_HAS_DEPENDENTS && $uninstaller !== null) {
            $names = preg_match('/: (.+)$/', $message, $m) === 1 ? array_map('trim', explode(',', $m[1])) : [];
            $answer = $answer->data(['dependents' => $names]);
        }
        if ($e->errorCode === ModuleUninstaller::ERROR_TABLE_NOT_OWNED) {
            $names = preg_match('/: (.+)$/', $message, $m) === 1 ? array_map('trim', explode(',', $m[1])) : [];
            $answer = $answer->data(['tables' => $names]);
        }

        return $answer;
    }

    /**
     * Retry the admin rebuild (after an install warned that it failed). Refused while an install is unfinished.
     *
     * Answers 202 with the background task uuid.
     *
     * @return mixed
     */
    public function adminRebuild(Request $request, BackgroundTaskRecorder $recorder)
    {
        $user = $request->user();
        if (! $user instanceof User || ! $user->isWebmaster()) {
            return ires()->forbidden('Only a Webmaster can use this endpoint.')->errorCode('WEBMASTER_ONLY');
        }

        if (app(InstallRunDispatcher::class)->hasUnfinished()) {
            return $this->running();
        }

        $task = $recorder->start('core.admin_build', 'Core', (int) $user->id);
        RebuildAdminJob::dispatch($task->uuid);

        return ires()->accepted(['task_uuid' => $task->uuid], 'Admin rebuild queued.');
    }

    /**
     * 422 answer when the preflight blocks or the irreversible-migration confirmation is missing; null when allowed.
     */
    private function refusal(PreflightResult $result, bool $confirmed, mixed $confirmName, string $name): mixed
    {
        if (! $result->canInstall()) {
            $first = $result->blocking[0];

            return ires()->error()
                ->code(422)
                ->errorCode((string) $first['code'])
                ->message((string) $first['message']);
        }

        $irreversible = in_array(InstallPreflight::WARN_IRREVERSIBLE, $result->warningCodes(), true);
        if ($irreversible && (! $confirmed || $confirmName !== $name)) {
            return ires()->error()
                ->code(422)
                ->errorCode('MODULE_CONFIRMATION_REQUIRED')
                ->message('This module has irreversible migrations: confirm them and type the module name to continue.');
        }

        return null;
    }

    /**
     * 422 MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED when the preflight warns that a partner module is installed for the
     * first time and the request does not confirm it; null otherwise. The answer carries the publisher and
     * the impact, so the upload drawer (which has no preflight) can show the confirmation.
     */
    private function thirdPartyRefusal(PreflightResult $result, bool $confirmedThirdParty): mixed
    {
        $warning = self::thirdPartyWarning($result);
        if ($warning === null || $confirmedThirdParty) {
            return null;
        }

        return ires()->error()
            ->code(422)
            ->errorCode(InstallPreflight::THIRD_PARTY_CONFIRMATION_REQUIRED)
            ->message('This module is published by a partner, not by iSPA, and runs with full rights on this site: confirm that you understand this to continue.')
            ->data([
                'publisher' => $warning['details']['publisher'] ?? null,
                'impact' => $warning['details']['impact'] ?? null,
            ]);
    }

    /**
     * The MODULE_WARN_THIRD_PARTY_FIRST_INSTALL warning of a preflight, or null.
     *
     * @return array{code: string, message: string, details: array<string, mixed>}|null
     */
    private static function thirdPartyWarning(PreflightResult $result): ?array
    {
        foreach ($result->warnings as $warning) {
            if (($warning['code'] ?? null) === InstallPreflight::WARN_THIRD_PARTY_FIRST_INSTALL) {
                return $warning;
            }
        }

        return null;
    }

    /**
     * Partner fields of the row to record: the publisher key when iSPA does not publish the module, and whether this
     * request carried the confirmation (only when the preflight asked for it). Empty for an iSPA module.
     *
     * @return array{third_party_publisher?: string, confirmed?: bool}
     */
    private function thirdParty(InstallPreflight $preflight, PreflightResult $result, string $name, ?string $keyId, bool $hubOptional): array
    {
        $warning = self::thirdPartyWarning($result);
        $publisher = $preflight->thirdPartyPublisher($name, $keyId, $hubOptional);
        if ($publisher === null && $warning === null) {
            return [];
        }

        return [
            'third_party_publisher' => $publisher['key'] ?? ($warning['details']['publisher']['key'] ?? ModuleInstall::PUBLISHER_UNKNOWN),
            // thirdPartyRefusal() already refused a warning without the confirmation.
            'confirmed' => $warning !== null,
        ];
    }

    /**
     * Audit `module.third_party_confirmed` when the row carries a partner confirmation.
     */
    private function auditThirdParty(ModuleInstall $install, User $user): void
    {
        if ($install->third_party_confirmed_at === null) {
            return;
        }

        event(new AuditLogEvent('module.third_party_confirmed', [
            'name' => $install->name,
            'version' => $install->version,
            'source' => $install->source,
            'third_party_publisher' => $install->third_party_publisher,
        ], ModuleInstall::class, (int) $install->id, null, null, (int) $user->id));
    }

    /**
     * Stage the uploaded files under the new install's uuid, then record the row; staging is removed on failure.
     *
     * @param array<string, mixed> $thirdParty partner fields ({@see self::thirdParty()})
     * @return ModuleInstall|mixed the row, or a 500 answer when the files can not be staged
     */
    private function recordUpload(User $user, ReleaseManifest $manifest, PreflightResult $result, bool $confirmed, UploadedFile $package, UploadedFile $signature, bool $backup = true, array $thirdParty = []): mixed
    {
        $uuid = (string) Str::uuid();
        try {
            ModuleInstaller::stageUpload($uuid, $package->getRealPath(), $signature->getRealPath());
        } catch (PackageVerificationException $e) {
            Log::warning('Module upload could not be staged.', ['code' => $e->errorCode]);

            return ires()->error()
                ->code(500)
                ->errorCode($e->errorCode)
                ->message('The uploaded package could not be stored on this site (' . $e->errorCode . ').');
        }

        try {
            return app(InstallRecorder::class)->record($user, $manifest->name, $manifest->version, ModuleInstall::SOURCE_UPLOAD, $result, $confirmed, $uuid, $backup, $thirdParty);
        } catch (Throwable $e) {
            app(ModuleInstaller::class)->cleanStaging((new ModuleInstall())->forceFill(['uuid' => $uuid]));
            throw $e;
        }
    }

    /**
     * The person's backup choice: only an explicit false (JSON false, "0", "false") skips the backup; a missing,
     * null or empty value keeps it on.
     */
    private function backupChosen(Request $request): bool
    {
        $value = $request->input('backup');

        return $value === null || $value === '' || filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) !== false;
    }

    /**
     * 409 answer when another install is still running.
     */
    private function running(): mixed
    {
        return ires()->conflict('Another module install is still running on this site.')->errorCode('MODULE_INSTALL_RUNNING');
    }

    /**
     * Cache lock key, unique per site.
     */
    private function lockKey(): string
    {
        return 'core:module-install:start:' . sha1((string) config('ispa-cms.cms_domain', '') . '|' . (string) config('app.url', ''));
    }
}
