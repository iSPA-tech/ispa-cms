<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Storage;

use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\SafeHttpFetcher;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationFailure;
use Cms\Core\Workflows\Runtime\Runners\Integration\IntegrationRunner;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\Storage;

/**
 * `file_storage`: write, read or list text/JSON files in the workflow's own folder `workflow/project-<id>/` on a disk
 * from `core.workflow_nodes.fetch.settings.storage.disks` (default `local`, which is private).
 *
 * Config: `op` (write|read|list), `disk`, `path` (relative; letters, digits, `-_.` and `/`; no `..`), `format`
 * (text|json), `content` (template; JSON format stores the value as JSON), `storeAs`.
 * Result: write {path, bytes}; read {path, content, bytes}; list {files[{path, bytes}]}. Paths in results are
 * relative to the workflow folder.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Storage
 * @since   2026-11-24
 */
class FileStorageRunner extends IntegrationRunner
{
    private const PATH_PATTERN = '#^[A-Za-z0-9_\-][A-Za-z0-9_\-.]{0,99}(/[A-Za-z0-9_\-][A-Za-z0-9_\-.]{0,99}){0,9}$#';

    protected function type(): string
    {
        return 'file_storage';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $settings = (array) SafeHttpFetcher::settings('storage', []);
        $disks = array_values(array_filter((array) ($settings['disks'] ?? ['local']), 'is_string'));
        $disk = (string) ($config['disk'] ?? ($disks[0] ?? 'local'));
        if (!in_array($disk, $disks, true)) {
            throw new IntegrationFailure("disk {$disk} is not allowed for workflow files.");
        }
        $maxBytes = max(1, (int) ($settings['max_bytes'] ?? 5 * 1024 * 1024));
        $op = (string) ($config['op'] ?? 'write');
        $format = (string) ($config['format'] ?? 'text');
        if (!in_array($format, ['text', 'json'], true)) {
            throw new IntegrationFailure('format must be text or json.');
        }
        $root = $this->root($context);
        $storage = Storage::disk($disk);

        if ($op === 'list') {
            $prefix = $this->renderText($config['path'] ?? '', $context);
            $folder = $prefix === '' ? $root : $root . '/' . $this->safePath($prefix);
            $files = [];
            foreach (array_slice($storage->allFiles($folder), 0, 1000) as $file) {
                $files[] = ['path' => substr($file, strlen($root) + 1), 'bytes' => $storage->size($file)];
            }
            $result = ['files' => $files, 'count' => count($files)];
        } else {
            $relative = $this->safePath($this->renderText($config['path'] ?? '', $context));
            $full = $root . '/' . $relative;
            if ($op === 'write') {
                $value = $this->render($config['content'] ?? '', $context);
                if ($format === 'json' && is_string($value)) {
                    // A template renders to a JSON string; keep the value it encodes, not a quoted string.
                    $decoded = json_decode($value, true);
                    $value = json_last_error() === JSON_ERROR_NONE ? $decoded : $value;
                }
                $content = $format === 'json'
                    ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT)
                    : (is_array($value) ? (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : (string) $value);
                if (strlen($content) > $maxBytes) {
                    throw new IntegrationFailure("content is larger than {$maxBytes} bytes.");
                }
                if (!$storage->put($full, $content)) {
                    throw new IntegrationFailure('the file could not be written.');
                }
                $result = ['path' => $relative, 'bytes' => strlen($content)];
            } elseif ($op === 'read') {
                if (!$storage->exists($full)) {
                    throw new IntegrationFailure("file {$relative} does not exist.");
                }
                if ($storage->size($full) > $maxBytes) {
                    throw new IntegrationFailure("file {$relative} is larger than {$maxBytes} bytes.");
                }
                $raw = (string) $storage->get($full);
                $content = $raw;
                if ($format === 'json') {
                    $content = json_decode($raw, true);
                    if (json_last_error() !== JSON_ERROR_NONE) {
                        throw new IntegrationFailure("file {$relative} is not valid JSON.");
                    }
                }
                $result = ['path' => $relative, 'content' => $content, 'bytes' => strlen($raw)];
            } else {
                throw new IntegrationFailure('op must be write, read or list.');
            }
        }

        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'file_storage',
            'op' => $op,
            'disk' => $disk,
            'path' => $result['path'] ?? null,
            'bytes' => $result['bytes'] ?? null,
        ]]);
    }

    private function root(WorkflowContext $context): string
    {
        $instanceId = (int) $context->getVarPath('__runtime.instance_id', 0);
        $projectId = $instanceId > 0 ? (int) WorkflowInstance::query()->whereKey($instanceId)->value('project_id') : 0;
        if ($projectId <= 0) {
            throw new IntegrationFailure('file_storage works only inside a workflow run.');
        }

        return 'workflow/project-' . $projectId;
    }

    private function safePath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path), '/');
        if ($path === '' || str_contains($path, '..') || !preg_match(self::PATH_PATTERN, $path)) {
            throw new IntegrationFailure('path must be a relative file name (letters, digits, - _ . and /).');
        }

        return $path;
    }
}
