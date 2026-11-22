<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Workflows\Schema;

use Throwable;
use SplFileInfo;
use ReflectionClass;
use ReflectionMethod;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RecursiveIteratorIterator;
use RecursiveDirectoryIterator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\Relations\MorphToMany;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasManyThrough;

/**
 * Discovers and caches Eloquent model schemas for workflow builder UI integration.
 *
 * Introspects database columns, Eloquent casts, relationships, and computed accessors
 * to build a comprehensive schema representation used by the workflow designer.
 *
 * @package Cms\Core\Workflows\Schema
 * @author  iSPA Team
 * @since   8.0.0 (2026-03-17)
 */
class ModelSchemaRegistry
{
    /**
     * Initialize the registry with a configurable cache TTL.
     *
     * @param int $ttl Cache time-to-live in seconds for schema entries.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct(
        protected int $ttl = 600
    )
    {
    }

    /**
     * Get schema of a relation's target model.
     *
     * @param class-string<Model> $modelClass
     * @param  string $relationName
     *
     * @return array
     * @throws InvalidArgumentException When the relation is not found on the model.
     */
    public function getRelationSchema(string $modelClass, string $relationName): array
    {
        $schema = $this->getSchema($modelClass);
        $relations = collect($schema['relations'] ?? [])
            ->keyBy('name');

        $relation = $relations->get($relationName);
        if (!$relation || !isset($relation['model'])) {
            throw new InvalidArgumentException("Relation {$relationName} not found on {$modelClass}");
        }

        /** @var class-string<Model> $related */
        $related = $relation['model'];

        return $this->getSchema($related);
    }

    /**
     * Get schema for a given Eloquent model class.
     *
     * @param class-string<Model> $modelClass
     *
     * @return array<string, mixed>
     */
    public function getSchema(string $modelClass): array
    {
        return Cache::remember(
            $this->cacheKey($modelClass),
            $this->ttl(),
            fn() => $this->buildSchema($modelClass)
        );
    }

    protected function cacheKey(string $modelClass): string
    {
        return 'workflow:model-schema:' . $modelClass;
    }

    protected function ttl(): int
    {
        return (int)config('core.workflow.schema_cache_ttl', $this->ttl);
    }

    /**
     * @param class-string<Model> $modelClass
     *
     * @return array<string, mixed>
     *
     * @throws InvalidArgumentException When the model class does not exist or is not an Eloquent model.
     */
    protected function buildSchema(string $modelClass): array
    {
        if (!class_exists($modelClass)) {
            throw new InvalidArgumentException("Model class {$modelClass} does not exist");
        }

        if (!is_subclass_of($modelClass, Model::class)) {
            throw new InvalidArgumentException("{$modelClass} is not an Eloquent model");
        }

        /** @var Model $instance */
        $instance = new $modelClass();

        $fields = $this->discoverFields($instance);
        $relations = $this->discoverRelations($instance);
        $computed = $this->discoverComputed($instance);

        return [
            'model' => $modelClass,
            'short_name' => class_basename($modelClass),
            'fields' => $fields,
            'relations' => $relations,
            'computed' => $computed,
        ];
    }

    protected function discoverFields(Model $model): array
    {
        $table = $model->getTable();
        $connection = $model->getConnectionName();
        $columns = Schema::connection($connection)->hasTable($table)
            ? Schema::connection($connection)->getColumnListing($table)
            : [];

        $casts = $model->getCasts();
        $hidden = array_flip($model->getHidden());

        $fields = [];

        foreach ($columns as $column) {
            if (isset($hidden[$column])) {
                continue;
            }

            $info = [
                'name' => $column,
                'type' => null,
                'nullable' => null,
                'cast' => $casts[$column] ?? null,
            ];

            try {
                $doctrineColumn = DB::connection($connection)->getDoctrineColumn($table, $column);
                $info['nullable'] = !$doctrineColumn->getNotnull();
                $info['type'] = $this->mapDoctrineType($doctrineColumn->getType()->getName());
            } catch (Throwable $e) {
                // Fallback: attempt Schema::getColumnType
                try {
                    $schemaType = Schema::connection($connection)->getColumnType($table, $column);
                    $info['type'] = $this->mapDoctrineType($schemaType);
                } catch (Throwable $ignored) {
                }
            }

            // Cast overrides detected type
            if ($info['cast']) {
                $castBase = explode(':', $info['cast'])[0];
                $info['type'] = $this->mapCastType($castBase) ?? $info['type'];
            }

            $fields[] = $info;
        }

        return $fields;
    }

