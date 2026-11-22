<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Admin\CustomFields\CustomFieldException;
use Cms\Core\Admin\CustomFields\CustomFieldMapper;
use Cms\Core\Admin\CustomFields\CustomFieldService;
use Cms\Core\Admin\CustomFields\CustomFieldType;
use Cms\Core\Admin\Resources\ResourceRegistry;
use Cms\Core\Http\Requests\CustomField\StoreCustomFieldRequest;
use Cms\Core\Http\Requests\CustomField\UpdateCustomFieldRequest;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\MetaAttribute;
use Cms\Core\Models\MetaClass;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Nwidart\Modules\Facades\Module;
use Symfony\Component\HttpKernel\Exception\HttpException;

/**
 * Admin API for operators managing the custom field definitions of opted-in admin resources.
 *
 * Permissions: `custom_field.view` reads (resource list, field list, usage);
 * `custom_field.manage` covers every write and the preview (preview writes nothing but is part of authoring), and also
 * allows reading. The user must also hold the resource's own view ability. Webmaster gets the permissions through the
 * normal role grant; no other role receives them automatically.
 *
 * Order of checks: unknown resource, resource not opted in or module disabled → 404; missing permission → 403; invalid
 * payload or definition → 422. Fields are addressed by `meta_attributes.uuid` only; a uuid of another resource → 404.
 *
 * Every write runs through CustomFieldService (which bumps the resource's schema cache epoch) and writes an
 * `audit_logs` row (`custom_field.*`) in the same transaction. Purge takes a backup first (BackupGate, id reported as `backup`) and needs the field key
 * retyped as `confirm_key`.
 */
class CustomFieldController extends Controller
{
    /** Ability that allows reading custom field definitions. */
    public const VIEW_ABILITY = 'custom_field.view';

    /** Ability that allows changing custom field definitions. */
    public const MANAGE_ABILITY = 'custom_field.manage';

    /**
     * Create the controller.
     */
    public function __construct(
        private readonly ResourceRegistry $registry,
        private readonly CustomFieldService $service,
    ) {}

    /**
     * Opted-in resources of enabled modules that the user may view, with whether the user may manage fields.
     */
    public function resources(Request $request): JsonResponse
    {
        $user = $request->user();
        if (! $this->canView($user)) {
            return $this->forbidden();
        }

        $items = [];
        foreach ($this->registry->keys() as $key) {
            [$module, $name] = array_pad(explode('/', $key, 2), 2, '');
            if ($name === '' || ! $this->moduleEnabled($module)) {
                continue;
            }
            $resource = $this->registry->make($key);
            if (! $resource->supportsCustomFields() || ! Gate::forUser($user)->allows($resource->ability())) {
                continue;
            }
            $labelKey = $resource->list()?->getLabels()['title'] ?? null;
            $items[] = [
                'key' => $key,
                'module' => $module,
                'resource' => $name,
                'label_key' => $labelKey,
                'label' => $labelKey !== null ? (string) __($labelKey) : $key,
            ];
        }

        return response()->json([
            'data' => $items,
            'meta' => ['can_manage' => $this->canManage($user)],
        ]);
    }

    /**
     * Custom fields of a resource, archived ones included, in display order.
     */
    public function index(Request $request, string $module, string $resource): JsonResponse
    {
        $key = $this->guardResource($request, $module, $resource, false);

        return $this->listResponse($request, $key);
    }

    /**
     * Define a new custom field.
     */
    public function store(Request $request, string $module, string $resource): JsonResponse
    {
        $key = $this->guardResource($request, $module, $resource, true);
        $data = app(StoreCustomFieldRequest::class)->validated();
        $userId = $request->user()->getKey();

        return $this->attempt(function () use ($request, $key, $data, $userId): JsonResponse {
            $field = DB::transaction(function () use ($request, $key, $data, $userId): MetaAttribute {
                $field = $this->service->create($key, $data, $userId);
                $this->audit($request, 'custom_field.created', $field, ['resource' => $key, 'after' => $this->present($field)]);

                return $field;
            });

            return response()->json(['data' => $this->present($field)], 201);
        });
    }

    /**
     * Build the schema field and rules of an unsaved definition. Nothing is written.
     */
    public function preview(Request $request, string $module, string $resource): JsonResponse
    {
        $key = $this->guardResource($request, $module, $resource, true);
        $data = app(StoreCustomFieldRequest::class)->validated();

        return $this->attempt(function () use ($key, $data): JsonResponse {
            $attribute = $this->service->draft($key, $data);
            try {
                $field = app(CustomFieldMapper::class)->toField($attribute);
            } catch (InvalidArgumentException $e) {
                throw CustomFieldException::invalidDefinition([$e->getMessage()]);
            }

            return response()->json(['data' => ['field' => $field->toSchema()]]);
        });
    }

