<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

/**
 * Fluent detail view definition of an admin resource.
 */
final class DetailView
{
    private ?string $routeName = null;

    /** @var array<int, string> */
    private array $sections = [];

    /** @var array<int, array{name: string, label: string, widget: string}> */
    private array $tabs = [];

    private ?string $timelineType = null;

    /** Allowed label slots. */
    private const LABEL_SLOTS = ['title'];

    /** @var array<string, string> */
    private array $labels = [];

    /** Start a new detail view. */
    public static function make(): static
    {
        return new self;
    }

    /** Set the detail endpoint route name. */
    public function endpoint(string $routeName): static
    {
        $this->routeName = $routeName;

        return $this;
    }

    /**
     * Set the summary section field names, in order.
     *
     * @param  array<int, string>  $fieldNames
     */
    public function sections(array $fieldNames): static
    {
        $this->sections = array_values($fieldNames);

        return $this;
    }

    /** Append a tab rendered by the given widget. */
    public function tab(string $name, string $labelKey, string $widget): static
    {
        $this->tabs[] = ['name' => $name, 'label' => $labelKey, 'widget' => $widget];

        return $this;
    }

    /**
     * Show the record timeline (notes and history) as the last tab of the detail drawer.
     *
     * @param  string  $recordType  key of a type registered in {@see \Cms\Core\Records\RecordRegistry}
     */
    public function timeline(string $recordType): static
    {
        $this->timelineType = $recordType;

        return $this;
    }

    /** Record type key of the timeline tab, or null when the detail view has none. */
    public function getTimelineType(): ?string
    {
        return $this->timelineType;
    }

    /**
     * Set detail drawer label translation keys keyed by slot.
     *
     * The title key may contain a `{name}` placeholder, filled with the record name.
     *
     * @param  array<string, string>  $keys  slots: title
     *
     * @throws \InvalidArgumentException on an unknown slot or a non-string / empty key
     */
    public function labels(array $keys): static
    {
        foreach ($keys as $slot => $key) {
            if (! in_array($slot, self::LABEL_SLOTS, true)) {
                throw new \InvalidArgumentException("Unknown detail label slot [{$slot}]; allowed: ".implode(', ', self::LABEL_SLOTS).'.');
            }
            if (! is_string($key) || $key === '') {
                throw new \InvalidArgumentException("Detail label [{$slot}] must be a non-empty translation key.");
            }
        }
        $this->labels = $keys;

        return $this;
    }

    /**
     * Detail label translation keys keyed by slot.
     *
     * @return array<string, string>
     */
    public function getLabels(): array
    {
        return $this->labels;
    }

    /** Detail endpoint route name. */
    public function getRouteName(): ?string
    {
        return $this->routeName;
    }

    /**
     * Section field names in declared order.
     *
     * @return array<int, string>
     */
    public function getSections(): array
    {
        return $this->sections;
    }

    /**
     * Tabs in declared order.
     *
     * @return array<int, array{name: string, label: string, widget: string}>
     */
    public function getTabs(): array
    {
        return $this->tabs;
    }
}
