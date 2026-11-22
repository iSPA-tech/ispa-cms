<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use JsonException;
use App\Events\FetchSpecies;
use App\Clients\SpeciesClient;
use GuzzleHttp\Exception\GuzzleException;
use Cms\Core\Interfaces\EnumerateInterface;
use App\Repositories\Interfaces\SpeciesInterface;
use App\Repositories\Interfaces\SpeciesTaxonomicInterface;

class FetchSpeciesHandle
{
    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(SpeciesInterface $species, SpeciesClient $client, EnumerateInterface $enum, SpeciesTaxonomicInterface $taxon)
    {
        $this->species = $species;
        $this->client = $client;
        $this->enum = $enum;
        $this->taxon = $taxon;
    }

    /**
     * Handle the event.
     *
     * @param FetchSpecies $event
     *
     * @return void
     *
     * @throws GuzzleException
     * @throws JsonException
     */
    public function handle(FetchSpecies $event): void
    {
    }
}
