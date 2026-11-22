<?php

declare(strict_types=1);

namespace App\Support\Modules;

use App\Support\Modules\Exceptions\CoreModuleCannotBeDisabled;
use Illuminate\Contracts\Cache\Repository as CacheRepository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Nwidart\Modules\Contracts\ActivatorInterface;
use Nwidart\Modules\Module;
use Throwable;

/**
 * nwidart activator that keeps module enable/disable status in the site
 * database (table `module_statuses`).
 *
 * The activator is built while service providers register, before Eloquent
 * boots, so it only uses the query builder, the cache manager and the logger.
 *
 * Read order: in-process memo, cache, database. When the table does not exist
 * yet the statuses file is used; on any other database error the last known
 * good value from the cache is used, else the file, and one warning is logged
 * per process. An empty table is seeded once from the statuses file.
 *
 * Core is always enabled and can never be disabled or deleted.
 */
class DatabaseActivator implements ActivatorInterface
{
    /**
     * Table holding the statuses.
     */
    public const TABLE = 'module_statuses';

    /**
     * Module that is always enabled.
     */
    public const CORE = 'Core';

    /**
     * Fresh cache entry lifetime in seconds (one day).
     */
    private const CACHE_TTL = 86400;

    /**
     * Whether the database-error warning was already logged in this process.
     */
    private static bool $warned = false;

    /**
     * In-process memo of name => enabled.
     *
     * @var array<string, bool>|null
     */
    private ?array $statuses = null;

    /**
     * Build the activator.
     *
     * @param Container $app Application container.
     */
    public function __construct(private readonly Container $app)
    {
    }

    /**
     * Forget per-process state (the warning latch). Intended for tests.
     */
    public static function flushProcessState(): void
    {
        self::$warned = false;
    }

    /**
     * Cache key for this site's statuses, derived from the connection's
     * driver, host, port and database so sites sharing a cache prefix never
     * collide.
     *
     * @return string Cache key.
     */
    public function cacheKey(): string
    {
        $config = $this->app['config'];
        $name = $this->connectionName() ?? $config->get('database.default');
        $connection = (array) $config->get('database.connections.'.$name, []);
        $host = $connection['host'] ?? '';

        $fingerprint = implode('|', [
            (string) ($connection['driver'] ?? ''),
            is_array($host) ? implode(',', $host) : (string) $host,
            (string) ($connection['port'] ?? ''),
            (string) ($connection['database'] ?? ''),
        ]);

        return 'module-statuses:v1:'.sha1($fingerprint);
    }

    /**
     * {@inheritDoc}
     */
    public function enable(Module $module): void
    {
        $this->setActiveByName($module->getName(), true);
    }

    /**
     * {@inheritDoc}
     *
     * @throws CoreModuleCannotBeDisabled When the module is Core.
     */
    public function disable(Module $module): void
    {
        $this->setActiveByName($module->getName(), false);
    }

    /**
     * {@inheritDoc}
     *
     * Core is always enabled; unknown modules are disabled.
     */
    public function hasStatus(Module|string $module, bool $status): bool
    {
        $name = $module instanceof Module ? $module->getName() : $module;

        if ($name === self::CORE) {
            return $status;
        }

        $statuses = $this->statuses();

        if (! isset($statuses[$name])) {
            return $status === false;
        }

        return $statuses[$name] === $status;
    }

    /**
     * {@inheritDoc}
     *
     * @throws CoreModuleCannotBeDisabled When disabling Core.
     */
    public function setActive(Module $module, bool $active): void
    {
        $this->setActiveByName($module->getName(), $active);
    }

    /**
     * {@inheritDoc}
     *
     * @throws CoreModuleCannotBeDisabled When disabling Core.
     */
    public function setActiveByName(string $name, bool $active): void
    {
        if ($name === self::CORE && ! $active) {
            throw new CoreModuleCannotBeDisabled('setActive');
        }

        $this->ensureSeeded();
        $this->table()->upsert(
            [['name' => $name, 'enabled' => $active] + $this->audit()],
            ['name'],
            ['enabled', 'changed_at', 'changed_by'],
        );
        $this->forget();
    }

