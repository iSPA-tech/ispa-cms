<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Jobs\Modules\RefreshRuntimeCachesJob;
use App\Models\User;
use App\Support\Modules\ArtisanSubprocess;
use App\Support\Modules\DatabaseActivator;
use App\Support\Modules\ModuleStatusService;
use App\Support\Modules\RuntimeModules;
use App\Support\Modules\RuntimePrecedence;
use Closure;
use Cms\Core\Console\Commands\ModuleInstallCommandRunCommand;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\BackgroundTask;
use Cms\Core\Models\Backup\Backup;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Cms\Core\Services\Backup\BackupGate;
use Cms\Core\Services\Backup\BackupRequiredException;
use Cms\Core\Services\Modules\Packages\PackageVerificationException;
use Cms\Core\Services\Modules\Packages\PackageVerifier;
use Cms\Core\Services\Modules\Packages\ReleaseManifest;
use Cms\Core\Services\Modules\Packages\RuntimeAssets;
use Cms\Core\Services\Modules\Packages\RuntimePlacement;
use Cms\Core\Services\Modules\Packages\SafeExtractor;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Nwidart\Modules\Facades\Module as ModuleFacade;
use RuntimeException;
use Throwable;

/**
 * Runs one runtime module install from the platform hub: placement, then enabling.
 *
 * Steps, each recorded on the install row (`status`, `step_message`) and on its background task:
 *  1. backing_up  — BackupGate::ensure('pre_install'); a failure stops before anything is downloaded. Skipped when the
 * person who started the install chose so (`backup_skipped`,): no dump runs, `backup_uuid`
 *                   stays null and audit `module.install_backup_skipped` names the module, version and requester; failure
 *                   messages then point at the site's own (scheduled) backups instead of a pre-install one.
 * 2. downloading — offline upload (`source = upload`,): read the zip and signature JSON the upload endpoint
 *                   staged in `<runtime-modules>/.staging/<install uuid>/` ({@see self::stageUpload()}), verify the
 *                   signature and check name and version; no hub request. Hub install: ask the hub for a download ticket, verify the ticket's signature JSON with PackageVerifier (so the
 *                   byte cap comes from a signed manifest), check name and version equal the request, then stream the
 *                   zip to `<runtime-modules>/.staging/<install uuid>/package.zip`, cut off at the manifest `zip_size`.
 * A premium download may be a per-grant copy: the ticket then names its `licensee`
 *                   zip hash and size, and those replace the manifest's zip facts in the cap and the checks below.
 * Partner gate, right after the manifest is verified and before any download: a package
 *                  signed with a hub countersign key, or a row the preflight marked as a partner module
 *                  (`third_party_publisher`), needs `third_party_confirmed_at` on the row or an earlier succeeded,
 *                  confirmed install from the same publisher; else MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED.
 * 3. verifying — the signed manifest's package facts first ({@see InstallPreflight::packageBlocking},:
 *                   MODULE_ADMIN_PACKAGE_MISSING, MODULE_THEME_COUPLED; a hub install has no manifest at preflight
 *                   time); zip size and sha256 equal the manifest; SafeExtractor unpacks into the staging folder;
 * RuntimeModules::validate checks the unpacked module (Core and Game* names refused, F1),
 *                   its `frontend-build/` folder included
 *                   (allowlisted files only, the Vite manifest the signed `frontend` names, every file it names); the
 *                   signed module.json `install.artisan` list must hold only `cms:<module-kebab>:<name>` commands
 *                   without arguments ({@see self::installCommands()}), else MODULE_RUNTIME_INVALID.
 * 4. placing — RuntimePlacement::place; then publishing_assets: a signed manifest with `frontend`
 *                   copies `frontend-build/` to RuntimeAssets `<Name>/<version>/`; a failure (MODULE_ASSETS_PUBLISH_FAILED)
 *                   removes the placed folder and link again, so the runtime folder is as before. The row keeps
 *                   status `placing` during this sub-step (no new status label). Then DatabaseActivator::setVersion
 *                   (new row starts disabled).
 *  5. enable step — the collaborator given as `$enableStep` (default: {@see self::enable()}):
 *     6. enabling   — ModuleStatusService::change(name, true, requester, 'install').
 *     7. migrating  — fresh artisan processes (ArtisanSubprocess; a queue worker's nwidart static module list does not
 *                     know the new module): `module:migrate <Name> --force`, `cms:modules-sync <Name>
 * --skip-translations`, the module's `install.artisan` commands in their declared order (;
 *                     each re-checked against the namespace rule just before it runs, and run through
 * `cms:module-install-command`, which refuses a name not registered exactly (F2); a
 *                     failure is MODULE_INSTALL_COMMAND_FAILED), `cms:translation-sync <Name>`, `cms:translation-generate --public-only` (only the admin language packs; the code tree stays read-only); then
 *                     the cache refresh of RefreshRuntimeCachesJob run inline (config/route/event caches, `queue:restart`).
 *     8. building_admin — a signed manifest with `custom_code` rebuilds the admin through {@see AdminRebuilder}; a
 *                     failure (or a site that is not a deployed release) only adds warning MODULE_ADMIN_BUILD_FAILED or
 *                     MODULE_ADMIN_BUILD_NOT_DEPLOYED: the install still succeeds and the Webmaster can retry the rebuild.
 *     9. audit `module.installed` (with `asset_files`, the published file count, when assets were published);
 *        status `succeeded`; the task succeeds with the warning codes.
 *     A failure in 6–7 disables the module again through the service, leaves the placed files in place, sets status
 *     `failed` naming the pre-install backup (or, when it was skipped, the site's own backups), fails the task and writes audit `module.install_failed`.
 * Upgrade (`kind = upgrade`,): same steps 1–3, then {@see self::placeUpgrade} publishes the new version's
 * front-end assets next to the old ones (before anything moves), swaps the module link to the new version folder and
 * moves the old folder to `<runtime>/.previous/<Name>-<old version>`; the module keeps the enabled state it had;
 * `module:migrate` runs only the new migrations, then `cms:modules-sync` and the new version's `install.artisan`
 * commands (idempotent sync commands such as permission syncs, which a deploy also reruns); audit `module.upgraded`;
 * older `.previous/<Name>-*` folders and the other published asset versions are removed only after that success. A failure after placement restores the old
 * folder and link and the stored version, removes the new asset version, disables nothing and writes audit
 * `module.upgrade_failed`.
 * Handover: an upgrade of a release module listed in the project's `runtime_modules.json`. With no runtime
 * copy yet, the old version is the release copy's: the new version is placed under `.versions` and linked as for a
 * first install, and the release copy stays untouched as the fallback. RuntimeModules::validate skips its release-name
 * rule for it. After placement RuntimePrecedence must prefer the new copy (else MODULE_HANDOVER_NOT_PREFERRED and the
 * placement is undone), so the following artisan steps run against it. A failure removes the new link and folder; the
 * module stays enabled on its release copy.
 * Uninstall (`kind = uninstall`,): handed to {@see ModuleUninstaller}.
 * Any failure before the enable step sets status `failed` with its error code, fails the background task and removes
 * the staging folder.
 *
 * Hub contract (site side; the hub side is):
 *  - Ticket: `POST /api/v2/public/platform/download-tickets` through PlatformHubClient (HMAC-signed request, signed
 *    response verified by the client), body `{"name": "Foo", "version": "2026.1.0"}`. Answer (top level JSON object):
 *    `{"download_url": "https://<hub host>/api/v2/public/platform/downloads/<token>", "expires_at": "<ISO-8601>",
 *      "signature_json": "<the package sig.json document: {key_id, signature, manifest}>"}`.
 *    The catalog (ModuleCatalog) does not carry zip size, hash or signature; they come from the signed manifest inside
 *    `signature_json` (fields `zip_size`, `zip_sha256`, `key_id`).
 * Buyer mark: the request also sends `"licensee": true` (older hubs ignore it). For a premium package
 *    the hub may then answer `"licensee": {"zip_sha256": "<64 hex>", "zip_size": <int>}`: the download is a per-grant
 *    copy with `<Name>/LICENSEE.json` and `<Name>/LICENSEE.sig` added. Its size may exceed the manifest `zip_size` by
 *    at most LICENSEE_EXTRA_BYTES; the zip is checked against these signed ticket values, and SafeExtractor still
 *    checks every signed file and accepts the two extra files only for a `premium` module.json.
 *  - Download: `GET <download_url>` signed the same way as every PlatformHubClient request (headers X-Ispa-Domain,
 *    X-Ispa-Timestamp, X-Ispa-Nonce, X-Ispa-Signature; the domain secret is never sent), redirects not followed.
 *    The scheme, host and port of `download_url` must equal the configured hub URL. The body is the zip
 *    (`Content-Length` optional); it carries no response signature because the Ed25519 manifest binds the zip hash.
 */
class ModuleInstaller
{
    /** Hub path of the download ticket request. */
    public const TICKET_PATH = '/api/v2/public/platform/download-tickets';