    /**
     * Change a custom field of the resource. Absent keys keep their value.
     */
    public function update(Request $request, string $module, string $resource, string $uuid): JsonResponse
    {
        $key = $this->guardResource($request, $module, $resource, true);
        $field = $this->findField($key, $uuid);
        $data = app(UpdateCustomFieldRequest::class)->validated();

        return $this->attempt(function () use ($request, $key, $field, $data): JsonResponse {
            $before = $this->present($field);
            $updated = DB::transaction(function () use ($request, $key, $field, $data, $before): MetaAttribute {
                $updated = $this->service->update($field, $data, $request->user()->getKey());
                $this->audit($request, 'custom_field.updated', $updated, [
                    'resource' => $key, 'before' => $before, 'after' => $this->present($updated),
                ]);

                return $updated;
            });

            return response()->json(['data' => $this->present($updated)]);
        });
    }

    /**
     * Set the display order of the resource's fields from a list of field uuids.
     */
    public function reorder(Request $request, string $module, string $resource): JsonResponse
    {
        $key = $this->guardResource($request, $module, $resource, true);
        $uuids = $request->validate([
            'uuids' => ['required', 'array', 'max:'.CustomFieldService::MAX_FIELDS],
            'uuids.*' => ['required', 'string', 'uuid'],
        ])['uuids'];

        return $this->attempt(function () use ($request, $key, $uuids): JsonResponse {
            DB::transaction(function () use ($request, $key, $uuids): void {
                $this->service->reorder($key, array_values($uuids));
                $class = MetaClass::query()->where('resource_key', $key)->first();
                $this->audit($request, 'custom_field.reordered', $class, ['resource' => $key, 'order' => array_values($uuids)]);
            });

            return $this->listResponse($request, $key);
        });
    }

    /**
     * Archive a field: hidden, values kept, restorable.
     */
    public function archive(Request $request, string $module, string $resource, string $uuid): JsonResponse
    {
        return $this->lifecycle($request, $module, $resource, $uuid, 'archive', 'custom_field.archived');
    }

    /**
     * Restore an archived field.
     */
    public function restore(Request $request, string $module, string $resource, string $uuid): JsonResponse
    {
        return $this->lifecycle($request, $module, $resource, $uuid, 'restore', 'custom_field.restored');
    }

    /**
     * Number of records holding a value for the field.
     */
    public function usage(Request $request, string $module, string $resource, string $uuid): JsonResponse
    {
        $key = $this->guardResource($request, $module, $resource, false);
        $field = $this->findField($key, $uuid);

        return response()->json(['data' => [
            'uuid' => $field->uuid,
            'key' => $this->keyOf($field),
            'records' => $this->service->usage($field),
        ]]);
    }

    /**
     * Permanently delete the field's values and retire the field; `confirm_key` must equal the field key.
     */
    public function purge(Request $request, string $module, string $resource, string $uuid): JsonResponse
    {
        $key = $this->guardResource($request, $module, $resource, true);
        $field = $this->findField($key, $uuid);
        $confirm = $request->validate(['confirm_key' => ['required', 'string', 'max:40']])['confirm_key'];

        return $this->attempt(function () use ($request, $key, $field, $confirm): JsonResponse {
            $snapshot = $this->present($field);
            // The backup runs outside the transaction so its records survive a later failure; no backup, no purge.
            $backup = $this->service->backupBeforePurge($field, $confirm, $request->user()->getKey());
            $removed = DB::transaction(function () use ($request, $key, $field, $confirm, $snapshot, $backup): int {
                $removed = $this->service->purge($field, $confirm, $backup);
                $this->audit($request, 'custom_field.purged', $field, [
                    'resource' => $key, 'before' => $snapshot, 'removed_values' => $removed, 'backup' => $backup->uuid,
                ]);

                return $removed;
            });

            return response()->json(['data' => [
                'uuid' => $snapshot['uuid'],
                'key' => $snapshot['key'],
                'removed_values' => $removed,
                'backup' => $backup->uuid,
            ]]);
        });
    }

    /**
     * Run archive or restore with its audit entry.
     */
    private function lifecycle(Request $request, string $module, string $resource, string $uuid, string $method, string $action): JsonResponse
    {
        $key = $this->guardResource($request, $module, $resource, true);
        $field = $this->findField($key, $uuid);

        return $this->attempt(function () use ($request, $key, $field, $method, $action): JsonResponse {
            $result = DB::transaction(function () use ($request, $key, $field, $method, $action): MetaAttribute {
                $result = $this->service->{$method}($field);
                $this->audit($request, $action, $result, ['resource' => $key]);

                return $result;
            });

            return response()->json(['data' => $this->present($result)]);
        });
    }

    /**
     * Check the resource and the user's rights; returns the resource key.
     *
     * @throws HttpException 404 when the resource is unknown, not opted in or its module is disabled; 403 when the
     *                       user lacks the custom field permission or the resource's view ability
     */
    private function guardResource(Request $request, string $module, string $resource, bool $manage): string
    {
        $key = $module.'/'.$resource;
        if (! $this->registry->has($key) || ! $this->moduleEnabled($module)) {
            abort(404, 'Not Found');
        }
        $definition = $this->registry->make($key);
        if (! $definition->supportsCustomFields()) {
            abort(404, 'Not Found');
        }

        $user = $request->user();
        $allowed = $manage ? $this->canManage($user) : $this->canView($user);
        if (! $allowed || ! Gate::forUser($user)->allows($definition->ability())) {
            abort(403, 'Forbidden');
        }

        return $key;
    }