    protected function mapDoctrineType(string $type): ?string
    {
        return match ($type) {
            'integer', 'smallint', 'bigint' => 'int',
            'decimal', 'float', 'double' => 'decimal',
            'string', 'text' => 'string',
            'datetime', 'datetimetz', 'timestamp', 'date' => 'datetime',
            'boolean' => 'bool',
            'json' => 'json',
            default => $type,
        };
    }

    protected function mapCastType(string $cast): ?string
    {
        return match ($cast) {
            'int', 'integer' => 'int',
            'real', 'float', 'double', 'decimal' => 'decimal',
            'string' => 'string',
            'bool', 'boolean' => 'bool',
            'array', 'json', 'collection' => 'json',
            'date', 'datetime', 'immutable_date', 'immutable_datetime', 'timestamp' => 'datetime',
            default => null,
        };
    }

    protected function discoverRelations(Model $model): array
    {
        $relations = [];
        $ref = new ReflectionClass($model);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            if ($method->isStatic()) {
                continue;
            }

            if ($method->getNumberOfRequiredParameters() > 0) {
                continue;
            }

            if ($method->getDeclaringClass()->getName() !== $ref->getName()) {
                continue;
            }

            try {
                $result = $method->invoke($model);
            } catch (Throwable $e) {
                continue;
            }

            if ($result instanceof Relation) {
                $related = $result->getRelated();
                $relations[] = [
                    'name' => $method->getName(),
                    'type' => class_basename($result),
                    'model' => $related::class,
                    'cardinality' => $this->relationCardinality($result),
                ];
            }
        }

        return $relations;
    }

    protected function relationCardinality(Relation $relation): string
    {
        return match (true) {
            $relation instanceof HasMany,
                $relation instanceof HasManyThrough,
                $relation instanceof MorphMany,
                $relation instanceof MorphToMany,
                $relation instanceof BelongsToMany => 'many',
            default => 'one',
        };
    }

    protected function discoverComputed(Model $model): array
    {
        $computed = [];
        $ref = new ReflectionClass($model);

        foreach ($ref->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
            $name = $method->getName();
            if (str_starts_with($name, 'get') && str_ends_with($name, 'Attribute')) {
                $attribute = $this->accessorNameToField($name);
                $computed[] = [
                    'name' => $attribute,
                    'type' => 'string',
                    'source' => 'accessor',
                ];
            }
        }

        return $computed;
    }

    protected function accessorNameToField(string $methodName): string
    {
        $trimmed = substr($methodName, 3, -9); // remove get / Attribute
        return Str::snake($trimmed);
    }

    /**
     * Discover available Eloquent models for UI dropdowns.
     *
     * @return array<int, array<string, string>>
     */
    public function listModels(): array
    {
        return Cache::remember(
            'workflow:model-schema:list',
            $this->ttl(),
            static function () {
                $roots = [
                    app_path(),
                    base_path('cms'),
                    base_path('projects'),
                ];

                $models = [];

                foreach ($roots as $root) {
                    if (!is_dir($root)) {
                        continue;
                    }

                    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root));

                    foreach ($iterator as $file) {
                        /** @var SplFileInfo $file */
                        if (!$file->isFile() || $file->getExtension() !== 'php') {
                            continue;
                        }

                        $contents = @file_get_contents($file->getPathname());
                        if (!$contents) {
                            continue;
                        }

                        if (
                            !preg_match('/^namespace\s+([^;]+);/m', $contents, $nsMatch)
                            || !preg_match('/^class\s+([A-Za-z_][A-Za-z0-9_]*)/m', $contents, $classMatch)
                        ) {
                            continue;
                        }

                        $fqcn = $nsMatch[1] . '\\' . $classMatch[1];

                        if (!class_exists($fqcn) || !is_subclass_of($fqcn, Model::class)) {
                            continue;
                        }

                        $ref = new ReflectionClass($fqcn);
                        if ($ref->isAbstract()) {
                            continue;
                        }

                        $models[$fqcn] = [
                            'class' => $fqcn,
                            'short_name' => $ref->getShortName(),
                            'namespace' => $ref->getNamespaceName(),
                        ];
                    }
                }

                ksort($models);

                return array_values($models);
            }
        );
    }
}
