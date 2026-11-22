<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaData;
use Cms\Core\Models\MetaValueText;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

/**
 * Canonical write path for the meta system (class → attribute → value).
 *
 * Guarantees value-pool reuse: a value is looked up (including soft-deleted
 * rows, which are revived) before anything is created, value rows are NEVER
 * mutated in place (a change points the assignment at a different row), and
 * assignments are upserted so the unique indexes added by the hardening
 * migrations cannot be violated.
 *
 * Usage:
 *   $service->assign($variant, $classId, $attribute, 'RWD');
 *   $service->assignMany($product, $classId, ['traction' => 'AWD', ...]);
 *
 * @see \Cms\Core\Services\MetaNormalizationService
 * @package Cms\Core\Services
 * @author  iSPA Team
 * @since   8.1.0 (2026-07-17)
 */
class MetaAssignmentService
{
    /**
     * Resolve (find-or-create) the shared value row for an attribute type.
     *
     * Strings are trimmed and whitespace-collapsed; the lookup includes
     * soft-deleted rows so a revived value keeps its identity instead of
     * colliding with the unique index.
     *
     * @param  string $type  Attribute type: string|text|date|boolean|tinyInt|bigInt.
     * @param  mixed  $value Raw value.
     * @return Model|null Null when the value normalizes to nothing.
     */
    public function resolveValue(string $type, mixed $value): ?Model
    {
        $normalized = $this->normalizeValue($type, $value);
        if ($normalized === null) {
            return null;
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = get_value_class($type);

        /** @var Model|null $existing */
        $existing = $modelClass === MetaValueText::class
            ? $modelClass::withTrashed()->where('value_hash', sha1((string) $normalized))->orderBy('id')->first()
            : $modelClass::withTrashed()->where('value', $normalized)->orderBy('id')->first();

        // Case-insensitive fallback so drivers with binary collations (sqlite)
        // behave like MySQL's CI collation and never split "hatchback"/"Hatchback"
        // into two pool rows.
        if (! $existing && is_string($normalized) && $modelClass !== MetaValueText::class) {
            $existing = $modelClass::withTrashed()
                ->whereRaw('LOWER(value) = ?', [mb_strtolower($normalized)])
                ->orderBy('id')
                ->first();
        }

        if ($existing) {
            if (method_exists($existing, 'trashed') && $existing->trashed()) {
                $existing->restore();
            }

            // Case-insensitive collations match "hatchback" for "Hatchback":
            // adopt the canonical casing. Safe: CI-equal means the unique key
            // and the meaning are identical — only presentation changes.
            if (is_string($normalized)
                && is_string($existing->value)
                && $existing->value !== $normalized
                && strcasecmp($existing->value, $normalized) === 0) {
                $existing->value = $normalized;
                $existing->save();
            }

            return $existing;
        }

        $created = new $modelClass();
        $created->value = $normalized;
        $created->label = str(is_scalar($normalized) ? (string) $normalized : json_encode($normalized))->limit()->__toString();
        $created->save();

        return $created;
    }

    /**
     * Register a value as a selectable option of an attribute (catalog pivot).
     *
     * @return void
     */
    public function ensureOption(MetaAttribute $attribute, Model $value): void
    {
        $pivot = CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT;
        $exists = DB::table($pivot)
            ->where('meta_attribute_id', $attribute->id)
            ->where('value_type', get_class($value))
            ->where('value_id', $value->getKey())
            ->exists();

        if (! $exists) {
            DB::table($pivot)->insert([
                'meta_attribute_id' => $attribute->id,
                'value_type' => get_class($value),
                'value_id' => $value->getKey(),
            ]);
        }
    }

    /**
     * Assign a value to a target entity (Product, ProductVariant, Place, ...).
     *
     * Single-value attributes update the existing assignment row in place;
     * `is_multiple` attributes accumulate one row per distinct value. Returns
     * null when the value resolves to nothing (assignment untouched).
     *
     * @param  Model         $target     Any model (polymorphic target).
     * @param  int|null      $classId    Meta class context.
     * @param  MetaAttribute $attribute  The attribute definition.
     * @param  mixed         $value      Raw value.
     * @param  bool          $asOption   Also register the value as a selectable option.
     * @param  string|null   $source     Provenance ('manual', a source code, …).
     *                                   Null keeps the row's existing provenance untouched.
     */
    public function assign(Model $target, ?int $classId, MetaAttribute $attribute, mixed $value, bool $asOption = false, ?string $source = null): ?MetaData
    {
        $valueRow = $this->resolveValue((string) $attribute->type, $value);
        if (! $valueRow) {
            return null;
        }

        if ($asOption) {
            $this->ensureOption($attribute, $valueRow);
        }

        $lookup = [
            'target_type' => get_class($target),
            'target_id' => $target->getKey(),
            'meta_attribute_id' => $attribute->id,
        ];

        if ($attribute->is_multiple) {
            $lookup['value_type'] = get_class($valueRow);
            $lookup['value_id'] = $valueRow->getKey();
        }

        $values = [
            'meta_class_id' => $classId,
            'value_type' => get_class($valueRow),
            'value_id' => $valueRow->getKey(),
        ];
        if ($source !== null) {
            $values['source'] = $source;
        }

        return MetaData::query()->updateOrCreate($lookup, $values);
    }

    /**
     * Assign many attribute => value pairs at once (attributes resolved by name).
     *
     * @param  array<string, mixed> $values attribute name => raw value
     * @return array<string, MetaData|null>
     */
    public function assignMany(Model $target, ?int $classId, array $values, bool $asOption = false): array
    {
        $attributes = MetaAttribute::query()
            ->whereIn('name', array_keys($values))
            ->get()
            ->keyBy('name');

        $results = [];
        foreach ($values as $name => $value) {
            $attribute = $attributes->get($name);
            $results[$name] = $attribute
                ? $this->assign($target, $classId, $attribute, $value, $asOption)
                : null;
        }

        return $results;
    }

    /**
     * Remove assignments of an attribute from a target (all values).
     *
     * @return int Deleted assignment rows.
     */
    public function unassign(Model $target, MetaAttribute $attribute): int
    {
        return MetaData::query()
            ->where('target_type', get_class($target))
            ->where('target_id', $target->getKey())
            ->where('meta_attribute_id', $attribute->id)
            ->delete();
    }

    /**
     * Normalize a raw value per attribute type. Null result = nothing to store.
     */
    private function normalizeValue(string $type, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        switch ($type) {
            case 'boolean':
                return (bool) $value;
            case 'tinyInt':
            case 'bigInt':
                return is_numeric($value) ? (int) $value : null;
            case 'date':
                try {
                    return now()->parse((string) $value)->toDateTimeString();
                } catch (\Throwable) {
                    return null;
                }
            case 'text':
                $text = trim((string) $value);

                return $text === '' ? null : $text;
            default: // string
                $text = trim((string) preg_replace('/\s+/u', ' ', (string) $value));

                return $text === '' ? null : $text;
        }
    }
}