    /** Staging folder under the runtime modules folder. */
    public const STAGING_FOLDER = '.staging';

    /** File names of an uploaded package inside its install staging folder (also the hub download target). */
    public const UPLOAD_ZIP = 'package.zip';
    public const UPLOAD_SIGNATURE = 'signature.json';

    public const ERROR_NO_RUNTIME_FOLDER = 'MODULE_INSTALL_NO_RUNTIME_FOLDER';
    public const ERROR_HUB = 'MODULE_INSTALL_HUB_UNAVAILABLE';
    public const ERROR_TICKET = 'MODULE_INSTALL_TICKET_INVALID';
    public const ERROR_DOWNLOAD_HOST = 'MODULE_DOWNLOAD_HOST_REFUSED';
    public const ERROR_DOWNLOAD_FAILED = 'MODULE_DOWNLOAD_FAILED';
    public const ERROR_DOWNLOAD_TOO_LARGE = 'MODULE_DOWNLOAD_TOO_LARGE';
    public const ERROR_PACKAGE_MISMATCH = 'MODULE_PACKAGE_MISMATCH';
    public const ERROR_RUNTIME_INVALID = 'MODULE_RUNTIME_INVALID';
    public const ERROR_STAGING = 'MODULE_INSTALL_STAGING';
    public const ERROR_UNEXPECTED = 'MODULE_INSTALL_FAILED';
    public const ERROR_ENABLE = 'MODULE_INSTALL_ENABLE_FAILED';
    public const ERROR_MIGRATE = 'MODULE_INSTALL_MIGRATE_FAILED';
    public const ERROR_ACTIVATION = 'MODULE_INSTALL_ACTIVATION_FAILED';
    public const ERROR_UPGRADE_NOT_INSTALLED = 'MODULE_UPGRADE_NOT_INSTALLED';
    public const ERROR_UPGRADE_PLACE = 'MODULE_UPGRADE_PLACE_FAILED';
    public const ERROR_HANDOVER_NOT_PREFERRED = 'MODULE_HANDOVER_NOT_PREFERRED';
    public const ERROR_ASSETS_PUBLISH = RuntimeAssets::PUBLISH_FAILED;
    public const ERROR_INSTALL_COMMAND = 'MODULE_INSTALL_COMMAND_FAILED';
    public const ERROR_THIRD_PARTY_UNCONFIRMED = InstallPreflight::THIRD_PARTY_CONFIRMATION_REQUIRED;

    /** Most bytes a per-grant premium copy may add to the signed zip (two small licensee files and their headers). */
    public const LICENSEE_EXTRA_BYTES = 65536;

    /** Most `install.artisan` commands one module may declare. */
    public const MAX_INSTALL_COMMANDS = 20;

    /** Folder of the previous module version kept by an upgrade, inside the runtime modules folder. */
    public const PREVIOUS_FOLDER = '.previous';

    /** @deprecated No longer added: the install now runs the admin rebuild and warns with its own codes. */
    public const WARN_ADMIN_BUILD_PENDING = 'MODULE_ADMIN_BUILD_PENDING';

    /** Seconds allowed for `module:migrate`. */
    private const MIGRATE_TIMEOUT = 900;

    /** Seconds allowed for each sync or translation command. */
    private const STEP_TIMEOUT = 300;

    private const CHUNK = 65536;

    private readonly PackageVerifier $verifier;

    private readonly SafeExtractor $extractor;

    private readonly RuntimePlacement $placement;

    private readonly ?DatabaseActivator $activator;

    /** @var (Closure(): PlatformHubClient)|null */
    private readonly ?Closure $clientFactory;

    /** @var (Closure(ModuleInstall, ReleaseManifest, BackgroundTask, string): void)|null */
    private readonly ?Closure $enableStep;

    private readonly ModuleStatusService $statuses;

    private readonly ArtisanSubprocess $artisan;

    private readonly AdminRebuilder $adminRebuilder;

    /** Files published by the current run's front-end assets; null when the package has none. */
    private ?int $assetFiles = null;

    /** @var list<string> `install.artisan` commands of the package being installed, validated at the verifying step. */
    private array $installCommands = [];

    /**
     * Every optional collaborator exists so tests can fake the environment.
     *
     * @param BackupGate                     $backupGate         safety backup before any download
     * @param BackgroundTaskRecorder         $recorder           background task progress
     * @param PackageVerifier|null           $verifier           signature check (default: trusted keys from config)
     * @param SafeExtractor|null             $extractor          safe unzip (default limits from config)
     * @param RuntimePlacement|null          $placement          atomic placement
     * @param DatabaseActivator|null         $activator          module status store (default: built from the container)
     * @param (callable(): PlatformHubClient)|null $clientFactory hub client builder (default: from config)
     * @param (callable(ModuleInstall, ReleaseManifest, BackgroundTask, string): void)|null $enableStep step after
     * placement (default: {@see self::enable}); implements enabling
     * @param string|null                    $releaseModulesPath release modules folder (default: config modules.paths.modules)
     * @param ModuleStatusService|null       $statuses           module status write path (enable, disable on failure)
     * @param ArtisanSubprocess|null         $artisan            fresh-process artisan runner for migrate, sync and caches
     * @param AdminRebuilder|null            $adminRebuilder     admin build runner for modules with custom admin code
     */
    public function __construct(
        private readonly BackupGate $backupGate,
        private readonly BackgroundTaskRecorder $recorder,
        ?PackageVerifier $verifier = null,
        ?SafeExtractor $extractor = null,
        ?RuntimePlacement $placement = null,
        ?DatabaseActivator $activator = null,
        ?callable $clientFactory = null,
        ?callable $enableStep = null,
        private readonly ?string $releaseModulesPath = null,
        ?ModuleStatusService $statuses = null,
        ?ArtisanSubprocess $artisan = null,
        ?AdminRebuilder $adminRebuilder = null,
    ) {
        $this->verifier = $verifier ?? new PackageVerifier();
        $this->extractor = $extractor ?? new SafeExtractor();
        $this->placement = $placement ?? new RuntimePlacement();
        $this->activator = $activator;
        $this->clientFactory = $clientFactory !== null ? Closure::fromCallable($clientFactory) : null;
        $this->enableStep = $enableStep !== null ? Closure::fromCallable($enableStep) : null;
        $this->statuses = $statuses ?? new ModuleStatusService();
        $this->artisan = $artisan ?? new ArtisanSubprocess();
        $this->adminRebuilder = $adminRebuilder ?? new AdminRebuilder($this->artisan);
    }

