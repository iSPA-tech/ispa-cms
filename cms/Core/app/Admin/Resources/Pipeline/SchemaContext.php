<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources\Pipeline;

use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\Resource;
use Illuminate\Contracts\Auth\Authenticatable;

/**
 * What every {@see SchemaStage} of one build knows: the resource, the user and the locale, plus the fields that stages
 * have registered so far.
 *
 * The field registry is how the permission stage learns which schema entries belong to which {@see Field}, including
 * fields a later stage (custom fields, overlays) added after the definition stage ran.
 */
final class SchemaContext
{
    /** @var array<string, Field> every field of the schema by name, whatever the user may use */
    private array $fields = [];

    /** @var array<string, array<int, Field>> input fields of each action by action name */
    private array $actionInputs = [];

    /** Create the context. */
    public function __construct(
        public readonly Resource $resource,
        public readonly Authenticatable $user,
        public readonly string $locale,
    ) {}

    /** Register a field so later stages (notably permissions) can look it up by name. */
    public function registerField(Field $field): void
    {
        $this->fields[$field->name()] = $field;
    }

    /**
     * Every registered field by name.
     *
     * @return array<string, Field>
     */
    public function fields(): array
    {
        return $this->fields;
    }

    /**
     * Register the input fields of an action.
     *
     * @param  array<int, Field>  $inputs
     */
    public function registerActionInputs(string $action, array $inputs): void
    {
        $this->actionInputs[$action] = $inputs;
    }

    /**
     * Input fields of an action in declaration order.
     *
     * @return array<int, Field>
     */
    public function actionInputs(string $action): array
    {
        return $this->actionInputs[$action] ?? [];
    }
}
