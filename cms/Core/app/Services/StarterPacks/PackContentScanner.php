<?php

declare(strict_types=1);

namespace Cms\Core\Services\StarterPacks;

use Cms\Core\Services\ConfigPackage\SecretGuard;
use FilesystemIterator;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * Scans starter-pack and export files so a pack never carries a secret, an encrypted setting or personal data.
 *
 * Findings are `['file' => relative path, 'line' => 1-based line, 'rule' => rule id]` and NEVER include the matched
 * value. Rule ids (stable):
 *  - `private_key`    `-----BEGIN ... PRIVATE KEY-----` (RSA, EC, DSA, OPENSSH, ENCRYPTED, plain, PGP ... KEY BLOCK).
 *  - `token`          provider-shaped tokens (`sk-`, `sk_live_`, `pk_live_`, `ghp_`, `github_pat_`, `xoxb-`, `AKIA...`,
 *                     `AIza...`), `Bearer <20+ chars>`, and any non-placeholder value of an `api_key`/`apiKey`/`x-api-key`.
 *  - `secret_value`   a non-placeholder value of another key that SecretGuard::isSecretKey() calls secret (whole-segment
 *                     match: `smtp_password`, `access_token`, `clientSecret`), and credentials embedded in a URL
 *                     (`scheme://user:pass@host`). Any value counts, not only long base64/hex: `"password": "x"` is flagged.
 *  - `ciphertext`     Laravel Crypt payloads (`eyJpdiI6...` or any base64 JSON with iv/value/mac): a leaked encrypted setting.
 *  - `email`          addresses outside the reserved example domains.
 *  - `phone`          Vietnamese mobiles (`0[35789]` + 8 digits, `+84`) and `+<country>` numbers with 9-15 digits.
 *  - `secret_setting` the `setting_key` of a secret setting (SecretGuard::isSecretSetting()) quoted in a file, or as a
 *                     YAML/ENV key, when a setting definition list is given.
 *  - `symlink`        a symbolic link inside a scanned directory (line 0); links are never followed.
 *
 * Key/value pairs are read from JSON (`"k": "v"`, `"k": 123`), PHP arrays (`'k' => 'v'`) and, only in YAML/ENV/INI/TXT
 * files, `k: v` / `K=v` lines. Every non-binary file is scanned (binary = known media/archive extension or a NUL byte in
 * the first 8 KB), not only the listed text types, to keep false negatives low.
 *
 * Documented false-positive allowances (all covered by tests):
 *  - Prose is never matched by words: "API key", "password", "token", "Bearer token" in text pass; `k: v` lines are not
 *    read in Markdown/HTML, and a secret key whose value contains whitespace (a label such as "Mật khẩu") passes.
 *  - Key names use SecretGuard's segment rule: `max_tokens`, `tokens_used`, `token_limit`, `token_length` pass. Keys whose
 *    other segments only describe the secret (min, max, length, limit, rules, policy, timeout, ttl, expires, uuid, id,
 *    label, hint, placeholder, type, enabled, required, header, name, ...) pass: `password_min_length: 8`, `token_uuid`.
 *  - Placeholder values pass: empty, null/none/true/false, `***`, `xxx`+, `...`, `${VAR}`, `{{ var }}`, `<...>`, `%VAR%`,
 *    `env(...)`, `your_...`, `changeme`, `redacted`, translation-key-shaped values (`text.password.label`) and a value equal
 *    to the key name or its last segment (`"token": "Token"`). A single `x` is NOT a placeholder.
 *  - Emails at example.com, example.org, example.net, example.test (and subdomains) and the reserved TLDs .test, .example,
 *    .invalid, .localhost pass; `logo@2x.png`-style asset names, vue-i18n `{'@'}` and npm scopes `@vue/x` never match.
 *  - Phones need a boundary not preceded by a word char, `-`, `.`, `+`, `/`, `#` or `:`: UUIDs, digits inside slugs, dates,
 *    ISO timestamps (`+07:00`), semver build metadata (`1.0.0+2026...`), hex colors, prices and ids starting with 1-9 pass.
 *    Known misses: VN landlines without `+84`, numbers written without separators context (e.g. inside slugs).
 */
final class PackContentScanner
{
    private const CIPHERTEXT_PREFIX = 'eyJpdiI6';