    /**
     * Run the install up to placement and the enable step. Never throws: failures are recorded on the row and task.
     */
    public function run(ModuleInstall $install): void
    {
        if ($install->isUninstall()) {
            // the same queued job runs an uninstall row; it shares this installer's collaborators.
            $this->uninstaller()->run($install);

            return;
        }

        $task = $this->task($install);
        $install->forceFill(['started_at' => $install->started_at ?? Carbon::now(), 'error_code' => null])->save();
        $this->assetFiles = null;
        $this->installCommands = [];
        // A long-lived worker memoises precedence decisions; this run reads the folders again.
        RuntimePrecedence::forget();

        $runtimeRoot = RuntimeModules::path();
        $stagingRoot = $runtimeRoot !== null ? $runtimeRoot . DIRECTORY_SEPARATOR . self::STAGING_FOLDER : null;
        $staging = $stagingRoot !== null ? $stagingRoot . DIRECTORY_SEPARATOR . $install->uuid : null;
        $upgrade = null;
        $manifest = null;

        try {
            if ($runtimeRoot === null || $staging === null || $stagingRoot === null) {
                throw new PackageVerificationException(self::ERROR_NO_RUNTIME_FOLDER, 'runtime modules folder is missing');
            }

            // 1. Safety backup, unless the person skipped it. The task is not passed on: the backup runner would close
            //    it as its own.
            if ($install->backup_skipped) {
                $this->skipBackup($install);
            } else {
                $this->step($install, $task, ModuleInstall::STATUS_BACKING_UP, 5, 'Taking a safety backup of the database.');
                $backup = $this->backupGate->ensure(Backup::REASON_PRE_INSTALL, $install->requested_by);
                $install->forceFill(['backup_uuid' => $backup->uuid])->save();
            }

            // 2. Signed manifest and package: the staged upload, or ticket + capped download from the hub.
            $uploaded = $install->source === ModuleInstall::SOURCE_UPLOAD;
            $this->step($install, $task, ModuleInstall::STATUS_DOWNLOADING, 20, $uploaded ? 'Reading the uploaded module package.' : 'Downloading the module package.');
            $ticket = $uploaded ? null : $this->ticket($install);
            $sigJson = $ticket !== null ? $ticket['signature_json'] : $this->stagedSignature($staging);
            $manifest = $this->verifier->verify($sigJson);
            if ($manifest->name !== $install->name || $manifest->version !== $install->version) {
                throw new PackageVerificationException(self::ERROR_PACKAGE_MISMATCH, 'signed manifest names another module or version');
            }
            $install->forceFill(['zip_sha256' => $manifest->zipSha256, 'key_id' => $manifest->keyId])->save();
            // a partner package never runs without the explicit confirmation, whichever path queued it.
            $this->assertThirdPartyConfirmed($install, $manifest);
            $zipPath = $staging . DIRECTORY_SEPARATOR . self::UPLOAD_ZIP;
            $copy = $ticket['licensee'] ?? null;
            if ($copy !== null && $copy['zip_size'] > $manifest->zipSize + self::LICENSEE_EXTRA_BYTES) {
                throw new PackageVerificationException(self::ERROR_TICKET, 'licensed package copy is far larger than the signed package');
            }
            if ($ticket !== null) {
                self::prepareStaging($stagingRoot, $staging);
                $this->download($ticket['download_url'], $zipPath, $copy['zip_size'] ?? $manifest->zipSize);
            }

            // 3. Hash and size, safe unzip, module rules.
            $this->step($install, $task, ModuleInstall::STATUS_VERIFYING, 45, 'Verifying the module package.');
            $refused = InstallPreflight::packageBlocking($manifest);
            if ($refused !== []) {
                Log::warning('Module install refused a package this site can not run.', ['install' => $install->uuid, 'codes' => array_column($refused, 'code')]);
                throw new PackageVerificationException((string) $refused[0]['code'], 'the package needs what this site does not provide');
            }
            self::checkZip($zipPath, $manifest, $copy);
            $moduleDir = $this->extractor->extract($zipPath, $manifest, $staging . DIRECTORY_SEPARATOR . 'unpacked', $copy);
            $errors = RuntimeModules::validate($moduleDir, $manifest->name, $this->releasePath(), $manifest->frontend['entries'] ?? [], $install->isUpgrade() && $this->isHandover($manifest->name));
            if ($errors !== []) {
                Log::warning('Module install refused an invalid runtime module.', ['install' => $install->uuid, 'errors' => $errors]);
                throw new PackageVerificationException(self::ERROR_RUNTIME_INVALID, 'module does not follow the runtime module rules');
            }
            $this->installCommands = self::installCommands($moduleDir, $manifest->name);

            // 4. Placement and the installed version (the new status row starts disabled).
            $this->step($install, $task, ModuleInstall::STATUS_PLACING, 60, $manifest->frontend !== null
                ? 'Placing the module files and publishing its front-end assets.'
                : 'Placing the module files.');
            if ($install->isUpgrade()) {
                $upgrade = $this->placeUpgrade($moduleDir, $manifest, $runtimeRoot);
                $this->activator()->setVersion($manifest->name, $manifest->version);
                // Keep the signed hashes for the integrity report (best effort, information only).
                ModuleIntegrity::record($runtimeRoot, $manifest->name, $sigJson);
                $install->forceFill(['previous_version' => $upgrade['old_version'], 'step_message' => 'Module files placed.'])->save();
                $this->recorder->progress($task, 65, 'text.background_tasks.step.module_install.placed');
                $this->upgradeActivate($install, $manifest, $task, $upgrade);
                $upgrade = null;

                return;
            }
            $placed = $this->placement->place($moduleDir, $manifest, $runtimeRoot);
            $this->publishInstallAssets($manifest, $placed, $runtimeRoot);
            $this->activator()->setVersion($manifest->name, $manifest->version);
            // Keep the signed hashes for the integrity report (best effort, information only).
            ModuleIntegrity::record($runtimeRoot, $manifest->name, $sigJson);
            $install->forceFill(['step_message' => 'Module files placed.'])->save();
            $this->recorder->progress($task, 65, 'text.background_tasks.step.module_install.placed');

            // 5. Enable, migrate, sync, caches and audit (steps 6–9).
            $this->enableStep !== null
                ? ($this->enableStep)($install, $manifest, $task, $placed)
                : $this->enable($install, $manifest, $task, $placed);

            $this->closeAfterPlacement($install->refresh(), $task->refresh(), $manifest);
        } catch (Throwable $e) {
            if ($upgrade !== null && $manifest !== null) {
                $this->failUpgrade($install, $manifest, $task, $upgrade, $this->codeOf($e));
            } else {
                $this->markFailed($install, $this->codeOf($e), $task);
            }
        } finally {
            if ($staging !== null && $stagingRoot !== null) {
                SafeExtractor::removeTree($staging, $stagingRoot);
            }
        }
    }

    /**
     * The uninstall runner (kind `uninstall`), built from this installer's collaborators.
     */
    public function uninstaller(): ModuleUninstaller
    {
        return new ModuleUninstaller(
            $this->backupGate,
            $this->recorder,
            new ModuleCatalog(null, $this->releaseModulesPath),
            $this->statuses,
            $this->artisan,
            $this->adminRebuilder,
            $this->activator,
            $this->releaseModulesPath,
        );
    }

    /**
     * Refuse a partner package that this install row does not carry the explicit confirmation for.
     *
     * A package signed with a hub countersign key is a partner package even when the row was recorded as iSPA's
     * (a hub without publisher data, or a module whose listing was not live). Such a row has no publisher key, so it
     * needs its own confirmation.
     *
     * @throws PackageVerificationException MODULE_THIRD_PARTY_CONFIRMATION_REQUIRED
     */
    private function assertThirdPartyConfirmed(ModuleInstall $install, ReleaseManifest $manifest): void
    {
        $countersigned = in_array($manifest->keyId, InstallPreflight::countersignKeyIds(), true);
        if (! $countersigned && $install->third_party_publisher === null) {
            return;
        }
        if ($install->third_party_confirmed_at !== null || InstallPreflight::confirmedBefore($install->third_party_publisher)) {
            return;
        }

        Log::warning('Module install refused a partner package that was not confirmed.', ['install' => $install->uuid, 'module' => $install->name]);
        throw new PackageVerificationException(self::ERROR_THIRD_PARTY_UNCONFIRMED, 'the module is published by a partner and its install was not confirmed');
    }

    /**
     * Audit fields of a partner install; empty for an iSPA module, so its audit entries keep their shape.
     *
     * @return array<string, mixed>
     */
    private function thirdPartyAudit(ModuleInstall $install): array
    {
        if ($install->third_party_publisher === null) {
            return [];
        }

        return [
            'third_party_publisher' => $install->third_party_publisher,
            'third_party_confirmed' => $install->third_party_confirmed_at !== null,
        ];
    }

    /**
     * Record a failure on the row and its task (used by run() and by the job after a worker kill or timeout).
     */
    public function markFailed(ModuleInstall $install, string $code, ?BackgroundTask $task = null): void
    {
        $status = $install->status;
        $install->forceFill([
            'status' => ModuleInstall::STATUS_FAILED,
            'error_code' => mb_substr($code, 0, 100),
            'step_message' => mb_substr('Install failed while ' . str_replace('_', ' ', $status) . ' (' . $code . ').', 0, 500),
            'finished_at' => Carbon::now(),
        ])->save();

        $task ??= $this->findTask($install);
        if ($task !== null && in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            $this->recorder->fail($task, 'text.background_tasks.step.module_install.failed', ['code' => $code]);
        }

        Log::warning('Module install failed.', ['install' => $install->uuid, 'module' => $install->name, 'code' => $code]);
    }

    /**
     * Stage an uploaded package for an install: create `<runtime>/.staging/<install uuid>/` (0700) and copy the zip
     * and the signature JSON into it (0600). The folder is removed again on failure.
     *
     * @throws PackageVerificationException MODULE_INSTALL_NO_RUNTIME_FOLDER or MODULE_INSTALL_STAGING
     */
    public static function stageUpload(string $installUuid, string $zipSource, string $signatureSource): void
    {
        $runtimeRoot = RuntimeModules::path();
        if ($runtimeRoot === null) {
            throw new PackageVerificationException(self::ERROR_NO_RUNTIME_FOLDER, 'runtime modules folder is missing');
        }
        $stagingRoot = $runtimeRoot . DIRECTORY_SEPARATOR . self::STAGING_FOLDER;
        $staging = $stagingRoot . DIRECTORY_SEPARATOR . $installUuid;

        self::prepareStaging($stagingRoot, $staging);
        foreach ([self::UPLOAD_ZIP => $zipSource, self::UPLOAD_SIGNATURE => $signatureSource] as $file => $source) {
            $target = $staging . DIRECTORY_SEPARATOR . $file;
            if (! @copy($source, $target)) {
                SafeExtractor::removeTree($staging, $stagingRoot);
                throw new PackageVerificationException(self::ERROR_STAGING, 'cannot store the uploaded package');
            }
            @chmod($target, 0600);
        }
    }

