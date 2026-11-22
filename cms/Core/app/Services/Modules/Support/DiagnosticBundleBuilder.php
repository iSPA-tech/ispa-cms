<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Support;

use App\Support\Modules\RuntimeModules;
use Cms\Core\Services\CoreUpgrade\CoreManifest;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Platform\HubCredentials;
use Cms\Core\Services\Platform\SiteStatusReport;
use Cms\Core\Services\Security\LogRedactor;
use Dotenv\Dotenv;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PDO;
use Throwable;

/**
 * Builds the masked diagnostic bundle a Webmaster may send to iSPA about one module.
 *
 * The bundle is a flat list of lines in three sections:
 * - `environment`: module version and source, Core version, PHP, Laravel, database engine, enabled modules
 *   (the same allowlisted facts as {@see SiteStatusReport});
 * - `log`: the newest error entries (ERROR and above) whose text touches the module namespace or folder, read from
 *   at most the last 2 MB of each of the three newest `laravel*.log` files, at most {@see self::MAX_LOG_ENTRIES};
 * - `failed_job`: failed queue jobs whose job class is in the module namespace, without the payload body.
 *
 * Every line is masked on the site before it is shown or sent: absolute paths are cut to the application root, then
 * {@see LogRedactor::redactKnownValues()} with the secret `.env` values of at least {@see self::MIN_KNOWN_VALUE_LENGTH}
 * characters (plus the app key, database passwords and the hub domain secret), {@see LogRedactor::redactIps()},
 * {@see LogRedactor::redactString()} (e-mails, phones, cards, Bearer/Basic credentials) and
 * {@see LogRedactor::redactKeyValuePairs()} (values of "password=", "token=", "\"secret\":" ... pairs).
 *
 * A `.env` value counts as secret by its key name or by its shape (, {@see self::secretParts}): every value of
 * a key named like a secret, and any other value that looks like a token (letters mixed with digits, symbols, long
 * numbers, random-case words, URLs with credentials, a query or a token-like path segment). Ordinary settings (names,
 * hosts, database names, paths, time zones, drivers, plain URLs, short numbers) stay readable, so the bundle is not
 * full of masked words. When unsure, a value is treated as secret.
 *
 * Each line carries an id derived from its masked content, so a rebuilt bundle keeps the same ids for the same lines
 * and {@see self::keep()} can select exactly the lines the Webmaster saw and kept; a line that appeared after the
 * preview has an id the Webmaster never sent, so it is dropped.
 *
 * @package Cms\Core\Services\Modules\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-11-15)
 */
class DiagnosticBundleBuilder
{
    /** Newest matching log entries kept. */
    public const int MAX_LOG_ENTRIES = 50;

    /** Newest failed jobs of the module kept. */
    public const int MAX_FAILED_JOBS = 20;

    /** Log files read (newest first). */
    public const int MAX_LOG_FILES = 3;

    /** Bytes read from the end of each log file. */
    public const int MAX_TAIL_BYTES = 2 * 1024 * 1024;

    /** Characters kept per line after masking. */
    public const int MAX_LINE_CHARS = 6000;

    /** Raw characters of one entry that are masked; the rest is dropped before masking (only the masked head is kept). */
    protected const int MAX_RAW_CHARS = 65536;

    /** `.env` values shorter than this are not masked as known values (too common to be secrets). */
    public const int MIN_KNOWN_VALUE_LENGTH = 6;

    /** Well-known `.env` values that are not secrets (engine, driver and mode names); they stay readable in a bundle. */
    protected const array HARMLESS_ENV_VALUES = [
        'mysql', 'pgsql', 'sqlite', 'redis', 'file', 'sync', 'local', 'production', 'true', 'false', 'null',
        'utf8', 'utf8mb4', 'utf8mb4_unicode_ci', 'utf8mb4_general_ci', 'argon2i', 'argon2id', 's3', 'http2',
    ];

