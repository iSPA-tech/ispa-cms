<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

use JsonException;

/**
 * Validates a decoded configuration package before any diff or import.
 *
 * Checks the envelope (format, supported format_version, allowed and required top-level keys), the shape of each
 * section and its stable key fields, row caps, the SHA-256 checksum, numeric `id`/`*_id` keys, the size cap and
 * secrets (SecretGuard). Error messages name paths, never values.
 *
 * validate() returns a ValidationResult, which is declared at the bottom of this file. Callers only use the returned
 * object (isValid()/errors()) and never reference ValidationResult by name, so it is always loaded together with
 * this class through PSR-4 autoloading of PackageValidator.
 */
final class PackageValidator
{
    /** Maximum nesting depth of menu items (1 = top level items). */
    public const MAX_MENU_DEPTH = 10;

    /** Stable key fields required on rows of each list section. */
    private const STABLE_FIELDS = [
        'settings' => ['key'],
        'translations' => ['name'],
        'roles' => ['name'],
        'menus' => ['group_key', 'name'],
        'workflows' => ['slug'],
        'custom_fields' => ['resource'],
    ];

    /**
     * @param int $maxBytes Maximum JSON size of the whole package.
     * @param int $maxRows Maximum rows per section (per table for `tables`).
     */
    public function __construct(
        private readonly int $maxBytes = PackageFormat::MAX_DECODED_BYTES,
        private readonly int $maxRows = PackageFormat::MAX_ROWS_PER_SECTION,
        private readonly SecretGuard $secretGuard = new SecretGuard(),
    ) {
    }

    /**
     * Validate a decoded package and collect every error found.
     *
     * @param array<array-key, mixed> $package
     */
    public function validate(array $package): ValidationResult
    {
        try {
            $size = strlen(json_encode($package, PackageFormat::JSON_FLAGS));
        } catch (JsonException) {
            return new ValidationResult([self::issue('encode_failed', [], 'Package cannot be encoded as JSON.')]);
        }

        if ($size > $this->maxBytes) {
            return new ValidationResult([self::issue('too_large', ['size' => $size, 'max' => $this->maxBytes], "Package size {$size} bytes exceeds the limit of {$this->maxBytes} bytes.")]);
        }

        $errors = [];
        $this->validateEnvelope($package, $errors);

        $sections = $package['sections'] ?? null;
        if (!array_key_exists('sections', $package)) {
            return new ValidationResult($errors);
        }

        if (!$this->isObject($sections)) {
            $errors[] = self::issue('sections_not_object', [], 'sections must be an object.');

            return new ValidationResult($errors);
        }

        foreach ($sections as $name => $section) {
            $this->validateSection((string) $name, $section, $errors);
        }

        $this->validateNoNumericIds($sections, 'sections', $errors);

        foreach ($this->secretGuard->findSecrets($sections) as $path) {
            $errors[] = self::issue('secret_found', ['path' => $path], "{$path} looks like a secret (secret key name or encrypted value); secrets are never exported.");
        }

        $this->validateChecksum($package['checksum'] ?? null, $sections, $errors);

        return new ValidationResult($errors);
    }

