<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use Illuminate\Database\Eloquent\Model;

/**
 * The declared list of reversible internal fields: a workflow may change
 * these fields without a person approving first, because the change is small, internal and can be undone. Every such
 * change is still an approval row (decided by the `reversible_fields` rule), logged, and undoable through
 * AgentApprovalService::undoReversible().
 *
 * Config `core.workflow_nodes.human.reversible_fields`: `[ModelClass => ['field', ...]]`. Empty by default (deny by
 * default); a module adds its own rows from its provider (for example a feedback post's `is_hidden`, a ticket's
 * `priority`). Hidden model attributes never count, even when listed.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
final class ReversibleFields
{
    /**
     * @return array<class-string<Model>, list<string>>
     */
    public static function all(): array
    {
        $list = [];
        foreach ((array) config('core.workflow_nodes.human.reversible_fields', []) as $class => $fields) {
            if (!is_string($class) || !is_array($fields)) {
                continue;
            }
            $class = ltrim($class, '\\');
            $list[$class] = array_values(array_filter($fields, static fn ($field): bool => is_string($field) && $field !== ''));
        }

        return $list;
    }

    /**
     * Whether every field is declared reversible for the model.
     *
     * @param list<string> $fields
     */
    public static function allows(string $modelClass, array $fields): bool
    {
        $modelClass = ltrim($modelClass, '\\');
        $declared = self::all()[$modelClass] ?? [];
        if ($fields === [] || $declared === [] || !class_exists($modelClass) || !is_subclass_of($modelClass, Model::class)) {
            return false;
        }

        $hidden = (new $modelClass())->getHidden();
        foreach ($fields as $field) {
            if (!is_string($field) || !in_array($field, $declared, true) || in_array($field, $hidden, true)) {
                return false;
            }
        }

        return true;
    }
}
