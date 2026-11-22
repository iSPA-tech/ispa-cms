<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Helpers;

use Illuminate\Support\Facades\Facade;
use Cms\Core\Interfaces\MetaClassInterface;

class MetaDataHelper extends Facade
{
    /**
     * Class structure
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-28, 14:55 ICT
     */
    public function __construct(
        protected MetaClassInterface $model,
    )
    {

    }

    /**
     * @return $this
     */
    public static function getInstance(): self
    {
        return static::getFacadeRoot();
    }

    /**
     * Get the registered name of the component.
     *
     * @return string
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-22, 18:01 ICT
     */
    protected static function getFacadeAccessor(): string
    {
        return 'MetaDataHelper';
    }

    /**
     * Save metadata by target
     *
     * Value rows are resolved through MetaAssignmentService (find-or-create in
     * the shared pool, soft-deleted rows revived) instead of the legacy
     * behavior that created a brand-new value row on every save and mutated
     * shared rows in place — the source of the duplicated value pool.
     *
     * Numeric "number"-input attributes keep the legacy literal semantics:
     * value_id stores the number itself, not a value-row FK (readers depend
     * on it, see get_meta_value_by_name()).
     *
     * @param mixed $model
     * @param array $metadata
     * @param string $target
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-28, 14:39 ICT
     */
    public function sync(mixed $model = null, array $metadata = [], string $target = 'User'): void
    {
        $attributes = $this->getAttributes($target);
        $assignment = app(\Cms\Core\Services\MetaAssignmentService::class);
        //  Save Meta data
        $list = [];
        foreach ($metadata as $name => $value) {
            $attribute = collect($attributes)->firstWhere('name', $name);

            if (empty($attribute)) {
                continue;
            }
            //  If attribute is selection (select, radio, checkbox) and have options
            if (
                $attribute['input_type'] === 'number'
                && in_array($attribute['type'], ['tinyInt', 'bigInt'])
            ) {
                $valueId = $value['value'];
            } else {
                $valueId = $assignment->resolveValue((string) $attribute['type'], $value['value'])?->id;
            }

            if ($valueId === null || $valueId === '') {
                continue;
            }

            $list[] = [
                'meta_class_id' => $value['class_id'],
                'meta_attribute_id' => $value['attr_id'],
                'value_type' => get_value_class($attribute['type']),
                'value_id' => $valueId,
            ];
        }

        // Distinct guard: the assignment table now carries a unique index on
        // (target, attribute, value); duplicated form entries must not 500.
        $list = collect($list)
            ->unique(fn (array $row): string => implode('|', [$row['meta_attribute_id'], $row['value_type'], $row['value_id']]))
            ->values()
            ->all();

        $model->meta()->delete();
        $model->meta()->createMany($list);
    }

    /**
     * Get Attributes list by target
     *
     * @param string $target
     *
     * @return array
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-28, 15:07 ICT
     */
    public function getAttributes(string $target = 'User'): array
    {
        $classes = $this->getClass($target);
        $attributes = collect();

        foreach ($classes as $class) {
            foreach ($class['options'] as $attribute) {
                //  Check attribute is existing in list
                $index = $attributes->search(function ($item) use ($attribute) {
                    return $item['id'] === $attribute['id'];
                });

                if ($index === false) {
                    $attributes->push($attribute);
                }
            }
        }

        return $attributes->toArray();
    }

    /**
     * Get metaClass by target
     *
     * @param string $target
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-28, 14:54 ICT
     */
    public function getClass(string $target = 'User'): mixed
    {
        return $this->model->model()->where('target', $target)->with([
            'options' => function ($query) {
                $query->when("type='bigInt' or type='tinyInt'", function ($query) {
                    $query->with([
                        'options' => fn($q) => $q->whereNull('user_id'),
                    ]);
                });
            },
        ])->paginate();
    }

    /**
     * Search value by user by attribute type of value
     *
     * @param int $valueId
     * @param string $attributeType
     * @param ?mixed $value
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-28, 16:08 ICT
     * @deprecated Mutates shared value-pool rows in place — use
     *             \Cms\Core\Services\MetaAssignmentService::resolveValue() and
     *             repoint the assignment instead.
     */
    public function updateValue(int $valueId = 0, string $attributeType = 'string', mixed $value = null): mixed
    {
        $model = get_value_class($attributeType);

        //  Create new data
        $newModel = (new $model())->query()->where('id', $valueId)->first();
        $newModel->value = $value ?? '';
        $newModel->label = str($value ?? '')->limit()->__toString();
        $newModel->save();

        return $newModel;
    }
}
