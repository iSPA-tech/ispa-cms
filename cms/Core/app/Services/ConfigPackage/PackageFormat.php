<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

/**
 * Constants and canonical encoding rules of the iSPA configuration package (format version 1).
 *
 * The package layout, stable keys and import rules are documented in guidelines/config-package-format.md.
 * Integrity is a SHA-256 checksum of the canonical JSON of `sections` only; packages are not signed.
 */
final class PackageFormat
{
    public const FORMAT = 'ispa-config-package';

    public const VERSION = 1;

    public const CHECKSUM_PREFIX = 'sha256:';

    public const MAX_DECODED_BYTES = 20 * 1024 * 1024;

    public const MAX_ROWS_PER_SECTION = 50000;

    /** Sections whose value is a list of row objects. */
    public const LIST_SECTIONS = ['settings', 'translations', 'roles', 'menus', 'workflows', 'custom_fields'];

    /** Section whose value is an object of table name => list of row objects. */
    public const TABLES_SECTION = 'tables';

    public const SECTIONS = ['settings', 'translations', 'roles', 'menus', 'workflows', 'custom_fields', 'tables'];

    public const REQUIRED_KEYS = ['format', 'format_version', 'created_at', 'sections', 'checksum'];

    public const TOP_LEVEL_KEYS = [
        'format',
        'format_version',
        'created_at',
        'source',
        'sections',
        'required_secrets',
        'skipped',
        'checksum',
    ];

    public const JSON_FLAGS = JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;

    /**
     * Encode a value as deterministic JSON.
     *
     * Associative arrays have their keys sorted (string comparison, recursively); lists keep their order. An empty
     * array is always encoded as `[]`, so `{}` and `[]` canonicalize identically. Encoding uses JSON_FLAGS.
     *
     * @throws \JsonException When the value cannot be encoded (for example invalid UTF-8).
     */
    public static function canonicalJson(mixed $value): string
    {
        return json_encode(self::canonicalize($value), self::JSON_FLAGS);
    }

    /**
     * Compute the integrity checksum of the package sections: `sha256:` followed by the hex digest of canonicalJson().
     *
     * @param array<string, mixed> $sections
     *
     * @throws \JsonException When the sections cannot be encoded.
     */
    public static function checksum(array $sections): string
    {
        return self::CHECKSUM_PREFIX . hash('sha256', self::canonicalJson($sections));
    }

    /**
     * Recursively sort associative array keys while keeping list order.
     */
    private static function canonicalize(mixed $value): mixed
    {
        if (!is_array($value)) {
            return $value;
        }

        $value = array_map(self::canonicalize(...), $value);

        if (!array_is_list($value)) {
            ksort($value, SORT_STRING);
        }

        return $value;
    }
}
