<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console\Commands;

use App\Services\WidgetQueryService;
use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\LegacyWidgetArtifactSetPublisher;
use Cms\Core\Services\Widget\PageWidgetBlockWalker;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use JsonException;

/**
 * Read-only inventory audit of the legacy Widget surface.
 *
 * Reports, per widget and theme: JS presence (boolean + byte length, never
 * the JS body), content/CSS SHA-256 fingerprints, provider keys parsed from
 * {query:...} placeholders, executable-surface detection counts (inline event
 * handlers, script tags, dangerous URL schemes, Blade include/exec
 * directives, raw echoes), project applicability mirroring the generator
 * fan-out semantics, generated artifact presence per applicable project, and
 * orphaned generated files. Aggregates a summary with provider key usage.
 *
 * READ-ONLY CONTRACT (Gate 0 Task 5a frozen contract): this command performs
 * database SELECTs and filesystem reads only. It must never write widget
 * rows, cache entries, or files. Do not add cache()->remember, file writes,
 * or Artisan sub-calls that mutate state.
 *
 * Usage: php artisan cms:audit-legacy-widgets [--format=json]
 *
 * @see     \Cms\Core\Console\GenerateTemplateBladeCommand fan-out semantics mirrored here
 * @see     \App\Services\WidgetQueryService                provider key registry
 * @package Cms\Core\Console\Commands
 * @author  iSPA Team
 * @since   8.0.0 (2026-07-17)
 */
class AuditLegacyWidgetsCommand extends Command
{
    /** Matches {query:type.name ...} provider placeholders in theme content. */
    private const PROVIDER_PLACEHOLDER_PATTERN = '/\{query:(\w+)\.(\w+)/';

    /**
     * Inline DOM event handler attributes (onclick=, onerror=, ...).
     * Tolerance: the leading whitespace requirement misses quote-adjacent
     * malformed handlers (e.g. "onclick=) and can over-match non-handler
     * attributes such as only= or online=; counts are indicative, not exact.
     */
    private const INLINE_HANDLER_PATTERN = '/\son[a-z]+\s*=/i';

    /** Opening <script> tags. */
    private const SCRIPT_TAG_PATTERN = '/<script\b/i';

    /**
     * Dangerous URL schemes usable as script gadgets. The left boundary
     * excludes identifiers that merely end with a scheme word (metadata:,
     * so-data:, ...).
     */
    private const DANGEROUS_URL_PATTERN = '/(?<![\w-])(?:javascript|vbscript|data)\s*:/i';

    /** Blade directives that pull in or execute other templates/PHP. */
    private const BLADE_INCLUDE_PATTERN =
        '/@(include|includeIf|includeWhen|includeUnless|includeFirst|extends|component|each|inject|php)\b/';

    /** Raw (unescaped) Blade echoes. */
    private const RAW_ECHO_PATTERN = '/\{!!/';

    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'cms:audit-legacy-widgets
        {--format=table : Output format (table or json)}
        {--fail-on-integrity : Return a failure code when widget references or artifacts are invalid}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Read-only audit of legacy widgets, themes, generated artifacts, and provider key usage';

    /**
     * Execute the console command.
     *
     * Builds the full inventory report and emits it as JSON or as console
     * tables. Always returns SUCCESS unless report encoding fails.
     *
     * @return int Command::SUCCESS or Command::FAILURE
     */
    public function handle(): int
    {
        $report = $this->buildReport();
        $exitCode = $this->integrityFailed($report) ? self::FAILURE : self::SUCCESS;

        if ($this->option('format') === 'json') {
            try {
                $this->line(json_encode(
                    $report,
                    JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
                ));
            } catch (JsonException $e) {
                $this->error('Failed to encode audit report: ' . $e->getMessage());

                return self::FAILURE;
            }

            return $this->option('fail-on-integrity') ? $exitCode : self::SUCCESS;
        }

        $this->renderTables($report);

        if ($this->option('fail-on-integrity') && $exitCode !== self::SUCCESS) {
            $this->error('Legacy widget integrity check failed.');
        }

        return $this->option('fail-on-integrity') ? $exitCode : self::SUCCESS;
    }