    private const BINARY_EXTENSIONS = [
        'png', 'jpg', 'jpeg', 'gif', 'webp', 'avif', 'ico', 'bmp', 'tif', 'tiff', 'pdf', 'zip', 'gz', 'tgz', 'rar', '7z',
        'woff', 'woff2', 'ttf', 'otf', 'eot', 'mp3', 'mp4', 'webm', 'mov', 'wav', 'ogg', 'heic',
    ];

    private const KV_EXTENSIONS = ['yml', 'yaml', 'env', 'ini', 'conf', 'properties', 'txt'];

    private const TOKEN_PATTERNS = [
        '/(?<![A-Za-z0-9])(?:sk|pk|rk)[-_](?:[a-z]{2,8}[-_])?[A-Za-z0-9]{20,}/',
        '/\bBearer\s+[A-Za-z0-9\-._~+\/]{20,}/',
        '/(?<![A-Za-z0-9])gh[pousr]_[A-Za-z0-9]{30,}/',
        '/(?<![A-Za-z0-9])github_pat_[A-Za-z0-9_]{30,}/',
        '/(?<![A-Za-z0-9])xox[abposr]-[A-Za-z0-9-]{10,}/',
        '/(?<![A-Z0-9])(?:AKIA|ASIA)[0-9A-Z]{16}(?![0-9A-Z])/',
        '/(?<![A-Za-z0-9])AIza[0-9A-Za-z_\-]{35}/',
    ];

    /** Key segments that only describe a secret (a limit, a rule, an id), never hold it. */
    private const DESCRIPTIVE_SEGMENTS = [
        'min', 'max', 'length', 'len', 'limit', 'count', 'size', 'ttl', 'timeout', 'expires', 'expiry', 'expire',
        'lifetime', 'rule', 'rules', 'policy', 'required', 'enabled', 'enable', 'disabled', 'label', 'placeholder',
        'hint', 'help', 'description', 'title', 'message', 'field', 'type', 'uuid', 'id', 'ids', 'header',
        'name', 'strength', 'regex', 'pattern', 'format', 'in', 'at',
    ];

    private const ALLOWED_EMAIL_DOMAINS = ['example.com', 'example.org', 'example.net', 'example.test'];

    private const ALLOWED_EMAIL_TLDS = ['test', 'example', 'invalid', 'localhost'];

    /** @var list<array<string, mixed>> */
    private array $settings = [];

    public function __construct(private readonly SecretGuard $guard = new SecretGuard())
    {
    }

    /**
     * Return a copy that also reports the keys of secret settings found in the given setting definition rows.
     *
     * @param list<array<string, mixed>> $rows settings rows (`setting_key`, `type`, `input_setting`, ...)
     */
    public function withSettings(array $rows): self
    {
        $copy = clone $this;
        $copy->settings = array_values($rows);

        return $copy;
    }

    /**
     * Scan a file or a directory (recursively) and return the findings, without matched values.
     *
     * @param list<array<string, mixed>> $settings extra setting definition rows for the `secret_setting` rule
     *
     * @return list<array{file: string, line: int, rule: string}>
     *
     * @throws InvalidArgumentException when the path does not exist
     */
    public function scan(string $path, array $settings = []): array
    {
        $secretKeys = $this->secretSettingKeys([...$this->settings, ...$settings]);

        if (is_file($path) && !is_link($path)) {
            return $this->isBinary($path) ? [] : $this->scanContent((string) file_get_contents($path), basename($path), $secretKeys);
        }

        if (!is_dir($path) || is_link($path)) {
            throw new InvalidArgumentException('Scan path is not a readable file or directory.');
        }

        $root = rtrim(str_replace('\\', '/', $path), '/') . '/';
        $files = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $info) {
            $files[substr(str_replace('\\', '/', $info->getPathname()), strlen($root))] = $info;
        }
        ksort($files, SORT_STRING);

        $findings = [];
        foreach ($files as $relative => $info) {
            if ($info->isLink()) {
                $findings[] = ['file' => $relative, 'line' => 0, 'rule' => 'symlink'];
            } elseif ($info->isFile() && !$this->isBinary($info->getPathname())) {
                array_push($findings, ...$this->scanContent((string) file_get_contents($info->getPathname()), $relative, $secretKeys));
            }
        }

