<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Security;

use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use WeakMap;

/**
 * How workflow steps see and address Eloquent models.
 *
 * Expressions, templates and stored variables never hold a live model: a model is shown as a snapshot of
 * `attributesToArray()` (so `$hidden` applies) without encrypted casts and without relations, plus a reference
 * `__class` / `__id` / `__sig`. `__sig` is an HMAC (APP_KEY) over the class, the key, a pending token for an unsaved
 * model and the run's instance id, so only a reference this run produced (its root, a `retrieve`, `create_object`,
 * `clone_object` or `change` result) can be handed to a write step; an array a webhook sender or form visitor wrote
 * into the trigger payload cannot.
 *
 * Models a run created or changed without saving stay in a per-context working set (never visible to expressions),
 * so `commit_objects` can save them later in the same process. After a delay or an approval the working set is gone
 * and the reference resolves to the stored row.
 *
 * @package Cms\Core\Workflows\Security
 * @since   2026-11-25
 */
final class WorkflowModelRefs
{
    /** @var WeakMap<WorkflowContext, array<string, Model>>|null */
    private static ?WeakMap $live = null;

    /** @var WeakMap<Model, string>|null Pending token of each unsaved model. */
    private static ?WeakMap $pending = null;

    /**
     * Attributes a workflow may see: `attributesToArray()` (honours `$hidden`) minus encrypted casts, which are hidden
     * before the cast runs so nothing is decrypted.
     *
     * @return array<string, mixed>
     */
    public static function attributes(Model $model): array
    {
        $encrypted = self::encryptedAttributes($model);
        if ($encrypted === []) {
            return $model->attributesToArray();
        }

        $copy = clone $model;
        $copy->makeHidden($encrypted);

        return $copy->attributesToArray();
    }

    /**
     * Attribute names with an encrypted cast (`encrypted`, `encrypted:*`, `AsEncrypted*`).
     *
     * @return list<string>
     */
    public static function encryptedAttributes(Model $model): array
    {
        $out = [];
        foreach ($model->getCasts() as $key => $cast) {
            $cast = strtolower(is_string($cast) ? $cast : '');
            if (str_starts_with($cast, 'encrypted') || str_contains($cast, 'asencrypted')) {
                $out[] = (string) $key;
            }
        }

        return $out;
    }

    /**
     * Snapshot an expression or a stored variable sees: the visible attributes at the top level plus the reference.
     *
     * @return array<string, mixed>
     */
    public static function snapshot(Model $model, WorkflowContext $context): array
    {
        return self::attributes($model) + self::ref($model, $context);
    }

    /**
     * The `retrieve` shape: the reference plus the visible attributes under `attributes`.
     *
     * @return array<string, mixed>
     */
    public static function nestedSnapshot(Model $model, WorkflowContext $context): array
    {
        return self::ref($model, $context) + ['attributes' => self::attributes($model)];
    }

    /**
     * A signed reference to $model for this run. An unsaved model is kept in the working set under a pending token.
     *
     * @return array{__class: string, __id: mixed, __pending?: string, __sig: string}
     */
    public static function ref(Model $model, WorkflowContext $context): array
    {
        $ref = ['__class' => $model::class, '__id' => $model->exists ? $model->getKey() : null];

        if (!$model->exists) {
            self::$pending ??= new WeakMap();
            $token = self::$pending[$model] ??= Str::random(40);
            $ref['__pending'] = $token;
            self::remember($context, $model);
        }

        $ref['__sig'] = self::sign($ref, $context);

        return $ref;
    }

    /**
     * Keep a model the run created or changed (saved or not) so later steps of this run work on the same object.
     */
    public static function remember(WorkflowContext $context, Model $model): void
    {
        self::$live ??= new WeakMap();
        $set = self::$live[$context] ?? [];

        if ($model->exists) {
            $set[self::rowKey($model::class, $model->getKey())] = $model;
        }
        if (isset(self::$pending[$model])) {
            $set['pending:' . self::$pending[$model]] = $model;
        }

        self::$live[$context] = $set;
    }

