<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources\Pipeline;

use Cms\Core\Admin\Resources\Action;
use Cms\Core\Admin\Resources\Condition;
use Cms\Core\Admin\Resources\DetailView;
use Cms\Core\Admin\Resources\Field;
use Cms\Core\Admin\Resources\FormView;
use Cms\Core\Admin\Resources\ListView;
use Cms\Core\Admin\Resources\Resource;
use Cms\Core\Admin\Resources\SchemaBuilder;
use Illuminate\Contracts\Auth\Access\Gate as GateContract;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use InvalidArgumentException;
use LogicException;

/**
 * First pipeline stage: builds list, form, detail and actions from the resource definition.
 *
 * Whole views and whole actions the user lacks the ability for are left out here, as before. Field-level removal
 * (field ability and data scope) is not done here: every declared field is built and registered on the
 * {@see SchemaContext}, and {@see PermissionStage} removes what the user may not use after the custom-field and
 * overlay stages ran. Endpoint paths follow the rules documented on {@see SchemaBuilder}.
 */
final class DefinitionStage implements SchemaStage
{
    private const API_PREFIX = '/api/v2/admin/';

    /**
     * Build the schema from the resource definition.
     *
     * @throws InvalidArgumentException on an unknown route, unknown field or invalid condition
     * @throws LogicException when a route is outside the admin API prefix or an endpoint is missing
     */
    public function apply(array $schema, SchemaContext $context): array
    {
        $resource = $context->resource;
        $gate = Gate::forUser($context->user);

        $this->validateActionReferences($resource);

        foreach ($resource->fields() as $field) {
            $this->validateField($field);
            $context->registerField($field);
        }
        $fields = $context->fields();

        return [
            'schema_version' => SchemaBuilder::VERSION,
            'resource' => $resource->key(),
            'list' => $this->buildList($resource->list(), $fields, $this->usableActionNames($resource->actions(), $gate)),
            'form' => $this->buildForm($resource->form(), $fields, $gate),
            'detail' => $this->buildDetail($resource->detail(), $fields),
            'actions' => $this->buildActions($resource->actions(), $gate, $context),
        ];
    }

    /**
     * List column of a field (also used for custom fields).
     *
     * @return array<string, mixed>
     */
    public static function columnOf(Field $field): array
    {
        $schema = $field->toSchema();
        $column = ['field' => $schema['field'], 'type' => $schema['type'], 'label' => $schema['label']];
        // secondary (text-with-code cell), start/end (date-range cell) and expiry/date_only (date cell) feed the list
        // display widgets.
        foreach (['options', 'colors', 'sortable', 'link', 'secondary', 'start', 'end', 'expiry', 'date_only'] as $key) {
            if (isset($schema[$key])) {
                $column[$key] = $schema[$key];
            }
        }
        if ($field->getWidth() !== null) {
            $column['width'] = $field->getWidth();
        }

        return $column;
    }

    /**
     * List filter of a field (also used for custom fields).
     *
     * @return array<string, mixed>
     */
    public static function filterOf(Field $field): array
    {
        $schema = $field->toSchema();
        $filter = ['field' => $schema['field'], 'type' => $schema['type'], 'label' => $schema['label']];
        if (isset($schema['options'])) {
            $filter['options'] = $schema['options'];
        }
        if ($field->getFilterAllLabel() !== null) {
            $filter['allLabel'] = $field->getFilterAllLabel();
        }

        return $filter;
    }

