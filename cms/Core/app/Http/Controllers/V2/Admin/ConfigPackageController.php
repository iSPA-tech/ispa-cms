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
use Cms\Core\Http\Requests\ConfigPackage\ApplyConfigPackageRequest;
use Cms\Core\Http\Requests\ConfigPackage\ExportConfigPackageRequest;
use Cms\Core\Http\Requests\ConfigPackage\UploadConfigPackageRequest;
use Cms\Core\Jobs\DryRunConfigPackageJob;
use Cms\Core\Models\ConfigPackageRun;
use Cms\Core\Models\Menu;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Models\Translate;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\ConfigPackage\ConfigExportRegistry;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplier;
use Cms\Core\Services\ConfigPackage\ConfigPackageApplyRefused;
use Cms\Core\Services\ConfigPackage\ConfigPackageExporter;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\ConfigPackage\PackageValidator;
use Cms\Core\Services\ConfigPackage\SecretGuard;
use Cms\Core\Services\ConfigPackage\ExportContext;
use Cms\Core\Services\ConfigPackage\Sections\CustomFieldsSection;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use JsonException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * Webmaster-only admin API for configuration packages: available sections, export and download, upload with a dry
 * run, the paged dry-run plan, apply and the run history. Access is enforced by the `admin.webmaster` route
 * middleware; every run is looked up by uuid and scoped to the user who created it (anything else is 404).
 * Responses never carry the stored package (`payload`), numeric ids or secret values.
 */
class ConfigPackageController extends AdminController
{
    /** Default and maximum plan items per page in show(). */
    private const PLAN_PER_PAGE = 50;
    private const PLAN_MAX_PER_PAGE = 200;

    /**
     * Available sections with their key patterns (settings), tables and current row counts.
     *
     * @param ConfigExportRegistry $registry Settings patterns and exportable tables.
     * @param SecretGuard          $guard    Secret classifier (secret settings are not counted).
     *
     * @return mixed Admin API response.
     */
    public function sections(ConfigExportRegistry $registry, SecretGuard $guard): mixed
    {
        $patterns = $registry->settingsPatterns();
        $settings = Setting::query()->get(['setting_key', 'type', 'input_setting'])
            ->filter(fn (Setting $s): bool => $registry->matches((string) $s->setting_key)
                && !$guard->isSecretSetting(['type' => $s->type, 'input_setting' => $s->input_setting]))
            ->count();

        $translations = [
            'web' => Translate::query()->where('for_web', true)->count(),
            'adm' => Translate::query()->where('for_adm', true)->count(),
            'app' => Translate::query()->where('for_app', true)->count(),
            'all' => Translate::query()->count(),
        ];

        $tables = array_map(static fn (string $table): array => [
            'table' => $table,
            'count' => DB::table($table)->count(),
        ], $registry->tables());

        return ires()->data([
            'sections' => [
                ['key' => 'settings', 'count' => $settings, 'patterns' => $patterns],
                ['key' => 'translations', 'count' => $translations['web'], 'counts_by_scope' => $translations],
                ['key' => 'roles', 'count' => Role::query()->count()],
                ['key' => 'menus', 'count' => Menu::query()->count()],
                ['key' => 'workflows', 'count' => WorkflowProject::query()->count()],
                // Field and overlay rows, the same rows an export of this section holds.
                ['key' => 'custom_fields', 'count' => count(app(CustomFieldsSection::class)->export(new ExportContext())->rows)],
                ['key' => 'tables', 'count' => array_sum(array_column($tables, 'count')), 'tables' => $tables],
            ],
            'translation_scopes' => ExportConfigPackageRequest::TRANSLATION_SCOPES,
        ]);
    }

    /**
     * Start an export of the chosen sections; answers 202 with the run uuid and the background task uuid.
     *
     * @param ExportConfigPackageRequest $request  Validated sections and translation scope.
     * @param ConfigPackageExporter      $exporter Exporter that records the run and dispatches the job.
     *
     * @return mixed Admin API response.
     */
    public function export(ExportConfigPackageRequest $request, ConfigPackageExporter $exporter): mixed
    {
        try {
            $run = $exporter->export(
                array_values((array) $request->validated('sections')),
                ['translation_scope' => (string) ($request->validated('translation_scope') ?? 'web')],
                (int) $request->user()->id,
            );
        } catch (InvalidArgumentException $e) {
            return ires()->error()->code(422)->errorCode('VALIDATION_FAILED')->message($e->getMessage());
        }

        return ires()->accepted($this->started($run));
    }

