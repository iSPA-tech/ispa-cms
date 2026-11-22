<?php

declare(strict_types=1);

namespace Cms\Core\Services\ConfigPackage;

use InvalidArgumentException;

/**
 * Registry of database tables whose rows may travel in a configuration package.
 *
 * A module declares a table in `module.json` (`config_export.tables`) AND registers it here; a declared table that
 * is not registered is a lint error and is ignored by export. Each registration names the natural key columns that
 * match a row between sites, the columns that never leave the site, and the foreign key columns that are carried
 * as portable values (only `language_id`, exported as the language path, is supported).
 */
final class ConfigTableRegistry
{
    /** Foreign key columns the tables section knows how to carry portably. */
    public const SUPPORTED_FOREIGN_KEYS = ['language_id'];

    /** @var array<string, array{table: string, key_columns: list<string>, exclude_columns: list<string>, foreign_keys: list<string>}> */
    private array $tables = [];

    /**
     * Register (or replace) a table.
     *
     * @param list<string> $keyColumns     Natural key columns that identify a row (at least one).
     * @param list<string> $excludeColumns Extra columns that are never exported (id, user and timestamp columns are always dropped).
     * @param list<string> $foreignKeys    Foreign key columns to carry as portable values (`language_id`).
     *
     * @throws InvalidArgumentException When the table name, key columns or foreign keys are invalid.
     */
    public function register(string $table, array $keyColumns, array $excludeColumns = [], array $foreignKeys = []): void
    {
        if (preg_match('/^[a-z][a-z0-9_]*$/', $table) !== 1) {
            throw new InvalidArgumentException("Invalid configuration table name '{$table}'.");
        }

        if ($keyColumns === [] || array_filter($keyColumns, static fn ($c): bool => !is_string($c) || preg_match('/^[a-z][a-z0-9_]*$/', $c) !== 1) !== []) {
            throw new InvalidArgumentException("Configuration table '{$table}' needs valid natural key columns.");
        }

        foreach ($foreignKeys as $foreignKey) {
            if (!in_array($foreignKey, self::SUPPORTED_FOREIGN_KEYS, true)) {
                throw new InvalidArgumentException("Configuration table '{$table}': foreign key '{$foreignKey}' is not supported.");
            }
        }

        $this->tables[$table] = [
            'table' => $table,
            'key_columns' => array_values($keyColumns),
            'exclude_columns' => array_values($excludeColumns),
            'foreign_keys' => array_values($foreignKeys),
        ];
    }

    /**
     * Tell whether a table is registered.
     */
    public function has(string $table): bool
    {
        return isset($this->tables[$table]);
    }

    /**
     * Get one registration, or null when the table is not registered.
     *
     * @return array{table: string, key_columns: list<string>, exclude_columns: list<string>, foreign_keys: list<string>}|null
     */
    public function get(string $table): ?array
    {
        return $this->tables[$table] ?? null;
    }

    /**
     * All registrations keyed by table name.
     *
     * @return array<string, array{table: string, key_columns: list<string>, exclude_columns: list<string>, foreign_keys: list<string>}>
     */
    public function all(): array
    {
        return $this->tables;
    }
}