    /**
     * Build the complete read-only inventory report.
     *
     * @return array{widgets: list<array<string, mixed>>, orphaned_generated_files: list<string>, summary: array<string, mixed>}
     */
    protected function buildReport(): array
    {
        $activeManifest = LegacyWidgetArtifactSetPublisher::readActiveManifest();
        $storageBase = $activeManifest === null
            ? storage_path('app/generated_widgets')
            : LegacyWidgetArtifactSetPublisher::rootPath();
        $knownProviderKeys = array_keys((new WidgetQueryService())->availableQueries());

        $widgets = Widget::query()
            ->with(['template', 'inputs'])
            ->orderBy('id')
            ->get();

        $providerKeyUsage = [];
        $expectedGeneratedPaths = [];
        $widgetRows = [];
        $inputCount = 0;
        $themeCount = 0;
        $widgetsWithJs = 0;
        $detectionTotals = [
            'inline_event_handlers' => 0,
            'script_tags' => 0,
            'dangerous_urls' => 0,
            'blade_includes' => 0,
            'raw_echoes' => 0,
        ];
        $generatedPresent = 0;
        $generatedMissing = 0;
        $definitionKeysMissing = 0;

        foreach ($widgets as $widget) {
            if (empty($widget->widget_key)) {
                $definitionKeysMissing++;
            }

            $js = (string) $widget->js;
            $hasJs = $js !== '';

            if ($hasJs) {
                $widgetsWithJs++;
            }

            $themeRows = [];

            foreach ($widget->template as $theme) {
                $themeCount++;

                if (empty($theme->theme_key)) {
                    $definitionKeysMissing++;
                }

                $content = (string) $theme->content;
                $css = (string) $theme->css;

                $queryKeys = $this->extractProviderKeys($content);
                foreach ($queryKeys as $key) {
                    $providerKeyUsage[$key] = ($providerKeyUsage[$key] ?? 0) + 1;
                }

                $detections = $this->detectSurfaces($content);
                foreach ($detections as $name => $count) {
                    $detectionTotals[$name] += $count;
                }

                $projectNames = $this->applicableProjectNames($theme->project_name);
                $generatedFiles = [];

                foreach ($projectNames as $name) {
                    $slug = $name === 'Default' ? 'default' : (string) Str::slug($name);
                    $filename = 'widget_' . $theme->widget_id . '_theme_' . $theme->id . '.blade.php';

                    if ($activeManifest === null) {
                        $path = $storageBase . '/' . $slug . '/' . $filename;
                        $expectedGeneratedPaths[$path] = true;
                        $exists = is_file($path);
                    } else {
                        $path = LegacyWidgetArtifactSetPublisher::resolveProjectFilePath(
                            $activeManifest,
                            $name,
                            $filename
                        );
                        $meta = $activeManifest->projectEntry($name)['files'][$filename] ?? null;
                        $exists = $path !== null
                            && is_readable($path)
                            && is_array($meta)
                            && filesize($path) === (int) ($meta['size'] ?? -1)
                            && hash_file('sha256', $path) === ($meta['sha256'] ?? null);
                    }
                    $generatedFiles[$slug] = $exists;

                    $exists ? $generatedPresent++ : $generatedMissing++;
                }

                $themeRows[] = [
                    'id' => $theme->id,
                    'name' => $theme->name,
                    'project_names' => $projectNames,
                    'fans_out' => $projectNames !== [],
                    'content_bytes' => strlen($content),
                    'content_sha256' => hash('sha256', $content),
                    'css_bytes' => strlen($css),
                    'css_sha256' => $css === '' ? null : hash('sha256', $css),
                    'query_keys' => $queryKeys,
                    'declared_query' => is_array($theme->query) ? $theme->query : null,
                    'detections' => $detections,
                    'generated_files' => $generatedFiles,
                ];
            }

            $inputCount += $widget->inputs->count();
            $definitionKeysMissing += $widget->inputs->filter(fn ($input) => empty($input->input_key))->count();

            $widgetRows[] = [
                'id' => $widget->id,
                'widget_key' => $widget->widget_key,
                'name' => $widget->name,
                'has_js' => $hasJs,
                'js_bytes' => strlen($js),
                'js_sha256' => $hasJs ? hash('sha256', $js) : null,
                'input_names' => $widget->inputs->pluck('name')->all(),
                'themes' => $themeRows,
            ];
        }

        // Report paths relative to storage_path() so output is stable across
        // environments and never leaks absolute local filesystem layout.
        $orphans = $activeManifest === null ? array_map(
            fn (string $path): string => $this->relativeToStoragePath($path),
            $this->orphanedGeneratedFiles($storageBase, $expectedGeneratedPaths)
        ) : [];

        ksort($providerKeyUsage);
        $pageReferences = $this->auditPageWidgetReferences($widgets);

        return [
            'widgets' => $widgetRows,
            'orphaned_generated_files' => $orphans,
            'page_widget_reference_issues' => $pageReferences['issues'],
            'summary' => [
                'widget_count' => $widgets->count(),
                'theme_count' => $themeCount,
                'input_count' => $inputCount,
                'widgets_with_js' => $widgetsWithJs,
                'detection_totals' => $detectionTotals,
                'provider_key_usage' => $providerKeyUsage,
                'known_provider_keys' => $knownProviderKeys,
                'unknown_provider_keys' => array_values(array_diff(array_keys($providerKeyUsage), $knownProviderKeys)),
                'generated_files_expected' => count($expectedGeneratedPaths),
                'generated_files_present' => $generatedPresent,
                'generated_files_missing' => $generatedMissing,
                'orphaned_generated_files' => count($orphans),
                'active_generation_missing' => $activeManifest === null ? 1 : 0,
                'definition_keys_missing' => $definitionKeysMissing,
                'page_widget_blocks' => $pageReferences['blocks'],
                'page_widget_keys_missing' => $pageReferences['keys_missing'],
                'page_widget_orphans' => $pageReferences['orphans'],
                'page_widget_key_mismatches' => $pageReferences['mismatches'],
                'page_widget_empty_placeholders' => $pageReferences['empty_placeholders'],
                'generated_storage_base' => $this->relativeToStoragePath($storageBase),
            ],
        ];
    }