    /**
     * Zip size and sha256 must equal the signed manifest, or the per-grant copy the signed ticket names.
     *
     * @param array{zip_sha256: string, zip_size: int}|null $copy
     *
     * @throws PackageVerificationException MODULE_PACKAGE_MISMATCH
     */
    public static function checkZip(string $zipPath, ReleaseManifest $manifest, ?array $copy = null): void
    {
        clearstatcache(true, $zipPath);
        $size = @filesize($zipPath);
        $hash = @hash_file('sha256', $zipPath);
        $expectedSize = $copy['zip_size'] ?? $manifest->zipSize;
        $expectedHash = $copy['zip_sha256'] ?? $manifest->zipSha256;
        if ($size !== $expectedSize || $hash === false || ! hash_equals($expectedHash, $hash)) {
            throw new PackageVerificationException(self::ERROR_PACKAGE_MISMATCH, 'package zip size or hash differs from the signed manifest');
        }
    }

    /**
     * Remove the staging folder of an install, when the runtime folder exists.
     */
    public function cleanStaging(ModuleInstall $install): void
    {
        $runtimeRoot = RuntimeModules::path();
        if ($runtimeRoot !== null) {
            $stagingRoot = $runtimeRoot . DIRECTORY_SEPARATOR . self::STAGING_FOLDER;
            SafeExtractor::removeTree($stagingRoot . DIRECTORY_SEPARATOR . $install->uuid, $stagingRoot);
        }
    }

    /**
     * Enable step after placement (steps 6–9): enable, migrate and sync in fresh processes, refresh caches, audit.
     *
     * Contract: called once the files are placed and the version row exists. It finishes the install itself, either
     * `succeeded` or `failed`; a failure in steps 6–7 disables the module again and leaves the placed files in place.
     */
    protected function enable(ModuleInstall $install, ReleaseManifest $manifest, BackgroundTask $task, string $placedPath): void
    {
        $user = $this->requester($install);
        $code = self::ERROR_ENABLE;

        try {
            // 6. Status through the single write path. The worker's nwidart list predates the placement: rescan it.
            $this->step($install, $task, ModuleInstall::STATUS_ENABLING, 70, 'Enabling the module.');
            ModuleFacade::resetModules();
            $this->statuses->change($manifest->name, true, $user, 'install', false);

            // 7. Migrate and sync in fresh processes.
            $code = self::ERROR_MIGRATE;
            $this->step($install, $task, ModuleInstall::STATUS_MIGRATING, 75, 'Running the module migrations.');
            $this->artisanStep(['module:migrate', $manifest->name, '--force'], self::MIGRATE_TIMEOUT);

            $code = self::ERROR_ACTIVATION;
            $this->recorder->progress($task, 85, 'text.background_tasks.step.module_install.syncing');
            $this->artisanStep(['cms:modules-sync', $manifest->name, '--skip-translations'], self::STEP_TIMEOUT);
            $code = self::ERROR_INSTALL_COMMAND;
            $this->runInstallCommands($manifest->name);
            $code = self::ERROR_ACTIVATION;
            $this->artisanStep(['cms:translation-sync', $manifest->name], self::STEP_TIMEOUT);
            $this->artisanStep(['cms:translation-generate', '--public-only'], self::STEP_TIMEOUT);
        } catch (Throwable $e) {
            $this->failActivation($install, $manifest, $task, $user, $code, $e);

            return;
        }

        // Caches and worker restart, non-fatal per step (the job logs and falls back to `<name>:clear`).
        $this->recorder->progress($task, 92, 'text.background_tasks.step.module_install.refreshing');
        try {
            (new RefreshRuntimeCachesJob())->handle($this->artisan, app(Kernel::class));
        } catch (Throwable $e) {
            Log::warning('Module install: cache refresh did not finish.', ['install' => $install->uuid, 'exception' => $e::class]);
        }

        // 8. Custom admin code needs the admin rebuilt; a failure is a warning, never an install failure.
        $warnings = is_array($install->warnings) ? $install->warnings : [];
        if ($manifest->customCode) {
            $this->step($install, $task, ModuleInstall::STATUS_BUILDING_ADMIN, 95, 'Rebuilding the admin.');
            try {
                $built = $this->adminRebuilder->rebuild();
            } catch (Throwable $e) {
                Log::warning('Module install: admin rebuild threw.', ['install' => $install->uuid, 'exception' => $e::class]);
                $built = ['ok' => false, 'errorCode' => AdminRebuilder::ERROR_FAILED];
            }
            if (! $built['ok']) {
                $notDeployed = ($built['errorCode'] ?? null) === AdminRebuilder::ERROR_NOT_DEPLOYED;
                $warnings[] = [
                    'code' => $notDeployed ? AdminRebuilder::ERROR_NOT_DEPLOYED : AdminRebuilder::ERROR_FAILED,
                    'message' => 'The module is installed, but the admin could not be rebuilt. A Webmaster can retry the admin rebuild before its screens appear.',
                    'details' => [],
                ];
            }
        }

        // 9. Audit, row and task.
        event(new AuditLogEvent('module.installed', [
            'name' => $manifest->name,
            'version' => $manifest->version,
            'zip_sha256' => $install->zip_sha256,
            'key_id' => $install->key_id,
            'backup_uuid' => $install->backup_uuid,
            'backup_skipped' => (bool) $install->backup_skipped,
            'source' => $install->source,
        ] + $this->assetAudit() + $this->thirdPartyAudit($install), ModuleInstall::class, (int) $install->id, null, null, (int) ($install->requested_by ?? 0)));

        $install->forceFill([
            'status' => ModuleInstall::STATUS_SUCCEEDED,
            'step_message' => 'Module installed and enabled.',
            'warnings' => $warnings,
            'error_code' => null,
            'finished_at' => Carbon::now(),
        ])->save();

        if (in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            $this->recorder->succeed($task, [
                'install_uuid' => $install->uuid,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'placed' => true,
                'enabled' => true,
                'backup_uuid' => $install->backup_uuid,
                'warnings' => array_values(array_filter(array_map(
                    static fn (mixed $w): ?string => is_array($w) && is_string($w['code'] ?? null) ? $w['code'] : null,
                    $warnings,
                ))),
            ]);
        }

        // Best effort: tell the platform hub what runs here now. Never fails the install.
        try {
            Artisan::call('cms:platform-report');
        } catch (Throwable $e) {
            Log::warning('Module install: platform status report failed.', ['install' => $install->uuid, 'exception' => $e::class]);
        }
    }

    /**
     * Swap the runtime module link to the new version folder and keep the old one in `.previous`.
     *
     * Order keeps the module readable at every moment: the new version's front-end assets are published beside the old
     * ones, the new folder is renamed into `.versions`, the link is replaced atomically, then the old version folder
     * moves to `.previous/<Name>-<old version>`. A module that is a plain folder (not a link) is moved to `.previous`
     * first and the link created after.
     *
     * @return array{name: string, old_version: string, new_version: string, new_target: string, relative: string, link: string, link_target: ?string, previous: string, old_path: string, runtime: string, moved_old: bool, assets: bool}
     *
     * @throws PackageVerificationException MODULE_UPGRADE_NOT_INSTALLED, MODULE_DOWNGRADE, MODULE_SAME_VERSION,
     *                                      MODULE_PLACE_TARGET_EXISTS, MODULE_ASSETS_PUBLISH_FAILED or
     *                                      MODULE_UPGRADE_PLACE_FAILED
     */
    private function placeUpgrade(string $stagedDir, ReleaseManifest $manifest, string $runtimeRoot): array
    {
        $runtimeRoot = rtrim($runtimeRoot, '/');
        $name = $manifest->name;
        $link = $runtimeRoot . '/' . $name;
        $staged = rtrim($stagedDir, '/');
        $stagingRoot = dirname($staged);

        $handover = $this->isHandover($name);
        $releaseVersion = $handover ? $this->folderVersion($this->releasePath() . '/' . $name) : null;
        if ($handover && ($releaseVersion === null || version_compare($manifest->version, $releaseVersion) <= 0)) {
            throw new PackageVerificationException(InstallPreflight::DOWNGRADE, 'package is not newer than the release copy');
        }
        if ($handover && @lstat($link) === false) {
            return $this->placeHandover($staged, $manifest, $runtimeRoot, (string) $releaseVersion);
        }
        if (@lstat($link) === false || ! is_dir($link)) {
            throw new PackageVerificationException(self::ERROR_UPGRADE_NOT_INSTALLED, 'module is not installed in the runtime folder');
        }
        $oldVersion = $this->folderVersion($link);
        if ($oldVersion === null) {
            throw new PackageVerificationException(self::ERROR_UPGRADE_NOT_INSTALLED, 'installed module version can not be read');
        }
        $order = version_compare($manifest->version, $oldVersion);
        if ($order === 0) {
            throw new PackageVerificationException(InstallPreflight::SAME_VERSION, 'module already has this version');
        }
        if ($order < 0) {
            throw new PackageVerificationException(InstallPreflight::DOWNGRADE, 'package is older than the installed module');
        }

        $versions = $runtimeRoot . '/' . RuntimePlacement::VERSIONS_FOLDER;
        $previousRoot = $runtimeRoot . '/' . self::PREVIOUS_FOLDER;
        $relative = RuntimePlacement::VERSIONS_FOLDER . '/' . $name . '-' . $manifest->version;
        $newTarget = $runtimeRoot . '/' . $relative;
        $previous = $previousRoot . '/' . $name . '-' . $oldVersion;
        $linkTarget = is_link($link) ? (string) readlink($link) : null;
        $oldPath = $linkTarget !== null
            ? ($linkTarget !== '' && $linkTarget[0] === '/' ? $linkTarget : $runtimeRoot . '/' . $linkTarget)
            : $link;

        if (@lstat($newTarget) !== false || @lstat($previous) !== false) {
            throw new PackageVerificationException(RuntimePlacement::TARGET_EXISTS, 'version or previous folder already exists');
        }
        foreach ([$versions, $previousRoot] as $dir) {
            if (@lstat($dir) !== false ? (is_link($dir) || ! is_dir($dir)) : (! @mkdir($dir, 0755) && ! is_dir($dir))) {
                throw new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'cannot prepare the versions or previous folder');
            }
        }

