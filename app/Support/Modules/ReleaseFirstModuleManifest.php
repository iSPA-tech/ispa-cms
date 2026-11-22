<?php

declare(strict_types=1);

namespace App\Support\Modules;

use Illuminate\Contracts\Container\Container;
use Illuminate\Support\Collection;
use Illuminate\Filesystem\Filesystem;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\ModuleManifest;

/**
 * Module manifest that ignores scan-path manifests shadowing a release module.
 *
 * The parent globs every scan path and keeps all enabled manifests, so a runtime
 * folder named like a release module would add its own providers and files.
 * Here such a manifest is dropped; the release manifest stays. When
 * {@see RuntimePrecedence} prefers the runtime copy, the release
 * manifest is dropped instead, but only if the runtime manifest is present.
 */
class ReleaseFirstModuleManifest extends ModuleManifest
{
    /**
     * @param array<int, string> $paths Scan paths (with the trailing `/*`).
     * @param string $releasePath Release modules folder.
     * @param string|null $basePath Application base path for RuntimePrecedence; null keeps the release copy always.
     */
    public function __construct(Filesystem $files, array $paths, string $manifestPath, private readonly ActivatorInterface $statuses, private readonly string $releasePath, private readonly ?string $basePath = null)
    {
        parent::__construct($files, $paths, $manifestPath, $statuses);
    }

    /** Result of the last scan, kept per process like the parent's (rebuilt in unit tests). */
    private static ?Collection $filtered = null;

    /**
     * Enabled manifests, without scan-path copies of release module names.
     */
    public function getModulesData(): Collection
    {
        if (self::$filtered !== null && ! app()->runningUnitTests()) {
            return self::$filtered;
        }

        return self::$filtered = $this->filterReleaseFirst($this->scanManifests());
    }

    /**
     * The parent's scan, except that a module.json that is not a JSON object with a name is skipped instead of
     * stopping the boot: a broken runtime copy must leave the release copy running.
     */
    private function scanManifests(): Collection
    {
        return $this->paths
            ->flatMap(function (string $path): array {
                $rows = [];
                foreach (glob($path . '/module.json') ?: [] as $manifest) {
                    $json = json_decode((string) @file_get_contents($manifest), true);
                    if (is_array($json) && ! array_is_list($json) && is_string($json['name'] ?? null) && $json['name'] !== '') {
                        $rows[] = ['module_directory' => dirname($manifest), ...$json];
                    }
                }

                return $rows;
            })
            ->filter(fn (array $module): bool => $this->statuses->hasStatus($module['name'], true))
            ->sortBy(fn (array $module) => $module['priority'] ?? 0);
    }

    /**
     * Drop the copy that does not win for every release module name.
     */
    private function filterReleaseFirst(Collection $data): Collection
    {

        $release = [];
        foreach (glob(rtrim($this->releasePath, '/') . '/*/module.json') ?: [] as $manifest) {
            $json = json_decode((string) @file_get_contents($manifest), true);
            if (is_array($json) && is_string($json['name'] ?? null)) {
                $release[strtolower($json['name'])] = dirname($manifest);
            }
        }
        if ($release === []) {
            return $data;
        }

        $runtimeWins = [];
        if ($this->basePath !== null) {
            $present = [];
            foreach ($data as $module) {
                $key = strtolower((string) ($module['name'] ?? ''));
                if (isset($release[$key]) && $release[$key] !== ($module['module_directory'] ?? null)) {
                    $present[$key] = (string) $module['name'];
                }
            }
            foreach ($present as $key => $name) {
                if (basename($release[$key]) === $name && RuntimePrecedence::preferRuntime($this->basePath, $name)) {
                    $runtimeWins[$key] = true;
                }
            }
        }

        return $data->filter(static function (array $module) use ($release, $runtimeWins): bool {
            $key = strtolower((string) ($module['name'] ?? ''));
            if (! isset($release[$key])) {
                return true;
            }

            return isset($runtimeWins[$key])
                ? $release[$key] !== $module['module_directory']
                : $release[$key] === $module['module_directory'];
        })->values();
    }

    /**
     * Build the manifest like the nwidart provider does, for the container extender.
     */
    public static function fromContainer(Container $app, string $manifestPath): self
    {
        $repository = $app->make(\Nwidart\Modules\Contracts\RepositoryInterface::class);

        return new self(new Filesystem(), $repository->getScanPaths(), $manifestPath, $app->make(ActivatorInterface::class), $repository->getPath(), method_exists($app, 'basePath') ? $app->basePath() : null);
    }
}
