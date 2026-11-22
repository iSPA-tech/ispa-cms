<?php

declare(strict_types=1);

namespace Cms\Core\SavedViews;

use Cms\Core\Admin\Resources\ResourceRegistry;
use InvalidArgumentException;

/**
 * Lists that accept saved views, with the abilities that guard them.
 *
 * A list key is either registered here explicitly (legacy lists such as `helpdesk/tickets`) or is a resource key of
 * {@see ResourceRegistry} (`helpdesk/organizations`). Explicit registrations win. For a resource, the view ability
 * is the resource ability and the share ability is its form ability (none when the resource has no form or the form
 * declares no ability). Bound as a singleton in the Core service provider.
 */
final class SavedViewRegistry
{
    /** @var array<string, array{view: string, share: ?string}> */
    private array $lists = [];

    /** Create the registry with the resource registry used as fallback. */
    public function __construct(private readonly ResourceRegistry $resources) {}

    /**
     * Register a list key with the ability needed to see shared views and the ability needed to share.
     *
     * @param  string       $listKey       List key, at most 150 characters
     * @param  string       $viewAbility   Permission ability of the list itself
     * @param  string|null  $shareAbility  Permission ability allowed to share views (the list's admin ability), or null
     *
     * @throws InvalidArgumentException when the key is empty, too long or already registered
     */
    public function register(string $listKey, string $viewAbility, ?string $shareAbility = null): void
    {
        if ($listKey === '' || strlen($listKey) > 150) {
            throw new InvalidArgumentException('Saved view list key must be 1 to 150 characters.');
        }
        if (isset($this->lists[$listKey])) {
            throw new InvalidArgumentException("Saved view list [{$listKey}] is already registered.");
        }

        $this->lists[$listKey] = ['view' => $viewAbility, 'share' => $shareAbility];
    }

    /**
     * Abilities of a list, or null when the key is unknown.
     *
     * @param  string  $listKey  List key
     * @return array{view: string, share: ?string}|null
     */
    public function resolve(string $listKey): ?array
    {
        if (isset($this->lists[$listKey])) {
            return $this->lists[$listKey];
        }

        if (!$this->resources->has($listKey)) {
            return null;
        }

        $resource = $this->resources->make($listKey);

        return ['view' => $resource->ability(), 'share' => $resource->form()?->getAbility()];
    }
}