        $state = [
            'name' => $name, 'old_version' => $oldVersion, 'new_version' => $manifest->version, 'new_target' => $newTarget, 'relative' => $relative, 'link' => $link,
            'link_target' => $linkTarget, 'previous' => $previous, 'old_path' => $oldPath, 'runtime' => $runtimeRoot, 'moved_old' => false, 'assets' => false,
        ];

        // Publish the new assets while the old version is still live; a failure here has changed nothing else.
        if ($manifest->frontend !== null) {
            $this->assetFiles = RuntimeAssets::publish($name, $manifest->version, $staged . '/' . ReleaseManifest::FRONTEND_BUILD_DIR);
            $state['assets'] = true;
        }

        try {
            if (! @rename($staged, $newTarget)) {
                throw new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'cannot move the new version into the versions folder');
            }
            if ($linkTarget !== null) {
                self::swapLink($runtimeRoot, $name, $relative);
                if (! @rename($oldPath, $previous)) {
                    throw new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'cannot move the old version to the previous folder');
                }
                $state['moved_old'] = true;
                // PHP keeps resolved real paths for ~2 minutes (realpath cache). A link at the old place that points to
                // the moved folder keeps the cached path of long-lived PHP workers valid until they refresh it.
                @symlink('../' . self::PREVIOUS_FOLDER . '/' . $name . '-' . $oldVersion, $oldPath);
            } else {
                if (! @rename($link, $previous)) {
                    throw new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'cannot move the old version to the previous folder');
                }
                $state['moved_old'] = true;
                self::swapLink($runtimeRoot, $name, $relative);
            }
            if ($handover) {
                $this->assertPreferred($name);
            }
        } catch (Throwable $e) {
            $this->restorePrevious($state);
            SafeExtractor::removeTree($staged, $stagingRoot);
            throw $e instanceof PackageVerificationException ? $e : new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'upgrade placement failed unexpectedly');
        }
        SafeExtractor::removeTree($staged, $stagingRoot);

        return $state;
    }

    /**
     * First runtime copy of a listed release module: place the new version under `.versions`, link it, and
     * check RuntimePrecedence now prefers it. The release copy is not touched. Any failure removes what was placed.
     *
     * @return array<string, mixed> the same state shape as {@see self::placeUpgrade()}, with `handover` true
     *
     * @throws PackageVerificationException
     */
    private function placeHandover(string $staged, ReleaseManifest $manifest, string $runtimeRoot, string $releaseVersion): array
    {
        $name = $manifest->name;
        $stagingRoot = dirname($staged);
        $versions = $runtimeRoot . '/' . RuntimePlacement::VERSIONS_FOLDER;
        $relative = RuntimePlacement::VERSIONS_FOLDER . '/' . $name . '-' . $manifest->version;
        $newTarget = $runtimeRoot . '/' . $relative;
        $link = $runtimeRoot . '/' . $name;

        if (@lstat($newTarget) !== false) {
            throw new PackageVerificationException(RuntimePlacement::TARGET_EXISTS, 'version folder already exists');
        }
        if (@lstat($versions) !== false ? (is_link($versions) || ! is_dir($versions)) : (! @mkdir($versions, 0755) && ! is_dir($versions))) {
            throw new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'cannot prepare the versions folder');
        }

        $state = [
            'name' => $name, 'old_version' => $releaseVersion, 'new_version' => $manifest->version, 'new_target' => $newTarget, 'relative' => $relative, 'link' => $link,
            'link_target' => null, 'previous' => '', 'old_path' => $link, 'runtime' => $runtimeRoot, 'moved_old' => false, 'assets' => false, 'handover' => true,
        ];

        if ($manifest->frontend !== null) {
            $this->assetFiles = RuntimeAssets::publish($name, $manifest->version, $staged . '/' . ReleaseManifest::FRONTEND_BUILD_DIR);
            $state['assets'] = true;
        }

        try {
            if (! @rename($staged, $newTarget)) {
                throw new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'cannot move the new version into the versions folder');
            }
            self::swapLink($runtimeRoot, $name, $relative);
            $this->assertPreferred($name);
        } catch (Throwable $e) {
            $this->restorePrevious($state);
            SafeExtractor::removeTree($staged, $stagingRoot);
            throw $e instanceof PackageVerificationException ? $e : new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'handover placement failed unexpectedly');
        }
        SafeExtractor::removeTree($staged, $stagingRoot);

        return $state;
    }

    /**
     * After a handover placement the site must prefer the new runtime copy, or the next steps would run the release copy.
     *
     * @throws PackageVerificationException MODULE_HANDOVER_NOT_PREFERRED
     */
    private function assertPreferred(string $name): void
    {
        RuntimePrecedence::forget();
        $decision = RuntimePrecedence::decide(dirname($this->releasePath()), $name);
        RuntimePrecedence::forget();
        if (! $decision['runtime']) {
            Log::warning('Module handover: the placed runtime copy would not run.', ['module' => $name, 'reason' => $decision['reason']]);
            throw new PackageVerificationException(self::ERROR_HANDOVER_NOT_PREFERRED, 'the placed runtime copy is not preferred: ' . $decision['reason']);
        }
    }

    /**
     * True for a release module listed in the project's runtime_modules.json.
     */
    private function isHandover(string $name): bool
    {
        return is_dir($this->releasePath() . '/' . $name)
            && in_array($name, RuntimePrecedence::listed(dirname($this->releasePath())), true);
    }

    /**
     * Atomically point `<runtime>/<name>` at $relative through a temporary link.
     *
     * @throws PackageVerificationException MODULE_UPGRADE_PLACE_FAILED
     */
    private static function swapLink(string $runtimeRoot, string $name, string $relative): void
    {
        $temp = $runtimeRoot . '/.' . $name . '.link-' . bin2hex(random_bytes(6));
        if (! @symlink($relative, $temp)) {
            throw new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'cannot create the module link');
        }
        if (! @rename($temp, $runtimeRoot . '/' . $name)) {
            @unlink($temp);
            throw new PackageVerificationException(self::ERROR_UPGRADE_PLACE, 'cannot publish the module link');
        }
    }

    /**
     * Put the old version back (best effort; each step is logged on failure) and drop the new version folder.
     *
     * @param array<string, mixed> $state from {@see self::placeUpgrade()}
     */
    private function restorePrevious(array $state): void
    {
        try {
            if (($state['handover'] ?? false) === true && $state['link_target'] === null && ! $state['moved_old']) {
                // First runtime copy of a release module: drop the link so the release copy runs again.
                if (is_link($state['link'])) {
                    @unlink($state['link']);
                }
            }
            if ($state['moved_old']) {
                if ($state['link_target'] !== null && is_link($state['old_path'])) {
                    @unlink($state['old_path']);
                }
                if ($state['link_target'] === null) {
                    if (is_link($state['link'])) {
                        @unlink($state['link']);
                    }
                    @rename($state['previous'], $state['link']);
                } else {
                    @rename($state['previous'], $state['old_path']);
                }
            }
            if ($state['link_target'] !== null) {
                self::swapLink($state['runtime'], $state['name'], $state['link_target']);
            }
            SafeExtractor::removeTree($state['new_target'], $state['runtime'] . '/' . RuntimePlacement::VERSIONS_FOLDER);
        } catch (Throwable $e) {
            Log::error('Module upgrade could not restore the previous version.', ['module' => $state['name'], 'exception' => $e::class]);
        }
        if (($state['assets'] ?? false) && ! RuntimeAssets::remove($state['name'], $state['new_version'])) {
            Log::error('Module upgrade could not remove the new front-end asset version.', ['module' => $state['name'], 'version' => $state['new_version']]);
        }
    }

    /**
     * Version in module.json (then composer.json) of a module folder.
     */
    private function folderVersion(string $dir): ?string
    {
        foreach (['module.json', 'composer.json'] as $file) {
            $raw = @file_get_contents($dir . DIRECTORY_SEPARATOR . $file);
            $decoded = $raw === false ? null : json_decode($raw, true);
            if (is_array($decoded) && is_string($decoded['version'] ?? null) && $decoded['version'] !== '') {
                return $decoded['version'];
            }
        }

        return null;
    }

    /**
     * Upgrade steps after placement: migrate (new migrations only), sync, caches, admin rebuild, audit.
     *
     * The module keeps the enabled state it had; nothing is enabled or disabled. A failure restores the old version.
     *
     * @param array<string, mixed> $upgrade from {@see self::placeUpgrade()}
     */
    private function upgradeActivate(ModuleInstall $install, ReleaseManifest $manifest, BackgroundTask $task, array $upgrade): void
    {
        $code = self::ERROR_MIGRATE;
        try {
            ModuleFacade::resetModules();
            $this->step($install, $task, ModuleInstall::STATUS_MIGRATING, 75, 'Running the new module migrations.');
            $this->artisanStep(['module:migrate', $manifest->name, '--force'], self::MIGRATE_TIMEOUT);

            $code = self::ERROR_ACTIVATION;
            $this->recorder->progress($task, 85, 'text.background_tasks.step.module_install.syncing');
            $this->artisanStep(['cms:modules-sync', $manifest->name, '--skip-translations'], self::STEP_TIMEOUT);
            $code = self::ERROR_INSTALL_COMMAND;
            $this->runInstallCommands($manifest->name);
            $code = self::ERROR_ACTIVATION;
            $this->artisanStep(['cms:translation-sync', $manifest->name], self::STEP_TIMEOUT);
            $this->artisanStep(['cms:translation-generate', '--public-only'], self::STEP_TIMEOUT);
        } catch (Throwable $e) {
            Log::warning('Module upgrade activation failed.', ['install' => $install->uuid, 'module' => $manifest->name, 'code' => $code, 'exception' => $e::class]);
            $this->failUpgrade($install, $manifest, $task, $upgrade, $code);

            return;
        }

        $this->recorder->progress($task, 92, 'text.background_tasks.step.module_install.refreshing');
        try {
            (new RefreshRuntimeCachesJob())->handle($this->artisan, app(Kernel::class));
        } catch (Throwable $e) {
            Log::warning('Module upgrade: cache refresh did not finish.', ['install' => $install->uuid, 'exception' => $e::class]);
        }

        $warnings = is_array($install->warnings) ? $install->warnings : [];
        if ($manifest->customCode) {
            $this->step($install, $task, ModuleInstall::STATUS_BUILDING_ADMIN, 95, 'Rebuilding the admin.');
            try {
                $built = $this->adminRebuilder->rebuild();
            } catch (Throwable $e) {
                Log::warning('Module upgrade: admin rebuild threw.', ['install' => $install->uuid, 'exception' => $e::class]);
                $built = ['ok' => false, 'errorCode' => AdminRebuilder::ERROR_FAILED];
            }
            if (! $built['ok']) {
                $notDeployed = ($built['errorCode'] ?? null) === AdminRebuilder::ERROR_NOT_DEPLOYED;
                $warnings[] = [
                    'code' => $notDeployed ? AdminRebuilder::ERROR_NOT_DEPLOYED : AdminRebuilder::ERROR_FAILED,
                    'message' => 'The module is upgraded, but the admin could not be rebuilt. A Webmaster can retry the admin rebuild before its screens appear.',
                    'details' => [],
                ];
            }
        }

        // Older previous folders go only now, after this upgrade succeeded.
        $previousRoot = $upgrade['runtime'] . '/' . self::PREVIOUS_FOLDER;
        foreach (@scandir($previousRoot) ?: [] as $entry) {
            if (str_starts_with($entry, $manifest->name . '-') && $previousRoot . '/' . $entry !== $upgrade['previous']) {
                SafeExtractor::removeTree($previousRoot . '/' . $entry, $previousRoot);
                $stub = $upgrade['runtime'] . '/' . RuntimePlacement::VERSIONS_FOLDER . '/' . $entry;
                if (is_link($stub)) {
                    @unlink($stub);
                }
            }
        }
        // Published asset versions other than the new one (the old one included) go only now, too.
        foreach (RuntimeAssets::versions($manifest->name) as $assetVersion) {
            if ($assetVersion !== $manifest->version && ! RuntimeAssets::remove($manifest->name, $assetVersion)) {
                Log::warning('Module upgrade could not remove an old front-end asset version.', ['module' => $manifest->name, 'version' => $assetVersion]);
            }
        }

        event(new AuditLogEvent('module.upgraded', [
            'name' => $manifest->name,
            'version' => $manifest->version,
            'previous_version' => $upgrade['old_version'],
            'zip_sha256' => $install->zip_sha256,
            'key_id' => $install->key_id,
            'backup_uuid' => $install->backup_uuid,
            'backup_skipped' => (bool) $install->backup_skipped,
            'source' => $install->source,
        ] + $this->assetAudit() + $this->thirdPartyAudit($install), ModuleInstall::class, (int) $install->id, null, null, (int) ($install->requested_by ?? 0)));

        $install->forceFill([
            'status' => ModuleInstall::STATUS_SUCCEEDED,
            'step_message' => 'Module upgraded.',
            'warnings' => $warnings,
            'error_code' => null,
            'finished_at' => Carbon::now(),
        ])->save();

        if (in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            $this->recorder->succeed($task, [
                'install_uuid' => $install->uuid,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'previous_version' => $upgrade['old_version'],
                'placed' => true,
                'backup_uuid' => $install->backup_uuid,
                'warnings' => array_values(array_filter(array_map(
                    static fn (mixed $w): ?string => is_array($w) && is_string($w['code'] ?? null) ? $w['code'] : null,
                    $warnings,
                ))),
            ]);
        }

        try {
            Artisan::call('cms:platform-report');
        } catch (Throwable $e) {
            Log::warning('Module upgrade: platform status report failed.', ['install' => $install->uuid, 'exception' => $e::class]);
        }
    }

    /**
     * Failure after placement: restore the old folder, link and stored version; disable nothing; fail row and task; audit.
     *
     * @param array<string, mixed> $upgrade from {@see self::placeUpgrade()}
     */
    private function failUpgrade(ModuleInstall $install, ReleaseManifest $manifest, BackgroundTask $task, array $upgrade, string $code): void
    {
        $this->restorePrevious($upgrade);
        try {
            $this->activator()->setVersion($manifest->name, $upgrade['old_version']);
        } catch (Throwable $e) {
            Log::error('Module upgrade could not restore the stored version.', ['install' => $install->uuid, 'exception' => $e::class]);
        }
        try {
            ModuleFacade::resetModules();
        } catch (Throwable) {
            // Best effort.
        }

        $status = $install->status;
        $install->forceFill([
            'status' => ModuleInstall::STATUS_FAILED,
            'error_code' => mb_substr($code, 0, 100),
            'step_message' => mb_substr('Upgrade failed while ' . str_replace('_', ' ', $status) . ' (' . $code . '). The previous version ' . $upgrade['old_version']
                . ' was restored and the module state is unchanged. ' . $this->restoreHint($install, 'upgrade'), 0, 500),
            'finished_at' => Carbon::now(),
        ])->save();

        if (in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            $this->recorder->fail($task, 'text.background_tasks.step.module_install.failed', ['code' => $code]);
        }

        event(new AuditLogEvent('module.upgrade_failed', [
            'name' => $manifest->name,
            'version' => $manifest->version,
            'previous_version' => $upgrade['old_version'],
            'error_code' => $code,
            'zip_sha256' => $install->zip_sha256,
            'key_id' => $install->key_id,
            'backup_uuid' => $install->backup_uuid,
            'backup_skipped' => (bool) $install->backup_skipped,
            'source' => $install->source,
        ], ModuleInstall::class, (int) $install->id, null, null, (int) ($install->requested_by ?? 0)));
    }

    /**
     * Step 1 skipped by choice: record it on the row and in the audit log with the requester.
     */
    private function skipBackup(ModuleInstall $install): void
    {
        $install->forceFill(['backup_uuid' => null, 'step_message' => 'Safety backup skipped by the person who started this.'])->save();

        event(new AuditLogEvent('module.install_backup_skipped', [
            'name' => $install->name,
            'version' => $install->version,
            'kind' => $install->kind ?? ModuleInstall::KIND_INSTALL,
            'source' => $install->source,
            'requested_by' => (int) ($install->requested_by ?? 0),
        ], ModuleInstall::class, (int) $install->id, null, null, (int) ($install->requested_by ?? 0)));
    }

    /**
     * Last sentence of a failure message: restore the pre-install backup, or, when it was skipped, the site's own.
     *
     * @param string $kind `install` or `upgrade`
     */
    private function restoreHint(ModuleInstall $install, string $kind): string
    {
        if ($install->backup_skipped) {
            return 'No backup was taken before the ' . $kind . ' (skipped by choice): if data changed, restore it from the site\'s own scheduled backups.';
        }

        return 'The database was backed up before the ' . $kind . ' (backup ' . (string) $install->backup_uuid . '): restore it if data changed.';
    }

    /**
     * Failure in steps 6–7: disable the module again, keep its files, fail the row and task, audit the failure.
     */
    private function failActivation(ModuleInstall $install, ReleaseManifest $manifest, BackgroundTask $task, ?User $user, string $code, Throwable $e): void
    {
        Log::warning('Module install activation failed.', ['install' => $install->uuid, 'module' => $manifest->name, 'code' => $code, 'exception' => $e::class]);

        try {
            $this->statuses->change($manifest->name, false, $user, 'install_failed');
        } catch (Throwable $disable) {
            Log::error('Module install could not disable the module after a failure.', ['install' => $install->uuid, 'module' => $manifest->name, 'exception' => $disable::class]);
        }

        $status = $install->status;
        $backup = (string) $install->backup_uuid;
        $install->forceFill([
            'status' => ModuleInstall::STATUS_FAILED,
            'error_code' => $code,
            'step_message' => mb_substr('Install failed while ' . str_replace('_', ' ', $status) . ' (' . $code . '). The module was disabled again; its files stay in place. '
                . $this->restoreHint($install, 'install'), 0, 500),
            'finished_at' => Carbon::now(),
        ])->save();

        if (in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            $this->recorder->fail($task, $install->backup_skipped
                ? 'text.background_tasks.step.module_install.failed_disabled_no_backup'
                : 'text.background_tasks.step.module_install.failed_disabled', [
                'code' => $code,
                'backup' => $backup,
            ]);
        }

        event(new AuditLogEvent('module.install_failed', [
            'name' => $manifest->name,
            'version' => $manifest->version,
            'error_code' => $code,
            'zip_sha256' => $install->zip_sha256,
            'key_id' => $install->key_id,
            'backup_uuid' => $install->backup_uuid,
            'backup_skipped' => (bool) $install->backup_skipped,
            'source' => $install->source,
        ], ModuleInstall::class, (int) $install->id, null, null, (int) ($install->requested_by ?? 0)));
    }

    /**
     * First install, after placement: publish the package's front-end assets. On failure the placed version folder and
     * the module link are removed again, so the runtime folder is as before, and the failure is rethrown.
     *
     * @throws PackageVerificationException MODULE_ASSETS_PUBLISH_FAILED
     */
    private function publishInstallAssets(ReleaseManifest $manifest, string $placed, string $runtimeRoot): void
    {
        if ($manifest->frontend === null) {
            return;
        }

        try {
            $this->assetFiles = RuntimeAssets::publish($manifest->name, $manifest->version, $placed . '/' . ReleaseManifest::FRONTEND_BUILD_DIR);
        } catch (Throwable $e) {
            $runtimeRoot = rtrim($runtimeRoot, '/');
            $link = $runtimeRoot . '/' . $manifest->name;
            if (is_link($link)) {
                @unlink($link);
            }
            SafeExtractor::removeTree($placed, $runtimeRoot . '/' . RuntimePlacement::VERSIONS_FOLDER);
            clearstatcache(true);

            throw $e instanceof PackageVerificationException
                ? $e
                : new PackageVerificationException(self::ERROR_ASSETS_PUBLISH, 'publishing the front-end assets failed unexpectedly');
        }
    }

    /**
     * Audit fields of the published front-end assets: the file count only (never names); empty when none.
     *
     * @return array<string, int>
     */
    private function assetAudit(): array
    {
        return $this->assetFiles !== null ? ['asset_files' => $this->assetFiles] : [];
    }

    /**
     * The `install.artisan` commands of the module.json in $moduleDir (signed, already hash-checked by the extractor).
     *
     * `install` may be absent; when present it is an object whose only key is `artisan`, a list of at most
     * MAX_INSTALL_COMMANDS strings, each {@see self::isInstallCommand()}. Anything else refuses the package.
     *
     * @return list<string>
     *
     * @throws PackageVerificationException MODULE_RUNTIME_INVALID
     */
    public static function installCommands(string $moduleDir, string $name): array
    {
        $raw = @file_get_contents(rtrim($moduleDir, '/') . '/module.json');
        $module = is_string($raw) ? json_decode($raw, true) : null;
        if (! is_array($module) || ! array_key_exists('install', $module)) {
            return [];
        }

        $install = $module['install'];
        $commands = is_array($install) && ($install === [] || ! array_is_list($install)) && array_diff(array_keys($install), ['artisan']) === []
            ? ($install['artisan'] ?? [])
            : null;
        $valid = is_array($commands) && array_is_list($commands) && count($commands) <= self::MAX_INSTALL_COMMANDS;
        foreach ($valid ? $commands : [] as $command) {
            $valid = $valid && is_string($command) && self::isInstallCommand($name, $command);
        }
        if (! $valid) {
            Log::warning('Module install refused declared install commands.', ['module' => $name]);
            throw new PackageVerificationException(self::ERROR_RUNTIME_INVALID, 'install.artisan must list commands in the module namespace without arguments');
        }

        return $commands;
    }

    /**
     * Whether $command is `cms:<module-kebab>:<name>` of module $name with no arguments (the manifest lint rule).
     */
    public static function isInstallCommand(string $name, string $command): bool
    {
        $prefix = 'cms:' . Str::kebab($name) . ':';

        return str_starts_with($command, $prefix)
            && preg_match('/^[a-z0-9][a-z0-9:_-]*$/', substr($command, strlen($prefix))) === 1;
    }

    /**
     * Run the validated `install.artisan` commands in order, each re-checked just before it runs.
     *
     * A declared name never reaches artisan directly, because artisan resolves abbreviations (F2): each one
     * runs through {@see ModuleInstallCommandRunCommand}, which runs it only when a command is registered under exactly
     * that name, and fails otherwise.
     *
     * @throws RuntimeException when a command is refused or exits non-zero
     */
    private function runInstallCommands(string $name): void
    {
        foreach ($this->installCommands as $command) {
            if (! self::isInstallCommand($name, $command)) {
                throw new RuntimeException('install command refused');
            }
            $this->artisanStep([ModuleInstallCommandRunCommand::NAME, $name, $command], self::STEP_TIMEOUT);
        }
    }

    /**
     * Run one artisan command in a fresh process; a non-zero exit is a failure (its truncated error output is logged).
     *
     * @param list<string> $args
     */
    private function artisanStep(array $args, int $timeout): void
    {
        $result = $this->artisan->run($args, $timeout);
        if ($result->successful()) {
            return;
        }

        Log::warning('Module install: artisan step failed.', [
            'command' => implode(' ', array_slice($args, 0, ($args[0] ?? '') === ModuleInstallCommandRunCommand::NAME ? 3 : 1)),
            'exit_code' => $result->exitCode(),
            'error_output' => ArtisanSubprocess::truncate((string) $result->errorOutput()),
        ]);

        throw new RuntimeException('artisan ' . ($args[0] ?? '') . ' exited with code ' . (string) $result->exitCode());
    }

    /**
     * The user who requested the install, when it still exists.
     */
    private function requester(ModuleInstall $install): ?User
    {
        return $install->requested_by !== null ? User::query()->find($install->requested_by) : null;
    }

    /**
     * After the enable step: when it left the install open, finish it with status `placing` done and close the task.
     */
    private function closeAfterPlacement(ModuleInstall $install, BackgroundTask $task, ReleaseManifest $manifest): void
    {
        if (! $install->isUnfinished()) {
            return;
        }

        $install->forceFill([
            'step_message' => 'Module files placed. Enabling is not available yet.',
            'finished_at' => Carbon::now(),
        ])->save();

        if (in_array($task->status, [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING], true)) {
            $this->recorder->succeed($task, [
                'install_uuid' => $install->uuid,
                'name' => $manifest->name,
                'version' => $manifest->version,
                'placed' => true,
                'enabled' => false,
                'backup_uuid' => $install->backup_uuid,
            ]);
        }
    }

    /**
     * Update status, step message and task progress.
     *
     * The install row keeps the English `$message` (API and logs); the task gets an admin i18n key derived from the
     * status (`text.background_tasks.step.module_install.<status>`, `uploading_read` for an uploaded package).
     */
    private function step(ModuleInstall $install, BackgroundTask $task, string $status, int $percent, string $message): void
    {
        $install->forceFill(['status' => $status, 'step_message' => $message])->save();
        $key = $status === ModuleInstall::STATUS_DOWNLOADING && $install->source === ModuleInstall::SOURCE_UPLOAD ? 'uploading_read' : $status;
        $this->recorder->progress($task, $percent, 'text.background_tasks.step.module_install.' . $key);
    }

    /**
     * Ask the hub for a download ticket and check its shape.
     *
     * `licensee` is the per-grant copy the hub prepared, or null for the signed package itself.
     *
     * @return array{download_url: string, signature_json: string, expires_at: ?string, licensee: array{zip_sha256: string, zip_size: int}|null}
     */
    private function ticket(ModuleInstall $install): array
    {
        $client = $this->clientFactory !== null ? ($this->clientFactory)() : new PlatformHubClient();
        $answer = $client->post(self::TICKET_PATH, ['name' => $install->name, 'version' => $install->version, 'licensee' => true]);

        $url = $answer['download_url'] ?? null;
        $sig = $answer['signature_json'] ?? null;
        if (! is_string($url) || $url === '' || ! is_string($sig) || $sig === '') {
            throw new PackageVerificationException(self::ERROR_TICKET, 'download ticket has an unexpected shape');
        }

        $licensee = $answer['licensee'] ?? null;
        if ($licensee !== null && (! is_array($licensee)
            || ! is_string($licensee['zip_sha256'] ?? null) || preg_match('/^[0-9a-f]{64}$/', $licensee['zip_sha256']) !== 1
            || ! is_int($licensee['zip_size'] ?? null) || $licensee['zip_size'] < 1)) {
            throw new PackageVerificationException(self::ERROR_TICKET, 'download ticket has an unexpected shape');
        }

        return [
            'download_url' => $url,
            'signature_json' => $sig,
            'expires_at' => is_string($answer['expires_at'] ?? null) ? $answer['expires_at'] : null,
            'licensee' => $licensee === null ? null : ['zip_sha256' => $licensee['zip_sha256'], 'zip_size' => $licensee['zip_size']],
        ];
    }

    /**
     * Stream the zip from the hub into $target, refusing other hosts and cutting off past $maxBytes.
     */
    private function download(string $url, string $target, int $maxBytes): void
    {
        [$signedUrl, $path, $query] = $this->hubUrl($url);

        // Same credentials as PlatformHubClient: `.env` first, then the encrypted database row.
        $credentials = app(HubCredentials::class);
        $domain = $credentials->domain();
        $timestamp = Carbon::now()->getTimestamp();
        $nonce = bin2hex(random_bytes(16));
        $signature = PlatformSignature::request($credentials->secret(), 'GET', $path, $query, $timestamp, $nonce, '');

        try {
            $response = Http::withoutRedirecting()
                ->connectTimeout(5)
                ->timeout(300)
                ->withOptions(['stream' => true])
                ->withHeaders([
                    PlatformSignature::HEADER_DOMAIN => $domain,
                    PlatformSignature::HEADER_TIMESTAMP => (string) $timestamp,
                    PlatformSignature::HEADER_NONCE => $nonce,
                    PlatformSignature::HEADER_SIGNATURE => $signature,
                ])
                ->get($signedUrl);
        } catch (ConnectionException) {
            throw new PackageVerificationException(self::ERROR_DOWNLOAD_FAILED, 'package download could not connect');
        }

        if ($response->status() !== 200) {
            throw new PackageVerificationException(self::ERROR_DOWNLOAD_FAILED, 'package download answered HTTP ' . $response->status());
        }
        $declared = $response->header('Content-Length');
        if ($declared !== '' && ctype_digit($declared) && (int) $declared > $maxBytes) {
            throw new PackageVerificationException(self::ERROR_DOWNLOAD_TOO_LARGE, 'package is larger than the signed manifest says');
        }

        $out = @fopen($target, 'xb');
        if ($out === false) {
            throw new PackageVerificationException(self::ERROR_STAGING, 'cannot create the package file');
        }
        @chmod($target, 0600);

        $body = $response->toPsrResponse()->getBody();
        $written = 0;
        try {
            while (! $body->eof()) {
                $chunk = $body->read(self::CHUNK);
                if ($chunk === '') {
                    continue;
                }
                $written += strlen($chunk);
                if ($written > $maxBytes) {
                    throw new PackageVerificationException(self::ERROR_DOWNLOAD_TOO_LARGE, 'package is larger than the signed manifest says');
                }
                if (fwrite($out, $chunk) !== strlen($chunk)) {
                    throw new PackageVerificationException(self::ERROR_STAGING, 'cannot write the package file');
                }
            }
        } catch (Throwable $e) {
            fclose($out);
            @unlink($target);
            throw $e instanceof PackageVerificationException
                ? $e
                : new PackageVerificationException(self::ERROR_DOWNLOAD_FAILED, 'package download was interrupted');
        } finally {
            $body->close();
        }
        fclose($out);
    }

    /**
     * Check the download URL against the configured hub and return [canonical URL, path, canonical query].
     *
     * @return array{0: string, 1: string, 2: string}
     */
    private function hubUrl(string $url): array
    {
        $hub = parse_url(rtrim((string) config('ispa-cms.platform_hub_url', 'https://ispa.tech'), '/'));
        $got = parse_url($url);
        if (! is_array($hub) || ! is_array($got) || isset($got['user']) || isset($got['pass']) || isset($got['fragment'])) {
            throw new PackageVerificationException(self::ERROR_DOWNLOAD_HOST, 'download URL is not on the platform hub');
        }

        $same = strtolower((string) ($got['scheme'] ?? '')) === strtolower((string) ($hub['scheme'] ?? ''))
            && strtolower((string) ($got['host'] ?? '')) === strtolower((string) ($hub['host'] ?? ''))
            && ($got['port'] ?? null) === ($hub['port'] ?? null)
            && in_array(strtolower((string) ($got['scheme'] ?? '')), ['https', 'http'], true);
        if (! $same || ($got['host'] ?? '') === '') {
            throw new PackageVerificationException(self::ERROR_DOWNLOAD_HOST, 'download URL is not on the platform hub');
        }

        $path = PlatformSignature::normalizePath((string) ($got['path'] ?? '/'));
        parse_str((string) ($got['query'] ?? ''), $params);
        $query = PlatformSignature::canonicalQuery($params);
        $base = $got['scheme'] . '://' . $got['host'] . (isset($got['port']) ? ':' . $got['port'] : '');

        return [$base . $path . ($query !== '' ? '?' . $query : ''), $path, $query];
    }

    /**
     * Signature JSON of a staged upload (a regular file, at most InstallPreflight::SIGNATURE_MAX_BYTES).
     */
    private function stagedSignature(string $staging): string
    {
        $path = $staging . DIRECTORY_SEPARATOR . self::UPLOAD_SIGNATURE;
        clearstatcache(true, $path);
        $size = is_link($path) || ! is_file($path) ? false : @filesize($path);
        $json = $size !== false && $size <= InstallPreflight::SIGNATURE_MAX_BYTES ? @file_get_contents($path) : false;
        if (! is_string($json) || $json === '') {
            throw new PackageVerificationException(self::ERROR_STAGING, 'the uploaded package is missing from staging');
        }

        return $json;
    }

    /**
     * Create `<runtime>/.staging` (0700) and an empty per-install folder inside it.
     */
    private static function prepareStaging(string $stagingRoot, string $staging): void
    {
        if (! is_dir($stagingRoot) && ! @mkdir($stagingRoot, 0700) && ! is_dir($stagingRoot)) {
            throw new PackageVerificationException(self::ERROR_STAGING, 'cannot create the staging folder');
        }
        if (is_link($stagingRoot) || @lstat($staging) !== false || ! @mkdir($staging, 0700)) {
            throw new PackageVerificationException(self::ERROR_STAGING, 'cannot create the install staging folder');
        }
    }

    /**
     * Machine code of a failure; unexpected exceptions get a generic code (their text stays in the log only).
     */
    private function codeOf(Throwable $e): string
    {
        if ($e instanceof BackupRequiredException) {
            return BackupRequiredException::CODE;
        }
        if ($e instanceof PackageVerificationException) {
            return $e->errorCode;
        }
        if ($e instanceof PlatformHubException) {
            return self::ERROR_HUB;
        }

        Log::error('Module install hit an unexpected error.', ['exception' => $e::class]);

        return self::ERROR_UNEXPECTED;
    }

    /**
     * The install's background task; one is created when the row has none.
     */
    private function task(ModuleInstall $install): BackgroundTask
    {
        $task = $this->findTask($install);
        if ($task === null) {
            $task = $this->recorder->start(ModuleInstall::TASK_TYPE, 'Core', $install->requested_by);
            $install->forceFill(['background_task_uuid' => $task->uuid])->save();
        }

        return $task;
    }

    /**
     * Look up the install's background task by uuid.
     */
    private function findTask(ModuleInstall $install): ?BackgroundTask
    {
        return $install->background_task_uuid !== null
            ? BackgroundTask::query()->where('uuid', $install->background_task_uuid)->first()
            : null;
    }

    /**
     * Module status store.
     */
    private function activator(): DatabaseActivator
    {
        return $this->activator ?? new DatabaseActivator(app());
    }

    /**
     * Absolute path of the release modules folder.
     */
    private function releasePath(): string
    {
        return rtrim($this->releaseModulesPath ?? (string) config('modules.paths.modules', base_path('cms')), '/');
    }
}
