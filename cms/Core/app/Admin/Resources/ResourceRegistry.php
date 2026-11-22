<?php

declare(strict_types=1);

namespace Cms\Core\Admin\Resources;

use Illuminate\Contracts\Container\Container;
use InvalidArgumentException;

/**
 * Registry of declarative admin resources, keyed by `<module>/<resource>`.
 *
 * Module service providers register their resource classes here; a disabled
 * module's provider does not boot, so its resources never appear.
 */
final class ResourceRegistry
{
    /** @var array<string, class-string<Resource>> */
    private array $resources = [];

    /** Create the registry with the container used to build resources. */
    public function __construct(private readonly Container $container) {}

    /**
     * Register a resource class under a key.
     *
     * @param  class-string<Resource>  $class
     *
     * @throws InvalidArgumentException when the key is taken or the class is not a Resource
     */
    public function register(string $key, string $class): void
    {
        if (isset($this->resources[$key])) {
            throw new InvalidArgumentException("Resource key [{$key}] is already registered.");
        }
        if (! is_subclass_of($class, Resource::class)) {
            throw new InvalidArgumentException("Class [{$class}] must extend ".Resource::class.'.');
        }

        $this->resources[$key] = $class;
    }

    /** Whether a resource is registered under the key. */
    public function has(string $key): bool
    {
        return isset($this->resources[$key]);
    }

    /**
     * Build the resource registered under the key through the container.
     *
     * @throws InvalidArgumentException when the key is unknown
     */
    public function make(string $key): Resource
    {
        if (! $this->has($key)) {
            throw new InvalidArgumentException("Unknown resource [{$key}].");
        }

        return $this->container->make($this->resources[$key]);
    }

    /**
     * Registered resource keys, in registration order.
     *
     * @return array<int, string>
     */
    public function keys(): array
    {
        return array_keys($this->resources);
    }
}
