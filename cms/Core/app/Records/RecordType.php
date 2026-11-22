<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Records;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use InvalidArgumentException;

/**
 * Declaration of one record type whose per-record history may be shown in the admin.
 *
 * A type names the Eloquent model, the abilities that guard it, how a record is looked up for a
 * given user, which attributes may expose their old/new values, and the labels of its audit actions.
 *
 * @package Cms\Core\Records
 * @author  iSPA Team
 */
final class RecordType
{
    /** Translation key used for audit actions without an explicit label. */
    public const UNKNOWN_ACTION_LABEL = 'text.history.action.unknown';

    /** Pattern every record type key must match. */
    public const KEY_PATTERN = '/^[a-z0-9._-]+$/';

    /** @var Closure(string, Authenticatable): ?Model|null Custom record lookup. */
    public readonly ?Closure $resolver;

    /**
     * Create a record type declaration.
     *
     * @param  string                       $key            Unique key, matching `[a-z0-9._-]+`
     * @param  class-string<Model>          $model          Eloquent model class of the records
     * @param  string                       $viewAbility    Ability required to read the history
     * @param  string                       $manageAbility  Ability required to manage the records
     * @param  callable|null                $resolver       `fn (string $uuid, Authenticatable $user): ?Model`; null = lookup by `uuid`
     * @param  array<int, string>           $historyFields  Attributes whose from/to values may be shown
     * @param  array<string, string>        $actionLabels   Map of stored action (`action.<event>`) to translation key
     *
     * @throws InvalidArgumentException When the key is malformed or the model is not an Eloquent model
     */
    public function __construct(
        public readonly string $key,
        public readonly string $model,
        public readonly string $viewAbility,
        public readonly string $manageAbility,
        ?callable $resolver = null,
        public readonly array $historyFields = [],
        public readonly array $actionLabels = [],
    ) {
        if (preg_match(self::KEY_PATTERN, $key) !== 1) {
            throw new InvalidArgumentException("Invalid record type key [{$key}].");
        }

        if (!is_subclass_of($model, Model::class)) {
            throw new InvalidArgumentException("Record type [{$key}] model must be an Eloquent model.");
        }

        $this->resolver = $resolver === null ? null : Closure::fromCallable($resolver);
    }

    /**
     * Look up the record with the given UUID as visible to the given user.
     *
     * @param  string           $uuid  Record UUID
     * @param  Authenticatable  $user  Current user
     * @return Model|null  The record, or null when it does not exist or the user may not see it
     */
    public function resolve(string $uuid, Authenticatable $user): ?Model
    {
        if ($this->resolver !== null) {
            $record = ($this->resolver)($uuid, $user);

            return $record instanceof Model ? $record : null;
        }

        return $this->model::query()->where('uuid', $uuid)->first();
    }

    /**
     * Translation key of a stored audit action.
     *
     * @param  string  $action  Stored action, e.g. `action.update`
     * @return string  Translation key; `text.history.action.unknown` when not declared
     */
    public function labelFor(string $action): string
    {
        $label = $this->actionLabels[$action] ?? null;

        return is_string($label) && $label !== '' ? $label : self::UNKNOWN_ACTION_LABEL;
    }

    /**
     * Whether the attribute may expose its old/new values in the history.
     *
     * @param  string  $field  Attribute name
     * @return bool
     */
    public function showsField(string $field): bool
    {
        return in_array($field, $this->historyFields, true);
    }
}
