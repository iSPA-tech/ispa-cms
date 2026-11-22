<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Packages;

use DateTimeImmutable;
use JsonException;

/**
 * Immutable, strictly validated release manifest of a module package (format 1).
 *
 * The manifest binds a package zip by hash and lists every file with its sha256. It can only be built from bytes
 * whose signature has already been checked (PackageVerifier), or by signing tooling for a schema pre-check.
 */
final class ReleaseManifest
{
    /** Fields of format 1, in canonical order. */
    public const FIELDS = [
        'format', 'name', 'version', 'zip_sha256', 'zip_size', 'unpacked_size', 'files', 'requires',
        'irreversible', 'custom_code', 'php_min', 'built_at', 'key_id',
    ];

    public const NAME_PATTERN = '/^[A-Z][A-Za-z0-9]{1,63}$/';
    public const VERSION_PATTERN = '/^\d+\.\d+\.\d+$/';
    public const MAX_PATH_SEGMENTS = 20;
    public const MIGRATION_NAME_PATTERN = '/^[A-Za-z0-9_.\-]{1,200}$/';
    public const MAX_IRREVERSIBLE_MIGRATIONS = 500;

    /**
     * Optional fields after format 1's fixed ones: `uninstall` = `{"tables": [<table name>, ...],
     * "refuse_while_rows": [<table name>...]}` (; `refuse_while_rows`, is optional inside it);
     * `admin_packages` = `{"<npm name>": <major>, ...}`, the shared admin packages the module's admin code needs from
     * the site's admin shell; `frontend` = `{"entries": [...], "styles":..., "build": "frontend-build"}`,
     * the module's prebuilt front-end assets under `<Name>/frontend-build/`. A reader that predates a field
     * refuses manifests carrying it.
     */
    public const OPTIONAL_FIELDS = ['uninstall', 'admin_packages', 'frontend', 'kind'];

    /**
     * Package kinds (open-source distribution B1): `module` (the default when the field is absent, so every manifest
     * written before `kind` existed parses unchanged) and `core` (the iSPA CMS core release zip, named CORE_NAME). A
     * reader that predates `kind` refuses manifests carrying it, so the hub is deployed before a core package is published.
     */
    public const KIND_MODULE = 'module';
    public const KIND_CORE = 'core';
    public const KINDS = [self::KIND_MODULE, self::KIND_CORE];
    public const CORE_NAME = 'ispa-cms';
    public const FRONTEND_BUILD_DIR = 'frontend-build';
    public const FRONTEND_STYLES = ['module', 'project-theme', 'none'];
    public const MAX_FRONTEND_ENTRIES = 50;
    public const NPM_NAME_PATTERN = '/^(?=.{1,214}$)(?:@[a-z0-9][a-z0-9._~-]*\/)?[a-z0-9][a-z0-9._~-]*$/';
    public const MAX_ADMIN_PACKAGES = 50;
    public const TABLE_NAME_PATTERN = '/^[A-Za-z0-9_]{1,64}$/';
    public const MAX_UNINSTALL_TABLES = 200;

    /**
     * @param array<string, string> $files    relative path => lowercase sha256 hex
     * @param array<string, string> $requires module name => required version
     * @param list<string> $irreversibleMigrations migration names listed by the manifest; empty when only a boolean was given
     * @param bool|list<string> $irreversibleWire the `irreversible` value exactly as received (kept for toArray())
     * @param list<string>|null $uninstallTables tables the module declares as its own data (`uninstall.tables`); null when absent
     * @param list<string>|null $uninstallRefuseWhileRows tables whose rows refuse any uninstall (`uninstall.refuse_while_rows`); null when absent
     * @param array<string, int>|null $adminPackages shared admin package name => major (`admin_packages`); null when absent
     * @param array{entries: list<string>, styles: string, build: string}|null $frontend prebuilt front-end assets (`frontend`); null when absent
     * @param string $kind `module` or `core` ({@see self::KINDS}); `module` when the field is absent
     */
    private function __construct(
        public readonly int $format,
        public readonly string $name,
        public readonly string $version,
        public readonly string $zipSha256,
        public readonly int $zipSize,
        public readonly int $unpackedSize,
        public readonly array $files,
        public readonly array $requires,
        public readonly bool $irreversible,
        public readonly bool $customCode,
        public readonly string $phpMin,
        public readonly string $builtAt,
        public readonly string $keyId,
        public readonly array $irreversibleMigrations = [],
        private readonly bool|array $irreversibleWire = false,
        public readonly ?array $uninstallTables = null,
        public readonly ?array $adminPackages = null,
        public readonly ?array $frontend = null,
        public readonly ?array $uninstallRefuseWhileRows = null,
        public readonly string $kind = self::KIND_MODULE,
    ) {
    }

