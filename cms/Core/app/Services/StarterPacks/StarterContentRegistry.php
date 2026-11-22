<?php

namespace Cms\Core\Services\StarterPacks;

use Cms\Core\Contracts\StarterContentImporter;

/**
 * Registry of the starter content importers that modules provide.
 */
class StarterContentRegistry
{
    /** @var array<string, StarterContentImporter> */
    private array $importers = [];

    /**
     * Register an importer under its id.
     *
     * @throws StarterPackException when the id is already registered.
     */
    public function register(StarterContentImporter $importer): void
    {
        $id = $importer->id();
        if (isset($this->importers[$id])) {
            throw StarterPackException::invalidPack($id, 'a starter content importer with this id is already registered');
        }

        $this->importers[$id] = $importer;
    }

    /**
     * Whether an importer with this id is registered.
     */
    public function has(string $id): bool
    {
        return isset($this->importers[$id]);
    }

    /**
     * Get an importer by id.
     *
     * @throws StarterPackException when the id is unknown.
     */
    public function get(string $id): StarterContentImporter
    {
        return $this->importers[$id]
            ?? throw StarterPackException::invalidPack($id, 'no starter content importer is registered with this id');
    }

    /**
     * Registered importer ids, sorted.
     *
     * @return list<string>
     */
    public function ids(): array
    {
        $ids = array_keys($this->importers);
        sort($ids);

        return $ids;
    }
}
