<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use App\Clients\MabyClient;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class SearchModal extends Component
{
    protected MabyClient $client;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(MabyClient $client)
    {
        $this->client = $client;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return Application|Factory|View
     *
     * @throws \JsonException
     */
    public function render(): View|Factory|Application
    {
        //  Todo: change type to popular here
        $services = $this->client->getServicesList();

        return view('components.search-modal', ['services' => ($services->content ?? [])]);
    }
}