    /**
     * Parse and validate manifest bytes whose signature was already verified.
     *
     * @throws PackageVerificationException MODULE_PACKAGE_SCHEMA or MODULE_PACKAGE_FORMAT
     */
    public static function fromVerifiedBytes(string $bytes): self
    {
        try {
            $data = json_decode($bytes, true, 16, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (JsonException) {
            throw self::schema('manifest is not valid JSON');
        }
        if (!is_array($data) || ($data !== [] && array_is_list($data)) || $data === []) {
            throw self::schema('manifest must be a JSON object');
        }

        $keys = array_map('strval', array_keys($data));
        $unknown = array_diff($keys, self::FIELDS, self::OPTIONAL_FIELDS);
        if ($unknown !== []) {
            throw self::schema('unknown field(s): ' . self::list($unknown));
        }
        $missing = array_diff(self::FIELDS, $keys);
        if ($missing !== []) {
            throw self::schema('missing field(s): ' . self::list($missing));
        }

        if (!is_int($data['format'])) {
            throw self::schema('format must be an integer');
        }
        if ($data['format'] !== 1) {
            throw new PackageVerificationException(PackageVerificationException::FORMAT, 'unsupported manifest format ' . $data['format']);
        }

        $kind = array_key_exists('kind', $data) ? $data['kind'] : self::KIND_MODULE;
        if (!is_string($kind) || !in_array($kind, self::KINDS, true)) {
            throw self::schema('kind must be one of ' . implode(', ', self::KINDS));
        }
        $core = $kind === self::KIND_CORE;
        if ($core && array_intersect(['uninstall', 'admin_packages', 'frontend'], $keys) !== []) {
            throw self::schema('a kind core manifest carries no module-only fields');
        }

        $name = self::string($data, 'name');
        if ($core ? $name !== self::CORE_NAME : preg_match(self::NAME_PATTERN, $name) !== 1) {
            throw self::schema($core ? 'a kind core manifest must be named ' . self::CORE_NAME : 'name is not a valid module name');
        }
        $version = self::string($data, 'version');
        if (preg_match(self::VERSION_PATTERN, $version) !== 1) {
            throw self::schema('version must look like 2026.03.0');
        }
        $zipSha = self::string($data, 'zip_sha256');
        if (!self::isSha256($zipSha)) {
            throw self::schema('zip_sha256 must be 64 lowercase hex chars');
        }
        $zipSize = self::size($data, 'zip_size');
        $unpackedSize = self::size($data, 'unpacked_size');
        $files = self::files($data['files'], $core ? null : $name);
        $requires = self::requires($data['requires']);
        [$irreversible, $irreversibleMigrations, $irreversibleWire] = self::irreversible($data['irreversible']);
        $customCode = self::bool($data, 'custom_code');

        $phpMin = self::string($data, 'php_min');
        if (preg_match('/^\d+\.\d+(\.\d+)?$/', $phpMin) !== 1) {
            throw self::schema('php_min must look like 8.3 or 8.3.0');
        }
        $builtAt = self::string($data, 'built_at');
        if (preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(\.\d{1,6})?(Z|[+-]\d{2}:\d{2})$/', $builtAt) !== 1
            || self::invalidDate($builtAt)) {
            throw self::schema('built_at must be an ISO-8601 timestamp with a zone');
        }
        $keyId = self::string($data, 'key_id');
        if (preg_match('/^[0-9a-f]{16}$/', $keyId) !== 1) {
            throw self::schema('key_id must be 16 lowercase hex chars');
        }

        [$uninstallTables, $uninstallRefuseWhileRows] = array_key_exists('uninstall', $data) ? self::uninstall($data['uninstall']) : [null, null];
        $adminPackages = array_key_exists('admin_packages', $data) ? self::adminPackages($data['admin_packages']) : null;
        $frontend = array_key_exists('frontend', $data) ? self::frontend($data['frontend'], $files, $name) : null;

        return new self(1, $name, $version, $zipSha, $zipSize, $unpackedSize, $files, $requires,
            $irreversible, $customCode, $phpMin, $builtAt, $keyId, $irreversibleMigrations, $irreversibleWire, $uninstallTables, $adminPackages, $frontend, $uninstallRefuseWhileRows, $kind);
    }

    /**
     * Manifest fields in canonical order with their wire names.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $fields = [
            'format' => $this->format,
            'name' => $this->name,
            'version' => $this->version,
            'zip_sha256' => $this->zipSha256,
            'zip_size' => $this->zipSize,
            'unpacked_size' => $this->unpackedSize,
            'files' => $this->files,
            'requires' => $this->requires,
            'irreversible' => $this->irreversibleWire,
            'custom_code' => $this->customCode,
            'php_min' => $this->phpMin,
            'built_at' => $this->builtAt,
            'key_id' => $this->keyId,
        ];
        if ($this->uninstallTables !== null) {
            $fields['uninstall'] = ['tables' => $this->uninstallTables];
            if ($this->uninstallRefuseWhileRows !== null) {
                $fields['uninstall']['refuse_while_rows'] = $this->uninstallRefuseWhileRows;
            }
        }
        if ($this->adminPackages !== null) {
            $fields['admin_packages'] = $this->adminPackages;
        }
        if ($this->frontend !== null) {
            $fields['frontend'] = $this->frontend;
        }
        if ($this->kind !== self::KIND_MODULE) {
            $fields['kind'] = $this->kind;
        }

        return $fields;
    }

    /**
     * Validate the optional `uninstall` object: only `tables` and `refuse_while_rows`, each a list of safe, unique table
     * names.
     *
     * @return array{0: list<string>, 1: list<string>|null} declared tables (empty when absent) and refuse-while-rows tables (null when absent)
     */
    private static function uninstall(mixed $value): array
    {
        if (!is_array($value) || array_is_list($value) || array_diff(array_map('strval', array_keys($value)), ['tables', 'refuse_while_rows']) !== []) {
            throw self::schema('uninstall must be an object with a tables list');
        }
        $tables = self::tableList($value['tables'] ?? [], 'tables');
        $refuseWhileRows = array_key_exists('refuse_while_rows', $value) ? self::tableList($value['refuse_while_rows'], 'refuse_while_rows') : null;

        return [$tables, $refuseWhileRows];
    }

    /**
     * Validate one `uninstall.<field>` list of safe, unique table names.
     *
     * @return list<string>
     */
    private static function tableList(mixed $tables, string $field): array
    {
        if (!is_array($tables) || ($tables !== [] && !array_is_list($tables)) || count($tables) > self::MAX_UNINSTALL_TABLES) {
            throw self::schema('uninstall.' . $field . ' must be a list of at most ' . self::MAX_UNINSTALL_TABLES . ' table names');
        }
        $seen = [];
        foreach ($tables as $i => $table) {
            if (!is_string($table) || preg_match(self::TABLE_NAME_PATTERN, $table) !== 1 || isset($seen[$table])) {
                throw self::schema('uninstall.' . $field . ' entry #' . ($i + 1) . ' is not a valid, unique table name');
            }
            $seen[$table] = true;
        }

        return $tables;
    }

    /**
     * Validate the optional `admin_packages` object: 1 to MAX_ADMIN_PACKAGES npm package names => major (int >= 0).
     *
     * @return array<string, int>
     */
    private static function adminPackages(mixed $value): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value) || count($value) > self::MAX_ADMIN_PACKAGES) {
            throw self::schema('admin_packages must be an object of 1 to ' . self::MAX_ADMIN_PACKAGES . ' package name => major version');
        }
        $out = [];
        foreach ($value as $name => $major) {
            $name = (string) $name;
            if (preg_match(self::NPM_NAME_PATTERN, $name) !== 1 || !is_int($major) || $major < 0) {
                throw self::schema('admin_packages entry #' . (count($out) + 1) . ' is not a package name => major version');
            }
            $out[$name] = $major;
        }