    /** Path segments of a URL value at least this long are checked for a token shape (shorter ones are "api", "v2"). */
    protected const int MIN_URL_TOKEN_LENGTH = 16;

    /** A bare number with at least this many digits is treated as an id or account number and masked. */
    protected const int MIN_SECRET_NUMBER_DIGITS = 9;

    /** A word inside a value with spaces is checked for a token shape from this length on. */
    protected const int MIN_SPACED_TOKEN_LENGTH = 12;

    /** A `.env` key whose name says it holds a secret: its value is always masked, whatever the value looks like. */
    protected const string SECRET_ENV_KEY = '/pass|secret|token|key|auth|credential|salt|dsn|private|cert/i';

    /** Failed-job rows scanned for the module namespace. */
    protected const int MAX_FAILED_SCAN = 200;

    /** Log levels kept. */
    protected const array ERROR_LEVELS = ['ERROR', 'CRITICAL', 'ALERT', 'EMERGENCY'];

    /** Start of a Laravel log entry: "[2026-11-14 10:15:30] production.ERROR: ". */
    protected const string ENTRY_HEADER = '/^\[\d{4}-\d{2}-\d{2}[ T][^\]]*\]\s+[^\s\]]+?\.([A-Z]+):/m';

    /**
     * Create the builder.
     *
     * @param LogRedactor   $redactor     Masks secrets and personal data.
     * @param ModuleCatalog $catalog      Installed state (version, source) of the module.
     * @param string|null   $logDirectory Folder of the Laravel log files (default storage/logs).
     * @param string|null   $envFile      The `.env` file whose values are masked (default the application's).
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-11-15)
     */
    public function __construct(
        protected LogRedactor $redactor,
        protected ModuleCatalog $catalog,
        protected ?string $logDirectory = null,
        protected ?string $envFile = null,
        protected ?string $basePath = null,
    ) {
    }

    /**
     * Build the masked bundle for one installed module.
     *
     * @param string $name Module name (validated by the route).
     *
     * @return array{module: string, generated_at: string, lines: list<array{id: string, section: string, key: ?string, text: string}>, counts: array{environment: int, log: int, failed_job: int}}
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-11-15)
     */
    public function build(string $name): array
    {
        $known = $this->knownValues();
        $lines = [];
        $seen = [];
        $add = function (string $section, ?string $key, string $raw) use (&$lines, &$seen, $known): void {
            $text = $this->maskWith($raw, $known);
            $id = substr(hash('sha256', $section . "\n" . ($key ?? '') . "\n" . $text), 0, 16);
            if (!isset($seen[$id])) {
                $seen[$id] = true;
                $lines[] = ['id' => $id, 'section' => $section, 'key' => $key, 'text' => $text];
            }
        };

        foreach ($this->environment($name) as $key => $value) {
            $add('environment', $key, $value);
        }
        foreach ($this->logEntries($name) as $entry) {
            $add('log', null, $entry);
        }
        foreach ($this->failedJobs($name) as $job) {
            $add('failed_job', null, $job);
        }

        $counts = ['environment' => 0, 'log' => 0, 'failed_job' => 0];
        foreach ($lines as $line) {
            $counts[$line['section']]++;
        }

        return [
            'module' => $name,
            'generated_at' => Carbon::now()->toIso8601String(),
            'lines' => $lines,
            'counts' => $counts,
        ];
    }

    /**
     * Keep only the lines whose id is listed; unknown ids are ignored.
     *
     * @param array{lines: list<array{id: string}>} $bundle A bundle from {@see self::build()}.
     * @param list<string>                          $ids    Line ids the Webmaster kept.
     *
     * @return list<array{id: string, section: string, key: ?string, text: string}>
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-11-15)
     */
    public function keep(array $bundle, array $ids): array
    {
        $wanted = array_fill_keys(array_map('strval', $ids), true);

        return array_values(array_filter($bundle['lines'], static fn (array $line): bool => isset($wanted[$line['id']])));
    }