    /**
     * {@inheritDoc}
     *
     * @throws CoreModuleCannotBeDisabled When the module is Core.
     */
    public function delete(Module $module): void
    {
        if ($module->getName() === self::CORE) {
            throw new CoreModuleCannotBeDisabled('delete');
        }

        $this->ensureSeeded();
        $this->table()->where('name', $module->getName())->delete();
        $this->forget();
    }

    /**
     * {@inheritDoc}
     *
     * Deletes every row except Core and keeps Core enabled, so the table is
     * never empty afterwards (an empty table would re-import the file).
     */
    public function reset(): void
    {
        $this->table()->where('name', '!=', self::CORE)->delete();
        $this->table()->upsert(
            [['name' => self::CORE, 'enabled' => true] + $this->audit()],
            ['name'],
            ['enabled', 'changed_at', 'changed_by'],
        );
        $this->forget();
    }

    /**
     * Store the installed version of a module. A new row starts disabled
     * (Core starts and stays enabled); an existing row keeps its status.
     *
     * @param string $name Module name.
     * @param string|null $version Version string, null to clear it.
     */
    public function setVersion(string $name, ?string $version): void
    {
        $this->ensureSeeded();
        $values = ['version' => $version] + $this->audit();

        if ($name === self::CORE) {
            $values['enabled'] = true;
        }

        if ($this->table()->where('name', $name)->exists()) {
            $this->table()->where('name', $name)->update($values);
        } else {
            $this->table()->insert(['name' => $name, 'enabled' => $name === self::CORE] + $values);
        }

        $this->forget();
    }

    /**
     * Resolve statuses: memo, cache, database, then the fallbacks.
     *
     * @return array<string, bool>
     */
    private function statuses(): array
    {
        if ($this->statuses !== null) {
            return $this->statuses;
        }

        $cached = $this->cacheGet($this->cacheKey());
        if (is_array($cached)) {
            return $this->statuses = $cached;
        }

        try {
            $connection = $this->connection();

            if (! $connection->getSchemaBuilder()->hasTable(self::TABLE)) {
                return $this->statuses = $this->readFile();
            }

            $statuses = $this->readTable();
            if ($statuses === []) {
                $this->import();
                $statuses = $this->readTable();
            }
        } catch (Throwable $e) {
            if ($this->isMissingTable($e)) {
                return $this->statuses = $this->readFile();
            }

            $this->warnOnce($e);
            $last = $this->cacheGet($this->cacheKey().':last');

            return $this->statuses = is_array($last) ? $last : $this->readFile();
        }

        $statuses[self::CORE] = true;
        $this->cachePut($statuses);

        return $this->statuses = $statuses;
    }

    /**
     * Read every row as name => enabled.
     *
     * @return array<string, bool>
     */
    private function readTable(): array
    {
        $statuses = [];
        foreach ($this->table()->get(['name', 'enabled']) as $row) {
            $statuses[(string) $row->name] = (bool) $row->enabled;
        }

        return $statuses;
    }

    /**
     * Seed the table from the file when it is empty, so a first write does
     * not leave every other module without a row.
     */
    private function ensureSeeded(): void
    {
        if (! $this->table()->exists()) {
            $this->import();
        }
    }

    /**
     * Copy the statuses file into the (empty) table and force Core enabled.
     */
    private function import(): void
    {
        $audit = $this->audit();
        $rows = [];
        foreach ($this->readFile() as $name => $enabled) {
            $rows[] = ['name' => $name, 'enabled' => $name === self::CORE ? true : $enabled] + $audit;
        }
        $rows[] = ['name' => self::CORE, 'enabled' => true] + $audit;

        foreach (array_chunk($rows, 100) as $chunk) {
            $this->table()->insertOrIgnore($chunk);
        }
        $this->table()->where('name', self::CORE)->update(['enabled' => true]);

        $this->log('info', 'Module statuses imported from the statuses file into the database.', [
            'file' => $this->setting('statuses-file'),
            'modules' => count($rows),
        ]);
    }