    /** @return array{blocks:int, keys_missing:int, orphans:int, mismatches:int, empty_placeholders:int, issues:list<array<string, mixed>>} */
    protected function auditPageWidgetReferences($widgets): array
    {
        $result = ['blocks' => 0, 'keys_missing' => 0, 'orphans' => 0, 'mismatches' => 0, 'empty_placeholders' => 0, 'issues' => []];

        if (!Schema::hasTable('posts') || !Schema::hasColumn('posts', 'content')) {
            return $result;
        }

        $widgetsById = $widgets->keyBy(fn ($widget) => (int) $widget->id);
        $widgetsByKey = $widgets->filter(fn ($widget) => !empty($widget->widget_key))->keyBy('widget_key');
        $themesById = $widgets->flatMap->template->keyBy(fn ($theme) => (int) $theme->id);

        foreach (DB::table('posts')->select(['id', 'content'])->whereNotNull('content')->cursor() as $post) {
            $content = is_array($post->content) ? $post->content : json_decode((string) $post->content, true);
            if (!is_array($content)) {
                continue;
            }

            app(PageWidgetBlockWalker::class)->walk($content, function (array $block, array $path) use (
                &$result,
                $post,
                $widgetsById,
                $widgetsByKey,
                $themesById,
            ): array {
                // Top-level blocks keep their integer index; nested blocks report a dotted path.
                $index = count($path) === 1 ? $path[0] : implode('.', $path);

                $result['blocks']++;
                $widgetId = (int) ($block['widget_id'] ?? 0);
                $themeId = (int) ($block['template_id'] ?? 0);
                $widgetKey = (string) ($block['widget_key'] ?? '');
                $themeKey = (string) ($block['theme_key'] ?? '');

                // A widget block whose widget was never chosen in the editor references nothing and renders
                // nothing. Report it separately instead of as an orphan so it cannot block a deploy.
                if ($widgetId === 0 && $themeId === 0 && $widgetKey === '' && $themeKey === '') {
                    $result['empty_placeholders']++;
                    $result['issues'][] = ['post_id' => $post->id, 'block_index' => $index, 'type' => 'empty_placeholder'];
                    return $block;
                }

                $numericTheme = $themesById->get($themeId);
                $numericValid = $widgetsById->has($widgetId)
                    && $numericTheme !== null
                    && (int) $numericTheme->widget_id === $widgetId;

                if ($widgetKey === '' || $themeKey === '') {
                    $result['keys_missing']++;
                }

                $stableWidget = $widgetKey === '' ? null : $widgetsByKey->get($widgetKey);
                $stableTheme = $stableWidget === null ? null : $stableWidget->template->firstWhere('theme_key', $themeKey);
                $stableValid = $stableWidget !== null && $stableTheme !== null;

                if (!$numericValid && !$stableValid) {
                    $result['orphans']++;
                    $result['issues'][] = ['post_id' => $post->id, 'block_index' => $index, 'type' => 'orphan'];
                    return $block;
                }

                if (($widgetKey !== '' || $themeKey !== '') && (!$stableValid
                    || $widgetId !== (int) $stableWidget->id
                    || $themeId !== (int) $stableTheme->id)) {
                    $result['mismatches']++;
                    $result['issues'][] = ['post_id' => $post->id, 'block_index' => $index, 'type' => 'key_mismatch'];
                }

                return $block;
            });
        }

        return $result;
    }