    /**
     * @param  array<string, Field>  $fields
     * @param  array<int, string>  $usable  names of the actions the user may run
     * @return array<string, mixed>|null
     */
    private function buildList(?ListView $list, array $fields, array $usable = []): ?array
    {
        if ($list === null) {
            return null;
        }

        $rowActions = array_values(array_intersect($list->getRowActions(), $usable));
        $bulkActions = array_values(array_intersect($list->getBulkActions(), $usable));

        $columns = [];
        foreach ($list->getColumns() as $name) {
            if (! isset($fields[$name])) {
                continue;
            }
            $column = self::columnOf($fields[$name]);
            if ($list->hasDetailLink() && $columns === []) {
                $column['link'] = 'detail';
            }
            $columns[] = $column;
        }

        $filters = [];
        foreach ($list->getFilters() as $name) {
            if (! isset($fields[$name])) {
                continue;
            }
            $filter = self::filterOf($fields[$name]);
            $filters[] = $filter;
        }

        $out = [
            'endpoint' => $this->endpoint($list->getRouteName(), null, $list->getAdapter()),
        ];
        if ($list->isSelectable()) {
            $out['selectable'] = true;
        }
        if ($rowActions !== []) {
            $out['rowActions'] = $rowActions;
        }
        if ($bulkActions !== []) {
            $out['bulkActions'] = $bulkActions;
        }
        if ($list->getSearchParam() !== null) {
            $out['search'] = ['param' => $list->getSearchParam(), 'placeholder' => $list->getSearchPlaceholder()];
        }
        $out['filters'] = $filters;
        $out['columns'] = $columns;
        if ($list->getLabels() !== []) {
            $out['labels'] = $list->getLabels();
        }
        if ($list->getBreadcrumbParent() !== null) {
            $out['breadcrumbParent'] = $list->getBreadcrumbParent();
        }

        return $out;
    }

    /**
     * @param  array<string, Field>  $fields
     * @return array<string, mixed>|null
     */
    private function buildForm(?FormView $form, array $fields, GateContract $gate): ?array
    {
        if ($form === null || ! $this->allowed($gate, $form->getAbility())) {
            return null;
        }

        $out = [];
        foreach ($form->contexts() as $context) {
            $routeName = $context === 'update' ? $form->getUpdateRouteName() : $form->getRouteName();
            $method = $context === 'update' ? $form->getUpdateMethod() : $form->getMethod();

            $contextFields = [];
            foreach ($form->fieldsFor($context) ?? [] as $name) {
                if (isset($fields[$name]) && $fields[$name]->availableIn($context)) {
                    $contextFields[] = $fields[$name]->toSchema();
                }
            }

            $part = [
                'endpoint' => $this->endpoint($routeName, $method),
                'fields' => $contextFields,
            ];
            if ($form->getSuccessMessage($context) !== null) {
                $part['success_message'] = $form->getSuccessMessage($context);
            }
            if ($form->getAfter() !== null) {
                $part['after'] = $form->getAfter();
            }
            $out[$context] = $part;
        }

        if ($out !== [] && $form->getLabels() !== []) {
            $out['labels'] = $form->getLabels();
        }

        return $out === [] ? null : $out;
    }

    /**
     * @param  array<string, Field>  $fields
     * @return array<string, mixed>|null
     */
    private function buildDetail(?DetailView $detail, array $fields): ?array
    {
        if ($detail === null) {
            return null;
        }

        $sections = [];
        foreach ($detail->getSections() as $name) {
            if (isset($fields[$name])) {
                $sections[] = $fields[$name]->toSchema();
            }
        }

        $out = [
            'endpoint' => $this->endpoint($detail->getRouteName()),
            'sections' => $sections,
            'tabs' => $detail->getTabs(),
        ];
        if ($detail->getLabels() !== []) {
            $out['labels'] = $detail->getLabels();
        }

        return $out;
    }

