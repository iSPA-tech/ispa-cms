<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

use InvalidArgumentException;

/**
 * Keeps secrets out of configuration packages.
 *
 * Two independent checks:
 *  - isSecretSetting() classifies a settings row the same way encrypted storage does (type `password`,
 *    `isEnv`/`is_env` true, or `isPublic`/`is_public` false; input_setting may be an array or a JSON string).
 *    The exporter excludes such rows and lists their keys in `required_secrets`.
 *  - findSecrets()/assertNoSecrets() walk the sections and refuse KEY NAMES that look secret and string VALUES
 *    that look like Laravel Crypt ciphertext. Key names are matched by whole segments (split on `_`, `.`, `-` and
 *    camelCase), not substrings: `access_token` and `apiKey` are refused, `max_tokens` and `token_limit` pass.
 *    Ordinary values are never matched by words, so a translation `name` of `text.password.label` or a text
 *    containing "token" passes.
 *
 * Reported paths never include values.
 */
final class SecretGuard
{
    /** Key segments that make a key name secret on their own (compared lowercase, whole segment). */
    public const SECRET_KEY_WORDS = [
        'password',
        'passwords',
        'passwd',
        'secret',
        'secrets',
        'token',
        'authorization',
        'bearer',
        'credential',
        'credentials',
        'apikey',
        'privatekey',
        'accesskey',
    ];

    /** Pairs of consecutive key segments that make a key name secret (`api_key`, `apiKey`, `x-api-key`, ...). */
    public const SECRET_KEY_PAIRS = [
        ['api', 'key'],
        ['private', 'key'],
        ['access', 'key'],
        ['client', 'secret'],
    ];

    /**
     * Safe key names that contain a secret word but only count or limit things (normalised with `_`).
     * `max_tokens`, `tokens_used` and the like need no entry: `tokens` is a different segment than `token`.
     * `token_length` is logged by CaptchaGateway; `token_limit` is reserved for step token budgets.
     */
    public const SAFE_KEY_NAMES = [
        'token_limit',
        'token_length',
    ];

    /**
     * Extra key segments that mask a SETTING (admin API policy) on top of isSecretKey(). Kept apart from
     * SECRET_KEY_WORDS so findSecrets() behaviour for package sections does not change.
     */
    public const MASKED_SETTING_EXTRA_WORDS = [
        'dsn',
        'private',
    ];

    /**
     * A name whose LAST segment is one of these holds a credential even without a secret word: the seeded
     * `api.GOOGLE_API` and `api.OPEN_AI_API` are API keys (2026-11-10). Names carrying a public marker
     * (`turnstile.TURNSTILE_SITE_KEY`) stay readable.
     */
    public const MASKED_SETTING_TAIL_WORDS = ['api', 'key'];

    /** Segments that mark a key-like name as public, e.g. a site key the browser receives anyway. */
    public const PUBLIC_KEY_MARKERS = ['site', 'public'];

    /** input_setting fields that may hold the environment variable name of a setting. */
    private const ENV_NAME_FIELDS = ['envKey', 'env_key', 'envName', 'env_name'];

    private const CIPHERTEXT_PREFIX = 'eyJpdiI6';

    /**
     * Tell whether a settings row holds a secret value (encrypted at rest).
     *
     * Reads `type`, and `isEnv`/`is_env`/`isPublic`/`is_public` from `input_setting` (array or JSON string,
     * possibly double encoded) and from the row itself.
     *
     * @param array<string, mixed> $row
     */
    public function isSecretSetting(array $row): bool
    {
        if (strtolower(trim((string) ($row['type'] ?? ''))) === 'password') {
            return true;
        }

        foreach ([$this->decodeInputSetting($row['input_setting'] ?? null), $row] as $source) {
            $isEnv = $this->toBool($source['isEnv'] ?? $source['is_env'] ?? null, false);
            $isPublic = $this->toBool($source['isPublic'] ?? $source['is_public'] ?? null, true);

            if ($isEnv || !$isPublic) {
                return true;
            }
        }

        return false;
    }

