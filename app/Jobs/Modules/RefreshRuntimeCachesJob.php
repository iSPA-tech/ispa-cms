<?php

declare(strict_types=1);

namespace App\Jobs\Modules;

use App\Support\Modules\ArtisanSubprocess;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Rebuild the runtime caches after a module status change and make
 * long-running workers reload.
 *
 * For config, route and event (in that order) the cache is rebuilt only
 * when its cache file already exists, through a clean ArtisanSubprocess
 * (nwidart keeps the module list per process). A failed `<name>:cache`
 * falls back to `<name>:clear` so the site keeps working uncached, as the
 * deploy script `after-activation.sh` does. Then `queue:restart`, and
 * `horizon:terminate` when Horizon is installed.
 *
 * Atomic write: `<name>:cache` first clears its file and then
 * writes the new one, so a request arriving in between found no route cache
 * and answered 500 during a Content upgrade on a dev site. The child now gets
 * `APP_CONFIG_CACHE` / `APP_ROUTES_CACHE` / `APP_EVENTS_CACHE` pointing at a
 * temporary file in the same folder, and the finished file is renamed over
 * the live one (rename is atomic within one file system), so the live cache
 * file is never missing. A successful command that wrote no file leaves the
 * previous cache in place.
 *
 * @package App\Jobs\Modules
 * @author  iSPA Team
 * @since   2026-11-06
 */
class RefreshRuntimeCachesJob implements ShouldQueue, ShouldBeUnique
{
    use Dispatchable, InteractsWithQueue, Queueable;

    /** Seconds the uniqueness lock is held. */
    public int $uniqueFor = 120;

    /** Timeout in seconds for each artisan subprocess. */
    private const STEP_TIMEOUT = 120;

    /** Environment variable that moves each cache file (read by Laravel's Application::normalizeCachePath()). */
    private const CACHE_VARIABLES = [
        'config' => 'APP_CONFIG_CACHE',
        'route' => 'APP_ROUTES_CACHE',
        'event' => 'APP_EVENTS_CACHE',
    ];

    /**
     * Rebuild existing caches and signal workers to reload.
     *
     * @param  ArtisanSubprocess  $artisan  Clean artisan subprocess runner.
     * @param  Kernel             $console  Console kernel, used to check that horizon:terminate exists.
     * @return void
     *
     * @author  iSPA Team
     * @since   2026-11-06
     */
    public function handle(ArtisanSubprocess $artisan, Kernel $console): void
    {
        $caches = [
            'config' => app()->getCachedConfigPath(),
            'route' => app()->getCachedRoutesPath(),
            'event' => app()->getCachedEventsPath(),
        ];

        foreach ($caches as $path) {
            $this->removeStaleTemporaryFiles($path);
        }

        foreach ($caches as $name => $path) {
            if (! is_file($path)) {
                continue;
            }

            if ($this->rebuildAtomically($artisan, $name, $path)) {
                continue;
            }

            Log::warning("Module status change: {$name}:cache failed; clearing the {$name} cache instead.");
            $this->step($artisan, "{$name}:clear");
        }

        $this->step($artisan, 'queue:restart');

        if (array_key_exists('horizon:terminate', $console->all())) {
            $this->step($artisan, 'horizon:terminate');
        }
    }

    /**
     * Build one cache into a temporary file beside $path and rename it over the live file.
     *
     * @param  ArtisanSubprocess  $artisan  Clean artisan subprocess runner.
     * @param  string             $name     `config`, `route` or `event`.
     * @param  string             $path     Live cache file.
     * @return bool  False when the command failed or the new file could not be moved into place.
     */
    private function rebuildAtomically(ArtisanSubprocess $artisan, string $name, string $path): bool
    {
        $temp = dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path, '.php').'.'.bin2hex(random_bytes(6)).'.tmp.php';
        $artisan->setExtraEnvironment([self::CACHE_VARIABLES[$name] => $temp]);
        try {
            $built = $this->step($artisan, "{$name}:cache");
        } finally {
            $artisan->setExtraEnvironment([]);
        }

        if ($built && is_file($temp)) {
            if (@rename($temp, $path)) {
                return true;
            }
            Log::warning("Module status change: the new {$name} cache could not be moved into place.");
            $built = false;
        } elseif ($built) {
            Log::warning("Module status change: {$name}:cache wrote no file; the previous {$name} cache stays.");
        }

        if (is_file($temp)) {
            @unlink($temp);
        }

        return $built;
    }

    /**
     * Remove temporary cache files of $path older than ten minutes (left behind when a worker was killed mid-build;
     * nothing loads them and `optimize:clear` does not see them).
     *
     * @param  string  $path  Live cache file.
     * @return void
     */
    private function removeStaleTemporaryFiles(string $path): void
    {
        foreach (glob(dirname($path).DIRECTORY_SEPARATOR.'.'.basename($path, '.php').'.*.tmp.php') ?: [] as $leftover) {
            if (@filemtime($leftover) < time() - 600) {
                @unlink($leftover);
            }
        }
    }

    /**
     * Run one artisan command; failures are logged, never thrown.
     */
    private function step(ArtisanSubprocess $artisan, string $command): bool
    {
        try {
            $result = $artisan->run([$command], self::STEP_TIMEOUT);
        } catch (Throwable $exception) {
            Log::warning("Module status change: {$command} could not run.", ['error' => $exception->getMessage()]);

            return false;
        }

        if ($result->successful()) {
            return true;
        }

        Log::warning("Module status change: {$command} failed.", [
            'exit_code' => $result->exitCode(),
            'error_output' => $result->errorOutput(),
        ]);

        return false;
    }
}
