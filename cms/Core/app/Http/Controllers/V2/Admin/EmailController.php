<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Responses\AppResponse;

use Exception;
use RuntimeException;
use Throwable;
use App\Models\Contact;
use App\Enums\UserStatusEnum;
use App\Events\SendEmail;
use App\Services\ContactAdminReadService;
use App\Services\SpamEmailBlocklistService;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Cms\Core\Interfaces\EmailInterface;
use Cms\Core\Http\Requests\Email\ReplyEmailRequest;
use Cms\Core\Http\Requests\Email\StoreEmailRequest;
use Cms\Core\Http\Requests\Email\UpdateEmailRequest;
use Cms\Core\Http\Requests\Email\MarkEmailSpamRequest;
use App\Repositories\Interfaces\ContactInterface;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\Email\MultiDeleteEmailRequest;

/**
 * CRUD operations for email messages in the admin panel.
 *
 * Manages inbox, sent, and unread emails with support for composing,
 * replying, and bulk deletion. Extends AdminController for shared
 * admin authentication and authorization logic.
 *
 * @see EmailInterface
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  malayvuong
 * @since   1.0.0
 */
class EmailController extends AdminController
{
    /**
     * Controller constructor
     *
     * @param EmailInterface $model
     * @param ContactInterface $contact
     *
     * @author malayvuong
     * @since 1.0.0
     */
    public function __construct(
        protected EmailInterface $model,
        protected ContactInterface $contact,
        protected SpamEmailBlocklistService $spamEmailBlocks,
    )
    {
        parent::__construct();
    }

    /**
     * Return email counters (unread, all, sent, total contacts).
     *
     * @return mixed  Email count summary
     */
    public function counter(): mixed
    {
        $unread = $this->model->model()->where([
            'type' => 'inbox', //EmailType::Inbox,
            'last_read' => null,
        ])->count();

        $all = $this->model->model()->where([
        ])->count();

        $sent = $this->model->model()->where([
            'type' => 'sent', //EmailType::Sent,
        ])->count();
        $totalContact = Contact::query()->count();

        return ires()->data([
            'unread' => $unread,
            'all' => $all,
            'sent' => $sent,
            'totalContact' => $totalContact,
        ]);
    }

    /**
     * Reply email
     *
     * @param  int $id
     * @param  ReplyEmailRequest $request
     * @return mixed
     * @author malayvuong
     * @since 6.62.6 - 2023-08-17, 16:16 ICT
     */
    public function reply(int $id, ReplyEmailRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $replyToMail = $this->model->find($id);

        if (!$replyToMail) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $newMail = $this->fill();
        $newMail->from_name = config('setting.email.MAIL_FROM_NAME');
        $newMail->from_email = config('setting.email.MAIL_FROM_ADDRESS');
        $newMail->reply_to = config('setting.email.MAIL_REPLY');
        $newMail->uuid = str()->uuid();

        if (!$newMail->save()) {
            return ires()->error()->message(__('mgs.er.create', ['name' => __('nav.a.emails')]));
        }

        //  Process to send email
        event(new SendEmail($newMail->toArray()));

        return ires();
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     **@author malayvuong
     */
    protected function fill($model = null): mixed
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        $model->cc = implode(',', request('cc', []));
        $model->bcc = implode(',', request('bcc', []));
        $model->raw_data = request()->all();
        $model->user_id = auth()->id();
        $model->type = 'sent'; //EmailType::Sent;

        if ((int)request('reply_id') < 1) {
            $model->reply_id = null;
        }

        return $model;
    }

    /**
     * Display a listing of the resource.
     *
     * @return $res
     * @author malayvuong
     */
    public function index(): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $query = trim((string) request('query', ''));

        $model = $this->model->get([
            'page' => request('limit', 20),
            'latest' => true,
            'with' => [
                'contact:id,name',
            ],
            'when' => [
                [
                    request('type', 'all') === 'inbox',
                    function ($q) {
                        $q->where([
                            'type' => 'inbox', //EmailType::Inbox,
                        ]);
                    },
                ],
                [
                    request('type', 'all') === 'sent',
                    function ($q) {
                        $q->where([
                            'type' => 'sent', //EmailType::Sent,
                        ]);
                    },
                ],
                [
                    request('type', 'all') === 'unread',
                    function ($q) {
                        $q->where([
                            'type' => 'inbox', //EmailType::Inbox,
                            'last_read' => null,
                        ]);
                    },
                ],
                [
                    $query !== '',
                    function ($q) use ($query): void {
                        $needle = '%' . mb_strtolower($query) . '%';

                        $q->where(function ($search) use ($needle): void {
                            $search
                                ->whereRaw('LOWER(subject) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(email) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(from_name) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(from_email) LIKE ?', [$needle])
                                ->orWhereRaw('LOWER(content) LIKE ?', [$needle]);
                        });
                    },
                ],
            ],
        ]);