    /**
     * Tell whether the admin settings API must mask a settings row (write-only, shown as `********`).
     *
     * Narrower than isSecretSetting(): env-managed (`isEnv`) or non-public rows stay readable and editable unless
     * they are real secrets. A row is masked when its type is `password`, input_setting `secret`/`is_secret` is
     * true, or its `setting_key` (and, for env-managed rows, its env variable name: input_setting
     * `envKey`/`env_key`/`envName`/`env_name` or the row `name`) looks secret (password, secret, token, private,
     * api_key, client_secret, dsn, credential, ...). Key names are matched by whole segments.
     *
     * @param array<string, mixed> $row Needs `type`, `setting_key` and `input_setting` (array or JSON string); `name` optional.
     */
    public function isMaskedSetting(array $row): bool
    {
        if (strtolower(trim((string) ($row['type'] ?? ''))) === 'password') {
            return true;
        }

        $input = $this->decodeInputSetting($row['input_setting'] ?? null);

        if ($this->toBool($input['secret'] ?? $input['is_secret'] ?? null, false)) {
            return true;
        }

        $names = [(string) ($row['setting_key'] ?? $row['key'] ?? '')];

        foreach (self::ENV_NAME_FIELDS as $field) {
            if (is_string($input[$field] ?? null)) {
                $names[] = $input[$field];
            }
        }

        $isEnv = $this->toBool($input['isEnv'] ?? $input['is_env'] ?? $row['isEnv'] ?? $row['is_env'] ?? null, false);
        if ($isEnv && is_string($row['name'] ?? null)) {
            $names[] = $row['name'];
        }

        foreach ($names as $name) {
            if ($name === '') {
                continue;
            }

            $segments = $this->segments($name);

            if ($this->isSecretKey($name) || array_intersect($segments, self::MASKED_SETTING_EXTRA_WORDS) !== []) {
                return true;
            }

            if (
                $segments !== []
                && in_array($segments[count($segments) - 1], self::MASKED_SETTING_TAIL_WORDS, true)
                && array_intersect($segments, self::PUBLIC_KEY_MARKERS) === []
                && !in_array(implode('_', $segments), self::SAFE_KEY_NAMES, true)
            ) {
                return true;
            }
        }

        return false;
    }

    /**
     * List the paths (e.g. `sections.settings.0.value.smtp.smtp_password`) of secret-looking keys and ciphertext values.
     *
     * @param array<array-key, mixed> $sections
     *
     * @return list<string>
     */
    public function findSecrets(array $sections): array
    {
        $paths = [];
        $this->walk($sections, 'sections', $paths);

        return $paths;
    }

    /**
     * Throw when the sections contain a secret-looking key or a ciphertext value; the message names paths only.
     *
     * @param array<array-key, mixed> $sections
     *
     * @throws InvalidArgumentException
     */
    public function assertNoSecrets(array $sections): void
    {
        $paths = $this->findSecrets($sections);

        if ($paths !== []) {
            throw new InvalidArgumentException('Configuration package contains secret data at: ' . implode(', ', $paths) . '.');
        }
    }

    /**
     * Paths of settings rows that target a real secret on THIS site (isMaskedSetting(): password type, secret flag or
     * secret-looking key), e.g. `sections.settings.3.key`. A package that carries such a row cannot be a clean export
     * (the exporter leaves them out), so it may hold a planted value and must not be stored.
     *
     * @param array<array-key, mixed> $sections Package sections.
     *
     * @return list<string>
     */
    public function findSecretSettingRows(array $sections): array
    {
        $rows = is_array($sections['settings'] ?? null) ? $sections['settings'] : [];
        $keys = [];
        foreach ($rows as $index => $row) {
            if (is_array($row) && is_string($row['key'] ?? null) && $row['key'] !== '') {
                $keys[(string) $row['key']][] = $index;
            }
        }

        if ($keys === []) {
            return [];
        }

        $paths = [];
        foreach (\Cms\Core\Models\Setting::query()->whereIn('setting_key', array_keys($keys))->get() as $setting) {
            $masked = $this->isMaskedSetting([
                'type' => $setting->type,
                'setting_key' => (string) $setting->setting_key,
                'name' => $setting->name,
                'input_setting' => $setting->input_setting,
            ]);

            if ($masked) {
                foreach ($keys[(string) $setting->setting_key] ?? [] as $index) {
                    $paths[] = 'sections.settings.' . $index . '.key';
                }
            }
        }

        sort($paths, SORT_NATURAL);

        return $paths;
    }