    /**
     * @param array<array-key, mixed> $package
     * @param list<array{key: string, params: array<string, mixed>, message: string}> $errors
     */
    private function validateEnvelope(array $package, array &$errors): void
    {
        foreach (array_keys($package) as $key) {
            if (!in_array($key, PackageFormat::TOP_LEVEL_KEYS, true)) {
                $errors[] = self::issue('unknown_key', ['key' => (string) $key], "Unknown top-level key \"{$key}\".");
            }
        }

        foreach (PackageFormat::REQUIRED_KEYS as $key) {
            if (!array_key_exists($key, $package)) {
                $errors[] = self::issue('missing_key', ['key' => (string) $key], "Missing required key \"{$key}\".");
            }
        }

        if (array_key_exists('format', $package) && $package['format'] !== PackageFormat::FORMAT) {
            $errors[] = self::issue('bad_format', ['format' => PackageFormat::FORMAT], 'format must be "' . PackageFormat::FORMAT . '".');
        }

        if (array_key_exists('format_version', $package)) {
            $version = $package['format_version'];
            if (!is_int($version)) {
                $errors[] = self::issue('version_not_integer', [], 'format_version must be an integer.');
            } elseif ($version > PackageFormat::VERSION) {
                $errors[] = self::issue('version_unsupported', ['version' => $version, 'max' => PackageFormat::VERSION], "Unsupported format_version {$version}; this server supports up to " . PackageFormat::VERSION . '.');
            } elseif ($version < 1) {
                $errors[] = self::issue('version_invalid', ['version' => $version], "Invalid format_version {$version}.");
            }
        }

        if (array_key_exists('created_at', $package) && (!is_string($package['created_at']) || $package['created_at'] === '')) {
            $errors[] = self::issue('created_at_invalid', [], 'created_at must be an ISO 8601 string.');
        }

        foreach (['source', 'skipped'] as $key) {
            if (array_key_exists($key, $package) && !$this->isObject($package[$key])) {
                $errors[] = self::issue('not_object', ['key' => (string) $key], "{$key} must be an object.");
            }
        }

        if (array_key_exists('required_secrets', $package)) {
            $secrets = $package['required_secrets'];
            if (!is_array($secrets) || !array_is_list($secrets) || array_filter($secrets, fn ($k): bool => !is_string($k)) !== []) {
                $errors[] = self::issue('secrets_not_list', [], 'required_secrets must be a list of setting keys.');
            }
        }
    }

    /**
     * @param list<array{key: string, params: array<string, mixed>, message: string}> $errors
     */
    private function validateSection(string $name, mixed $section, array &$errors): void
    {
        $path = "sections.{$name}";

        if (!in_array($name, PackageFormat::SECTIONS, true)) {
            $errors[] = self::issue('unknown_section', ['section' => $name], "Unknown section \"{$name}\".");

            return;
        }

        if ($name === PackageFormat::TABLES_SECTION) {
            if (!$this->isObject($section)) {
                $errors[] = self::issue('tables_not_object', ['path' => $path], "{$path} must be an object of table name => rows.");

                return;
            }

            foreach ($section as $table => $rows) {
                $this->validateRows($rows, "{$path}.{$table}", [], $errors);
            }

            return;
        }

        if (!$this->validateRows($section, $path, self::STABLE_FIELDS[$name], $errors)) {
            return;
        }

        foreach ($section as $index => $row) {
            if (!$this->isObject($row)) {
                continue;
            }

            if ($name === 'menus' && array_key_exists('items', $row)) {
                $this->validateMenuItems($row['items'], "{$path}.{$index}.items", $errors, 1);
            }

            if ($name === 'workflows') {
                if (array_key_exists('steps', $row)) {
                    $this->validateRows($row['steps'], "{$path}.{$index}.steps", ['step_code'], $errors);
                }
                if (array_key_exists('connections', $row)) {
                    $this->validateRows($row['connections'], "{$path}.{$index}.connections", ['from', 'to'], $errors);
                }
            }
        }
    }

    /**
     * Validate a list of row objects with required stable fields; returns false when the list itself is malformed.
     *
     * @param list<string> $fields
     * @param list<array{key: string, params: array<string, mixed>, message: string}> $errors
     */
    private function validateRows(mixed $rows, string $path, array $fields, array &$errors): bool
    {
        if (!is_array($rows) || !array_is_list($rows)) {
            $errors[] = self::issue('rows_not_list', ['path' => $path], "{$path} must be a list of objects.");

            return false;
        }

        if (count($rows) > $this->maxRows) {
            $errors[] = self::issue('rows_too_many', ['path' => $path, 'count' => count($rows), 'max' => $this->maxRows], "{$path} has " . count($rows) . " rows; the limit is {$this->maxRows}.");

            return false;
        }

        foreach ($rows as $index => $row) {
            if (!$this->isObject($row) || $row === []) {
                $errors[] = self::issue('row_not_object', ['path' => "{$path}.{$index}"], "{$path}.{$index} must be an object.");

                continue;
            }

            foreach ($fields as $field) {
                if (!isset($row[$field]) || !is_string($row[$field]) || $row[$field] === '') {
                    $errors[] = self::issue('field_required', ['path' => "{$path}.{$index}.{$field}"], "{$path}.{$index}.{$field} is required.");
                }
            }
        }

        return true;
    }

