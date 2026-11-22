<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use Illuminate\Mail\SentMessage;
use App\Events\ResendEmailVerify;
use App\Mail\SendMail;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;

class ResendEmailVerifyHandler implements ShouldQueue
{
    public EmailLogInterface $logs;

    /**
     * Create the event listener.
     *
     * @param EmailLogInterface $logs
     */
    public function __construct(EmailLogInterface $logs)
    {
        $this->logs = $logs;
    }

    /**
     * Handle the event.
     *
     * @param  ResendEmailVerify  $event
     *
     * @return SentMessage|null
     */
    public function handle(ResendEmailVerify $event): ?SentMessage
    {
        $mail = [
            'subject' => '['.config('app.name', env('APP_NAME')).'] Verify email address',
            'brand' => config('app.name', env('APP_NAME')),
            'subTitle' => 'Account Activation',
            'url' => $event->url,
        ];

        $event->user->load('antiPhishing');
        if ($event->user->antiPhishing) {
            $mail['antiPhishingCode'] = $event->user->antiPhishing->value;
        }

        //  Process to save Mail logs for live view
        $model = $this->logs->model();
        $model->uuid = $this->logs->checkUnique('uuid', Str::uuid());
        $model->type = 'verify-account';
        $model->subject = $mail['subject'];
        $model->to = $event->user->email;
        $model->blade = 'mail.verify-email';
        $model->user_id = $event->user->id;
        $model->content = $mail;
        //  Make Live HREF
        $mail['uuid'] = $model->uuid;

        //  Process to remove password on the mail (for security)
        $model->save();

        return Mail::to($model->to)->send(new SendMail($mail, $mail['subject'], $model->blade));
    }
}