    /**
     * Download the package of the caller's succeeded export as a JSON attachment.
     *
     * @param Request $request Signed-in Webmaster.
     * @param string  $uuid    Export run uuid.
     *
     * @return mixed Streamed JSON file, or a 404 admin API response.
     */
    public function download(Request $request, string $uuid): mixed
    {
        $run = $this->ownRun($request, $uuid)
            ->where('kind', ConfigPackageRun::KIND_EXPORT)
            ->where('status', ConfigPackageRun::STATUS_SUCCEEDED)
            ->first();

        if ($run === null || !$run->hasPayload()) {
            return $this->notFound();
        }

        try {
            $json = json_encode($run->payload(), PackageFormat::JSON_FLAGS | JSON_PRETTY_PRINT);
        } catch (Throwable) {
            return $this->notFound();
        }

        $host = preg_replace('/[^A-Za-z0-9.-]+/', '-', (string) (parse_url((string) config('app.url', ''), PHP_URL_HOST) ?: 'site'));
        $filename = sprintf('config-package-%s-%s.json', $host, ($run->created_at ?? now())->format('Ymd-His'));

        return new StreamedResponse(static function () use ($json): void {
            echo $json;
        }, 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $filename . '"',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'no-store, private',
        ]);
    }

    /**
     * Upload a package: decode and validate it first (422 with the validator messages and no run), then record a
     * queued dry run holding the package and dispatch the dry-run job; answers 202 with the run uuid.
     *
     * @param UploadConfigPackageRequest $request   Validated `.json` file of at most 20 MB.
     * @param PackageValidator           $validator Package format validator.
     * @param SecretGuard                $guard     Secret guard.
     *
     * @return mixed Admin API response.
     */
    public function upload(UploadConfigPackageRequest $request, PackageValidator $validator, SecretGuard $guard): mixed
    {
        $contents = (string) $request->file('file')?->get();

        try {
            $package = json_decode($contents, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $package = null;
        }

        if (!is_array($package)) {
            return ires()->error()->code(422)->errorCode('CONFIG_PACKAGE_INVALID_JSON')
                ->message('The file is not a valid JSON configuration package.');
        }

        $result = $validator->validate($package);
        if (!$result->isValid()) {
            return $this->invalidPackage($result->issues());
        }

        try {
            $guard->assertNoSecrets((array) $package['sections']);
            // A row aimed at a real secret setting of this site is refused before anything is stored.
            $guard->assertNoSecretSettingRows((array) $package['sections']);
        } catch (InvalidArgumentException $e) {
            return $this->invalidPackage([['key' => 'text.config_package.validation.secret_blocked', 'params' => [], 'message' => $e->getMessage()]]);
        }

        $userId = (int) $request->user()->id;
        $run = new ConfigPackageRun([
            'kind' => ConfigPackageRun::KIND_DRY_RUN,
            'status' => ConfigPackageRun::STATUS_QUEUED,
            'sections' => array_map('strval', array_keys((array) $package['sections'])),
            'created_by' => $userId,
        ]);
        $run->setPayload($package);
        $run->save();

        DryRunConfigPackageJob::dispatch((string) $run->uuid, $userId);

        return ires()->accepted($this->started($run->refresh()));
    }

    /**
     * One of the caller's runs: status, summary and one page of the plan items of one section.
     *
     * @param Request $request Query: `section` (default the first plan section), `page` (default 1), `per_page` (1-200, default 50).
     * @param string  $uuid    Run uuid.
     *
     * @return mixed Admin API response.
     */
    public function show(Request $request, string $uuid): mixed
    {
        $run = $this->ownRun($request, $uuid)->with('parentRun:id,uuid')->first();

        if ($run === null) {
            return $this->notFound();
        }

        return ires()->data([
            'run' => $this->present($run),
            'plan' => $this->planPage($run, $request),
        ]);
    }

    /**
     * Apply the caller's dry run with the operator's choices; answers 202 with the apply run uuid, or 409 when the
     * applier refuses (stale, drifted, checksum mismatch, not a successful dry run).
     *
     * @param ApplyConfigPackageRequest $request Validated choices.
     * @param ConfigPackageApplier      $applier Applier that records the apply run and dispatches the job.
     * @param string                    $uuid    Dry run uuid.
     *
     * @return mixed Admin API response.
     */
    public function apply(ApplyConfigPackageRequest $request, ConfigPackageApplier $applier, string $uuid): mixed
    {
        $dryRun = $this->ownRun($request, $uuid)->where('kind', ConfigPackageRun::KIND_DRY_RUN)->first();

        if ($dryRun === null) {
            return $this->notFound();
        }

        try {
            $run = $applier->apply($dryRun, $request->choices(), (int) $request->user()->id);
        } catch (ConfigPackageApplyRefused $e) {
            return ires()->conflict($e->getMessage())->errorCode('CONFIG_PACKAGE_APPLY_REFUSED');
        }

        return ires()->accepted($this->started($run));
    }

    /**
     * Paginated history of the caller's runs, newest first, without payload or plan; optional filter `kind`.
     *
     * @param Request $request Query: per_page (1-100, default 20), page, kind.
     *
     * @return mixed Admin API response with `data` and `meta`.
     */
    public function runs(Request $request): mixed
    {
        $perPage = max(1, min(100, (int) $request->query('per_page', 20)));
        $query = ConfigPackageRun::query()
            ->where('created_by', (int) $request->user()->id)
            ->with('parentRun:id,uuid')
            ->orderByDesc('id');

        $kind = $request->query('kind');
        if (is_string($kind) && in_array($kind, ConfigPackageRun::KINDS, true)) {
            $query->where('kind', $kind);
        }

        $page = $query->paginate($perPage)->through(fn (ConfigPackageRun $run): array => $this->present($run));

        return ires()->fromPaginator($page, false);
    }

    /**
     * Query of the caller's run with this uuid.
     *
     * @return Builder<ConfigPackageRun>
     */
    private function ownRun(Request $request, string $uuid): Builder
    {
        return ConfigPackageRun::query()
            ->where('uuid', $uuid)
            ->where('created_by', (int) $request->user()->id);
    }

    /**
     * Response body of a started run: run uuid, background task uuid (when already known) and status.
     *
     * @return array{run: string, task: string|null, status: string}
     */
    private function started(ConfigPackageRun $run): array
    {
        return [
            'run' => (string) $run->uuid,
            'task' => $run->background_task_uuid !== null ? (string) $run->background_task_uuid : null,
            'status' => (string) $run->status,
        ];
    }

    /**
     * Run metadata without payload, plan or numeric ids.
     *
     * @return array<string, mixed>
     */
    private function present(ConfigPackageRun $run): array
    {
        return [
            'uuid' => (string) $run->uuid,
            'kind' => $run->kind,
            'status' => $run->status,
            'sections' => array_values((array) $run->sections),
            'package_checksum' => $run->package_checksum,
            'summary' => $run->summary,
            'parent_uuid' => $run->parentRun?->uuid,
            'background_task_uuid' => $run->background_task_uuid,
            'backup_ref' => $run->backup_ref,
            'has_package' => $run->hasPayload(),
            'created_at' => $run->created_at?->toIso8601String(),
            'updated_at' => $run->updated_at?->toIso8601String(),
            'expires_at' => $run->expires_at?->toIso8601String(),
        ];
    }

    /**
     * Plan of a run with the items of one section paged. Section overviews come without items; the fingerprint is
     * left out. Apply runs only carry their effective `choices`.
     *
     * @return array<string, mixed>|null Null when the run has no plan.
     */
    private function planPage(ConfigPackageRun $run, Request $request): ?array
    {
        $plan = (array) ($run->plan ?? []);
        if ($plan === []) {
            return null;
        }

        $sections = is_array($plan['sections'] ?? null) ? $plan['sections'] : [];
        $overview = [];
        foreach ($sections as $name => $section) {
            $overview[(string) $name] = array_diff_key((array) $section, ['items' => true]);
        }

        $requested = $request->query('section');
        $section = is_string($requested) && array_key_exists($requested, $sections)
            ? $requested
            : (array_key_first($sections) !== null ? (string) array_key_first($sections) : null);

        $items = $section !== null ? array_values((array) ($sections[$section]['items'] ?? [])) : [];
        $perPage = max(1, min(self::PLAN_MAX_PER_PAGE, (int) $request->query('per_page', self::PLAN_PER_PAGE)));
        $lastPage = max(1, (int) ceil(count($items) / $perPage));
        $page = max(1, min($lastPage, (int) $request->query('page', 1)));

        return [
            'sections' => $overview,
            'section' => $section,
            'items' => array_slice($items, ($page - 1) * $perPage, $perPage),
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => $lastPage,
            'item_total' => count($items),
            'warnings' => array_values((array) ($plan['warnings'] ?? [])),
            'required_secrets' => $plan['required_secrets'] ?? null,
            'choices' => $plan['choices'] ?? [],
        ];
    }

    /**
     * 422 for a package that failed validation or carries secret data; messages name paths, never values.
     *
     * `errors` keeps the English messages for API clients; `issues` carries translation keys with params.
     *
     * @param list<array{key: string, params: array<string, mixed>, message: string}> $issues Validator issues.
     */
    private function invalidPackage(array $issues): mixed
    {
        return ires()->error()->code(422)->errorCode('CONFIG_PACKAGE_INVALID')
            ->message('The configuration package did not pass validation.')
            ->data([
                'errors' => array_values(array_map(static fn (array $issue): string => $issue['message'], $issues)),
                'issues' => array_values($issues),
            ]);
    }

    /**
     * 404 for a run that does not exist, is not the caller's or does not fit the action.
     */
    private function notFound(): mixed
    {
        return ires()->notFound('The configuration package run was not found.')->errorCode('CONFIG_PACKAGE_RUN_NOT_FOUND');
    }
}