    /**
     * Mask free text written by the Webmaster (subject, description) with the same rules as the bundle lines.
     *
     * @param string $text Free text.
     *
     * @return string The masked text.
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-11-15)
     */
    public function mask(string $text): string
    {
        return $this->maskWith($text, $this->knownValues());
    }

    /**
     * Cut absolute paths to the application root, then mask known values, IP addresses and patterns.
     *
     * @param list<string> $known
     */
    protected function maskWith(string $text, array $known): string
    {
        // Log files may hold broken UTF-8; the patterns and the JSON answer need valid text.
        $text = mb_scrub($text, 'UTF-8');
        if (mb_strlen($text) > self::MAX_RAW_CHARS) {
            // Drop the token cut in half at the boundary, so no fragment of a secret escapes the known-value match.
            $text = (string) preg_replace('/\S*$/u', '', mb_substr($text, 0, self::MAX_RAW_CHARS));
        }
        $text = $this->relativePaths($text);
        $text = $this->redactor->redactKnownValues($text, $known);
        $text = $this->redactor->redactIps($text);
        $text = $this->redactor->redactString($text);
        // After redactString, so "Authorization: Bearer <token>" loses the token before the pair value is masked.
        $text = $this->redactor->redactKeyValuePairs($text);

        return mb_strlen($text) > self::MAX_LINE_CHARS ? mb_substr($text, 0, self::MAX_LINE_CHARS) . ' …' : $text;
    }

    /**
     * Replace the application root and the runtime module folder (plain and JSON-escaped) with short labels.
     */
    protected function relativePaths(string $text): string
    {
        $map = [];
        // RuntimeModules::path() is null on a site without a runtime-modules folder.
        foreach ([[RuntimeModules::path() ?? '', 'runtime-modules'], [base_path(), '']] as [$path, $label]) {
            $path = rtrim($path, '/\\');
            if ($path === '') {
                continue;
            }
            $map[$path . '/'] = $label === '' ? '' : $label . '/';
            $map[str_replace('/', '\/', $path) . '\/'] = $label === '' ? '' : $label . '\/';
        }

        return strtr($text, $map);
    }

    /**
     * Allowlisted environment facts: module version and source, Core, PHP, Laravel, database engine, enabled modules.
     *
     * @return array<string, string>
     */
    protected function environment(string $name): array
    {
        $state = $this->catalog->installedState($name);
        $report = [];
        try {
            $report = app(SiteStatusReport::class)->build();
        } catch (Throwable) {
            // Keep the facts read below.
        }

        $enabled = [];
        foreach (is_array($report['modules'] ?? null) ? $report['modules'] : [] as $module) {
            if (($module['enabled'] ?? false) === true && is_string($module['name'] ?? null)) {
                $enabled[] = $module['name'] . (is_string($module['version'] ?? null) ? ' ' . $module['version'] : '');
            }
        }

        return [
            'module_version' => (string) ($state['installed_version'] ?? 'unknown'),
            'module_source' => (string) ($state['source'] ?? $state['installed']),
            'module_enabled' => $state['enabled'] ? 'yes' : 'no',
            'core_version' => $this->packageVersion() ?? (string) ($report['app_version'] ?? config('ispa-cms.app_version') ?? 'unknown'),
            'core_module_version' => $this->coreModuleVersion(),
            'release' => (string) ($report['release'] ?? $report['revision'] ?? 'unknown'),
            'php_version' => PHP_VERSION,
            'laravel_version' => app()->version(),
            'database' => $this->databaseEngine(),
            'enabled_modules' => $enabled === [] ? 'none' : implode(', ', $enabled),
        ];
    }