    /**
     * Throw when a settings row targets a real secret on this site; the message names paths only.
     *
     * @param array<array-key, mixed> $sections Package sections.
     *
     * @throws InvalidArgumentException
     */
    public function assertNoSecretSettingRows(array $sections): void
    {
        $paths = $this->findSecretSettingRows($sections);

        if ($paths !== []) {
            throw new InvalidArgumentException('Configuration package contains secret settings at: ' . implode(', ', $paths) . '.');
        }
    }

    /**
     * Tell whether a key name looks like it holds a secret.
     *
     * The key is lowercased and split into segments on `_`, `.`, `-` and camelCase boundaries. It is secret when a
     * segment is a secret word, two consecutive segments form a secret pair, or the joined segments equal
     * `apikey`/`privatekey`/`accesskey`; names in SAFE_KEY_NAMES are never secret. Whole segments only, so
     * `max_tokens` passes while `access_token` and `x-api-key` are flagged.
     */
    public function isSecretKey(string $key): bool
    {
        $segments = $this->segments($key);

        if (in_array(implode('_', $segments), self::SAFE_KEY_NAMES, true)) {
            return false;
        }

        if (array_intersect($segments, self::SECRET_KEY_WORDS) !== []) {
            return true;
        }

        if (in_array(implode('', $segments), ['apikey', 'privatekey', 'accesskey'], true)) {
            return true;
        }

        $count = count($segments);
        for ($i = 0; $i < $count - 1; ++$i) {
            foreach (self::SECRET_KEY_PAIRS as [$first, $second]) {
                if ($segments[$i] === $first && $segments[$i + 1] === $second) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Split a key into lowercase segments on `_`, `.`, `-` and camelCase boundaries.
     *
     * @return list<string>
     */
    private function segments(string $key): array
    {
        $spaced = preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key) ?? $key;
        $spaced = preg_replace('/([A-Z]+)([A-Z][a-z])/', '$1_$2', $spaced) ?? $spaced;

        return array_values(array_filter(
            preg_split('/[_.\-\s]+/', strtolower($spaced)) ?: [],
            static fn (string $segment): bool => $segment !== '',
        ));
    }

    /**
     * Tell whether a string looks like Laravel Crypt ciphertext (base64 JSON payload with iv, value and mac).
     */
    public function looksLikeCiphertext(string $value): bool
    {
        $value = trim($value);

        if (str_starts_with($value, self::CIPHERTEXT_PREFIX)) {
            return true;
        }

        if (strlen($value) < 24 || preg_match('/^[A-Za-z0-9+\/]+={0,2}$/', $value) !== 1) {
            return false;
        }

        $decoded = base64_decode($value, true);
        if ($decoded === false || !str_starts_with(ltrim($decoded), '{')) {
            return false;
        }

        $payload = json_decode($decoded, true);

        return is_array($payload) && isset($payload['iv'], $payload['value'], $payload['mac']);
    }

    /**
     * @param list<string> $paths
     */
    private function walk(mixed $value, string $path, array &$paths): void
    {
        if (is_string($value)) {
            if ($this->looksLikeCiphertext($value)) {
                $paths[] = $path;
            }

            return;
        }

        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $childPath = $path . '.' . $key;

            if (is_string($key) && $this->isSecretKey($key)) {
                $paths[] = $childPath;

                continue;
            }

            $this->walk($child, $childPath, $paths);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeInputSetting(mixed $value): array
    {
        for ($depth = 0; $depth < 2 && is_string($value); ++$depth) {
            $value = json_decode($value, true);
        }

        return is_array($value) ? $value : [];
    }

    private function toBool(mixed $value, bool $default): bool
    {
        if ($value === null) {
            return $default;
        }

        if (is_bool($value)) {
            return $value;
        }

        return filter_var($value, FILTER_VALIDATE_BOOLEAN, FILTER_NULL_ON_FAILURE) ?? $default;
    }
}