    /**
     * Resolve a step's target: null (nothing), one reference, or (when $allowList) a list of references.
     *
     * @return array{0: list<Model>, 1: string|null} The models, or an error when any item is not a reference this
     *                                               run produced or its row no longer exists.
     */
    public static function resolve(WorkflowContext $context, mixed $target, bool $allowList = true): array
    {
        if ($target === null) {
            return [[], null];
        }

        if (!is_array($target)) {
            return [[], 'target is not a workflow model reference'];
        }

        $items = array_key_exists('__class', $target) ? [$target] : ($allowList && array_is_list($target) ? $target : null);
        if ($items === null) {
            return [[], 'target is not a workflow model reference'];
        }

        $models = [];
        foreach ($items as $item) {
            [$model, $error] = self::resolveOne($context, $item);
            if ($error !== null) {
                return [[], $error];
            }
            $models[] = $model;
        }

        return [$models, null];
    }

    /**
     * @return array{0: Model|null, 1: string|null}
     */
    private static function resolveOne(WorkflowContext $context, mixed $ref): array
    {
        if (!is_array($ref) || !is_string($ref['__class'] ?? null) || !is_string($ref['__sig'] ?? null)) {
            return [null, 'target is not a workflow model reference'];
        }

        $claimed = ['__class' => $ref['__class'], '__id' => $ref['__id'] ?? null];
        if (is_string($ref['__pending'] ?? null)) {
            $claimed['__pending'] = $ref['__pending'];
        }

        $expected = self::sign($claimed, $context);
        if ($expected === '' || !hash_equals($expected, $ref['__sig'])) {
            return [null, 'target reference was not produced by this run'];
        }

        $class = $claimed['__class'];
        if (!class_exists($class) || !is_subclass_of($class, Model::class)) {
            return [null, 'target is not a model'];
        }

        $set = self::$live[$context] ?? [];

        if (isset($claimed['__pending'])) {
            $model = $set['pending:' . $claimed['__pending']] ?? null;

            return $model instanceof Model ? [$model, null] : [null, 'unsaved target is no longer available'];
        }

        $id = $claimed['__id'];
        if (!is_int($id) && !is_string($id)) {
            return [null, 'target is not a workflow model reference'];
        }

        if (($set[self::rowKey($class, $id)] ?? null) instanceof Model) {
            return [$set[self::rowKey($class, $id)], null];
        }

        $root = $context->getRoot();
        if ($root::class === $class && $root->exists && (string) $root->getKey() === (string) $id) {
            return [$root, null];
        }

        $query = $class::query();
        if (in_array(SoftDeletes::class, class_uses_recursive($class), true)) {
            $query->withTrashed();
        }

        $model = $query->find($id);

        return $model instanceof Model ? [$model, null] : [null, 'target row not found'];
    }

    /**
     * Whether $expression reads the trigger payload directly (`trigger…` at the top level, not `vars.trigger…`).
     */
    public static function readsTrigger(string $expression): bool
    {
        return preg_match('/(?<![A-Za-z0-9_.$])trigger\b/', $expression) === 1;
    }

    /**
     * @param array<string, mixed> $ref
     */
    private static function sign(array $ref, WorkflowContext $context): string
    {
        $key = (string) config('app.key');
        if ($key === '') {
            // No key, no references: an empty-key HMAC would be forgeable.
            return '';
        }

        $instance = $context->getVarPath(WorkflowContext::RUNTIME_KEY . '.instance_id');

        return hash_hmac('sha256', implode("\0", [
            'workflow-model-ref',
            strtolower(ltrim((string) $ref['__class'], '\\')),
            (string) ($ref['__id'] ?? ''),
            (string) ($ref['__pending'] ?? ''),
            is_scalar($instance) ? (string) $instance : '',
        ]), $key);
    }

    private static function rowKey(string $class, int|string $id): string
    {
        return strtolower(ltrim($class, '\\')) . '#' . $id;
    }
}
