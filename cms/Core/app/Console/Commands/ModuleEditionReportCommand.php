<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\ModuleEdition;
use Illuminate\Console\Command;

/**
 * Lists the modules under a modules directory by edition bucket and flags open modules that require a
 * non-open module. Exit code 1 when any such violation exists.
 */
class ModuleEditionReportCommand extends Command
{
    protected $signature = 'cms:module-editions {--path= : Directory whose subdirectories contain module.json (default: base_path(\'cms\'))} {--json : Print JSON instead of text}';

    protected $description = 'List modules by edition bucket (open, premium, internal, client, game) and check the open dependency rule.';

    /**
     * Print the report.
     */
    public function handle(): int
    {
        $path = (string) ($this->option('path') ?: base_path('cms'));
        $buckets = array_fill_keys(ModuleEdition::ALL, []);
        $undeclared = [];
        $violations = [];

        $files = glob(rtrim($path, '/\\') . '/*/module.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $name = basename(dirname($file));
            $manifest = json_decode((string) file_get_contents($file), true);
            $manifest = is_array($manifest) ? $manifest : [];
            $bucket = ModuleEdition::of($name, $manifest);
            $buckets[$bucket][] = $name;
            if ($bucket !== ModuleEdition::GAME && !ModuleEdition::isDeclared($manifest)) {
                $undeclared[] = $name;
            }
            array_push($violations, ...ModuleEdition::dependencyViolations($name, $manifest, ModuleEdition::requireNames($manifest['requires'] ?? []), $path));
        }

        if ((bool) $this->option('json')) {
            $this->line((string) json_encode(['buckets' => $buckets, 'undeclared' => $undeclared, 'violations' => $violations], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($buckets as $bucket => $names) {
                $this->line(sprintf('%s (%d): %s', $bucket, count($names), $names === [] ? '-' : implode(', ', $names)));
            }
            foreach ($undeclared as $name) {
                $this->warn('No edition declared (counts as internal): ' . $name);
            }
            foreach ($violations as $problem) {
                $this->error($problem);
            }
        }

        return $violations === [] ? self::SUCCESS : self::FAILURE;
    }
}