    /** @param array<string, mixed> $report */
    protected function integrityFailed(array $report): bool
    {
        $summary = $report['summary'];

        return $summary['definition_keys_missing'] > 0
            || $summary['generated_files_missing'] > 0
            || $summary['orphaned_generated_files'] > 0
            || $summary['active_generation_missing'] > 0
            || $summary['page_widget_orphans'] > 0
            || $summary['page_widget_key_mismatches'] > 0;
    }

    /**
     * Extract provider keys (type.name) from {query:...} placeholders.
     *
     * @return list<string>
     */
    protected function extractProviderKeys(string $content): array
    {
        if (preg_match_all(self::PROVIDER_PLACEHOLDER_PATTERN, $content, $matches) === 0) {
            return [];
        }

        $keys = [];
        foreach ($matches[1] as $index => $type) {
            $keys[] = $type . '.' . $matches[2][$index];
        }

        return array_values(array_unique($keys));
    }

    /**
     * Count executable/host-gadget surfaces inside theme content.
     *
     * @return array{inline_event_handlers: int, script_tags: int, dangerous_urls: int, blade_includes: int, raw_echoes: int}
     */
    protected function detectSurfaces(string $content): array
    {
        return [
            'inline_event_handlers' => (int) preg_match_all(self::INLINE_HANDLER_PATTERN, $content),
            'script_tags' => (int) preg_match_all(self::SCRIPT_TAG_PATTERN, $content),
            'dangerous_urls' => (int) preg_match_all(self::DANGEROUS_URL_PATTERN, $content),
            'blade_includes' => (int) preg_match_all(self::BLADE_INCLUDE_PATTERN, $content),
            'raw_echoes' => (int) preg_match_all(self::RAW_ECHO_PATTERN, $content),
        ];
    }

    /**
     * Mirror GenerateTemplateBladeCommand project fan-out semantics exactly:
     * non-array + non-empty scalar wraps to [value]; non-array + empty falls
     * back to ['Default']; an array (including empty) is used as-is — an empty
     * array therefore generates NO files.
     *
     * @param mixed $projectName Raw project_name attribute (json cast)
     * @return list<string>
     */
    protected function applicableProjectNames(mixed $projectName): array
    {
        if (!is_array($projectName)) {
            return !empty($projectName) ? [(string) $projectName] : ['Default'];
        }

        return array_values(array_map('strval', $projectName));
    }