    /**
     * @param  array<int, Action>  $actions
     * @return array<int, array<string, mixed>>
     */
    private function buildActions(array $actions, GateContract $gate, SchemaContext $context): array
    {
        $out = [];
        foreach ($actions as $action) {
            if (! $this->allowed($gate, $action->getAbility())) {
                continue;
            }

            $item = [
                'name' => $action->name(),
                'label' => $action->getLabel() ?? $action->name(),
                'endpoint' => $this->endpoint($action->getRouteName(), $action->getMethod()),
            ];
            if ($action->getLabelFrom() !== null) {
                $item['label_from'] = $action->getLabelFrom();
            }
            if ($action->getPayload() !== []) {
                $item['payload'] = $action->getPayload();
            }
            if ($action->getConfirm() !== null) {
                $item['confirm'] = $action->getConfirm();
            }
            if ($action->getVisibleWhen() !== null) {
                Condition::validate($action->getVisibleWhen());
                $item['visible'] = $action->getVisibleWhen();
            }

            $inputs = [];
            $inputFields = [];
            foreach ($action->getFields() as $field) {
                $this->validateField($field);
                $inputFields[] = $field;
                $inputs[] = $field->toSchema();
            }
            $context->registerActionInputs($action->name(), $inputFields);
            if ($inputs !== []) {
                $item['fields'] = $inputs;
            }
            if ($action->isDanger()) {
                $item['danger'] = true;
            }
            if ($action->isBulk()) {
                $item['bulk'] = true;
            }
            if ($action->getAfter() !== null) {
                $item['after'] = $action->getAfter();
            }
            if ($action->getRefresh() !== null) {
                $item['refresh'] = $action->getRefresh();
            }
            if ($action->getSuccessMessage() !== null) {
                $item['success_message'] = $action->getSuccessMessage();
            }
            if ($action->getSubmitLabel() !== null) {
                $item['submitLabel'] = $action->getSubmitLabel();
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * Names of the declared actions the user may run.
     *
     * @param  array<int, Action>  $actions
     * @return array<int, string>
     */
    private function usableActionNames(array $actions, GateContract $gate): array
    {
        $names = [];
        foreach ($actions as $action) {
            if ($this->allowed($gate, $action->getAbility())) {
                $names[] = $action->name();
            }
        }

        return $names;
    }

    /**
     * Check list row/bulk action names against the declared actions and bulk endpoints for `:uuid`.
     *
     * @throws InvalidArgumentException on an unknown or non-bulk action name, or a bulk endpoint with `:uuid`
     */
    private function validateActionReferences(Resource $resource): void
    {
        /** @var array<string, Action> $declared */
        $declared = [];
        foreach ($resource->actions() as $action) {
            $declared[$action->name()] = $action;
            if ($action->isBulk() && str_contains($this->endpoint($action->getRouteName(), $action->getMethod())['path'], ':uuid')) {
                throw new InvalidArgumentException("Bulk action [{$action->name()}] must use an endpoint without a :uuid parameter.");
            }
        }

        $list = $resource->list();
        if ($list === null) {
            return;
        }
        foreach ($list->getRowActions() as $name) {
            if (! isset($declared[$name])) {
                throw new InvalidArgumentException("Unknown row action [{$name}] on the list of [{$resource->key()}].");
            }
        }
        foreach ($list->getBulkActions() as $name) {
            if (! isset($declared[$name])) {
                throw new InvalidArgumentException("Unknown bulk action [{$name}] on the list of [{$resource->key()}].");
            }
            if (! $declared[$name]->isBulk()) {
                throw new InvalidArgumentException("Action [{$name}] is listed as a bulk action but is not marked bulk().");
            }
        }
    }

    /**
     * Build an endpoint descriptor with `:param` placeholders.
     *
     * @return array<string, string>
     *
     * @throws LogicException when no route name is set or the route is outside the admin API prefix
     * @throws InvalidArgumentException when the route name is unknown
     */
    private function endpoint(?string $routeName, ?string $method = null, ?string $adapter = null): array
    {
        if ($routeName === null) {
            throw new LogicException('A view or action is missing its endpoint route name.');
        }

        $route = Route::getRoutes()->getByName($routeName);
        if ($route === null) {
            throw new InvalidArgumentException("Unknown route [{$routeName}].");
        }

        $placeholders = [];
        foreach ($route->parameterNames() as $param) {
            $placeholders[$param] = ':'.$param;
        }

        $url = str_replace('%3A', ':', route($routeName, $placeholders, false));
        if (! str_starts_with($url, self::API_PREFIX)) {
            throw new LogicException("Route [{$routeName}] ({$url}) is outside the admin API prefix [".self::API_PREFIX.'].');
        }

        if ($method === null) {
            $method = 'GET';
            foreach ($route->methods() as $candidate) {
                if ($candidate !== 'HEAD') {
                    $method = $candidate;
                    break;
                }
            }
        }

        $endpoint = [
            'route' => $routeName,
            'path' => substr($url, strlen(self::API_PREFIX)),
            'method' => $method,
        ];
        if ($adapter !== null) {
            $endpoint['adapter'] = $adapter;
        }

        return $endpoint;
    }

    /** Validate the field's visibleWhen / requiredWhen conditions. */
    private function validateField(Field $field): void
    {
        $schema = $field->toSchema();
        foreach (['visible', 'required_when'] as $key) {
            if (isset($schema[$key])) {
                Condition::validate($schema[$key]);
            }
        }
    }

    /** Whether the ability is unset or granted to the user. */
    private function allowed(GateContract $gate, ?string $ability): bool
    {
        return $ability === null || $gate->allows($ability);
    }
}
