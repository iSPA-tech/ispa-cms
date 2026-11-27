<?php

use App\Support\Modules\ModuleStatus;
use Illuminate\Support\Facades\Route;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Controllers\V2\Public\AgentAnswerController;
use Cms\Core\Http\Middleware\AgentAnswerEnvelope;
use Illuminate\Routing\Middleware\ThrottleRequests;
use App\Http\Controllers\Website\UtilitiesController as WebsiteUtilitiesController;
use Cms\Core\Http\Controllers\V2\Admin\AiRuntimeController;
use Cms\Core\Http\Controllers\V2\Admin\AuthController;
use Cms\Core\Http\Controllers\V2\Admin\BackgroundTaskController;
use Cms\Core\Http\Controllers\V2\Admin\NotificationController;
use Cms\Core\Http\Controllers\V2\Admin\BackupController;
use Cms\Core\Http\Controllers\V2\Admin\BackupOAuthController;
use Cms\Core\Http\Controllers\V2\Admin\UserController;
use Cms\Core\Http\Controllers\V2\Admin\UserTagController;
use Cms\Core\Http\Controllers\V2\Admin\RoleController;
use Cms\Core\Http\Controllers\V2\Admin\MenuController;
use Cms\Core\Http\Controllers\V2\Admin\BulkController;
use Cms\Core\Http\Controllers\V2\Admin\ResourceSchemaController;
use Cms\Core\Http\Controllers\V2\Admin\CustomFieldController;
use Cms\Core\Http\Controllers\V2\Admin\StarterPackController;
use Cms\Core\Http\Controllers\V2\Admin\RegistryController;
use Cms\Core\Http\Controllers\V2\Admin\RecordHistoryController;
use Cms\Core\Http\Controllers\V2\Admin\RecordNoteController;
use Cms\Core\Http\Controllers\V2\Admin\SavedViewController;
use Cms\Core\Http\Controllers\V2\Admin\EmailController;
use Cms\Core\Http\Controllers\V2\Admin\FieldController;
use Cms\Core\Http\Controllers\V2\Admin\FormOptionSourceController;
use Cms\Core\Http\Controllers\V2\Public\SiteConnectController;
use Cms\Core\Http\Controllers\V2\Admin\SiteConnectController as AdminSiteConnectController;
use Cms\Core\Http\Controllers\V2\Admin\HubOneTapConnectController;
use Cms\Core\Http\Controllers\V2\Public\SiteStatusController;
use Cms\Core\Http\Controllers\V2\Admin\AgenticCatalogController;
use Cms\Core\Http\Controllers\V2\Admin\AgenticRunController;
use Cms\Core\Http\Controllers\V2\Admin\AgenticApprovalController;
use Cms\Core\Http\Controllers\V2\Admin\WorkflowRunController;
use Cms\Core\Http\Controllers\V2\Admin\WorkflowCredentialController;
use Cms\Core\Http\Controllers\V2\Admin\AgenticScheduleController;
use Cms\Core\Http\Controllers\V2\Admin\AgenticProvisioningController;
use Cms\Core\Http\Controllers\V2\Admin\UploadController;
use Cms\Core\Http\Controllers\V2\Admin\WidgetController;
use Cms\Core\Http\Controllers\V2\Admin\ModuleCatalogController;
use Cms\Core\Http\Controllers\V2\Admin\ModuleController;
use Cms\Core\Http\Controllers\V2\Admin\CoreUpdateController;
use Cms\Core\Http\Controllers\V2\Admin\ModuleInstallController;
use Cms\Core\Http\Controllers\V2\Admin\ModuleIntegrityController;
use Cms\Core\Http\Controllers\V2\Admin\ModuleSupportController;
use Cms\Core\Http\Controllers\V2\Admin\AccountController;
use Cms\Core\Http\Controllers\V2\Admin\SettingController;
use Cms\Core\Http\Controllers\V2\Admin\StorageController;
use Cms\Core\Http\Controllers\ImportDefinitionController;
use Cms\Core\Http\Controllers\V2\Admin\UtilitiesController;
use Cms\Core\Http\Controllers\V2\Admin\LanguageController;
use Cms\Core\Http\Controllers\V2\Admin\CategoryController;
use Cms\Core\Http\Controllers\V2\Admin\MenuItemController;
use Cms\Core\Http\Controllers\V2\Admin\TranslateController;
use Cms\Core\Http\Controllers\V2\Admin\EnumerateController;
use Cms\Core\Http\Controllers\V2\Admin\MetaClassController;
use Cms\Core\Http\Controllers\V2\Admin\MetaValueController;
use Cms\Core\Http\Controllers\V2\Admin\DashboardController;
use Cms\Core\Http\Controllers\V2\Admin\PermissionController;
use Cms\Core\Http\Controllers\V2\Admin\WidgetInputController;
use Cms\Core\Http\Controllers\V2\Admin\WidgetThemeController;
use Cms\Core\Http\Controllers\V2\Admin\EnumerateTypeController;
use Cms\Core\Http\Controllers\V2\Admin\MetaAttributeController;
use Cms\Core\Http\Controllers\V2\Admin\EmailTemplateController;
use Cms\Core\Http\Controllers\V2\Admin\PermissionCatalogController;

$moduleIsEnabled = static function (string $module): bool {
    return ModuleStatus::isEnabled($module);
};

// Public i18n packs: one admin page load fetches ~15 shards, so they must not drain the shared
// per-IP `api` budget (60/min, 429 after a few reloads). They carry their own limit instead;
// `throttle.route` keeps it per route template. Payload and Cache-Control are unchanged.
Route::withoutMiddleware(ThrottleRequests::class . ':api')
    ->middleware('throttle.route:600,1')
    ->group(static function (): void {
        Route::get('i18n/app/manifest.json', [AdminController::class, 'appI18nManifest']);
        Route::get('i18n/{file}', [AdminController::class, 'locales']);
        Route::get('{version}/i18n/{path}/{locale}/{character}.json', [AdminController::class, 'locales']);
    });
//  QR Generator
Route::get('qr', [AdminController::class, 'QrGenerate'])->middleware('throttle:qr-public');

Route::group(['prefix' => 'v5.x', 'as' => 'v5.'], static function () {
    // Admin APIs routes: staff with an admin token, like every other admin group
    Route::middleware(['auth:sanctum', 'api.admin'])->prefix('admin')->group(static function () {

    });

    // Public APIs routes
    Route::prefix('public')->group(function () {

    });
});