        return $out;
    }

    /**
     * Validate the optional `frontend` object: exactly `entries` (1 to MAX_FRONTEND_ENTRIES unique relative paths under
     * resources/), `styles` (one of FRONTEND_STYLES) and `build` (always FRONTEND_BUILD_DIR), and the file map must
     * hold the build's Vite manifest `<Name>/frontend-build/manifest.json`.
     *
     * @param array<string, string> $files validated file map
     *
     * @return array{entries: list<string>, styles: string, build: string}
     */
    private static function frontend(mixed $value, array $files, string $name): array
    {
        if (!is_array($value) || $value === [] || array_is_list($value)) {
            throw self::schema('frontend must be an object with entries, styles and build');
        }
        $keys = array_map('strval', array_keys($value));
        sort($keys);
        if ($keys !== ['build', 'entries', 'styles']) {
            throw self::schema('frontend must hold exactly entries, styles and build');
        }
        if ($value['build'] !== self::FRONTEND_BUILD_DIR) {
            throw self::schema('frontend.build must be ' . self::FRONTEND_BUILD_DIR);
        }
        if (!is_string($value['styles']) || !in_array($value['styles'], self::FRONTEND_STYLES, true)) {
            throw self::schema('frontend.styles must be one of ' . implode(', ', self::FRONTEND_STYLES));
        }
        $entries = $value['entries'];
        if (!is_array($entries) || $entries === [] || !array_is_list($entries) || count($entries) > self::MAX_FRONTEND_ENTRIES) {
            throw self::schema('frontend.entries must be a list of 1 to ' . self::MAX_FRONTEND_ENTRIES . ' paths');
        }
        $seen = [];
        foreach ($entries as $i => $entry) {
            if (!is_string($entry) || isset($seen[$entry]) || !str_starts_with($entry, 'resources/')
                || self::unsafePathReason($name . '/' . $entry, $name) !== null) {
                throw self::schema('frontend.entries entry #' . ($i + 1) . ' is not a unique safe path under resources/');
            }
            $seen[$entry] = true;
        }
        if (!isset($files[$name . '/' . self::FRONTEND_BUILD_DIR . '/manifest.json'])) {
            throw self::schema('frontend needs ' . self::FRONTEND_BUILD_DIR . '/manifest.json in files');
        }

        return ['entries' => $entries, 'styles' => $value['styles'], 'build' => self::FRONTEND_BUILD_DIR];
    }

    /**
     * Validate the file map: safe relative paths under "<Name>/" (any safe relative path for a core manifest, which
     * passes null), lowercase sha256 values, no case-folded duplicates.
     *
     * @return array<string, string>
     */
    private static function files(mixed $files, ?string $name): array
    {
        if (!is_array($files) || $files === [] || array_is_list($files)) {
            throw self::schema('files must be a non-empty object of path => sha256');
        }
        $seen = [];
        $out = [];
        foreach ($files as $path => $hash) {
            $path = (string) $path;
            $reason = self::unsafePathReason($path, $name);
            if ($reason !== null) {
                throw self::schema('file path #' . (count($out) + 1) . ' ' . $reason);
            }
            if (!is_string($hash) || !self::isSha256($hash)) {
                throw self::schema('file hash #' . (count($out) + 1) . ' must be 64 lowercase hex chars');
            }
            $folded = mb_strtolower($path, 'UTF-8');
            if (isset($seen[$folded])) {
                throw self::schema('file path #' . (count($out) + 1) . ' duplicates another path ignoring case');
            }
            $seen[$folded] = true;
            $out[$path] = $hash;
        }

        return $out;
    }

    /**
     * Return why a path is unsafe to extract, or null when it is safe.
     */
    private static function unsafePathReason(string $path, ?string $name): ?string
    {
        if ($path === '') {
            return 'is empty';
        }
        if (str_contains($path, "\0")) {
            return 'contains NUL';
        }
        if (str_contains($path, '\\')) {
            return 'contains a backslash';
        }
        if (str_starts_with($path, '/')) {
            return 'is absolute';
        }
        if (preg_match('/^[A-Za-z]:/', $path) === 1) {
            return 'has a drive letter';
        }
        if (!mb_check_encoding($path, 'UTF-8')) {
            return 'is not valid UTF-8';
        }
        $segments = explode('/', $path);
        if (count($segments) > self::MAX_PATH_SEGMENTS) {
            return 'has more than ' . self::MAX_PATH_SEGMENTS . ' segments';
        }
        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return 'has an empty, "." or ".." segment';
            }
        }
        if ($name !== null && ($segments[0] !== $name || count($segments) < 2)) {
            return 'must start with "' . $name . '/"';
        }

        return null;
    }

    /**
     * Validate requires: module name => non-empty version constraint string (an empty object/array is allowed).
     *
     * @return array<string, string>
     */
    private static function requires(mixed $requires): array
    {
        if (!is_array($requires) || ($requires !== [] && array_is_list($requires))) {
            throw self::schema('requires must be an object of module name => version');
        }
        $out = [];
        foreach ($requires as $module => $constraint) {
            $module = (string) $module;
            if (preg_match(self::NAME_PATTERN, $module) !== 1) {
                throw self::schema('requires has an invalid module name');
            }
            if (!is_string($constraint) || $constraint === '' || strlen($constraint) > 64) {
                throw self::schema('requires.' . $module . ' must be a non-empty string');
            }
            $out[$module] = $constraint;
        }

        return $out;
    }

    /**
     * Read a required string field.
     */
    private static function string(array $data, string $field): string
    {
        if (!is_string($data[$field])) {
            throw self::schema($field . ' must be a string');
        }

        return $data[$field];
    }

    /**
     * Read a required non-negative integer field.
     */
    private static function size(array $data, string $field): int
    {
        if (!is_int($data[$field]) || $data[$field] < 0) {
            throw self::schema($field . ' must be a non-negative integer');
        }

        return $data[$field];
    }

    /**
     * Read the irreversible field: a boolean, or a list of migration names (tolerant reader).
     *
     * @return array{0: bool, 1: list<string>, 2: bool|list<string>} flag, migration names, wire value as received
     */
    private static function irreversible(mixed $value): array
    {
        if (is_bool($value)) {
            return [$value, [], $value];
        }
        if (!is_array($value) || ($value !== [] && !array_is_list($value))) {
            throw self::schema('irreversible must be a boolean or a list of migration names');
        }
        if (count($value) > self::MAX_IRREVERSIBLE_MIGRATIONS) {
            throw self::schema('irreversible lists more than ' . self::MAX_IRREVERSIBLE_MIGRATIONS . ' migrations');
        }
        $seen = [];
        foreach ($value as $i => $name) {
            if (!is_string($name) || preg_match(self::MIGRATION_NAME_PATTERN, $name) !== 1 || $name === '.' || $name === '..') {
                throw self::schema('irreversible entry #' . ($i + 1) . ' is not a valid migration name');
            }
            if (isset($seen[$name])) {
                throw self::schema('irreversible entry #' . ($i + 1) . ' is a duplicate');
            }
            $seen[$name] = true;
        }

        return [$value !== [], $value, $value];
    }

    /**
     * Read a required boolean field.
     */
    private static function bool(array $data, string $field): bool
    {
        if (!is_bool($data[$field])) {
            throw self::schema($field . ' must be a boolean');
        }

        return $data[$field];
    }

    /**
     * Whether a value is a lowercase 64-char hex sha256.
     */
    private static function isSha256(string $value): bool
    {
        return preg_match('/^[0-9a-f]{64}$/', $value) === 1;
    }

    /**
     * Whether an ISO-8601 string fails to name a real calendar instant.
     */
    private static function invalidDate(string $value): bool
    {
        $date = DateTimeImmutable::createFromFormat('Y-m-d\TH:i:sP', preg_replace('/\.\d+/', '', $value) ?? '');
        $errors = DateTimeImmutable::getLastErrors();

        return $date === false || ($errors !== false && ($errors['warning_count'] > 0 || $errors['error_count'] > 0));
    }

    /**
     * Format field names for a message (field names only, never values).
     *
     * @param array<int, string> $names
     */
    private static function list(array $names): string
    {
        $safe = array_map(static fn (string $n): string => preg_replace('/[^A-Za-z0-9_]/', '?', mb_substr($n, 0, 40)) ?? '', $names);

        return implode(', ', array_slice($safe, 0, 10));
    }

    /**
     * Build a schema refusal.
     */
    private static function schema(string $message): PackageVerificationException
    {
        return new PackageVerificationException(PackageVerificationException::SCHEMA, $message);
    }
}