        return $findings;
    }

    /**
     * Scan in-memory content as if it were a file named $file (the extension selects the key/value syntaxes).
     *
     * @param list<array<string, mixed>> $settings extra setting definition rows for the `secret_setting` rule
     *
     * @return list<array{file: string, line: int, rule: string}>
     */
    public function scanString(string $content, string $file = 'inline', array $settings = []): array
    {
        return $this->scanContent($content, $file, $this->secretSettingKeys([...$this->settings, ...$settings]));
    }

    /**
     * @param list<string> $secretKeys
     *
     * @return list<array{file: string, line: int, rule: string}>
     */
    private function scanContent(string $content, string $file, array $secretKeys): array
    {
        $base = strtolower(basename($file));
        $extension = pathinfo($base, PATHINFO_EXTENSION);
        $readKv = in_array($extension, self::KV_EXTENSIONS, true) || str_starts_with($base, '.env') || str_ends_with($base, '.env');

        $findings = [];
        foreach (preg_split('/\r\n|\r|\n/', $content) ?: [] as $index => $line) {
            foreach (array_unique($this->lineRules($line, $readKv, $secretKeys)) as $rule) {
                $findings[] = ['file' => $file, 'line' => $index + 1, 'rule' => $rule];
            }
        }

        return $findings;
    }

    /**
     * @param list<string> $secretKeys
     *
     * @return list<string>
     */
    private function lineRules(string $line, bool $readKv, array $secretKeys): array
    {
        $rules = [];

        if (preg_match('/-----BEGIN (?:[A-Z0-9]+ )*PRIVATE KEY(?: BLOCK)?-----/', $line) === 1) {
            $rules[] = 'private_key';
        }

        foreach (self::TOKEN_PATTERNS as $pattern) {
            if (preg_match($pattern, $line) === 1) {
                $rules[] = 'token';
                break;
            }
        }

        foreach ($this->pairs($line, $readKv) as [$key, $value]) {
            if (!$this->guard->isSecretKey($key) || $this->isDescriptiveKey($key) || $this->isPlaceholder($key, $value)) {
                continue;
            }
            if (!$this->guard->looksLikeCiphertext($value)) {
                $rules[] = $this->isApiKey($key) ? 'token' : 'secret_value';
            }
        }

        if (preg_match('#[a-z][a-z0-9+.\-]*://[^\s:/@"\'<>]+:[^\s/@"\'<>]+@#i', $line) === 1) {
            $rules[] = 'secret_value';
        }

        if (str_contains($line, self::CIPHERTEXT_PREFIX) || $this->hasBase64Ciphertext($line)) {
            $rules[] = 'ciphertext';
        }

        if ($this->hasPersonalEmail($line)) {
            $rules[] = 'email';
        }

        if ($this->hasPhone($line)) {
            $rules[] = 'phone';
        }

        foreach ($secretKeys as $key) {
            $quoted = preg_quote($key, '/');
            if (preg_match('/(["\'])' . $quoted . '\1|^\s*(?:export\s+)?' . $quoted . '\s*[:=]/', $line) === 1) {
                $rules[] = 'secret_setting';
                break;
            }
        }

        return $rules;
    }

    /**
     * Extract key/value pairs from JSON, PHP array and (when allowed) YAML/ENV syntax on one line.
     *
     * @return list<array{0: string, 1: string}>
     */
    private function pairs(string $line, bool $readKv): array
    {
        $pairs = [];

        preg_match_all('/"([^"\\\\]+)"\s*:\s*(?:"((?:[^"\\\\]|\\\\.)*)"|(-?\d+(?:\.\d+)?)\b)/', $line, $json, PREG_SET_ORDER);
        foreach ($json as $match) {
            $pairs[] = [$match[1], ($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? '')];
        }

        preg_match_all('/([\'"])([^\'"]+)\1\s*=>\s*([\'"])((?:(?!\3).)*)\3/', $line, $php, PREG_SET_ORDER);
        foreach ($php as $match) {
            $pairs[] = [$match[2], $match[4]];
        }

        if ($readKv && preg_match('/^\s*(?:export\s+)?(?:-\s+)?["\']?([A-Za-z_][A-Za-z0-9_.\-]*)["\']?\s*[:=]\s*(.*)$/', $line, $kv) === 1) {
            $value = trim((string) preg_replace('/\s+#.*$/', '', $kv[2]));
            if ($value !== '' && !in_array($value[0], ['{', '[', '|', '>'], true)) {
                $pairs[] = [$kv[1], trim($value, '"\'')];
            }
        }

        return $pairs;
    }

    private function isDescriptiveKey(string $key): bool
    {
        $snake = strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key));
        $segments = preg_split('/[_.\-\s]+/', $snake) ?: [];

        return array_intersect($segments, self::DESCRIPTIVE_SEGMENTS) !== [];
    }

    private function isApiKey(string $key): bool
    {
        $joined = strtolower((string) preg_replace('/[^A-Za-z0-9]/', '', $key));

        return str_ends_with($joined, 'apikey');
    }

    private function isPlaceholder(string $key, string $value): bool
    {
        $value = trim($value);
        $lower = strtolower($value);

        if ($value === '' || preg_match('/\s/', $value) === 1) {
            return true;
        }

        if (in_array($lower, ['null', 'none', 'nil', '~', 'true', 'false', 'changeme', 'redacted', '...'], true)
            || preg_match('/^(?:\*+|x{3,}|\$\{.*\}|\{\{.*\}\}|<.*>|%[^%]+%|env\(.*\))$/i', $value) === 1
            || preg_match('/^(?:your|my)[_\-]/i', $value) === 1
            || preg_match('/^[a-z][a-z0-9_]*(?:\.[a-z0-9_]+){2,}$/', $value) === 1) {
            return true;
        }

        $normalised = (string) preg_replace('/[^a-z0-9]/', '', $lower);
        $keyParts = preg_split('/[_.\-]+/', strtolower((string) preg_replace('/([a-z0-9])([A-Z])/', '$1_$2', $key))) ?: [];

        return $normalised === implode('', $keyParts) || $normalised === (string) end($keyParts);
    }

    private function hasBase64Ciphertext(string $line): bool
    {
        preg_match_all('/[A-Za-z0-9+\/]{40,}={0,2}/', $line, $matches);
        foreach ($matches[0] as $candidate) {
            if ($this->guard->looksLikeCiphertext($candidate)) {
                return true;
            }
        }

        return false;
    }

    private function hasPersonalEmail(string $line): bool
    {
        preg_match_all('/(?<![A-Za-z0-9._%+\-@{\'])[A-Za-z0-9][A-Za-z0-9._%+\-]*@((?:[A-Za-z0-9](?:[A-Za-z0-9\-]*[A-Za-z0-9])?\.)+([A-Za-z]{2,}))(?![A-Za-z0-9\-])/', $line, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $domain = strtolower($match[1]);
            $tld = strtolower($match[2]);
            if (in_array($tld, self::BINARY_EXTENSIONS, true) || in_array($tld, ['svg', 'css', 'js', 'json'], true)) {
                continue;
            }
            if (in_array($tld, self::ALLOWED_EMAIL_TLDS, true)) {
                continue;
            }
            foreach (self::ALLOWED_EMAIL_DOMAINS as $allowed) {
                if ($domain === $allowed || str_ends_with($domain, '.' . $allowed)) {
                    continue 2;
                }
            }

            return true;
        }

        return false;
    }

    private function hasPhone(string $line): bool
    {
        $boundary = '(?<![\w.+\/#:\-])';

        if (preg_match('/' . $boundary . '(?:\+84[ .\-]?|0)[35789](?:[ .\-]?\d){8}(?![\w\-]|\.\d)/', $line) === 1) {
            return true;
        }

        preg_match_all('/' . $boundary . '\+\d[\d .\-()]{6,22}\d(?![\w\-]|\.\d)/', $line, $matches);
        foreach ($matches[0] as $candidate) {
            $digits = strlen((string) preg_replace('/\D/', '', $candidate));
            if ($digits >= 9 && $digits <= 15) {
                return true;
            }
        }

        return false;
    }

    private function isBinary(string $path): bool
    {
        if (in_array(strtolower(pathinfo($path, PATHINFO_EXTENSION)), self::BINARY_EXTENSIONS, true)) {
            return true;
        }

        $handle = @fopen($path, 'rb');
        if ($handle === false) {
            return false;
        }
        $head = (string) fread($handle, 8192);
        fclose($handle);

        return str_contains($head, "\0");
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<string>
     */
    private function secretSettingKeys(array $rows): array
    {
        $keys = [];
        foreach ($rows as $row) {
            $key = trim((string) ($row['setting_key'] ?? ''));
            if ($key !== '' && $this->guard->isSecretSetting($row)) {
                $keys[$key] = $key;
            }
        }

        return array_values($keys);
    }
}