Route::group(['prefix' => 'v2', 'as' => 'v2.'], static function () use ($moduleIsEnabled) {
    // Public inbound webhook of a `trigger_webhook` workflow project (lane N3): no session, the per-project secret
    // is checked before any work; throttled per IP here and per project in WebhookTriggerService.
    Route::post('workflow/webhooks/{token}', \Cms\Core\Http\Controllers\Workflow\WorkflowWebhookController::class)
        ->where('token', '[A-Za-z0-9]{32,64}')
        ->middleware('throttle.route:120,1,workflow-webhook:')
        ->name('workflow.webhook');

    // Admin APIs routes
    Route::prefix('admin')->group(static function () use ($moduleIsEnabled) {
        Route::get('qr', [AdminController::class, 'QrGenerate'])
            ->middleware(['auth:sanctum', 'api.admin', 'throttle:qr-admin'])
            ->name('admin.qr.generate');
        Route::post('qr', [AdminController::class, 'QrRender'])
            ->middleware(['auth:sanctum', 'api.admin', 'throttle:qr-admin'])
            ->name('admin.qr.render');
        // Hub connect from the admin (spec 2026-11-22): `hub.connect`, site id + secret, no setup code
        Route::post('site-connect', [AdminSiteConnectController::class, 'store'])
            ->middleware(['auth:sanctum', 'api.admin', 'throttle.route:5,1'])
            ->name('admin.site-connect');
        // One-tap hub connect (plan 2026-11-22-hub-one-tap-connect): start sends the browser to the hub portal, finish
        // exchanges the returned code (PKCE) and runs the same connect pipeline as site-connect
        Route::post('hub-connect/start', [HubOneTapConnectController::class, 'start'])
            ->middleware(['auth:sanctum', 'api.admin', 'throttle.route:10,1'])
            ->name('admin.hub-connect.start');
        Route::post('hub-connect/finish', [HubOneTapConnectController::class, 'finish'])
            ->middleware(['auth:sanctum', 'api.admin', 'throttle.route:10,1'])
            ->name('admin.hub-connect.finish');

        Route::middleware(['auth:sanctum', 'api.admin', 'throttle:500,1'])->group(function () use ($moduleIsEnabled) {
            Route::post('{module}/bulk/{resource}/{action}', [BulkController::class, 'dispatch'])
                ->middleware(['throttle:bulk']);

            Route::get('resources/{module}/{resource}/schema', [ResourceSchemaController::class, 'show'])
                ->where(['module' => '[a-z0-9-]+', 'resource' => '[a-z0-9-]+'])
                ->name('resources.schema');

            // Starter packs, read only: starter_pack.view or starter_pack.apply (Webmaster only,)
            Route::prefix('starter-packs')->where(['key' => '[a-z0-9-]+'])->name('starter-packs.')->group(function () {
                Route::get('/', [StarterPackController::class, 'index'])->name('index');
                Route::get('{key}', [StarterPackController::class, 'show'])->name('show');
                Route::get('{key}/history', [StarterPackController::class, 'history'])->name('history');
                // Apply: starter_pack.apply; a pack with a configuration needs a Webmaster
                Route::post('{key}/apply', [StarterPackController::class, 'apply'])
                    ->middleware('throttle.route:2,10')
                    ->name('apply');
            });

            // Custom field definitions of opted-in resources: view = custom_field.view, writes = custom_field.manage
            Route::get('custom-field-resources', [CustomFieldController::class, 'resources'])->name('custom-fields.resources');
            Route::prefix('resources/{module}/{resource}/custom-fields')
                // {uuid} accepts any segment and the controller answers 404 for a non-uuid (a numeric id), because the
                // app-wide GET fallback would otherwise turn a PUT/POST to an unmatched path into a 405.
                ->where(['module' => '[a-z0-9-]+', 'resource' => '[a-z0-9-]+', 'uuid' => '[A-Za-z0-9-]+'])
                ->name('custom-fields.')
                ->group(function () {
                    Route::get('/', [CustomFieldController::class, 'index'])->name('index');
                    Route::post('/', [CustomFieldController::class, 'store'])->middleware('throttle.route:30,1')->name('store');
                    Route::post('preview', [CustomFieldController::class, 'preview'])->middleware('throttle.route:60,1')->name('preview');
                    Route::post('reorder', [CustomFieldController::class, 'reorder'])->middleware('throttle.route:30,1')->name('reorder');
                    Route::match(['put', 'patch'], '{uuid}', [CustomFieldController::class, 'update'])->middleware('throttle.route:30,1')->name('update');
                    Route::post('{uuid}/archive', [CustomFieldController::class, 'archive'])->middleware('throttle.route:30,1')->name('archive');
                    Route::post('{uuid}/restore', [CustomFieldController::class, 'restore'])->middleware('throttle.route:30,1')->name('restore');
                    Route::get('{uuid}/usage', [CustomFieldController::class, 'usage'])->name('usage');
                    Route::post('{uuid}/purge', [CustomFieldController::class, 'purge'])->middleware('throttle.route:3,10')->name('purge');
                });

            Route::get('registry', [RegistryController::class, 'index'])->name('admin.registry');

            Route::get('records/{type}/{uuid}/history', [RecordHistoryController::class, 'show'])
                ->where('type', '[a-z0-9._-]+')
                ->whereUuid('uuid')
                ->middleware('throttle.route:120,1')
                ->name('records.history');

            // Internal notes and @mentions on a registered record
            Route::get('records/{type}/{uuid}/notes', [RecordNoteController::class, 'index'])
                ->where('type', '[a-z0-9._-]+')
                ->whereUuid('uuid')
                ->middleware('throttle.route:120,1')
                ->name('records.notes.index');
            Route::post('records/{type}/{uuid}/notes', [RecordNoteController::class, 'store'])
                ->where('type', '[a-z0-9._-]+')
                ->whereUuid('uuid')
                ->middleware('throttle.route:30,1')
                ->name('records.notes.store');
            Route::put('records/{type}/{uuid}/notes/{note}', [RecordNoteController::class, 'update'])
                ->where('type', '[a-z0-9._-]+')
                ->whereUuid(['uuid', 'note'])
                ->middleware('throttle.route:30,1')
                ->name('records.notes.update');
            Route::delete('records/{type}/{uuid}/notes/{note}', [RecordNoteController::class, 'destroy'])
                ->where('type', '[a-z0-9._-]+')
                ->whereUuid(['uuid', 'note'])
                ->middleware('throttle.route:30,1')
                ->name('records.notes.destroy');
            Route::get('records/{type}/{uuid}/mentionable', [RecordNoteController::class, 'mentionable'])
                ->where('type', '[a-z0-9._-]+')
                ->whereUuid('uuid')
                ->middleware('throttle.route:60,1')
                ->name('records.mentionable');

            // Saved views of an admin list (per user, optionally shared)
            Route::get('saved-views', [SavedViewController::class, 'index'])->name('admin.saved-views.index');
            Route::post('saved-views', [SavedViewController::class, 'store'])
                ->middleware('throttle.route:60,1')
                ->name('admin.saved-views.store');
            Route::match(['put', 'patch'], 'saved-views/{uuid}', [SavedViewController::class, 'update'])
                ->whereUuid('uuid')
                ->middleware('throttle.route:60,1')
                ->name('admin.saved-views.update');
            Route::delete('saved-views/{uuid}', [SavedViewController::class, 'destroy'])
                ->whereUuid('uuid')
                ->middleware('throttle.route:60,1')
                ->name('admin.saved-views.destroy');

            // routes/core/auth.php
            Route::prefix('auth')->group(function () {
                Route::post('logout', [AuthController::class, 'logout']);
                Route::get('me', [AuthController::class, 'me']);
                Route::get('passkeys', [AuthController::class, 'passkeys']);
                Route::post('passkeys/options', [AuthController::class, 'passkeyRegistrationOptions']);
                Route::post('passkeys', [AuthController::class, 'passkeyRegister']);
                Route::delete('passkeys/{passkey}', [AuthController::class, 'deletePasskey']);
            });

            // Admin background tasks (header task bar)
            Route::get('background-tasks', [BackgroundTaskController::class, 'index'])->name('admin.background-tasks.index');
            Route::get('background-tasks/{uuid}', [BackgroundTaskController::class, 'show'])->name('admin.background-tasks.show');

            // In-app notifications of the signed-in admin
            Route::get('notifications', [NotificationController::class, 'index'])->middleware('throttle.route:120,1')->name('admin.notifications.index');
            Route::get('notifications/unread-count', [NotificationController::class, 'unreadCount'])->middleware('throttle.route:120,1')->name('admin.notifications.unread-count');
            Route::post('notifications/read-all', [NotificationController::class, 'readAll'])->middleware('throttle.route:120,1')->name('admin.notifications.read-all');
            Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereUuid('id')->middleware('throttle.route:120,1')->name('admin.notifications.read');

            // Backup destinations: connect Google Drive / OneDrive (Webmaster only, checked in the controller)
            Route::post('backups/destinations/{uuid}/oauth/start', [BackupOAuthController::class, 'start'])
                ->name('admin.backups.oauth.start');

            // Backups: destinations, probes, manual runs, history and status (Webmaster only)
            Route::controller(BackupController::class)
                ->prefix('backups')
                ->middleware('admin.webmaster')
                ->name('admin.backups.')
                ->group(function () {
                    Route::get('status', 'status')->name('status');
                    Route::get('destinations', 'destinations')->name('destinations.index');
                    Route::post('destinations', 'store')->name('destinations.store');
                    Route::put('destinations/{uuid}', 'update')->whereUuid('uuid')->name('destinations.update');
                    Route::post('destinations/{uuid}/test', 'test')->whereUuid('uuid')->name('destinations.test');
                    Route::delete('destinations/{uuid}', 'destroy')->whereUuid('uuid')->name('destinations.destroy');
                    Route::post('run', 'run')->middleware('throttle.route:3,10')->name('run');
                    Route::get('/', 'index')->name('index');
                });

            // Core update card: installed and latest core version, preflight, upgrade and restore requests for the
            // upgrade runner (Webmaster only, like the module installs). Each request route is 6 per minute per user.
            Route::controller(CoreUpdateController::class)
                ->middleware('admin.webmaster')
                ->group(function () {
                    Route::get('core-update', 'show');
                    Route::post('core-update', 'store')->middleware('throttle.route:6,1');
                    Route::post('core-update/restore', 'restore')->middleware('throttle.route:6,1');
                });

            // Configuration packages: export, download, upload + dry run, plan, apply and history (Webmaster only).
            // The group limit uses its own key prefix so upload/apply count once against each of their two limits.
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\ConfigPackageController::class)
                ->prefix('config-packages')
                ->middleware(['admin.webmaster', 'throttle.route:30,1,config-packages:'])
                ->name('admin.config-packages.')
                ->group(function () {
                    Route::get('sections', 'sections')->name('sections');
                    Route::post('export', 'export')->name('export');
                    Route::get('runs', 'runs')->name('runs');
                    Route::get('runs/{uuid}', 'show')->whereUuid('uuid')->name('show');
                    Route::get('{uuid}/download', 'download')->whereUuid('uuid')->name('download');
                    Route::post('upload', 'upload')->middleware('throttle.route:6,1')->name('upload');
                    Route::post('{uuid}/apply', 'apply')->whereUuid('uuid')->middleware('throttle.route:6,1')->name('apply');
                });

            // Environment-bound settings: list, replace, clear (Webmaster only; secrets are write-only).
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\EnvironmentSettingController::class)
                ->prefix('environment-settings')
                ->middleware(['admin.webmaster', 'throttle.route:20,1,environment-settings:'])
                ->name('admin.environment-settings.')
                ->group(function () {
                    Route::get('/', 'index')->name('index');
                    Route::put('{key}', 'update')->where('key', '[A-Za-z0-9_.\-]+')->name('update');
                    Route::delete('{key}', 'clear')->where('key', '[A-Za-z0-9_.\-]+')->name('clear');
                });

            // API token settings: Webmaster only.
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\SecurityTokenSettingsController::class)
                ->prefix('security/token-settings')
                ->middleware(['admin.webmaster', 'throttle.route:30,1,token-settings:'])
                ->name('admin.security.token-settings.')
                ->group(function () {
                    Route::get('/', 'show')->name('show');
                    Route::put('/', 'update')->name('update');
                    Route::post('preview', 'preview')->name('preview');
                    // Sets past expiries and, with `revoke_past`, deletes tokens: needs a fresh step-up grant it consumes.
                    Route::post('apply-to-existing', 'applyToExisting')->middleware('security.stepup:revoke_tokens,consume')->name('apply');
                });

            // Step-up confirmation before revoking tokens: any signed-in operator, for their own account.
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\SecurityStepUpController::class)
                ->prefix('security/step-up')
                ->middleware('throttle.route:10,1')
                ->name('admin.security.step-up.')
                ->group(function () {
                    Route::get('status', 'status')->name('status');
                    Route::post('/', 'start')->name('start');
                    Route::post('second-factor', 'secondFactor')->name('second-factor');
                    Route::post('email-code', 'emailCode')->name('email-code');
                });

            // Own API tokens: any signed-in operator lists and ends the tokens of their own account, never the
            // one in use; every revoke needs a step-up grant (bulk ones consume it).
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\SecurityTokenController::class)
                ->prefix('security/tokens/mine')
                ->name('admin.security.tokens.mine.')
                ->group(function () {
                    Route::get('/', 'mine')->middleware('throttle.route:60,1,tokens-mine:')->name('index');
                    Route::delete('{uuid}', 'mineDestroy')->whereUuid('uuid')
                        ->middleware(['throttle.route:20,1,tokens-mine-revoke:', 'security.stepup:revoke_tokens'])->name('destroy');
                    Route::post('revoke-others', 'revokeOthers')
                        ->middleware(['throttle.route:5,1,tokens-mine-revoke:', 'security.stepup:revoke_tokens,consume'])->name('revoke-others');
                });

            // Every account's API tokens: Webmaster only; the Webmaster's own current token is always spared.
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\SecurityTokenController::class)
                ->prefix('security/tokens')
                ->middleware('admin.webmaster')
                ->name('admin.security.tokens.')
                ->group(function () {
                    Route::get('/', 'index')->middleware('throttle.route:60,1,tokens-list:')->name('index');
                    Route::delete('{uuid}', 'destroy')->whereUuid('uuid')
                        ->middleware(['throttle.route:20,1,tokens-revoke:', 'security.stepup:revoke_tokens'])->name('destroy');
                    Route::post('revoke-user', 'revokeUser')
                        ->middleware(['throttle.route:5,1,tokens-revoke-bulk:', 'security.stepup:revoke_tokens,consume'])->name('revoke-user');
                    Route::post('revoke-audience', 'revokeAudience')
                        ->middleware(['throttle.route:5,1,tokens-revoke-bulk:', 'security.stepup:revoke_tokens,consume'])->name('revoke-audience');
                    Route::post('revoke-all', 'revokeAll')
                        ->middleware(['throttle.route:5,1,tokens-revoke-bulk:', 'security.stepup:revoke_tokens,consume'])->name('revoke-all');
                });

            Route::controller(AccountController::class)
                ->prefix('account')->group(function () {
                    Route::put('/', 'update');
                    Route::post('sign-out', 'signOut');
                    Route::post('sign-in-as', 'signInAs');
                    Route::get('info', 'info');
                    Route::get('mfa', 'mfaStatus');
                    Route::post('mfa/authenticator/start', 'startAuthenticatorEnrollment');
                    Route::post('mfa/authenticator/confirm', 'confirmAuthenticatorEnrollment');
                    Route::delete('mfa/authenticator', 'disableAuthenticator');
                    Route::post('mfa/recovery-codes/regenerate', 'regenerateRecoveryCodes');
                    Route::get('activities', 'activities');
                    // The current password is checked here: a per-route limit keeps it from being guessed.
                    Route::post('change-password', 'changePassword')->middleware('throttle.route:6,1');
                });

            Route::controller(AgenticCatalogController::class)
                ->prefix('agentic')->group(function () {
                    Route::get('health', 'health');
                    Route::get('tool-definitions', 'definitions');
                    Route::get('tool-bindings', 'bindings');
                    Route::get('profiles', 'profiles');
                    Route::get('profiles/{profile}/tool-definitions', 'profileToolDefinitions');
                });

            Route::controller(AgenticRunController::class)
                ->prefix('agentic')->group(function () {
                    Route::get('runs', 'index');
                    Route::get('runs/{run}', 'show');
                    Route::post('runs/launch', 'launch');
                    Route::post('runs/{run}/stop', 'stop');
                    Route::post('runs/{run}/cancel', 'cancel');
                    Route::post('runs/{run}/rerun', 'rerun');
                });

            Route::controller(AgenticScheduleController::class)
                ->prefix('agentic')->group(function () {
                    Route::get('schedules', 'index');
                    Route::post('schedules', 'store');
                    Route::patch('schedules/{schedule}', 'update');
                    Route::post('schedules/{schedule}/dispatch', 'queueRun');
                    Route::post('schedules/{schedule}/run-once', 'queueRunOnce');
                    Route::post('schedules/{schedule}/resume-dispatch', 'resumeAndQueueRun');
                });

            Route::controller(AgenticProvisioningController::class)
                ->prefix('agentic')->group(function () {
                    Route::post('profiles', 'storeProfile');
                    Route::post('profiles/seed-templates', 'seedTemplateProfiles');
                    Route::patch('profiles/{profile}', 'updateProfile');
                    Route::post('tool-definitions/sync', 'sync');
                    Route::post('profiles/{profile}/tool-definitions/grant', 'grantProfileToolDefinitions');
                    Route::patch('profiles/{profile}/tool-definitions/{toolDefinition}', 'updateProfileToolDefinitionGrant');
                    Route::delete('profiles/{profile}/tool-definitions/{toolDefinition}', 'revokeProfileToolDefinitionGrant');
                    Route::patch('tool-bindings/{binding}', 'updateToolBinding');
                });

            Route::controller(AgenticApprovalController::class)
                ->prefix('agentic')->group(function () {
                    Route::get('approvals', 'index');
                    Route::get('approvals/sla-summary', 'slaSummary');
                    Route::get('approvals/{approval}', 'show');
                    Route::post('approvals/{approval}/approve', 'approve');
                    Route::post('approvals/{approval}/reject', 'reject');
                    Route::post('approvals/{approval}/request-changes', 'requestChanges');
                    Route::post('approvals/{approval}/apply', 'apply');
                    Route::post('approvals/{approval}/approve-apply', 'approveAndApply');
                    Route::post('approvals/{approval}/undo', 'undo');
                });

            // Run an authored workflow project by hand or on a cron schedule.
            Route::controller(WorkflowRunController::class)
                ->prefix('workflow/projects/{project}')
                ->whereUuid('project')
                ->group(function () {
                    Route::post('run', 'run')->middleware('throttle.route:20,1,workflow-run:');
                    Route::put('schedule', 'schedule');
                    Route::get('instances', 'instances');
                });

            // Workflow versions (guardrails B1): history, diff, publish, rollback; the project key of the builder routes.
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\WorkflowVersionController::class)
                ->prefix('workflows/{project}/versions')
                ->whereUuid('project')
                ->group(function () {
                    Route::get('/', 'index');
                    Route::get('draft-status', 'draftStatus');
                    Route::get('preview-publish', 'previewPublish');
                    Route::post('publish', 'publish')->middleware('throttle.route:20,1,workflow-publish:');
                    Route::get('{uuid}', 'show')->whereUuid('uuid');
                    Route::get('{uuid}/diff', 'diff')->whereUuid('uuid');
                    Route::post('{uuid}/rollback', 'rollback')->whereUuid('uuid')->middleware('throttle.route:20,1,workflow-publish:');
                    // Change control (B3): decisions on a waiting sensitive version.
                    Route::post('{uuid}/approve', 'approve')->whereUuid('uuid')->middleware('throttle.route:20,1,workflow-publish:');
                    Route::post('{uuid}/reject', 'reject')->whereUuid('uuid')->middleware('throttle.route:20,1,workflow-publish:');
                    Route::post('{uuid}/cancel', 'cancel')->whereUuid('uuid')->middleware('throttle.route:20,1,workflow-publish:');
                    Route::post('{uuid}/release-now', 'releaseNow')->whereUuid('uuid')->middleware('throttle.route:20,1,workflow-publish:');
                });

            // Waiting sensitive versions of every project the caller may publish (B3).
            Route::get('workflow/versions/pending', [\Cms\Core\Http\Controllers\V2\Admin\WorkflowVersionController::class, 'pending']);

            // Project trigger settings (lane N3): webhook URL/secret (secret shown once) and the error workflow.
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\WorkflowTriggerController::class)
                ->prefix('workflow/projects/{project}')
                ->whereUuid('project')
                ->group(function () {
                    Route::get('webhook', 'webhook');
                    Route::post('webhook', 'rotateWebhook')->middleware(['throttle.route:10,1,workflow-webhook-rotate:', 'security.stepup:workflow_publish,consume']);
                    Route::delete('webhook', 'disableWebhook');
                    Route::put('error-workflow', 'errorWorkflow');
                });

            // Switch a project paused by a circuit breaker back on (guardrails B7; project `publish` ability).
            Route::post('workflow/projects/{project}/resume', [\Cms\Core\Http\Controllers\V2\Admin\WorkflowGuardController::class, 'resume'])
                ->whereUuid('project')->middleware('throttle.route:10,1,workflow-resume:');

            // Workflow credentials (`$bind:<name>`): workflow_credential.manage; values go in, never come out.
            Route::controller(WorkflowCredentialController::class)
                ->prefix('workflow/credentials')
                ->group(function () {
                    Route::get('/', 'index');
                    // Changing a secret needs a fresh step-up grant (guardrails B6, purpose workflow_credential).
                    Route::post('/', 'store')->middleware(['throttle.route:30,1,workflow-credential:', 'security.stepup:workflow_credential']);
                    Route::match(['put', 'patch'], '{uuid}', 'update')->middleware(['throttle.route:30,1,workflow-credential:', 'security.stepup:workflow_credential']);
                    Route::delete('{uuid}', 'destroy')->middleware(['throttle.route:30,1,workflow-credential:', 'security.stepup:workflow_credential']);
                    Route::post('{uuid}/test', 'test')->middleware('throttle.route:10,1,workflow-credential-test:');
                });

            // Reviewer picker of the workflow builder (approval assignees): permission holders by name, or names of uuids.
            Route::get('workflows/reviewers', [\Cms\Core\Http\Controllers\Workflow\WorkflowReviewerController::class, 'index']);

            // Workflow template library from the hub (phase C): catalog, verified detail, install (inactive, never overwrites).
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\WorkflowLibraryController::class)
                ->prefix('workflows/library')
                ->group(function () {
                    Route::get('/', 'index')->middleware('throttle.route:30,1,workflow-library:');
                    Route::get('{key}', 'show')->where('key', '[A-Za-z0-9][A-Za-z0-9._-]{0,190}')->middleware('throttle.route:30,1,workflow-library:');
                    Route::post('{key}/install', 'install')->where('key', '[A-Za-z0-9][A-Za-z0-9._-]{0,190}')->middleware('throttle.route:10,1,workflow-library-install:');
                });

            // Workflow packages (A4): preview (validate + dry run), install (add-only, inactive), export by slug.
            Route::controller(\Cms\Core\Http\Controllers\V2\Admin\WorkflowPackageController::class)
                ->prefix('workflow/packages')
                ->group(function () {
                    Route::post('preview', 'preview')->middleware('throttle.route:30,1,workflow-package:');
                    Route::post('install', 'install')->middleware('throttle.route:10,1,workflow-package-install:');
                    Route::get('export/{slug}', 'export')->where('slug', '[A-Za-z0-9_-]+');
                });

            Route::controller(AiRuntimeController::class)
                ->prefix('ai-runtime')->group(function () {
                    Route::get('/', 'index');
                    Route::get('selectable-models', 'selectableModels');
                    Route::put('providers', 'updateProviders');
                    Route::put('tasks', 'updateTasks');
                    Route::put('budget', 'updateBudget');
                    Route::get('usage', 'usage');
                    Route::get('calls', 'calls');
                    Route::post('test', 'testConnection');
                });
            //  Admin category
            Route::prefix('category')->controller(CategoryController::class)->group(function () {
                Route::get('search', 'search');
                Route::get('tree', 'getTree');
                Route::get('menu', 'getMenu');
                Route::put('{id}/priority', 'updatePriority')->where('id', '\d+');
            });
            //  Admin Place
            Route::controller(DashboardController::class)->prefix('dashboard')->group(function () {
                Route::get('server', 'server');
            });
            //  Admin email
            Route::controller(EmailController::class)->prefix('email')->group(function () {
                Route::put('spam', 'markSpam');
                Route::put('delete', 'massDestroy');
                Route::put('{id}/reply', 'reply');
                Route::get('counter', 'counter');
            });
            Route::resource('email', EmailController::class)->except(['create']);
            // Managed e-mail templates: picker catalog, variables and preview (before the resource, whose {id} would match).
            Route::controller(EmailTemplateController::class)->prefix('email-template')->group(function () {
                Route::get('catalog', 'catalog');
                Route::post('preview', 'preview');
                Route::get('{id}/variables', 'variables')->where('id', '\d+');
                Route::post('{id}/preview', 'preview')->where('id', '\d+');
            });
            Route::resource('email-template', EmailTemplateController::class)->except(['create']);
            // Admin Enumerate
            Route::get('enumerate/search', [EnumerateController::class, 'search']);
            Route::post('enumerate/generate', [EnumerateController::class, 'generate']);
            //  Admin Contact & field
            Route::get('field/option-sources', [FormOptionSourceController::class, 'catalogue']);
            Route::get('field/option-sources/{source}/options', [FormOptionSourceController::class, 'options']);
            Route::get('field/search', [FieldController::class, 'search']);
            Route::post('field/preview', [FieldController::class, 'preview']);
            Route::resource('field', FieldController::class)->except(['show', 'create']);
            //  Admin language
            Route::controller(LanguageController::class)->prefix('language')->group(function () {
                Route::get('search', 'search');
                Route::get('flags', 'flags');
                Route::put('{id}/restore', 'restore')->where('id', '\d+');
                Route::put('{id}/switch/{col}', 'switch')->where('id', '\d+');
            });
            //  Meta data
            Route::get('meta-attribute/search', [MetaAttributeController::class, 'search']);
            //  Admin Role
            Route::get('role/search', [RoleController::class, 'search']);
            //  Admin Permission
            Route::delete('permission/delete/{group}', [PermissionController::class, 'groupDelete']);
            // Permission Catalog (lazy-loading for permission editor)
            Route::prefix('permissions')->controller(PermissionCatalogController::class)->group(function () {
                Route::get('modules', 'modules');
                Route::get('catalog', 'catalog');
            });
            // Permission picker of the workflow step form (workflow editors).
            Route::get('workflows/permission-options', [\Cms\Core\Http\Controllers\Workflow\WorkflowPermissionOptionsController::class, 'index']);
            // Ready-made choices of the workflow step form (JEV criteria, score rubrics, classify labels).
            Route::get('workflows/presets', [\Cms\Core\Http\Controllers\Workflow\WorkflowPresetController::class, 'index']);
            // Setup questions of an installed sample workflow (only what the site must decide).
            Route::controller(\Cms\Core\Http\Controllers\Workflow\WorkflowSetupController::class)
                ->prefix('workflows/{project}/setup')
                ->whereUuid('project')
                ->group(function () {
                    Route::get('/', 'show');
                    Route::get('recipients', 'recipients')->middleware('throttle.route:60,1,workflow-setup-recipients:');
                    Route::put('/', 'update')->middleware('throttle.route:30,1,workflow-setup:');
                    Route::post('test-run', 'testRun')->middleware('throttle.route:20,1,workflow-setup-test:');
                });
            // Auto-publish after JEV calibration of one approval step (state, calibration, estimate; project `view`).
            Route::get('workflows/{project}/steps/{stepCode}/auto-approval', [\Cms\Core\Http\Controllers\Workflow\WorkflowAutoApprovalController::class, 'show'])
                ->whereUuid('project')->where('stepCode', '[A-Za-z0-9_.:-]{1,120}');
            // Data scope management (read API + scope changes)
            Route::prefix('data-scope')->middleware('can:data_scope.manage')->controller(\Cms\Core\Http\Controllers\V2\Admin\DataScopeController::class)->group(function () {
                Route::get('dimensions', 'dimensions');
                Route::get('dimensions/{dimension}/nodes', 'nodes')->where('dimension', '[a-z0-9_]+');
                Route::get('roles/{id}', 'role')->where('id', '\d+');
                Route::get('users/{uuid}', 'user');
                Route::put('roles/{id}', 'updateRole')->where('id', '\d+')->middleware('throttle.route:60,1');
                Route::put('users/{uuid}', 'updateUser')->middleware('throttle.route:60,1');
            });
            // Role Permission Sync
            Route::get('role/{id}/permissions', [RoleController::class, 'getPermissions'])->where('id', '\d+');
            Route::put('role/{id}/permissions', [RoleController::class, 'syncPermissions'])->where('id', '\d+');
            // Admin Setting
            Route::controller(SettingController::class)
                ->prefix('setting')->group(function () {
                    Route::put('{id}/quick', 'quickUpdate');
                    Route::post('save', 'save');
                    Route::put('homepage', 'updateHomePageSetting');
                    Route::get('homepage', 'getHomePageSetting');
                });
            // Admin Storage
            Route::get('storages', [StorageController::class, 'index']);
            Route::prefix('storage')->controller(StorageController::class)->group(function () {
                Route::get('search', 'search');
                Route::put('multi-update', 'multipleUpdate');
                Route::get('getAmount', 'getAmount');
                Route::get('searchDashboard', 'searchDashboard');
                Route::get('getCountScan/{type}', 'getCountScan');
                Route::get('tree', 'getTree');
            });
            Route::resource('storage', StorageController::class)->except(['create']);

            //  Admin translate
            Route::controller(TranslateController::class)
                ->prefix('translate')->group(function () {
                    Route::get('search', 'search');
                    Route::put('generate', 'generate');
                    Route::put('{id}/switch', 'switch')->where('id', '\d+');
                });
            if ($moduleIsEnabled('Content')) {
                //  Admin upload
                Route::prefix('upload')->controller(UploadController::class)->group(function () {
                    Route::get('dashboard', 'dashboard');
                    Route::post('new', 'newUpload');
                    Route::post('{type}', 'uploadByType')->where('type', 'image|avatar|audio|video|document|archive|page');
                    Route::get('folder', 'folderList');
                    Route::get('media', 'mediaList');
                    Route::post('image', 'store');
                    Route::post('{id}/main', 'setMain');
                });
            }
            //  Admin user tags (UUID-first). Every `users/...` route is registered before any `user/{uuid}` or `users/{uuid}` route.
            Route::get('user-tags', [UserTagController::class, 'index']);
            Route::post('user-tags', [UserTagController::class, 'store']);
            Route::delete('user-tags/{uuid}', [UserTagController::class, 'destroy']);
            Route::get('users/filter', [UserController::class, 'filter']);
            Route::post('users/tags/attach', [UserController::class, 'attachTags']);
            Route::post('users/tags/detach', [UserController::class, 'detachTags']);
            //  Admin user
            Route::controller(UserController::class)
                ->prefix('user')->group(function () {
                    Route::get('search', 'search');
                    Route::get('dashboard', 'dashboard');
                    Route::get('dashboard/student', 'dashboardStudent');
                    Route::put('{uuid}/settings', 'putSettings');
                    Route::get('{uuid}/settings', 'getSettings');
                    Route::put('{id}/switch', 'switch')->where('id', '\d+');
                    Route::put('{uuid}/password', 'setPassword');
                    Route::post('{uuid}/email-verification/resend', 'resendEmailVerification')
                        ->middleware('throttle.route:10,1');
                });

            // Explicit compatibility routes for the legacy Widget admin surface.
            // URL shapes match what base-admin already calls. Theme/Input child
            // routes are widget-scoped so lookups always constrain by BOTH
            // {widgetId} and the child {id}; the top-level widget-template and
            // widget-input routes are read-only because unscoped child mutation
            // cannot enforce widget ownership.
            // Contract: cms/Core/tests/Feature/Http/Controllers/V2/Admin/WidgetAdminContractTest.php
            Route::get('widget', [WidgetController::class, 'index']);
            Route::post('widget', [WidgetController::class, 'store']);
            Route::get('widget/search', [WidgetController::class, 'search']);
            Route::get('widget/admin-list', [WidgetController::class, 'adminList']);
            // Widget library (unified widget catalog part 2, Task 4).
            Route::get('widget/catalog-status', [WidgetController::class, 'catalogStatus']);
            // Protected operator recovery action for a sync_status: pending
            // incident (Gate 0 Task 2) — never a shell-command-only remedy.
            Route::post('widget/retry-sync', [WidgetController::class, 'retrySync']);
            Route::get('widget/{id}/edit', [WidgetController::class, 'edit'])->whereNumber('id');
            Route::match(['put', 'patch'], 'widget/{id}', [WidgetController::class, 'update'])->whereNumber('id');
            Route::delete('widget/{id}', [WidgetController::class, 'destroy'])->whereNumber('id');
            Route::post('widget/{widgetId}/preview', [WidgetController::class, 'preview'])->whereNumber('widgetId');
            Route::get('widget/{id}/usage', [WidgetController::class, 'usage'])->whereNumber('id');
            // Widget hub (widget hub distribution Task 5), gated on `widget.hub` in the controller.
            Route::get('widget/hub-catalog', [WidgetController::class, 'hubCatalog']);
            Route::post('widget/hub/{widgetKey}/download', [WidgetController::class, 'hubDownload'])
                ->where('widgetKey', '[A-Za-z0-9][A-Za-z0-9._-]{0,190}');
            // Hub preview image of one design of a hub version, proxied and cached (widget hub distribution Task 8a).
            Route::get('widget/hub/{widgetKey}/previews/{version}/{themeKey}', [WidgetController::class, 'hubPreview'])
                ->where(['widgetKey' => '[A-Za-z0-9][A-Za-z0-9._-]{0,190}', 'version' => '[1-9][0-9]{0,8}', 'themeKey' => '[A-Za-z0-9][A-Za-z0-9._-]{0,190}'])
                ->name('widget-hub.preview');
            Route::get('widget/{id}/hub-update/impact', [WidgetController::class, 'hubUpdateImpact'])->whereNumber('id');
            Route::post('widget/{id}/hub-update', [WidgetController::class, 'hubUpdate'])->whereNumber('id');
            // Emergency block (unified widget catalog Task 7), gated on `widget.block` in the controller.
            Route::post('widgets/{id}/block', [WidgetController::class, 'block'])->whereNumber('id');
            Route::delete('widgets/{id}/block', [WidgetController::class, 'unblock'])->whereNumber('id');

            Route::prefix('widget/{widgetId}')->whereNumber('widgetId')->group(function () {
                Route::get('template', [WidgetThemeController::class, 'index']);
                Route::post('template', [WidgetThemeController::class, 'store']);
                Route::get('template/{id}/edit', [WidgetThemeController::class, 'edit'])->whereNumber('id');
                Route::match(['put', 'patch'], 'template/{id}', [WidgetThemeController::class, 'update'])->whereNumber('id');
                Route::delete('template/{id}', [WidgetThemeController::class, 'destroy'])->whereNumber('id');

                Route::get('inputs', [WidgetInputController::class, 'index']);
                Route::post('inputs', [WidgetInputController::class, 'store']);
                Route::get('inputs/{id}/edit', [WidgetInputController::class, 'edit'])->whereNumber('id');
                Route::match(['put', 'patch'], 'inputs/{id}', [WidgetInputController::class, 'update'])->whereNumber('id');
                Route::delete('inputs/{id}', [WidgetInputController::class, 'destroy'])->whereNumber('id');
            });

            // Top-level compatibility reads (GET-only by contract).
            Route::get('widget-template', [WidgetThemeController::class, 'index']);
            // Captured design preview (widget hub distribution Task 6); same permission as a design save.
            Route::post('widget-template/{id}/preview-image', [WidgetController::class, 'previewImage'])->whereNumber('id');
            Route::get('widget-input', [WidgetInputController::class, 'index']);

            $adminResources = [
                'category' => CategoryController::class,
                'enumerate' => EnumerateController::class,
                'enumerate-type' => EnumerateTypeController::class,
                'language' => LanguageController::class,
                'menu' => MenuController::class,
                'menu.items' => MenuItemController::class,
                'meta-attribute' => MetaAttributeController::class,
                'meta-attribute.options' => MetaValueController::class,
                'meta-class' => MetaClassController::class,
                'permission' => PermissionController::class,
                'role' => RoleController::class,
                'setting' => SettingController::class,
                'translate' => TranslateController::class,
                'user' => UserController::class,
            ];

            // Admin tag routes live in cms/Content/routes/api.php since 2026-11-21.
            if ($moduleIsEnabled('Content')) {
                $adminResources['upload'] = UploadController::class;
            }

            Route::resources($adminResources);

            Route::prefix('utilities')->group(function () {
                Route::get('modules/list', [WebsiteUtilitiesController::class, 'getModuleList']);
                Route::post('clear-cache', [WebsiteUtilitiesController::class, 'clearCache']);
                Route::post('rebuild-search-index', [WebsiteUtilitiesController::class, 'rebuildSearchIndex']);
                Route::get('list-days', [UtilitiesController::class, 'listDays']);
                Route::get('components', [UtilitiesController::class, 'listComponents']);
                Route::get('projects', [WebsiteUtilitiesController::class, 'projectList']);

                Route::prefix('modules')->controller(ModuleController::class)->group(function () {
                    Route::get('/', 'index');
                    Route::put('{module}/status', 'updateStatus');
                });

                // Installable modules from the platform hub and the install preflight (Webmaster only).
                Route::prefix('modules/catalog')
                    ->controller(ModuleCatalogController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::get('/', 'index');
                        Route::get('media/{hash}', 'media')->where('hash', '[a-f0-9]{64}');
                        Route::get('{name}/listing', 'listing')->where('name', '[A-Z][A-Za-z0-9]{1,63}');
                        // Ratings, Q&A and private feedback through the hub; writes 10 per hour per user.
                        Route::get('{name}/ratings/mine', 'myRating')->where('name', '[A-Z][A-Za-z0-9]{1,63}');
                        Route::get('{name}/ratings', 'ratings')->where('name', '[A-Z][A-Za-z0-9]{1,63}');
                        Route::get('{name}/questions', 'questions')->where('name', '[A-Z][A-Za-z0-9]{1,63}');
                        Route::put('{name}/rating', 'rate')->where('name', '[A-Z][A-Za-z0-9]{1,63}')
                            ->middleware('throttle.route:10,60');
                        Route::post('{name}/questions', 'ask')->where('name', '[A-Z][A-Za-z0-9]{1,63}')
                            ->middleware('throttle.route:10,60');
                        Route::post('{name}/feedback', 'feedback')->where('name', '[A-Z][A-Za-z0-9]{1,63}')
                            ->middleware('throttle.route:10,60');
                        Route::get('{name}/{version}/preflight', 'preflight')
                            ->where(['name' => '[A-Z][A-Za-z0-9]{1,63}', 'version' => '\d+\.\d+\.\d+']);
                    });

                // Masked diagnostic bundle and support request for one installed module (, Webmaster only).
                // Preview only reads the site; sending reaches the hub, 3 per hour per user.
                Route::controller(ModuleSupportController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::get('modules/{name}/support/preview', 'preview')->where('name', '[A-Z][A-Za-z0-9]{1,63}')
                            ->middleware('throttle.route:30,10');
                        Route::post('modules/{name}/support', 'send')->where('name', '[A-Z][A-Za-z0-9]{1,63}')
                            ->middleware('throttle.route:3,60');
                        // This site's support requests on the hub: list, public thread and reply. Reads reach
                        // the hub on every call (60 per 10 minutes); replies 10 per hour per user.
                        Route::get('modules/support', 'index')->middleware('throttle.route:60,10');
                        Route::get('modules/support/{uuid}', 'show')->where('uuid', '[^/]{1,64}')
                            ->middleware('throttle.route:60,10');
                        Route::post('modules/support/{uuid}/reply', 'reply')->where('uuid', '[^/]{1,64}')
                            ->middleware('throttle.route:10,60');
                    });

                // Integrity report: installed module files against the signed package hashes (, Webmaster only,
                // information only). The check reads local files, so it is limited to 6 per 10 minutes per user.
                Route::controller(ModuleIntegrityController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::get('modules/integrity', 'index');
                        Route::post('modules/integrity/check', 'check')->middleware('throttle.route:6,10');
                    });

                // Site report level sent to the hub: off, basic or full, with a preview of the exact report (,
                // Webmaster only). The preview reads health probes, so 30 per 10 minutes; saving 10 per minute (audited).
                Route::controller(\Cms\Core\Http\Controllers\V2\Admin\SiteReportLevelController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::get('modules/report-level', 'show');
                        Route::put('modules/report-level', 'update')->middleware('throttle.route:10,1');
                        Route::get('modules/report-level/preview', 'preview')->middleware('throttle.route:30,10');
                    });

                // The Webmaster's "allow module install" switch (spec 2026-11-22; audited, 10 changes a minute).
                Route::controller(\Cms\Core\Http\Controllers\V2\Admin\ModuleInstallSettingController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::get('modules/install-setting', 'show');
                        Route::put('modules/install-setting', 'update')->middleware('throttle.route:10,1');
                    });

                // Outside hosts workflow steps may call (Webmaster only, audited, 10 changes a minute).
                Route::controller(\Cms\Core\Http\Controllers\V2\Admin\WorkflowHttpHostsController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::get('workflow/http-hosts', 'show');
                        Route::put('workflow/http-hosts', 'update')->middleware('throttle.route:10,1,workflow-http-hosts:');
                    });

                // Pause every workflow of the site (guardrails B7; Webmaster only, audited, alerted).
                Route::controller(\Cms\Core\Http\Controllers\V2\Admin\WorkflowGuardController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::get('workflow/pause-all', 'showPauseAll');
                        Route::put('workflow/pause-all', 'updatePauseAll')->middleware('throttle.route:10,1,workflow-pause-all:');
                        // Who reviews workflow changes ("Người duyệt thay đổi quy trình"; empty = every publisher).
                        Route::get('workflow/change-reviewers', 'showReviewers');
                        Route::put('workflow/change-reviewers', 'updateReviewers')->middleware('throttle.route:10,1,workflow-change-reviewers:');
                    });

                // Runtime module installs from the platform hub (Webmaster only).
                // Start, upload and uninstall share one abuse limit: 10 requests per route per user in 10 minutes.
                // Runs are already one at a time (a single unfinished install row), and refused requests count too
                // (a retry, an upload sent again with its confirmation), so 3 per 10 minutes stopped an owner who
                // upgraded a few modules in a row (2026-11-25). The admin rebuild stays at 1 per 10 minutes: it is
                // one long build, never a series.
                Route::controller(ModuleInstallController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::post('modules/install', 'store')->middleware('throttle.route:10,10');
                        Route::post('modules/upload', 'upload')->middleware('throttle.route:10,10');
                        Route::get('modules/installs', 'index');
                        // Runtime module removal.
                        Route::get('modules/runtime/{name}/uninstall', 'uninstallInfo')
                            ->where('name', '[A-Z][A-Za-z0-9]{1,63}');
                        Route::delete('modules/runtime/{name}', 'uninstall')
                            ->where('name', '[A-Z][A-Za-z0-9]{1,63}')
                            ->middleware('throttle.route:10,10');
                        Route::post('modules/admin-rebuild', 'adminRebuild')->middleware('throttle.route:1,10');
                    });

                // "Nâng cấp tất cả" (upgrade-all spec 2026-11-26): plan, start (6 a minute), open/last run, stop, hide.
                Route::controller(\Cms\Core\Http\Controllers\V2\Admin\UpdateBatchController::class)
                    ->middleware('admin.webmaster')
                    ->group(function () {
                        Route::get('updates/batch/plan', 'plan');
                        Route::post('updates/batch', 'store')->middleware('throttle.route:6,1');
                        Route::get('updates/batch/current', 'current');
                        Route::post('updates/batch/{uuid}/stop', 'stop')->whereUuid('uuid');
                        Route::post('updates/batch/{uuid}/dismiss', 'dismiss')->whereUuid('uuid');
                    });
            });

            // Import Definition Metadata
            Route::get('import-definitions/{module}/{entity}', [ImportDefinitionController::class, 'show']);
        });
        // routes/core/auth.php
        Route::controller(AuthController::class)
            ->prefix('auth')->group(function () {
                Route::post('sign-in', 'signIn')->middleware('throttle.route:10,1');
                Route::post('passkey/options', 'passkeyOptions')->middleware('throttle.route:10,1');
                Route::post('passkey/login', 'passkeyLogin')->middleware('throttle.route:10,1');
                Route::post('two-factor', 'twoFactorVerify')->middleware('throttle.route:10,1');
                Route::post('challenge', 'verifyChallenge')->middleware('throttle.route:10,1');
                Route::post('challenge/recovery-code', 'useRecoveryCode')->middleware('throttle.route:10,1');
                Route::post('challenge/resend', 'resendChallenge')->middleware('throttle.route:10,1');
                Route::post('request-code', 'requestCode')->middleware('throttle.route:5,10');
                Route::post('check-code', 'checkCode')->middleware('throttle.route:10,1');
                Route::post('set-password', 'setPassword')->middleware('throttle.route:5,10');
                // JWT/OAuth2 Authentication
//            Route::post('login', [AuthController::class, 'login']);
//            Route::post('register', [AuthController::class, 'register']);
//            Route::post('logout', [AuthController::class, 'logout'])->middleware('auth:sanctum');
//            Route::post('refresh', [AuthController::class, 'refresh']);
//            Route::get('me', [AuthController::class, 'me'])->middleware('auth:sanctum');

                // Password Reset
//            Route::post('forgot-password', [PasswordResetController::class, 'sendResetLink']);
//            Route::post('reset-password', [PasswordResetController::class, 'reset']);

                // Email Verification
//            Route::get('email/verify/{id}/{hash}', [VerificationController::class, 'verify'])
//                ->name('verification.verify');
//            Route::post('email/resend', [VerificationController::class, 'resend']);
            });

        // OAuth provider redirect for backup destinations: no admin token, the single-use state authenticates it
        Route::get('backups/oauth/callback', [BackupOAuthController::class, 'callback'])
            ->middleware('throttle.route:10,1')
            ->name('admin.backups.oauth.callback');
    });

    // Public APIs routes
    Route::prefix('public')->group(function () {
        Route::get('form-option-sources/{source}/options', [FormOptionSourceController::class, 'publicOptions'])
            ->middleware('throttle.route:120,1')->name('form-option-sources.options');
        // Hub connection status for the admin gate, answered by this backend: no token, secret or hub URL.
        Route::get('site-status', [SiteStatusController::class, 'show'])
            ->middleware('throttle.route:60,1')->name('public.site-status');
        // Pre-login connect screen: setup code first, then the signed hub connect; 5 attempts a minute.
        Route::post('site-connect', [SiteConnectController::class, 'store'])
            ->middleware('throttle.route:5,1')->name('public.site-connect');
        // Authenticated one-turn content answer. The envelope stays outermost (middleware priority).
        Route::post('agent/answers', AgentAnswerController::class)
            ->middleware([AgentAnswerEnvelope::class, 'auth:sanctum', 'throttle:agent-answers'])
            ->name('public.agent.answers');
        // Return aggregated module.json for a given cms module and locale
        Route::get('module/{module}/lang/{locale}/module.json', [AdminController::class, 'moduleJson']);
    });
});