    /**
     * Read the statuses file as name => enabled; missing or invalid file = [].
     *
     * @return array<string, bool>
     */
    private function readFile(): array
    {
        $path = $this->setting('statuses-file');
        if (! is_string($path) || ! is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (! is_array($decoded)) {
            return [];
        }

        $statuses = [];
        foreach ($decoded as $name => $enabled) {
            if (is_string($name) && $name !== '') {
                $statuses[$name] = (bool) $enabled;
            }
        }

        return $statuses;
    }

    /**
     * Whether the error means the statuses table does not exist.
     *
     * @param Throwable $e Database error.
     */
    private function isMissingTable(Throwable $e): bool
    {
        if (! $e instanceof QueryException) {
            return false;
        }

        $state = (string) ($e->errorInfo[0] ?? $e->getCode());

        return in_array($state, ['42S02', '42P01'], true)
            || str_contains(strtolower($e->getMessage()), 'no such table');
    }

    /**
     * Log the database-error warning once per process.
     *
     * @param Throwable $e Database error.
     */
    private function warnOnce(Throwable $e): void
    {
        if (self::$warned) {
            return;
        }
        self::$warned = true;

        $this->log('warning', 'Module statuses could not be read from the database; using cached or file statuses.', [
            'error' => $e->getMessage(),
        ]);
    }

    /**
     * Write a log entry without ever failing.
     *
     * @param string $level Log level.
     * @param string $message Message.
     * @param array<string, mixed> $context Context.
     */
    private function log(string $level, string $message, array $context = []): void
    {
        try {
            $this->app['log']->{$level}($message, $context);
        } catch (Throwable) {
            // Logging must never take the site down.
        }
    }

    /**
     * Columns written on every change: time and acting user.
     *
     * @return array{changed_at: string, changed_by: int|null}
     */
    private function audit(): array
    {
        return ['changed_at' => Carbon::now()->toDateTimeString(), 'changed_by' => $this->userId()];
    }

    /**
     * Authenticated user id, or null when none or when auth is unavailable.
     */
    private function userId(): ?int
    {
        try {
            if (! $this->app->bound('auth')) {
                return null;
            }
            $id = $this->app['auth']->id();

            return is_numeric($id) ? (int) $id : null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Clear the memo and the fresh cache entry after a write.
     */
    private function forget(): void
    {
        $this->statuses = null;

        try {
            $this->cache()->forget($this->cacheKey());
        } catch (Throwable) {
            // A cache outage must not fail the write; the TTL bounds staleness.
        }
    }

    /**
     * Read a cache value, swallowing cache errors.
     *
     * @param string $key Cache key.
     */
    private function cacheGet(string $key): mixed
    {
        try {
            return $this->cache()->get($key);
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Store fresh statuses (one day) and the last known good copy (no TTL).
     *
     * @param array<string, bool> $statuses Statuses.
     */
    private function cachePut(array $statuses): void
    {
        try {
            $cache = $this->cache();
            $cache->put($this->cacheKey(), $statuses, self::CACHE_TTL);
            $cache->forever($this->cacheKey().':last', $statuses);
        } catch (Throwable) {
            // Cache is an optimisation only.
        }
    }

    /**
     * Configured cache store (null = default store).
     */
    private function cache(): CacheRepository
    {
        return $this->app['cache']->store($this->setting('cache_store'));
    }

    /**
     * Configured database connection (null = default connection).
     */
    private function connection(): ConnectionInterface
    {
        return $this->app['db']->connection($this->connectionName());
    }

    /**
     * Query builder on the statuses table.
     */
    private function table(): Builder
    {
        return $this->connection()->table(self::TABLE);
    }

    /**
     * Configured connection name, null for the default connection.
     */
    private function connectionName(): ?string
    {
        $name = $this->setting('connection');

        return is_string($name) && $name !== '' ? $name : null;
    }

    /**
     * Read a setting under modules.activators.database.
     *
     * @param string $key Setting key.
     */
    private function setting(string $key): mixed
    {
        return $this->app['config']->get('modules.activators.database.'.$key);
    }
}
