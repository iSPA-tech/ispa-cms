<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use ReflectionClass;
use Throwable;

/**
 * Run the seeders belonging to the project this deployment is running as.
 *
 * Modules already have this: `cms:modules-sync` discovers and runs every seeder under an enabled
 * module's `database/seeders`. Projects had nothing equivalent. A project seeder could only run if
 * it was named by hand in the deployment hook — and that hook is shared by every site, so naming one
 * project in it is not an option — or if something called the root `DatabaseSeeder`, which no deploy
 * does. Seeders registered there are dead code in production.
 *
 * That is what it costs: a project seeder sat correctly registered in
 * `DatabaseSeeder`, its table stayed empty, and every author saw "No active journal is
 * configured for submissions".
 *
 * Only the active project's seeders run. A deployment ships one `projects/<Project>` tree, and
 * `ispa-cms.folder_name` names it, so this stays a no-op on a site that has no project seeders.
 */
final class SeedActiveProjectCommand extends Command
{
    protected $signature = 'cms:project-seed
        {--project= : Override the project folder instead of using ispa-cms.folder_name}
        {--pretend : List the seeders that would run without running them}';

    protected $description = 'Run the database seeders shipped by the active project under projects/<Project>/Database/Seeders.';

    public function handle(): int
    {
        $project = trim((string) ($this->option('project') ?: config('ispa-cms.folder_name', 'Default')));

        if ($project === '' || strcasecmp($project, 'Default') === 0) {
            $this->components->info('No project-specific deployment; nothing to seed.');

            return self::SUCCESS;
        }

        $directory = base_path('projects/' . $project . '/Database/Seeders');

        if (! File::isDirectory($directory)) {
            $this->components->info("Project {$project} ships no seeders.");

            return self::SUCCESS;
        }

        $seeders = $this->discover($project, $directory);

        if ($seeders === []) {
            $this->components->info("Project {$project} ships no runnable seeders.");

            return self::SUCCESS;
        }

        $failed = false;

        foreach ($seeders as $class) {
            if ($this->option('pretend')) {
                $this->components->twoColumnDetail('Would run', $class);

                continue;
            }

            $this->components->twoColumnDetail('Seeder', $class);

            try {
                $exitCode = $this->call('db:seed', ['--class' => $class, '--force' => true]);

                if ($exitCode !== self::SUCCESS) {
                    $failed = true;
                    $this->components->error("Seeder failed with exit code {$exitCode}: {$class}");
                }
            } catch (Throwable $exception) {
                // Report every failure but keep running the remaining project seeders so operators
                // receive the full failure inventory. The command still fails after the loop.
                $failed = true;
                $this->components->error("Seeder failure: {$exception->getMessage()}");
                report($exception);
            }
        }

        return $failed ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<class-string<Seeder>>
     */
    private function discover(string $project, string $directory): array
    {
        $namespace = "PJ\\{$project}\\Database\\Seeders\\";
        $candidates = [];

        foreach (File::files($directory) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $class = $namespace . $file->getFilenameWithoutExtension();

            if (! class_exists($class) || ! is_subclass_of($class, Seeder::class)) {
                continue;
            }

            $candidates[] = $class;
        }

        //  An aggregate "*DatabaseSeeder" runs first, matching how module seeders are ordered.
        usort(
            $candidates,
            static fn (string $a, string $b): int => (Str::endsWith($a, 'DatabaseSeeder') ? 0 : 10)
                <=> (Str::endsWith($b, 'DatabaseSeeder') ? 0 : 10)
                ?: strcmp($a, $b),
        );

        return $this->withoutNestedCalls($candidates);
    }

    /**
     * Drop seeders another seeder in the same project already calls, so they run once, not twice.
     *
     * @param  list<class-string<Seeder>>  $classes
     * @return list<class-string<Seeder>>
     */
    private function withoutNestedCalls(array $classes): array
    {
        $known = array_fill_keys($classes, true);
        $nested = [];

        foreach ($classes as $class) {
            foreach ($this->referencedClasses($class) as $referenced) {
                if ($referenced !== $class && isset($known[$referenced])) {
                    $nested[$referenced] = true;
                }
            }
        }

        return array_values(array_filter($classes, static fn (string $class): bool => ! isset($nested[$class])));
    }

    /**
     * @param  class-string<Seeder>  $class
     * @return list<string>
     */
    private function referencedClasses(string $class): array
    {
        $file = (new ReflectionClass($class))->getFileName();

        if (! is_string($file) || ! is_file($file)) {
            return [];
        }

        $source = (string) file_get_contents($file);

        if ($source === '' || preg_match_all('/(?<![A-Za-z0-9_\\\\])\\\\?([A-Za-z_][A-Za-z0-9_\\\\]*)::class/', $source, $matches) === 0) {
            return [];
        }

        $imports = $this->imports($source);

        return array_values(array_unique(array_map(
            static fn (string $match): string => str_contains($match, '\\')
                ? ltrim($match, '\\')
                : ($imports[$match] ?? $match),
            $matches[1],
        )));
    }

    /**
     * @return array<string, string>
     */
    private function imports(string $source): array
    {
        if (preg_match_all('/^use\s+([^;]+);/m', $source, $matches) === 0) {
            return [];
        }

        $imports = [];

        foreach ($matches[1] as $statement) {
            $statement = trim($statement);

            if (str_contains($statement, ' as ')) {
                [$fqcn, $alias] = array_map('trim', explode(' as ', $statement, 2));
                $imports[$alias] = ltrim($fqcn, '\\');

                continue;
            }

            $fqcn = ltrim($statement, '\\');
            $imports[Str::afterLast($fqcn, '\\')] = $fqcn;
        }

        return $imports;
    }
}