    /**
     * The installed iSPA CMS package version (`core-manifest.json`), or null on a site without one.
     */
    protected function packageVersion(): ?string
    {
        try {
            return CoreManifest::installed($this->basePath ?? base_path())?->version;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * The Core module.json version: a technical contract counter for iSPA support, not the product version.
     */
    protected function coreModuleVersion(): string
    {
        $file = ($this->basePath ?? base_path()) . '/cms/Core/module.json';
        $data = is_file($file) ? json_decode((string) file_get_contents($file), true) : null;
        $version = is_array($data) ? ($data['version'] ?? null) : null;

        return is_string($version) && $version !== '' ? $version : 'unknown';
    }

    /**
     * Database driver and server version ("mysql 10.6.18-MariaDB"), or "unknown".
     */
    protected function databaseEngine(): string
    {
        try {
            $connection = DB::connection();
            $version = null;
            try {
                $version = $connection->getPdo()->getAttribute(PDO::ATTR_SERVER_VERSION);
            } catch (Throwable) {
            }

            return trim($connection->getDriverName() . ' ' . (is_string($version) ? $version : ''));
        } catch (Throwable) {
            return 'unknown';
        }
    }

    /**
     * Newest error entries that touch the module, newest first.
     *
     * @return list<string>
     */
    protected function logEntries(string $name): array
    {
        $needles = $this->moduleNeedles($name);
        $entries = [];

        foreach ($this->logFiles() as $file) {
            $chunk = $this->tail($file);
            if ($chunk === '' || preg_match_all(self::ENTRY_HEADER, $chunk, $m, PREG_OFFSET_CAPTURE | PREG_SET_ORDER) === false) {
                continue;
            }
            for ($i = count($m) - 1; $i >= 0; $i--) {
                if (!in_array($m[$i][1][0], self::ERROR_LEVELS, true)) {
                    continue;
                }
                $start = $m[$i][0][1];
                $end = isset($m[$i + 1]) ? $m[$i + 1][0][1] : strlen($chunk);
                $entry = rtrim(substr($chunk, $start, $end - $start));
                if ($this->touches($entry, $needles)) {
                    $entries[] = $entry;
                    if (count($entries) >= self::MAX_LOG_ENTRIES) {
                        return $entries;
                    }
                }
            }
        }

        return $entries;
    }

    /**
     * The newest `laravel*.log` files (by modification time).
     *
     * @return list<string>
     */
    protected function logFiles(): array
    {
        $files = glob(rtrim($this->logDirectory ?? storage_path('logs'), '/') . '/laravel*.log') ?: [];
        $files = array_values(array_filter($files, 'is_file'));
        usort($files, static fn (string $a, string $b): int => (@filemtime($b) ?: 0) <=> (@filemtime($a) ?: 0));

        return array_slice($files, 0, self::MAX_LOG_FILES);
    }

    /**
     * The last {@see self::MAX_TAIL_BYTES} bytes of a file ("" when unreadable).
     */
    protected function tail(string $file): string
    {
        $handle = @fopen($file, 'rb');
        if ($handle === false) {
            return '';
        }
        try {
            $size = (int) @filesize($file);
            if ($size > self::MAX_TAIL_BYTES) {
                fseek($handle, $size - self::MAX_TAIL_BYTES);
            }

            return (string) stream_get_contents($handle, self::MAX_TAIL_BYTES);
        } finally {
            fclose($handle);
        }
    }

    /**
     * Strings that mark an entry as touching the module: its namespace and its release or runtime folder.
     *
     * @return list<string>
     */
    protected function moduleNeedles(string $name): array
    {
        $needles = ['Cms\\' . $name . '\\', '/cms/' . $name . '/'];
        $runtime = rtrim(RuntimeModules::path() ?? '', '/');
        if ($runtime !== '') {
            $needles[] = $runtime . '/' . $name . '/';
        }

        return $needles;
    }

    /**
     * Whether an entry mentions one of the needles, also in its JSON-escaped spelling.
     *
     * @param list<string> $needles
     */
    protected function touches(string $entry, array $needles): bool
    {
        $plain = str_replace(['\\\\', '\\/'], ['\\', '/'], $entry);
        foreach ($needles as $needle) {
            if (str_contains($entry, $needle) || str_contains($plain, $needle)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Failed jobs whose job class is in the module namespace: time, class, queue and exception; never the payload.
     *
     * @return list<string>
     */
    protected function failedJobs(string $name): array
    {
        $table = config('queue.failed.table', 'failed_jobs');
        $prefix = 'Cms\\' . $name . '\\';
        $jobs = [];

        try {
            if (!is_string($table) || !Schema::hasTable($table)) {
                return [];
            }
            $rows = DB::table($table)->select(['queue', 'payload', 'exception', 'failed_at'])
                ->orderByDesc('failed_at')->limit(self::MAX_FAILED_SCAN)->get();
        } catch (Throwable) {
            return [];
        }

        foreach ($rows as $row) {
            $payload = json_decode((string) $row->payload, true);
            $class = is_array($payload) ? ($payload['data']['commandName'] ?? $payload['displayName'] ?? null) : null;
            if (!is_string($class) || !str_starts_with($class, $prefix)) {
                continue;
            }
            $jobs[] = $row->failed_at . ' ' . $class . ' (queue ' . $row->queue . '): ' . (string) $row->exception;
            if (count($jobs) >= self::MAX_FAILED_JOBS) {
                break;
            }
        }

        return $jobs;
    }

    /**
     * Secret values to mask (each at least {@see self::MIN_KNOWN_VALUE_LENGTH} characters): the secret `.env` values
     * and their secret parts ({@see self::secretParts()}), the app key, the database passwords and the hub domain
     * secret (which may live in the database, not `.env`).
     *
     * @return list<string>
     */
    protected function knownValues(): array
    {
        $values = [];
        foreach ($this->envPairs() as $key => $value) {
            array_push($values, ...$this->secretParts((string) $key, $value));
        }
        $values[] = config('app.key');
        foreach ((array) config('database.connections', []) as $connection) {
            $values[] = is_array($connection) ? ($connection['password'] ?? null) : null;
        }
        try {
            $values[] = app(HubCredentials::class)->secret();
        } catch (Throwable) {
        }

        $known = [];
        foreach ($values as $value) {
            if (is_string($value) && mb_strlen($value) >= self::MIN_KNOWN_VALUE_LENGTH) {
                $known[$value] = true;
            }
        }

        // A numeric string becomes an integer array key; cast back so an all-digit secret is still masked.
        return array_map('strval', array_keys($known));
    }

    /**
     * The parts of one `.env` value to mask: the whole value when its key is named like a secret or the value is
     * secret-shaped, plus the secret parts of a URL or spaced value (password, query values, token-like path segment or
     * word), so such a part is masked when a log prints it alone. Empty for an ordinary setting.
     *
     * @param string $key   The `.env` key.
     * @param string $value Its value.
     *
     * @return list<string>
     *
     * @author iSPA Team
     * @since  8.0.0 (2026-11-16)
     */
    protected function secretParts(string $key, string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return [];
        }
        if (preg_match(self::SECRET_ENV_KEY, $key) === 1) {
            return [$value];
        }
        if (str_contains($trimmed, '://')) {
            $parts = $this->secretUrlParts($trimmed);

            return $parts === [] ? [] : [$value, ...$parts];
        }
        if (preg_match('/\s/u', $trimmed) === 1) {
            $words = array_values(array_filter(
                preg_split('/\s+/u', $trimmed) ?: [],
                fn (string $word): bool => mb_strlen($word) >= self::MIN_SPACED_TOKEN_LENGTH && $this->secretShaped($word),
            ));

            return $words === [] ? [] : [$value, ...$words];
        }

        return $this->secretShaped($trimmed) ? [$value] : [];
    }

    /**
     * Secret parts of a URL value; empty for a plain URL (scheme, host, port and an ordinary path). A URL with a user,
     * password, query or fragment, or with a token-like path segment, is secret; an unparsable one too.
     *
     * @return list<string> The parts to mask besides the whole value (may be empty when the value itself is secret).
     */
    protected function secretUrlParts(string $url): array
    {
        $parts = parse_url($url);
        if (!is_array($parts) || !isset($parts['scheme'], $parts['host'])) {
            return [$url];
        }
        $secret = false;
        $found = [];
        foreach (['user', 'pass', 'fragment'] as $part) {
            if (isset($parts[$part])) {
                $secret = true;
                $found[] = rawurldecode((string) $parts[$part]);
            }
        }
        if (isset($parts['query'])) {
            $secret = true;
            parse_str((string) $parts['query'], $query);
            array_walk_recursive($query, static function (mixed $item) use (&$found): void {
                $found[] = (string) $item;
            });
        }
        foreach (explode('/', (string) ($parts['path'] ?? '')) as $segment) {
            $segment = rawurldecode($segment);
            if ((preg_match('/^\d+$/', $segment) === 1 && strlen($segment) >= self::MIN_SECRET_NUMBER_DIGITS)
                || (mb_strlen($segment) >= self::MIN_URL_TOKEN_LENGTH && $this->secretShaped($segment))) {
                $secret = true;
                $found[] = $segment;
            }
        }

        return $secret ? ($found === [] ? [$url] : $found) : [];
    }

    /**
     * Whether one bare value (no spaces, not a URL) looks like a token rather than an ordinary setting.
     *
     * Ordinary: a well-known word, a number shorter than {@see self::MIN_SECRET_NUMBER_DIGITS} digits, a version or
     * IPv4-like dotted number (IPs are masked later anyway), an absolute path, an e-mail (masked later anyway), a host
     * name, or a word made of letters with `_ - . / :` and at most one lower-to-upper case change ("Asia/Ho_Chi_Minh",
     * "probe_cms", "iSPA"). Everything else is a token: letters mixed with digits, any other symbol, a long number,
     * random-case letters or a letter run of 24 or more.
     */
    protected function secretShaped(string $value): bool
    {
        if (in_array(strtolower($value), self::HARMLESS_ENV_VALUES, true)) {
            return false;
        }
        if (preg_match('/^-?\d+$/', $value) === 1) {
            return strlen(ltrim($value, '-')) >= self::MIN_SECRET_NUMBER_DIGITS;
        }
        if (preg_match('/^\d+(?:\.\d+)+$/', $value) === 1
            || preg_match('#^/[A-Za-z0-9_.\-/]*$#', $value) === 1
            || filter_var($value, FILTER_VALIDATE_EMAIL) !== false
            || preg_match('/^(?=.{4,253}$)(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,63}$/i', $value) === 1) {
            return false;
        }
        if (preg_match('/^[A-Za-z][A-Za-z_\-.\/:]*$/', $value) !== 1) {
            return true;
        }

        return preg_match_all('/\p{Ll}\p{Lu}/u', $value) >= 2 || preg_match('/[A-Za-z]{24,}/', $value) === 1;
    }

    /**
     * Key and value pairs of the `.env` file (Dotenv parser; a line-based fallback when the file does not parse).
     *
     * @return array<string, string>
     */
    protected function envPairs(): array
    {
        $file = $this->envFile ?? app()->environmentFilePath();
        $content = is_file($file) ? (string) @file_get_contents($file) : '';
        if ($content === '') {
            return [];
        }

        try {
            return array_filter(Dotenv::parse($content), 'is_string');
        } catch (Throwable) {
            preg_match_all('/^\s*(?:export\s+)?([A-Za-z_][A-Za-z0-9_.]*)\s*=\s*(.*)$/m', $content, $m);

            return array_combine($m[1], array_map(static fn (string $raw): string => trim(trim($raw), '"\''), $m[2])) ?: [];
        }
    }
}