    /**
     * Find a (not purged) custom field of the resource by uuid.
     *
     * @throws HttpException 404 when the value is not a uuid or does not name a field of this resource
     */
    private function findField(string $resourceKey, string $uuid): MetaAttribute
    {
        // Numeric ids are never accepted (UUID-first admin API).
        if (! Str::isUuid($uuid)) {
            abort(404, 'Not Found');
        }
        $field = MetaAttribute::query()->forResource($resourceKey)->where('uuid', $uuid)->first();
        if ($field === null) {
            abort(404, 'Not Found');
        }

        return $field;
    }

    /** Whether the user may read custom field definitions (view or manage). */
    private function canView(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->any([self::VIEW_ABILITY, self::MANAGE_ABILITY]);
    }

    /** Whether the user may change custom field definitions. */
    private function canManage(?Authenticatable $user): bool
    {
        return $user !== null && Gate::forUser($user)->allows(self::MANAGE_ABILITY);
    }

    /**
     * Run a write and turn a CustomFieldException into a 422 (404 when the resource stopped accepting fields).
     *
     * @param  callable(): JsonResponse  $callback
     */
    private function attempt(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (CustomFieldException $e) {
            if ($e->reason() === CustomFieldException::RESOURCE_NOT_SUPPORTED) {
                return response()->json(['message' => 'Not Found'], 404);
            }

            return response()->json([
                'message' => $e->getMessage(),
                'reason' => $e->reason(),
                'errors' => ['definition' => $e->errors() !== [] ? $e->errors() : [$e->getMessage()]],
            ], 422);
        }
    }

    /**
     * Field list response of a resource.
     */
    private function listResponse(Request $request, string $key): JsonResponse
    {
        $fields = $this->service->fieldsFor($key, true);

        return response()->json([
            'data' => $fields->map(fn (MetaAttribute $field): array => $this->present($field))->values()->all(),
            'meta' => [
                'limit' => CustomFieldService::MAX_FIELDS,
                'count' => $fields->count(),
                'can_manage' => $this->canManage($request->user()),
            ],
        ]);
    }

    /**
     * API shape of a field; never exposes the numeric id or internal attribute name.
     *
     * @return array<string, mixed>
     */
    private function present(MetaAttribute $field): array
    {
        $rules = (array) ($field->rules ?? []);
        $options = $rules['options'] ?? [];
        unset($rules['options']);

        return [
            'uuid' => $field->uuid,
            'key' => $this->keyOf($field),
            'type' => $this->typeOf($field)?->value,
            'labels' => $field->labels,
            'help' => $field->help,
            'rules' => (object) $rules,
            'options' => array_values((array) $options),
            'is_required' => (bool) $field->is_required,
            'show_in_list' => (bool) $field->show_in_list,
            'is_searchable' => (bool) $field->is_searchable,
            'is_filterable' => (bool) $field->is_filter,
            'sort_order' => (int) $field->priority,
            'archived' => $field->archived_at !== null,
            'archived_at' => $field->archived_at?->toIso8601String(),
            'updated_at' => $field->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Write one audit entry for a custom field change.
     *
     * @param  array<string, mixed>  $changed
     */
    private function audit(Request $request, string $action, ?object $target, array $changed): void
    {
        AuditLog::query()->create([
            'action' => $action,
            'changed' => $changed,
            'target_type' => $target !== null ? $target::class : null,
            'target_id' => $target?->getKey(),
            'ip_address' => $request->ip(),
            'agent' => $request->userAgent(),
            'url' => $request->fullUrl(),
            'options' => ['method' => $request->method()],
            'user_id' => $request->user()?->getKey(),
        ]);
    }

    /** Field key: the part of the attribute name after the last dot. */
    private function keyOf(MetaAttribute $field): string
    {
        $name = (string) $field->name;

        return substr($name, (int) strrpos($name, '.') + 1);
    }

    /** Custom field type of a stored attribute, or null when it matches none. */
    private function typeOf(MetaAttribute $field): ?CustomFieldType
    {
        foreach (CustomFieldType::cases() as $case) {
            if ($case->inputType() === $field->input_type && $case->isMultiple() === (bool) $field->is_multiple) {
                return $case;
            }
        }

        return null;
    }

    /** 403 JSON response. */
    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }

    /**
     * Whether the module matching the name or alias exists and is enabled (same rule as the schema endpoint).
     */
    private function moduleEnabled(string $module): bool
    {
        $needle = strtolower($module);

        foreach (Module::all() as $candidate) {
            if (strtolower($candidate->getName()) === $needle || strtolower((string) $candidate->get('alias')) === $needle) {
                return $candidate->isEnabled();
            }
        }

        return false;
    }
}
