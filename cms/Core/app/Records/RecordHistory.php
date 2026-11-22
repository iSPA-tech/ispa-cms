<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Records;

use Cms\Core\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

/**
 * Read the audit history of one record for display in the admin.
 *
 * Privacy rules: the output never carries the IP address, browser agent, page url or options of an
 * audit row (those columns are not even selected); the actor is reduced to `{uuid, name}`; old/new
 * values are shown only for the attributes the record type whitelists. Scalar values are kept
 * (strings cut to 200 characters); a non-scalar value (array/object) is replaced by null while the
 * change entry itself is kept, so the reader still sees that the field changed.
 *
 * @package Cms\Core\Records
 * @author  iSPA Team
 */
class RecordHistory
{
    /** Smallest page size. */
    public const MIN_PER_PAGE = 1;

    /** Largest page size. */
    public const MAX_PER_PAGE = 50;

    /** Maximum characters of a shown string value. */
    public const MAX_VALUE_LENGTH = 200;

    /**
     * Clamp a requested page size into the allowed range.
     *
     * @param  int  $perPage  Requested page size
     * @return int  Page size between 1 and 50
     */
    public static function clampPerPage(int $perPage): int
    {
        return max(self::MIN_PER_PAGE, min(self::MAX_PER_PAGE, $perPage));
    }

    /**
     * One page of the record's history, newest first.
     *
     * Rows are matched by `target_id` = the record key and `target_type` = the record's morph class
     * or its class name (writers store the FQCN; both are accepted so a later morph map keeps old rows).
     * Paging uses an id cursor: pass the previous page's `next_before` as `$beforeId`.
     *
     * @param  RecordType  $type      Record type declaration (field whitelist and labels)
     * @param  Model       $record    The record whose history is read
     * @param  int         $perPage   Page size (clamped to 1-50)
     * @param  int|null    $beforeId  Only rows with an id lower than this
     * @return array{items: array<int, array<string, mixed>>, next_before: int|null}
     */
    public function for(RecordType $type, Model $record, int $perPage, ?int $beforeId): array
    {
        $perPage = self::clampPerPage($perPage);
        $targetTypes = array_values(array_unique([$record->getMorphClass(), $record::class]));

        $rows = AuditLog::query()
            ->select(['id', 'action', 'changed', 'user_id', 'created_at', 'target_type', 'target_id'])
            ->whereIn('target_type', $targetTypes)
            ->where('target_id', $record->getKey())
            ->when($beforeId !== null, static fn ($query) => $query->where('id', '<', $beforeId))
            ->with('creator:id,uuid,name')
            ->orderByDesc('id')
            ->limit($perPage + 1)
            ->get();

        $hasMore = $rows->count() > $perPage;
        $rows = $rows->take($perPage);

        $items = $rows->map(fn (AuditLog $row) => $this->item($type, $row))->values()->all();

        return [
            'items' => $items,
            'next_before' => $hasMore && $rows->isNotEmpty() ? (int) $rows->last()->id : null,
        ];
    }

    /**
     * Map one audit row to its public shape.
     *
     * @param  RecordType  $type  Record type declaration
     * @param  AuditLog    $row   Audit row (restricted columns)
     * @return array<string, mixed>
     */
    private function item(RecordType $type, AuditLog $row): array
    {
        $action = (string) $row->action;
        $creator = $row->creator;

        return [
            'id' => (int) $row->id,
            'action' => $action,
            'label' => $type->labelFor($action),
            'at' => $row->created_at?->toIso8601String(),
            'actor' => $creator ? ['uuid' => $creator->uuid, 'name' => $creator->name] : null,
            'changes' => $this->changes($type, $row->changed),
        ];
    }

    /**
     * Whitelisted changes of an audit row.
     *
     * Accepts the observer shape `{from: {field: v}, to: {field: v}}` and the per-field shape
     * `{field: {from, to}}`; malformed entries are ignored.
     *
     * @param  RecordType  $type     Record type declaration
     * @param  mixed       $changed  Decoded `changed` column
     * @return array<int, array{field: string, from: mixed, to: mixed}>
     */
    private function changes(RecordType $type, mixed $changed): array
    {
        if (!is_array($changed) || $changed === []) {
            return [];
        }

        $pairs = [];

        if ($this->isFromToShape($changed)) {
            $from = is_array($changed['from'] ?? null) ? $changed['from'] : [];
            $to = is_array($changed['to'] ?? null) ? $changed['to'] : [];
            foreach (array_keys($from + $to) as $field) {
                $pairs[(string) $field] = [$from[$field] ?? null, $to[$field] ?? null];
            }
        } else {
            foreach ($changed as $field => $entry) {
                if (!is_array($entry) || (!array_key_exists('from', $entry) && !array_key_exists('to', $entry))) {
                    continue;
                }
                $pairs[(string) $field] = [$entry['from'] ?? null, $entry['to'] ?? null];
            }
        }

        $changes = [];
        foreach ($pairs as $field => [$old, $new]) {
            if (!$type->showsField($field)) {
                continue;
            }
            $changes[] = ['field' => $field, 'from' => $this->value($old), 'to' => $this->value($new)];
        }

        return $changes;
    }

    /**
     * Whether `changed` uses the observer shape `{from: {...}, to: {...}}`.
     *
     * Only `from`/`to` keys holding arrays qualify. A per-field row whose only attributes are literally
     * named "from"/"to" is indistinguishable and is read as the observer shape, which is what writers store.
     *
     * @param  array<mixed>  $changed  Decoded `changed` column
     * @return bool
     */
    private function isFromToShape(array $changed): bool
    {
        if (array_diff(array_keys($changed), ['from', 'to']) !== []) {
            return false;
        }

        foreach ($changed as $side) {
            if ($side !== null && !is_array($side)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Displayable form of one value: scalars kept (strings cut), non-scalars replaced by null.
     *
     * @param  mixed  $value  Raw value
     * @return string|int|float|bool|null
     */
    private function value(mixed $value): string|int|float|bool|null
    {
        if (is_string($value)) {
            return mb_strlen($value) > self::MAX_VALUE_LENGTH ? mb_substr($value, 0, self::MAX_VALUE_LENGTH) : $value;
        }

        return is_scalar($value) ? $value : null;
    }
}
