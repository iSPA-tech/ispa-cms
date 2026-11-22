<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources\Fields;

use Illuminate\Validation\Rule;

/**
 * Enumerate field configuration: a value picked from one group (`type`) of the Core `enumerates` table.
 *
 * The submitted value is the entry's `enum_key`. The group and the options endpoint are exported to the UI schema; the
 * server-side existence rule is only used to build validation rules.
 */
final class EnumerateField
{
    /** Core admin endpoint (relative to the admin API prefix) that lists enumerates and accepts a `type` filter. */
    public const ENDPOINT = 'enumerate';

    /** Core table holding the enumerate entries. */
    private const TABLE = 'enumerates';

    /**
     * @param  string  $group  enumerates `type` the value must belong to
     */
    public function __construct(private readonly string $group) {}

    /** The enumerates `type` this field is restricted to. */
    public function group(): string
    {
        return $this->group;
    }

    /**
     * Export the lookup config for the UI schema.
     *
     * @return array<string, string>
     */
    public function toSchema(): array
    {
        return [
            'endpoint' => self::ENDPOINT,
            'group' => $this->group,
            'value_key' => 'enum_key',
            'label_key' => 'name',
            'alias_key' => 'name_alias',
        ];
    }

    /**
     * Validation rules for a submitted value: a string that is the `enum_key` of a live entry in the group.
     *
     * @return array<int, mixed>
     */
    public function rules(): array
    {
        return [
            'string',
            Rule::exists(self::TABLE, 'enum_key')
                ->where('type', $this->group)
                ->whereNull('deleted_at'),
        ];
    }
}
