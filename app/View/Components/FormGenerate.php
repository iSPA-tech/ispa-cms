<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Illuminate\Contracts\View\Factory;
use Cms\Core\Interfaces\FieldInterface;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\Str;

class FormGenerate extends Component
{
    protected FieldInterface $model;

    public string $form;

    /** A trusted Blade-provided endpoint enables the dynamic, reviewed flow. */
    public ?string $actionUrl;

    /** Stable for this rendered form; the endpoint uses it to deduplicate retries. */
    public ?string $submissionToken;

    /**
     * Create a new component instance.
     *
     * @return void
     */
    public function __construct(FieldInterface $model, string $form = 'contact', ?string $actionUrl = null, ?string $submissionToken = null)
    {
        $this->model = $model;
        $this->form = $form;
        $this->actionUrl = $actionUrl;
        $this->submissionToken = $actionUrl ? ($submissionToken ?: (string) Str::uuid()) : $submissionToken;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Factory|Application
     */
    public function render(): View|Factory|Application
    {
        if ($this->actionUrl) {
            // Published admission forms must reflect admin edits and status immediately.
            $fields = $this->model->findBy(['form_id' => $this->form], ['id', 'name', 'form_id', 'custom', 'styles', 'status'], ['items']);

            return view('components.form-generate', compact('fields'));
        }

        $cacheKey = make_key(['type' => 'form', 'form_id' => $this->form]);

        $fields = cache()->remember($cacheKey, config('setting.cache.form', 3600), function () use ($cacheKey) {
            //  Todo: change this query here fill about expires_from and expires_to
            return $this->model->findBy(['form_id' => $this->form], ['id', 'name', 'form_id', 'custom', 'styles', 'status'], [
                'items',
            ]);
        });

        if ($fields && app(\Cms\Core\Services\Forms\FormOptionResolver::class)->hasSources($fields)) {
            $this->actionUrl = route('ajax.contact-request');
            return view('components.form-generate', ['fields' => $fields, 'isContactForm' => true]);
        }

        return view('components.form-generate', compact('fields'));
    }
}