    /**
     * @param list<array{key: string, params: array<string, mixed>, message: string}> $errors
     */
    private function validateMenuItems(mixed $items, string $path, array &$errors, int $depth): void
    {
        if ($depth > self::MAX_MENU_DEPTH) {
            $errors[] = self::issue('menu_too_deep', ['path' => $path, 'max' => self::MAX_MENU_DEPTH], "{$path} nests deeper than " . self::MAX_MENU_DEPTH . " levels.");

            return;
        }

        if (!$this->validateRows($items, $path, ['name'], $errors)) {
            return;
        }

        foreach ($items as $index => $item) {
            if ($this->isObject($item) && array_key_exists('items', $item)) {
                $this->validateMenuItems($item['items'], "{$path}.{$index}.items", $errors, $depth + 1);
            }
        }
    }

    /**
     * @param list<array{key: string, params: array<string, mixed>, message: string}> $errors
     */
    private function validateNoNumericIds(mixed $value, string $path, array &$errors): void
    {
        if (!is_array($value)) {
            return;
        }

        foreach ($value as $key => $child) {
            $childPath = "{$path}.{$key}";

            if (is_string($key) && ($key === 'id' || str_ends_with($key, '_id')) && (is_int($child) || (is_string($child) && is_numeric($child)))) {
                $errors[] = self::issue('numeric_id', ['path' => $childPath], "{$childPath} is a numeric id; packages reference records by stable keys only.");

                continue;
            }

            $this->validateNoNumericIds($child, $childPath, $errors);
        }
    }

    /**
     * @param array<array-key, mixed> $sections
     * @param list<array{key: string, params: array<string, mixed>, message: string}> $errors
     */
    private function validateChecksum(mixed $checksum, array $sections, array &$errors): void
    {
        if ($checksum === null) {
            return;
        }

        if (!is_string($checksum) || !str_starts_with($checksum, PackageFormat::CHECKSUM_PREFIX)) {
            $errors[] = self::issue('checksum_prefix', ['prefix' => PackageFormat::CHECKSUM_PREFIX], 'checksum must start with "' . PackageFormat::CHECKSUM_PREFIX . '".');

            return;
        }

        try {
            $expected = PackageFormat::checksum($sections);
        } catch (JsonException) {
            $errors[] = self::issue('checksum_unencodable', [], 'sections cannot be encoded as canonical JSON.');

            return;
        }

        if (!hash_equals($expected, $checksum)) {
            $errors[] = self::issue('checksum_mismatch', [], 'checksum does not match the package sections.');
        }
    }

    /**
     * Build one structured validation issue.
     *
     * @param string               $slug    Key suffix under `text.config_package.validation.`.
     * @param array<string, mixed> $params  Values the translated sentence needs (paths and numbers, never data values).
     * @param string               $message English sentence kept for logs and API clients.
     *
     * @return array{key: string, params: array<string, mixed>, message: string}
     */
    private static function issue(string $slug, array $params, string $message): array
    {
        return ['key' => 'text.config_package.validation.' . $slug, 'params' => $params, 'message' => $message];
    }

    /**
     * A decoded JSON object: an associative array, or an empty array (`{}` decodes to `[]`).
     */
    private function isObject(mixed $value): bool
    {
        return is_array($value) && ($value === [] || !array_is_list($value));
    }
}

/**
 * Outcome of PackageValidator::validate(). Declared in PackageValidator.php; obtain it only from validate().
 */
final class ValidationResult
{
    /**
     * @param list<array{key: string, params: array<string, mixed>, message: string}> $errors
     */
    public function __construct(private readonly array $errors)
    {
    }

    /**
     * Tell whether the package passed every check.
     */
    public function isValid(): bool
    {
        return $this->errors === [];
    }

    /**
     * Error messages (English, paths only, never values); see issues() for the translatable form.
     *
     * @return list<string>
     */
    public function errors(): array
    {
        return array_map(static fn (array $issue): string => $issue['message'], $this->errors);
    }

    /**
     * Structured issues: a translation key, its params and the English message (paths only, never values).
     *
     * @return list<array{key: string, params: array<string, mixed>, message: string}>
     */
    public function issues(): array
    {
        return $this->errors;
    }
}
