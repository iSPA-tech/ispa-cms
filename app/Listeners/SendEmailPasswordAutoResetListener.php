<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use App\Mail\SendMail;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Mail;
use Cms\Core\Events\SendEmailPasswordAutoResetEvent;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;

class SendEmailPasswordAutoResetListener implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    /**
     * Create the event listener.
     */
    public function __construct(protected EmailLogInterface $log)
    {
        $this->afterCommit = true;
    }

    /**
     * Handle the event.
     */
    public function handle(SendEmailPasswordAutoResetEvent $event): void
    {
        $mail = $event->data;
        // A new password is a password reset: the date-time keeps each such mail its own inbox thread.
        $mail['subject'] = $event->data['subject'] ?? \App\Support\Mail\MailBrand::stamped(__('mgs.mail.auto-password-subject', ['email' => $event->data['email']]));
        $mail['brand'] = \App\Support\Mail\MailBrand::name();

        //  Process to save Mail logs for live view
        $model = $this->log->model();
        $model->uuid = $this->log->checkUnique('uuid', Str::uuid());
        $model->type = 'auto-reset-password';
        $model->subject = $mail['subject'];
        $model->to = $mail['email'];
        $model->blade = 'mail.' . $model->type;
        $model->target_type = \App\Models\User::class;
        $model->target_id = $mail['user_id'] ?? null;
        $model->content = $mail;
        //  Make Live HREF
        $mail['uuid'] = $model->uuid;

        //  Process to remove password on the mail (for security)
        $model->save();

        Mail::to($model->to)->send(new SendMail($mail, $mail['subject'], $model->blade));
    }
}
