<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

/**
 * Fluent list view definition of an admin resource.
 */
final class ListView
{
    /** @var array<int, string> */
    private array $columns = [];

    /** @var array<int, string> */
    private array $filters = [];

    private ?string $searchParam = null;

    private ?string $searchPlaceholder = null;

    private ?string $routeName = null;

    private string $adapter = 'ispa-list-v1';

    private bool $detailLink = false;

    /** @var array<int, string> */
    private array $rowActions = [];

    /** @var array<int, string> */
    private array $bulkActions = [];

    private bool $selectable = false;

    /** Allowed label slots. */
    private const LABEL_SLOTS = ['title', 'subtitle', 'create', 'reload', 'empty', 'load_failed'];

    /** @var array<string, string> */
    private array $labels = [];

    /** @var array{route: string, label: string}|null */
    private ?array $breadcrumbParent = null;

    /** Start a new list view. */
    public static function make(): static
    {
        return new self;
    }

    /**
     * Set the displayed column field names, in order.
     *
     * @param  array<int, string>  $fieldNames
     */
    public function columns(array $fieldNames): static
    {
        $this->columns = array_values($fieldNames);

        return $this;
    }

    /** Enable text search with the query parameter and placeholder translation key. */
    public function search(string $param, string $placeholderKey): static
    {
        $this->searchParam = $param;
        $this->searchPlaceholder = $placeholderKey;

        return $this;
    }

    /**
     * Set the filter field names, in order.
     *
     * @param  array<int, string>  $fieldNames
     */
    public function filters(array $fieldNames): static
    {
        $this->filters = array_values($fieldNames);

        return $this;
    }

    /**
     * Set the list endpoint route name and response adapter.
     *
     * Adapters are free-form identifiers resolved by the client: `ispa-list-v1` (default) and
     * `laravel-paginate-v1` (`{data: {data, current_page, per_page, total, last_page}}`) are built in.
     */
    public function endpoint(string $routeName, string $adapter = 'ispa-list-v1'): static
    {
        $this->routeName = $routeName;
        $this->adapter = $adapter;

        return $this;
    }

    /**
     * Name the declared actions shown on every row.
     *
     * @param  array<int, string>  $actionNames
     */
    public function rowActions(array $actionNames): static
    {
        $this->rowActions = array_values($actionNames);

        return $this;
    }

    /**
     * Name the declared bulk actions offered for the selected rows.
     *
     * @param  array<int, string>  $actionNames
     */
    public function bulkActions(array $actionNames): static
    {
        $this->bulkActions = array_values($actionNames);

        return $this;
    }

    /** Turn row selection checkboxes on or off. */
    public function selectable(bool $selectable = true): static
    {
        $this->selectable = $selectable;

        return $this;
    }

    /** Turn row links to the detail view on or off. */
    public function detailLink(bool $on = true): static
    {
        $this->detailLink = $on;

        return $this;
    }

    /**
     * Set list page label translation keys keyed by slot.
     *
     * @param  array<string, string>  $keys  slots: title, subtitle, create, reload, empty, load_failed
     *
     * @throws \InvalidArgumentException on an unknown slot or a non-string / empty key
     */
    public function labels(array $keys): static
    {
        foreach ($keys as $slot => $key) {
            if (! in_array($slot, self::LABEL_SLOTS, true)) {
                throw new \InvalidArgumentException("Unknown list label slot [{$slot}]; allowed: ".implode(', ', self::LABEL_SLOTS).'.');
            }
            if (! is_string($key) || $key === '') {
                throw new \InvalidArgumentException("List label [{$slot}] must be a non-empty translation key.");
            }
        }
        $this->labels = $keys;

        return $this;
    }

    /**
     * Show a parent link before the page title in the breadcrumb.
     *
     * @param  string  $routeName  admin (front-end) route name of the parent page
     * @param  string  $labelKey  translation key of the link text
     * @param  string|null  $icon  optional icon name known to the admin `i-icon` registry
     *
     * @throws \InvalidArgumentException when the route name or label key is empty, or the icon is an empty string
     */
    public function breadcrumbParent(string $routeName, string $labelKey, ?string $icon = null): static
    {
        if ($routeName === '' || $labelKey === '') {
            throw new \InvalidArgumentException('The breadcrumb parent needs a route name and a label translation key.');
        }
        if ($icon === '') {
            throw new \InvalidArgumentException('The breadcrumb parent icon must be null or a non-empty icon name.');
        }
        $this->breadcrumbParent = ['route' => $routeName, 'label' => $labelKey];
        if ($icon !== null) {
            $this->breadcrumbParent['icon'] = $icon;
        }

        return $this;
    }

    /**
     * Breadcrumb parent link, if set.
     *
     * @return array{route: string, label: string, icon?: string}|null
     */
    public function getBreadcrumbParent(): ?array
    {
        return $this->breadcrumbParent;
    }

    /**
     * List page label translation keys keyed by slot.
     *
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /**
     * Column field names in declared order.
     *
     * @return array<int, string>
     */
    public function getColumns(): array
    {
        return $this->columns;
    }

    /**
     * Filter field names in declared order.
     *
     * @return array<int, string>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    /** Search query parameter, if search is enabled. */
    public function getSearchParam(): ?string
    {
        return $this->searchParam;
    }

    /** Search placeholder translation key, if search is enabled. */
    public function getSearchPlaceholder(): ?string
    {
        return $this->searchPlaceholder;
    }

    /** List endpoint route name. */
    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    /** List response adapter identifier. */
    public function getAdapter(): string
    {
        return $this->adapter;
    }

    /** Whether rows link to the detail view. */
    public function hasDetailLink(): bool
    {
        return $this->detailLink;
    }

    /**
     * Row action names in declared order.
     *
     * @return array<int, string>
     */
    public function getRowActions(): array
    {
        return $this->rowActions;
    }

    /**
     * Bulk action names in declared order.
     *
     * @return array<int, string>
     */
    public function getBulkActions(): array
    {
        return $this->bulkActions;
    }

    /** Whether rows can be selected. */
    public function isSelectable(): bool
    {
        return $this->selectable;
    }
}
