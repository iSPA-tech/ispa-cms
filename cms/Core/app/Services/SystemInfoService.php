<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use PDO;
use Exception;
use JsonException;
use Throwable;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Illuminate\Support\Facades\DB;
use Nwidart\Modules\Facades\Module;
use Illuminate\Database\ConnectionInterface;
use Nwidart\Modules\Laravel\Module as LaravelModule;
use Symfony\Component\Process\Process;

/**
 * Collects comprehensive server, database, directory, and module information.
 *
 * Gathers PHP configuration, database version and size, directory sizes,
 * composer package list, htaccess status, module inventory, and project
 * structure. Used by the admin system info dashboard.
 *
 * @package Cms\Core\Services
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class SystemInfoService
{
    private const CACHE_KEY_PREFIX = 'core.system_info.';
    private const CACHE_TTL_DATABASE = 300;
    private const CACHE_TTL_DIRECTORIES = 3600;
    private const CACHE_TTL_COMPOSER = 3600;
    private const CACHE_TTL_HTACCESS = 600;
    private const CACHE_TTL_MODULES = 1800;
    private const CACHE_TTL_PROJECT_STRUCTURE = 1800;
    private const DIRECTORY_SIZE_TIMEOUT_SECONDS = 5;

    /**
     * Get comprehensive server information across all categories.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function getFullServerInfo(): array
    {
        return [
            'server_info' => $this->getServerInfo(),
            'database_info' => $this->rememberSection(
                'database',
                self::CACHE_TTL_DATABASE,
                fn (): array => $this->getDatabaseInfo()
            ),
            'directories_info' => $this->rememberSection(
                'directories',
                self::CACHE_TTL_DIRECTORIES,
                fn (): array => $this->getDirectoriesInfo()
            ),
            'composer_info' => $this->rememberSection(
                'composer',
                self::CACHE_TTL_COMPOSER,
                fn (): array => $this->getComposerInfo()
            ),
            'htaccess_info' => $this->rememberSection(
                'htaccess',
                self::CACHE_TTL_HTACCESS,
                fn (): array => $this->getHtaccessInfo()
            ),
            'modules_info' => $this->rememberSection(
                'modules',
                self::CACHE_TTL_MODULES,
                fn (): array => $this->getModulesInfo()
            ),
            ...$this->rememberSection(
                'project_structure',
                self::CACHE_TTL_PROJECT_STRUCTURE,
                fn (): array => $this->getProjectStructureInfo()
            ),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function rememberSection(string $section, int $ttlSeconds, callable $resolver): array
    {
        $key = self::CACHE_KEY_PREFIX . $section;

        try {
            $cached = cache()->get($key);
            if (is_array($cached)) {
                return $cached;
            }
        } catch (Throwable) {
            // Cache is best-effort; system dashboard should still render.
        }

        try {
            $value = $resolver();
        } catch (Throwable $exception) {
            return [$section . '_error' => $exception->getMessage()];
        }

        if (!is_array($value)) {
            return [$section . '_error' => 'Unexpected system information payload.'];
        }

        try {
            cache()->put($key, $value, $ttlSeconds);
        } catch (Throwable) {
            // Ignore cache write failures; fresh data is still usable.
        }

        return $value;
    }

    /**
     * Get PHP and web server configuration details.
     *
     * @return array<string, string>
     */
    public function getServerInfo(): array
    {
        return [
            'server_architecture' => $this->getServerArchitecture(),
            'web_server' => $this->getWebServer(),
            'php_version' => $this->getPhpVersion(),
            'php_sapi' => $this->getPhpSapi(),
            'php_max_input_variables' => $this->getMaxInputVars(),
            'php_time_limit' => $this->getTimeLimit(),
            'php_memory_limit' => $this->getMemoryLimit(),
            'max_input_time' => $this->getMaxInputTime(),
            'upload_max_filesize' => $this->getUploadMaxFilesize(),
            'php_post_max_size' => $this->getPostMaxSize(),
            'curl_version' => $this->getCurlVersion(),
            'suhosin_installed' => $this->isSuhosinInstalled(),
            'imagick_available' => $this->isImagickAvailable(),
            'pretty_permalinks_supported' => $this->isPrettyPermalinksSupported(),
            'current_time' => $this->getCurrentTime(),
            'current_utc_time' => $this->getCurrentUtcTime(),
            'current_server_time' => $this->getCurrentServerTime(),
        ];
    }

    protected function getServerArchitecture(): string
    {
        return php_uname('s') . ' ' . php_uname('r') . ' ' . php_uname('m');
    }

    protected function getWebServer(): string
    {
        return $_SERVER['SERVER_SOFTWARE'] ?? 'N/A';
    }

    protected function getPhpVersion(): string
    {
        return PHP_VERSION . ' (Supports 64bit values: ' . (PHP_INT_SIZE === 8 ? 'Yes' : 'No') . ')';
    }

    protected function getPhpSapi(): string
    {
        return PHP_SAPI;
    }

    protected function getMaxInputVars(): string
    {
        return ini_get('max_input_vars') ?: '1000';
    }

    protected function getTimeLimit(): string
    {
        return ini_get('max_execution_time') ?: '30';
    }

    protected function getMemoryLimit(): string
    {
        return ini_get('memory_limit') ?: '1024M';
    }

    protected function getMaxInputTime(): string
    {
        $maxInputTime = ini_get('max_input_time');
        return $maxInputTime === false || $maxInputTime === '' ? '-1' : $maxInputTime;
    }

    protected function getUploadMaxFilesize(): string
    {
        return ini_get('upload_max_filesize') ?: '512M';
    }

    protected function getPostMaxSize(): string
    {
        return ini_get('post_max_size') ?: '256M';
    }

    protected function getCurlVersion(): string
    {
        if (!function_exists('curl_version')) {
            return 'N/A';
        }

        $curl = curl_version();
        return $curl['version'] . ' ' . ($curl['ssl_version'] ?? 'N/A');
    }

    protected function isSuhosinInstalled(): string
    {
        return extension_loaded('suhosin') ? 'Yes' : 'No';
    }

    protected function isImagickAvailable(): string
    {
        return extension_loaded('imagick') ? 'Yes' : 'No';
    }

    protected function isPrettyPermalinksSupported(): string
    {
        if (function_exists('apache_get_modules')) {
            return in_array('mod_rewrite', apache_get_modules()) ? 'Yes' : 'No';
        }

        // For nginx or other servers
        return 'Yes (Assuming server supports rewrite rules)';
    }

    protected function getCurrentTime(): string
    {
        return now()->toISOString();
    }

    protected function getCurrentUtcTime(): string
    {
        return now()->utc()->format('l, d-M-y H:i:s UTC');
    }

    protected function getCurrentServerTime(): string
    {
        return now()->toISOString();
    }

    /**
     * Get database connection and configuration details.
     *
     * @return array<string, string>
     */
    public function getDatabaseInfo(): array
    {
        try {
            $connection = DB::connection();
            $pdo = $connection->getPdo();
            $driver = $connection->getDriverName();
            $connectionConfig = $this->getCurrentConnectionConfig();
            $details = $this->getDatabaseDriverDetails($connection);

            return [
                'database_extension' => $pdo->getAttribute(PDO::ATTR_DRIVER_NAME),
                'server_version' => $details['server_version'],
                'client_version' => $pdo->getAttribute(PDO::ATTR_CLIENT_VERSION),
                'database_username' => $connectionConfig['username'] ?? 'N/A',
                'database_host' => $connectionConfig['host'] ?? ($driver === 'sqlite' ? 'local-file' : 'N/A'),
                'database_name' => $connectionConfig['database'] ?? 'N/A',
                'table_prefix' => $connectionConfig['prefix'] ?? '',
                'database_charset' => $details['database_charset'],
                'database_collation' => $details['database_collation'],
                'max_allowed_packet_size' => $details['max_allowed_packet_size'],
                'max_connections_number' => $details['max_connections_number'],
            ];
        } catch (Exception $e) {
            return [
                'database_error' => $e->getMessage(),
            ];
        }
    }

    protected function formatBytes($bytes, $precision = 2): string
    {
        if (!is_numeric($bytes) || $bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $bytes = (float)$bytes;

        // Use iterative calculation instead of log() to avoid precision errors
        $pow = 0;
        while ($bytes >= 1024 && $pow < count($units) - 1) {
            $bytes /= 1024;
            $pow++;
        }

        // Use number_format instead of round for consistent decimal formatting
        return number_format($bytes, $precision) . ' ' . $units[$pow];
    }

    /**
     * Get sizes and locations of key application directories.
     *
     * @return array<string, string>
     */
    public function getDirectoriesInfo(): array
    {
        $basePath = base_path();

        return [
            'laravel_directory_location' => $basePath,
            'laravel_directory_size' => $this->getDirectorySize($basePath),
            'public_directory_location' => public_path(),
            'public_directory_size' => $this->getDirectorySize(public_path()),
            'storage_directory_location' => storage_path(),
            'storage_directory_size' => $this->getDirectorySize(storage_path()),
            'uploads_directory_location' => public_path('uploads'),
            'uploads_directory_size' => $this->getDirectorySize(public_path('uploads')),
            'themes_directory_location' => resource_path('themes'),
            'themes_directory_size' => $this->getDirectorySize(resource_path('themes')),
            'plugins_directory_location' => base_path('plugins'),
            'plugins_directory_size' => $this->getDirectorySize(base_path('plugins')),
            'fonts_directory_location' => public_path('fonts'),
            'fonts_directory_size' => $this->getDirectorySize(public_path('fonts')),
            'database_size' => $this->getDatabaseSize(),
        ];
    }

    protected function getDirectorySize(string $path): string
    {
        if (!file_exists($path) || !is_dir($path)) {
            return '0 MB';
        }

        $size = $this->getDirectorySizeInBytes($path);

        return $size === null ? 'N/A' : $this->formatBytes($size);
    }

    protected function getDirectorySizeInBytes(string $path): ?int
    {
        try {
            $process = new Process(['du', '-sk', $path]);
            $process->setTimeout(self::DIRECTORY_SIZE_TIMEOUT_SECONDS);
            $process->run();

            if (!$process->isSuccessful()) {
                return null;
            }

            $output = trim($process->getOutput());
            if (!preg_match('/^(\d+)/', $output, $matches)) {
                return null;
            }

            return (int)$matches[1] * 1024;
        } catch (Throwable) {
            return null;
        }
    }

    protected function getDatabaseSize(): string
    {
        try {
            $connection = DB::connection();
            $driver = $connection->getDriverName();

            if ($driver === 'pgsql') {
                $result = $connection->select('SELECT pg_database_size(current_database()) as size');
                return $this->formatBytes($result[0]->size ?? 0);
            }

            if ($driver === 'mysql') {
                $databaseName = $this->getCurrentConnectionConfig()['database'] ?? null;
                if (!is_string($databaseName) || $databaseName === '') {
                    return 'N/A';
                }

                $result = $connection->select(
                    "SELECT SUM(data_length + index_length) as size
                     FROM information_schema.TABLES
                     WHERE table_schema = ?",
                    [$databaseName]
                );

                return $this->formatBytes($result[0]->size ?? 0);
            }

            if ($driver === 'sqlite') {
                $databasePath = $this->getCurrentConnectionConfig()['database'] ?? null;
                if (is_string($databasePath) && $databasePath !== '' && file_exists($databasePath)) {
                    return $this->formatBytes(filesize($databasePath) ?: 0);
                }
            }

            return 'N/A';
        } catch (Exception $e) {
            return 'N/A';
        }
    }

    /**
     * @return array<string, mixed>
     */
    protected function getCurrentConnectionConfig(): array
    {
        $connectionName = (string) config('database.default', 'mysql');
        $connectionConfig = config("database.connections.{$connectionName}");

        return is_array($connectionConfig) ? $connectionConfig : [];
    }

    /**
     * @return array<string, string>
     */
    protected function getDatabaseDriverDetails(ConnectionInterface $connection): array
    {
        $driver = $connection->getDriverName();

        if ($driver === 'pgsql') {
            $serverVersion = $connection->select('SELECT version() as version')[0]->version ?? 'N/A';
            $encoding = $connection->select('SHOW server_encoding')[0]->server_encoding ?? 'N/A';
            $collation = $connection->select('SHOW lc_collate')[0]->lc_collate ?? 'N/A';
            $maxConnections = $connection->select('SHOW max_connections')[0]->max_connections ?? 'N/A';

            return [
                'server_version' => (string) $serverVersion,
                'database_charset' => (string) $encoding,
                'database_collation' => (string) $collation,
                'max_allowed_packet_size' => 'N/A',
                'max_connections_number' => (string) $maxConnections,
            ];
        }

        if ($driver === 'sqlite') {
            $serverVersion = $connection->select('select sqlite_version() as version')[0]->version ?? 'N/A';

            return [
                'server_version' => (string) $serverVersion,
                'database_charset' => 'UTF-8',
                'database_collation' => 'N/A',
                'max_allowed_packet_size' => 'N/A',
                'max_connections_number' => 'N/A',
            ];
        }

        $versionResult = $connection->select('SELECT VERSION() as version');
        $charsetResult = $connection->select('SHOW VARIABLES LIKE "character_set_database"');
        $collationResult = $connection->select('SHOW VARIABLES LIKE "collation_database"');
        $packetResult = $connection->select('SHOW VARIABLES LIKE "max_allowed_packet"');
        $connectionsResult = $connection->select('SHOW VARIABLES LIKE "max_connections"');

        return [
            'server_version' => (string) ($versionResult[0]->version ?? 'N/A'),
            'database_charset' => (string) ($charsetResult[0]->Value ?? 'N/A'),
            'database_collation' => (string) ($collationResult[0]->Value ?? 'N/A'),
            'max_allowed_packet_size' => $this->formatBytes($packetResult[0]->Value ?? 0),
            'max_connections_number' => (string) ($connectionsResult[0]->Value ?? 'N/A'),
        ];
    }

    /**
     * Get composer packages and version information.
     *
     * @return array<string, mixed>
     * @throws JsonException
     */
    public function getComposerInfo(): array
    {
        $composerLockPath = base_path('composer.lock');
        $composerJsonPath = base_path('composer.json');

        $packages = [];

        if (file_exists($composerLockPath)) {
            $composerLock = json_decode(file_get_contents($composerLockPath), true, 512, JSON_THROW_ON_ERROR);
            foreach ($composerLock['packages'] ?? [] as $package) {
                $packages[$package['name']] = $package['version'];
            }
        }

        $composerVersion = $this->runComposerCommand(['--version'], 10);

        return [
            'composer_version' => ($composerVersion['success'] ?? false)
                ? ($composerVersion['output'] ?: 'N/A')
                : 'N/A',
            'installed_packages' => $packages,
            'composer_lock_exists' => file_exists($composerLockPath) ? 'Yes' : 'No',
            'composer_json_exists' => file_exists($composerJsonPath) ? 'Yes' : 'No',
        ];
    }

    /**
     * Get htaccess and robots.txt status.
     *
     * @return array<string, string>
     */
    public function getHtaccessInfo(): array
    {
        $htaccessPath = public_path('.htaccess');

        return [
            'htaccess_exists' => file_exists($htaccessPath) ? 'Yes' : 'No',
            'htaccess_writable' => is_writable($htaccessPath) ? 'Yes' : 'No',
            'htaccess_location' => $htaccessPath,
            'robots_txt_exists' => file_exists(public_path('robots.txt')) ? 'Yes' : 'No',
        ];
    }

    /**
     * Get installed module inventory with status, sizes, and metadata.
     *
     * @return array<string, mixed>
     */
    public function getModulesInfo(): array
    {
        // Check whether the nwidart/laravel-modules package is installed
        if (!class_exists(Module::class)) {
            return ['modules_error' => 'nwidart/laravel-modules package not installed'];
        }

        try {
            $modules = Module::all();
            $modulesInfo = [];

            foreach ($modules as $module) {
                /** @var LaravelModule $module */
                $modulesInfo[$module->getName()] = [
                    'enabled' => $module->isEnabled(),
                    'status' => $module->isEnabled() ? 'Enabled' : 'Disabled',
                    'path' => $module->getPath(),
                    'name' => $module->getName(),
                    'priority' => $module->get('priority', 'N/A'),
                    'description' => $module->get('description', 'N/A'),
                    'version' => $module->get('version', 'N/A'),
                    'authors' => $module->get('authors', []),
                    'requirements' => $module->get('requires', []),
                    'files_count' => $this->countFilesInModule($module->getPath()),
                    'size' => $this->getDirectorySize($module->getPath()),
                ];
            }

            return [
                'modules_count' => count($modulesInfo),
                'modules_enabled_count' => count(array_filter($modulesInfo, fn($m) => $m['enabled'])),
                'modules_disabled_count' => count(array_filter($modulesInfo, fn($m) => !$m['enabled'])),
                'modules_list' => $modulesInfo,
            ];

        } catch (Exception $e) {
            return ['modules_error' => $e->getMessage()];
        }
    }

    protected function countFilesInModule(string $modulePath): int
    {
        if (!file_exists($modulePath)) {
            return 0;
        }

        $iterator = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($modulePath, RecursiveDirectoryIterator::SKIP_DOTS)
        );

        $count = 0;
        foreach ($iterator as $file) {
            if ($file->isFile()) {
                $count++;
            }
        }

        return $count;
    }

    /**
     * Get the project directory structure tree.
     *
     * @return array<string, mixed>
     */
    public function getProjectStructureInfo(): array
    {
        $projectPath = base_path('projects');
        $structure = [];

        if (!file_exists($projectPath)) {
            return ['project_structure' => 'projects directory not found'];
        }

        try {
            $projects = scandir($projectPath);

            foreach ($projects as $project) {
                //  ignore . and .. and .DS_Store
                if (in_array($project, ['.', '..', '.DS_Store'], true)) {
                    continue;
                }

                $projectFullPath = $projectPath . '/' . $project;

                if (is_dir($projectFullPath)) {
                    $structure[$project] = $this->scanProjectDirectory($projectFullPath);
                }
            }

            return ['project_structure' => $structure];

        } catch (Exception $e) {
            return ['project_structure_error' => $e->getMessage()];
        }
    }

    protected function scanProjectDirectory(string $path, int $depth = 2, int $currentDepth = 0): array
    {
        if ($currentDepth >= $depth) {
            return ['type' => 'directory', 'depth_limit_reached' => true];
        }

        $structure = [];
        // Filter out hidden/system artifacts like .DS_Store at every depth
        $items = array_values(array_filter(scandir($path), static function ($item) {
            return !in_array($item, ['.', '..', '.DS_Store'], true);
        }));

        foreach ($items as $item) {

            $fullPath = $path . '/' . $item;

            if (is_dir($fullPath)) {
                $structure[$item] = [
                    'type' => 'directory',
                    'size' => $this->getDirectorySize($fullPath),
                    'content' => $this->scanProjectDirectory($fullPath, $depth, $currentDepth + 1),
                ];
            } else {
                $structure[$item] = [
                    'type' => 'file',
                    'size' => $this->formatBytes(filesize($fullPath)),
                    'extension' => pathinfo($fullPath, PATHINFO_EXTENSION),
                    'modified' => date('Y-m-d H:i:s', filemtime($fullPath)),
                ];
            }
        }

        return $structure;
    }

    /**
     * Check for outdated composer packages.
     *
     * @return array<string, mixed>
     */
    public function checkForComposerUpdates(): array
    {
        try {
            $result = $this->runComposerCommand(['outdated', '--format=json'], 30);
            $output = (string) ($result['output'] ?? '');

            if (! ($result['success'] ?? false)) {
                return [
                    'success' => false,
                    'error' => (string) ($result['error'] ?? $output ?: 'composer outdated failed'),
                    'output' => $output,
                ];
            }

            $data = json_decode($output, true, 512, JSON_THROW_ON_ERROR);

            return [
                'success' => true,
                'updates' => $data['installed'] ?? [],
                'output' => $output,
            ];
        } catch (Exception $e) {
            return [
                'success' => false,
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Update a composer package or all packages.
     *
     * @param string|null $package Optional specific package name to update.
     * @param bool        $dev     Whether to include dev dependencies.
     * @return array<string, mixed>
     */
    public function updateComposerPackage(?string $package = null, bool $dev = false): array
    {
        return [
            'success' => false,
            'error' => 'Composer package updates are disabled from SystemInfoService. Run Composer from deployment tooling.',
        ];
    }

    /**
     * @param array<int, string> $arguments
     * @return array{success: bool, output: string, exit_code: int|null, error?: string}
     */
    private function runComposerCommand(array $arguments, int $timeoutSeconds): array
    {
        try {
            $process = new Process(['composer', ...$arguments], base_path());
            $process->setTimeout($timeoutSeconds);
            $process->run();

            $output = trim(implode("\n", array_filter([
                trim($process->getOutput()),
                trim($process->getErrorOutput()),
            ], static fn (string $value): bool => $value !== '')));

            return [
                'success' => $process->isSuccessful(),
                'output' => $output,
                'exit_code' => $process->getExitCode(),
            ];
        } catch (Throwable $exception) {
            return [
                'success' => false,
                'output' => '',
                'exit_code' => null,
                'error' => $exception->getMessage(),
            ];
        }
    }
}
