<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use App\Models\User;
use App\Events\SendEmail;
use App\Mail\SendMail;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SendEmailHandle implements ShouldQueue
{
    use InteractsWithQueue, Queueable;


    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(protected EmailLogInterface $log)
    {
    }

    /**
     * Handle the event.
     *
     * @param SendEmail $event
     */
    public function handle(SendEmail $event): void
    {
        $mail = $event->data;
        $mail['subject'] = $event->data['subject'] ?? __('mgs.mail.contact-reply-subject');
        $mail['brand'] = config('app.name', env('APP_NAME'));
        [$targetType, $targetId] = $this->resolveEmailTarget($mail);

        //  Process to save Mail logs for live view
        $model = $this->log->model();
        $model->uuid = $this->log->checkUnique('uuid', Str::uuid());
        $model->type = 'send-email';
        $model->subject = $mail['subject'];
        $model->to = $mail['email'];
        $model->blade = 'mail.' . $model->type;
        $model->content = $mail;
        $model->target_type = $targetType;
        $model->target_id = $targetId;
        $model->user_id = data_get($mail, 'user_id') ?: $model->user_id;
        //  Make Live HREF
        $mail['uuid'] = $model->uuid;

        //  Process to remove password on the mail (for security)
        $model->save();

        Mail::to($model->to)->send(new SendMail($mail, $mail['subject'], $model->blade));
    }

    /**
     * Resolve valid target type/id for email log.
     *
     * Prioritize QA post relation first, then fallback to the current user.
     *
     * @param array $mail
     * @return array{0:?string,1:?int}
     */
    protected function resolveEmailTarget(array $mail): array
    {
        $targetType = data_get($mail, 'target_type');
        $targetId = data_get($mail, 'target_id');

        if (!$this->isValidTargetPair($targetType, $targetId)) {
            $targetType = data_get($mail, 'qa_post_type');
            $targetId = data_get($mail, 'qa_post_id');
        }

        if (!$this->isValidTargetPair($targetType, $targetId)) {
            $userId = data_get($mail, 'user_id') ?: user()?->id;

            if (!is_numeric((string) $userId) || (int) $userId < 1) {
                $userId = user()?->id;
            }

            return [User::class, (int) $userId];
        }

        return [$targetType, (int) $targetId];
    }

    /**
     * @param mixed $targetType
     * @param mixed $targetId
     * @return bool
     */
    protected function isValidTargetPair(mixed $targetType, mixed $targetId): bool
    {
        if (!is_string($targetType) || $targetType === '' || !class_exists($targetType)) {
            return false;
        }

        if (!is_subclass_of($targetType, Model::class)) {
            return false;
        }

        if (!is_numeric((string) $targetId) || (int) $targetId < 1) {
            return false;
        }

        /** @var class-string<Model> $targetType */
        return (bool) $targetType::find((int) $targetId);
    }
}
