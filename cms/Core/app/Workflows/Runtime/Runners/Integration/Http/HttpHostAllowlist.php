<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration\Http;

use Cms\Core\Models\Setting;
use Throwable;

/**
 * The site-wide list of hosts workflow steps may call.
 *
 * Effective list = `core.workflow_security.http_hosts` (server config) + the `workflow.http_hosts` setting (JSON list
 * written only by WorkflowHttpHostsController, Webmaster only). Empty = no outbound calls at all.
 *
 * Entry forms: `example.com` (that host only), `*.example.com` (any subdomain, not the apex), an optional `:port`
 * (`api.example.com:8443`) that allows that port; without one only ports 80 and 443 are allowed. A public IPv4
 * literal is accepted; IPv6 literals and bare wildcards (`*`, `*.com`) are not.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration\Http
 * @since   2026-11-24
 */
class HttpHostAllowlist
{
    public const SETTING_KEY = 'workflow.http_hosts';

    public const MAX_ENTRIES = 200;

    private const LABEL = '[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?';

    /**
     * Effective entries (server config first, then the Webmaster's list), normalized and de-duplicated.
     *
     * @return list<string>
     */
    public function entries(): array
    {
        return array_values(array_unique(array_merge($this->serverEntries(), $this->settingEntries())));
    }

    /** @return list<string> */
    public function serverEntries(): array
    {
        return self::normalizeList((array) config('core.workflow_security.http_hosts', []));
    }

    /** @return list<string> */
    public function settingEntries(): array
    {
        try {
            // Read the whole row: the value cast needs `type`/`input_setting` to decrypt and decode.
            $raw = Setting::query()->where('setting_key', self::SETTING_KEY)->first()?->value;
        } catch (Throwable) {
            return [];
        }
        $decoded = is_array($raw) ? $raw : (is_string($raw) ? json_decode($raw, true) : null);

        return is_array($decoded) ? self::normalizeList($decoded) : [];
    }

    /**
     * Save the Webmaster's list (already validated with normalizeEntry()).
     *
     * @param list<string> $entries
     */
    public function saveSettingEntries(array $entries): Setting
    {
        $setting = Setting::withTrashed()->where('setting_key', self::SETTING_KEY)->first() ?? new Setting();
        if ($setting->trashed()) {
            $setting->restore();
        }
        $setting->fill([
            'setting_key' => self::SETTING_KEY,
            'type' => 'text',
            'group_name' => 'workflow',
            'name' => 'http_hosts',
            'input_setting' => ['isPublic' => false, 'isEnv' => false, 'title' => 'Workflow outbound hosts'],
            'value' => (string) json_encode(array_values($entries), JSON_UNESCAPED_SLASHES),
        ])->save();

        return $setting;
    }

    /**
     * Whether host:port may be called.
     */
    public function allows(string $host, int $port): bool
    {
        return self::matchesAny($this->entries(), $host, $port);
    }

    /**
     * Whether host:port matches one of the given normalized entries (same entry forms and port rule as the site list;
     * also used for the hosts a `$bind:` HTTP credential may be sent to).
     *
     * @param list<string> $entries
     */
    public static function matchesAny(array $entries, string $host, int $port): bool
    {
        $host = self::normalizeHost($host);
        if ($host === '') {
            return false;
        }

        foreach ($entries as $entry) {
            [$pattern, $entryPort] = self::split($entry);
            $portOk = $entryPort !== null ? $port === $entryPort : in_array($port, [80, 443], true);
            if (!$portOk) {
                continue;
            }
            if (str_starts_with($pattern, '*.')) {
                if (str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1) {
                    return true;
                }
            } elseif ($host === $pattern) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether some entry names this host at all (used to tell "host not allowed" from "port not allowed").
     */
    public function knowsHost(string $host): bool
    {
        $host = self::normalizeHost($host);
        foreach ($this->entries() as $entry) {
            [$pattern] = self::split($entry);
            if ($host === $pattern || (str_starts_with($pattern, '*.') && str_ends_with($host, substr($pattern, 1)) && strlen($host) > strlen($pattern) - 1)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Normalize one entry typed by a person; null when it is not a valid entry.
     */
    public static function normalizeEntry(mixed $entry): ?string
    {
        if (!is_string($entry)) {
            return null;
        }
        $entry = strtolower(trim($entry));
        $entry = (string) preg_replace('#^https?://#', '', $entry);
        $entry = rtrim($entry, '/');
        if ($entry === '' || strlen($entry) > 260) {
            return null;
        }

        $port = null;
        if (preg_match('/^(.+):(\d{1,5})$/', $entry, $m)) {
            $entry = $m[1];
            $port = (int) $m[2];
            if ($port < 1 || $port > 65535) {
                return null;
            }
        }

        $entry = rtrim($entry, '.');
        if (filter_var($entry, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            if (!IpRangeGuard::isPublic($entry)) {
                return null;
            }
        } else {
            $wildcard = str_starts_with($entry, '*.');
            $name = $wildcard ? substr($entry, 2) : $entry;
            $name = self::toAscii($name);
            $label = self::LABEL;
            if ($name === null || !preg_match("/^{$label}(?:\\.{$label})+$/", $name)) {
                return null;
            }
            // A wildcard needs at least a registrable-looking domain under it: `*.example.com`, never `*.com`.
            if ($wildcard && substr_count($name, '.') < 1) {
                return null;
            }
            if (preg_match('/^[0-9.]+$/', $name)) {
                return null;
            }
            $entry = ($wildcard ? '*.' : '') . $name;
        }

        return $entry . ($port !== null ? ':' . $port : '');
    }

    public static function normalizeHost(string $host): string
    {
        $host = strtolower(trim($host, "[] \t\n\r\0\x0B"));
        $host = rtrim($host, '.');

        return self::toAscii($host) ?? '';
    }

    /**
     * @param array<int|string, mixed> $list
     * @return list<string>
     */
    private static function normalizeList(array $list): array
    {
        $out = [];
        foreach ($list as $entry) {
            $normalized = self::normalizeEntry($entry);
            if ($normalized !== null) {
                $out[] = $normalized;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array{0: string, 1: int|null}
     */
    private static function split(string $entry): array
    {
        if (preg_match('/^(.+):(\d{1,5})$/', $entry, $m)) {
            return [$m[1], (int) $m[2]];
        }

        return [$entry, null];
    }

    private static function toAscii(string $host): ?string
    {
        if ($host === '' || preg_match('/^[\x00-\x7F]*$/', $host)) {
            return $host;
        }
        if (!function_exists('idn_to_ascii')) {
            return null;
        }
        $ascii = idn_to_ascii($host, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

        return is_string($ascii) ? strtolower($ascii) : null;
    }
}
