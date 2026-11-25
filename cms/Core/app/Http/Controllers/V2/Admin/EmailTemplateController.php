<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;


use Illuminate\Support\Facades\Artisan;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Interfaces\EmailTemplateInterface;
use Cms\Core\Http\Requests\EmailTemplate\StoreEmailTemplateRequest;
use Cms\Core\Http\Requests\EmailTemplate\UpdateEmailTemplateRequest;
use Cms\Core\Http\Requests\EmailTemplate\MultiDeleteEmailTemplateRequest;
use Cms\Core\Http\Requests\EmailTemplate\PreviewEmailTemplateRequest;
use Cms\Core\Models\EmailTemplate;
use Cms\Core\Services\Mail\MailLayoutAllowlist;
use Cms\Core\Services\Mail\ManagedEmailTemplates;
use Cms\Core\Constants\CoreTableConstant;

/**
 * CRUD operations for email templates in the admin panel.
 *
 * Manages reusable email templates grouped by key and language, each with a mail class (`system` / `business`, owner
 * e-mail policy B 2026-11-25; only workflow `email_send` reads it). Also: catalog of a group by key, variables of a
 * template and a preview filled through SafeTemplateRenderer (routes in cms/Core/routes/api.php).
 * Automatically synchronizes templates to a backup SQL file after
 * each create or update. Extends AdminController.
 *
 * @see EmailTemplateInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class EmailTemplateController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param EmailTemplateInterface $model
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(protected EmailTemplateInterface $model)
    {
        parent::__construct();
    }

    /**
     * Display a listing of the resource.
     *
     * @param $res
     *
     * @return $res
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->model->get([
            'when' => [
                [
                    !empty(request('group', null)),
                    function ($q): void {
                        $q->where('group', request('group'));
                    },
                ],
                [
                    is_numeric(request('language_id')),
                    function ($q): void {
                        $q->where('language_id', (int) request('language_id'));
                    },
                ],
                [
                    in_array(request('mail_class'), EmailTemplate::MAIL_CLASSES, true),
                    function ($q): void {
                        $q->where('mail_class', request('mail_class'));
                    },
                ],
                [
                    is_string(request('key_name')) && request('key_name') !== '',
                    function ($q): void {
                        $q->where('key_name', request('key_name'));
                    },
                ],
            ],
            'latest' => true,
            'page' => request('limit', 20),
            'with' => [
                'language:id,name,flag',
            ],
        ]);

        return ires()->data($model);
    }

    /**
     * One template with its language and variables (`GET email-template/{id}`).
     *
     * @return mixed
     */
    public function show(int $id): mixed
    {
        $model = $id > 0 ? EmailTemplate::query()->with('language:id,name,code,flag')->find($id) : null;
        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }
        if (!user()?->can('view', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model->toArray() + ['variable_list' => app(ManagedEmailTemplates::class)->variables($model)]);
    }

    /**
     * Variables of one template: declared ones, then every placeholder its subject and body use, with sample values
     * (`GET email-template/{id}/variables`).
     *
     * @return mixed
     */
    public function variables(int $id): mixed
    {
        $model = $id > 0 ? EmailTemplate::query()->find($id) : null;
        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }
        if (!user()?->can('view', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data(app(ManagedEmailTemplates::class)->variables($model));
    }

    /**
     * Templates of one group by key, for pickers such as the workflow `email_send` node
     * (`GET email-template/catalog?group=workflow`): `[{key_name, mail_class, name, languages: [{id, language_id,
     * code, name}], variables: [..]}]`, the row in the site's default language first.
     *
     * @return mixed
     */
    public function catalog(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $group = is_string(request('group')) && request('group') !== '' ? (string) request('group') : EmailTemplate::GROUP_WORKFLOW;
        $templates = app(ManagedEmailTemplates::class);
        $out = [];
        foreach (EmailTemplate::query()->where('group', $group)->with('language:id,name,code')->orderBy('key_name')->orderBy('id')->get()->groupBy('key_name') as $key => $rows) {
            $main = $templates->find($group, (string) $key) ?? $rows->first();
            $out[] = [
                'key_name' => (string) $key,
                'mail_class' => (string) $main->mail_class,
                'name' => (string) $main->name,
                'languages' => $rows->map(fn (EmailTemplate $row): array => [
                    'id' => (int) $row->id,
                    'language_id' => $row->language_id,
                    'code' => $row->language?->code,
                    'name' => $row->language?->name,
                    'mail_class' => (string) $row->mail_class,
                ])->values()->all(),
                'variables' => $templates->variables($main),
            ];
        }

        return ires()->data($out);
    }

    /**
     * Preview a template filled with sample data (`POST email-template/preview`, or `POST email-template/{id}/preview`
     * for a saved row; `name`, `content` and `sample_data` in the body override the saved values so unsaved edits can be
     * previewed). Filled through SafeTemplateRenderer like a real send (values escaped, nothing compiled). Returns
     * `{subject, body, html, variables}`: `body` the filled fragment, `html` the whole mail in its frame (workflow
     * templates: the shared branded frame; others: their allowlisted `blade_layout`).
     *
     * @return mixed
     */
    public function preview(PreviewEmailTemplateRequest $request, ?int $id = null): mixed
    {
        if ($id !== null) {
            $model = $id > 0 ? EmailTemplate::query()->find($id) : null;
            if (!$model) {
                return ires()->error()->message(__('mgs.model.404'));
            }
            if (!user()?->can('view', $model)) {
                return ires()->error()->message(__('mgs.no.permission'));
            }
            $model = $model->replicate();
        } else {
            $model = new EmailTemplate(['group' => EmailTemplate::GROUP_WORKFLOW]);
            if (!user()?->can('create', $model)) {
                return ires()->error()->message(__('mgs.no.permission'));
            }
        }

        $model->fill(array_filter($request->only(['name', 'content', 'group', 'blade_layout']), static fn ($v): bool => $v !== null));
        if ($request->has('sample_data')) {
            $model->sample_data = (array) $request->input('sample_data', []);
        }

        $templates = app(ManagedEmailTemplates::class);
        $data = is_array($model->sample_data) ? $model->sample_data : [];
        ['subject' => $subject, 'html' => $body] = $templates->render($model, $data);

        $html = $model->group === EmailTemplate::GROUP_WORKFLOW
            ? view('mail.branded', ['title' => $subject, 'body' => $body])->render()
            : MailLayoutAllowlist::wrap($model->blade_layout, $body, $data + $templates->builtins());

        return ires()->data([
            'subject' => $subject,
            'body' => $body,
            'html' => $html,
            'variables' => $templates->variables($model),
        ]);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreEmailTemplateRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function store(StoreEmailTemplateRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if ($refusal = $this->systemClassRefusal($model)) {
            return $refusal;
        }

        //  Check if the group + key_name already exists
        $exists = $this->model->findBy([
            'group' => $model->group,
            'key_name' => $model->key_name,
        ]);
        if ($exists) {
            return ires()->error()->message(__('mgs.er.unique', ['name' => __('fields.key_name') . ' + ' . __('fields.group')]));
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.email_templates')]));
        }

        $this->synchronizeSettingFile();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.email_templates')]));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return $res
     * @author malayvuong
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param Integer $id
     * @param UpdateEmailTemplateRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function update(int $id, UpdateEmailTemplateRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        if ($refusal = $this->systemClassRefusal($model)) {
            return $refusal;
        }

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.email_templates')]));
        }

        $this->synchronizeSettingFile();

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.email_templates')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param Integer $id
     *
     * @return $res
     * @author malayvuong
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.email_templates')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.email_templates')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteEmailTemplateRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteEmailTemplateRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.email_templates')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return null|mixed $model
     *
     **@author malayvuong
     */
    /**
     * Refusal when a template would become `system` mail (a new system row, or a class change to system) and the user is
     * not a Webmaster; null otherwise. A row that already is `system` keeps its class when others edit its text.
     */
    private function systemClassRefusal(EmailTemplate $model): mixed
    {
        $becomesSystem = $model->mail_class === 'system' && (!$model->exists || $model->isDirty('mail_class'));
        if ($becomesSystem && !user()?->can('setSystemClass', $model)) {
            return ires()->error()->code(403)->errorCode('mail_class_system_webmaster_only')
                ->message('Only a Webmaster can make a template system mail (sent by workflows with no approval).');
        }

        return null;
    }

    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        //  Make description from content by stripping HTML tags and limit to 200 characters
        $description = strip_tags($model->content);
        $model->description = strlen($description) > 200 ? substr($description, 0, 197) . '...' : $description;

        return $model;
    }

    /**
     * Sync template sql for backup
     *
     * @author malayvuong
     * @since 7.160.1175 - 2025-08-27, 11:03 ICT
     */

    protected function synchronizeSettingFile(): void
    {
        Artisan::call('cms:backup-email-template');

        //  Call command to export db for seeder
        Artisan::call('cms:export-db ' . CoreTableConstant::EMAIL_TEMPLATES);
    }
}