        //  Strip tag content
        foreach ($model as $item) {
            $item->content = strip_tags($item->content);
        }

        return ires()->data($model);
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreEmailRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function store(StoreEmailRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            //  Check contact & save
            $check = $this->contact->findBy(['email' => request('email')], ['id']);

            if (!$check) {
                //  Create new contact
                $check = $this->contact->reset()->model();
                $check->email = request('email');
                $check->save();
                $check->fresh();
            }
            //  Process to save
            $model->contact_id = $check->id;
            $model->save();

            //  Queue to send Email
            event(new SendEmail(request()->all()));
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.emails')]));
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return $res
     * @author malayvuong
     */
    public function show(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid], ['*'], ['contact']);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        //  Update last read time
        $model->last_read = now();
        $model->save();

        return ires()->data($model);
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
     * @param int $id
     * @param UpdateEmailRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function update(int $id, UpdateEmailRequest $request): mixed
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

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.emails')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.emails')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param string $uuid
     *
     * @return $res
     * @author malayvuong
     */
    public function destroy(string $uuid): mixed
    {
        if (empty($uuid)) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->findBy(['uuid' => $uuid]);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.emails')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.emails')]));
    }

    /**
     * Block senders of inbox emails and remove the selected emails from the mailbox.
     */
    public function markSpam(MarkEmailSpamRequest $request): mixed
    {
        $uuids = $request->validated('uuids');
        $mails = $this->model->model()
            ->newQuery()
            ->with('contact:id,email')
            ->where('type', 'inbox')
            ->whereIn('uuid', $uuids)
            ->get();

        if ($mails->count() !== count($uuids)) {
            return ires()->error()->code(404)->message(__('mgs.model.404'));
        }

        $senderEmails = $mails
            ->map(fn ($mail) => $mail->from_email ?: $mail->email ?: $mail->contact?->email);

        if ($senderEmails->contains(fn ($email) => !is_string($email) || trim($email) === '')) {
            return ires()->error()->code(422)->message(__('mgs.form.error'));
        }

        $senderEmails = $senderEmails
            ->map(fn (string $email) => mb_strtolower(trim($email)))
            ->unique()
            ->values()
            ->all();
        $matchingMails = $this->model->model()
            ->newQuery()
            ->where('type', 'inbox')
            ->where(function ($q) use ($senderEmails): void {
                $q->whereIn(DB::raw('LOWER(from_email)'), $senderEmails)
                    ->orWhereIn(DB::raw('LOWER(email)'), $senderEmails)
                    ->orWhereHas(
                        'contact',
                        fn ($contact) => $contact->whereIn(DB::raw('LOWER(email)'), $senderEmails),
                    );
            })
            ->get();
        $matchingContactIds = $matchingMails->pluck('contact_id')->filter()->values()->all();
        $matchingContacts = Contact::query()
            ->where(function ($query) use ($matchingContactIds, $senderEmails): void {
                if ($matchingContactIds !== []) {
                    $query->whereIn('id', $matchingContactIds)
                        ->orWhereIn(DB::raw('LOWER(email)'), $senderEmails);

                    return;
                }

                $query->whereIn(DB::raw('LOWER(email)'), $senderEmails);
            })
            ->get();

        $user = user();
        if (
            !$user ||
            $matchingMails->contains(fn ($mail) => !$user->can('delete', $mail)) ||
            $matchingContacts->contains(fn (Contact $contact) => !$user->can('update', $contact))
        ) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $blockDomains = $request->boolean('block_domain');

        try {
            $marked = DB::transaction(function () use ($senderEmails, $matchingMails, $matchingContacts, $blockDomains): bool {
                if (!$this->spamEmailBlocks->block($senderEmails, $blockDomains)) {
                    return false;
                }

                foreach ($matchingContacts as $contact) {
                    $contact->status = UserStatusEnum::USER_BANNED->value;
                    $contact->is_subscribe = false;

                    if ($contact->isDirty() && !$contact->save()) {
                        throw new RuntimeException('Unable to block spam contact.');
                    }
                }

                foreach ($matchingMails as $mail) {
                    if (!$mail->delete()) {
                        throw new RuntimeException('Unable to remove spam email.');
                    }
                }

                return true;
            });
        } catch (Throwable) {
            Log::warning('Unable to mark emails as spam.');

            return ires()->error()->message(__('mgs.form.error'));
        }

        if (!$marked) {
            return ires()->error()->code(422)->message(__('mgs.form.error'));
        }

        Cache::put(
            ContactAdminReadService::DASHBOARD_CACHE_VERSION_KEY,
            (string) Str::uuid(),
            now()->addMinutes(10),
        );

        return ires();
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteEmailRequest $request
     *
     * @return $res
     * @author malayvuong
     */
    public function massDestroy(MultiDeleteEmailRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            $this->model->model()->whereIn('id', request('listId'))->delete();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.emails')]));
    }
}