    /**
     * List generated widget files that no current (widget, theme, project)
     * tuple accounts for. Scans every project directory, matching the stale
     * cleanup scope of the generator.
     *
     * @param array<string, bool> $expectedPaths
     * @return list<string>
     */
    protected function orphanedGeneratedFiles(string $storageBase, array $expectedPaths): array
    {
        if (!is_dir($storageBase)) {
            return [];
        }

        $orphans = [];

        foreach (File::directories($storageBase) as $directory) {
            foreach (File::glob($directory . '/widget_*_theme_*.blade.php') as $path) {
                if (!isset($expectedPaths[$path])) {
                    $orphans[] = $path;
                }
            }
        }

        sort($orphans);

        return $orphans;
    }

    /**
     * Express a path relative to the application storage root.
     */
    protected function relativeToStoragePath(string $path): string
    {
        $root = rtrim(storage_path(), '/') . '/';

        return str_starts_with($path, $root) ? substr($path, strlen($root)) : $path;
    }

    /**
     * Render the report as console tables for human review.
     *
     * @param array<string, mixed> $report
     */
    protected function renderTables(array $report): void
    {
        $this->info('Legacy Widget inventory (read-only audit)');

        $themeRows = [];
        foreach ($report['widgets'] as $widget) {
            foreach ($widget['themes'] as $theme) {
                $themeRows[] = [
                    $widget['id'],
                    Str::limit((string) $widget['name'], 24),
                    $widget['has_js'] ? 'yes (' . $widget['js_bytes'] . ' B)' : 'no',
                    $theme['id'],
                    Str::limit((string) $theme['name'], 24),
                    implode(',', $theme['project_names']) ?: '(none)',
                    implode(',', $theme['query_keys']) ?: '-',
                    collect($theme['detections'])->filter()->map(fn ($v, $k) => "$k=$v")->implode(' ') ?: '-',
                    collect($theme['generated_files'])->map(fn ($v, $k) => $k . ($v ? ':ok' : ':missing'))->implode(' ') ?: '-',
                    substr((string) $theme['content_sha256'], 0, 12),
                ];
            }
        }

        $this->table(
            ['Widget', 'Name', 'JS', 'Theme', 'Theme name', 'Projects', 'Provider keys', 'Detections', 'Generated', 'SHA-256'],
            $themeRows
        );

        foreach ($report['orphaned_generated_files'] as $orphan) {
            $this->warn('Orphaned generated file: ' . $orphan);
        }

        $summary = $report['summary'];
        $this->table(['Summary metric', 'Value'], [
            ['Widgets', $summary['widget_count']],
            ['Themes', $summary['theme_count']],
            ['Inputs', $summary['input_count']],
            ['Widgets with JS', $summary['widgets_with_js']],
            ['Provider key usage', collect($summary['provider_key_usage'])->map(fn ($v, $k) => "$k=$v")->implode(', ') ?: '-'],
            ['Unknown provider keys', implode(', ', $summary['unknown_provider_keys']) ?: '-'],
            ['Generated expected/present/missing', $summary['generated_files_expected'] . '/' . $summary['generated_files_present'] . '/' . $summary['generated_files_missing']],
            ['Orphaned generated files', $summary['orphaned_generated_files']],
            ['Active generation missing', $summary['active_generation_missing']],
            ['Definition keys missing', $summary['definition_keys_missing']],
            ['Page widget blocks/keys missing', $summary['page_widget_blocks'] . '/' . $summary['page_widget_keys_missing']],
            ['Page widget orphans/key mismatches', $summary['page_widget_orphans'] . '/' . $summary['page_widget_key_mismatches']],
            ['Page widget empty placeholders', $summary['page_widget_empty_placeholders']],
        ]);
    }
}
