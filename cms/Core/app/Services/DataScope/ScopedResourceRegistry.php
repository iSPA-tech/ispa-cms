<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use InvalidArgumentException;

/**
 * Registry of data-scoped resources, keyed by resource key and looked up by model class.
 *
 * Bound as a singleton; modules register their resources while booting.
 */
class ScopedResourceRegistry
{
    /**
     * @var array<string, ScopedResource>
     */
    protected array $resources = [];

    /**
     * Register a resource.
     *
     * @throws InvalidArgumentException When the key is already registered.
     */
    public function register(ScopedResource $resource): void
    {
        $key = $resource->key();

        if (isset($this->resources[$key])) {
            throw new InvalidArgumentException("Scoped resource [{$key}] is already registered.");
        }

        $this->resources[$key] = $resource;
    }

    /**
     * Whether a resource with this key is registered.
     */
    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }

    /**
     * The resource with this key.
     *
     * @throws InvalidArgumentException When the key is unknown.
     */
    public function get(string $key): ScopedResource
    {
        if (! isset($this->resources[$key])) {
            throw new InvalidArgumentException("Unknown scoped resource [{$key}].");
        }

        return $this->resources[$key];
    }

    /**
     * The first registered resource whose model is exactly this class, or null when none is.
     *
     * @param class-string $class
     */
    public function forModel(string $class): ?ScopedResource
    {
        $class = ltrim($class, '\\');

        foreach ($this->resources as $resource) {
            if ($resource->model() === $class) {
                return $resource;
            }
        }

        return null;
    }

    /**
     * Every registered resource whose model is exactly this class, in registration order (empty when none is).
     *
     * @param class-string $class
     * @return list<ScopedResource>
     */
    public function allForModel(string $class): array
    {
        $class = ltrim($class, '\\');

        return array_values(array_filter(
            $this->resources,
            static fn (ScopedResource $resource): bool => $resource->model() === $class
        ));
    }
}
