<?php

declare(strict_types=1);

namespace Cms\Core\Services\DataScope;

use Closure;
use InvalidArgumentException;
use Nwidart\Modules\Contracts\RepositoryInterface;

/**
 * Registry of data-scope dimensions (organisation units, categories, ...) contributed by modules.
 *
 * Each dimension is owned by a module and exposes a HierarchyProvider (or a lazy factory for one).
 * Fail closed: unknown dimensions and dimensions of disabled modules have no provider.
 */
class DimensionRegistry
{
    /**
     * @var array<string, array{module: string, label_key: string, provider: HierarchyProvider|Closure}>
     */
    protected array $dimensions = [];

    /**
     * @param RepositoryInterface $modules Module repository used for the enabled check.
     */
    public function __construct(protected RepositoryInterface $modules)
    {
    }

    /**
     * Register a dimension.
     *
     * @param string $name Dimension name, /^[a-z][a-z0-9_]{1,63}$/.
     * @param string $module Owning module name.
     * @param HierarchyProvider|Closure $provider Provider, or a Closure returning one (resolved lazily once).
     * @param string $labelKey Translation key of the dimension label.
     * @throws InvalidArgumentException On a malformed or duplicate name.
     */
    public function register(string $name, string $module, HierarchyProvider|Closure $provider, string $labelKey): void
    {
        if (preg_match('/^[a-z][a-z0-9_]{1,63}$/', $name) !== 1) {
            throw new InvalidArgumentException("Invalid data-scope dimension name [{$name}].");
        }

        if (isset($this->dimensions[$name])) {
            throw new InvalidArgumentException("Data-scope dimension [{$name}] is already registered.");
        }

        $this->dimensions[$name] = ['module' => $module, 'label_key' => $labelKey, 'provider' => $provider];
    }

    /**
     * Whether a dimension with this name is registered (regardless of module state).
     */
    public function has(string $name): bool
    {
        return isset($this->dimensions[$name]);
    }

    /**
     * Metadata of every registered dimension, without resolving providers.
     *
     * @return array<string, array{module: string, label_key: string}>
     */
    public function all(): array
    {
        $all = [];

        foreach ($this->dimensions as $name => $entry) {
            $all[$name] = ['module' => $entry['module'], 'label_key' => $entry['label_key']];
        }

        return $all;
    }

    /**
     * Provider of a dimension; null when unknown, when the owning module is missing or disabled, or when a lazy
     * factory does not return a HierarchyProvider (fail closed).
     */
    public function provider(string $name): ?HierarchyProvider
    {
        if (!isset($this->dimensions[$name])) {
            return null;
        }

        $entry = $this->dimensions[$name];
        $module = $this->modules->find($entry['module']);

        if ($module === null || !$module->isEnabled()) {
            return null;
        }

        $provider = $entry['provider'];

        if ($provider instanceof Closure) {
            $provider = $provider();
            // Memoise the resolved value (null-safe: an invalid result stays invalid and fails closed).
            $this->dimensions[$name]['provider'] = $provider instanceof HierarchyProvider ? $provider : static fn () => null;
        }

        return $provider instanceof HierarchyProvider ? $provider : null;
    }
}
