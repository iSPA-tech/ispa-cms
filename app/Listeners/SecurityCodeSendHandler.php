<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use App\Events\SecurityCodeSend;
use App\Mail\SendMail;
use App\Repositories\Interfaces\MailLogsInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class SecurityCodeSendHandler implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    public $logs;

    /**
     * Create the event listener.
     *
     * @param  MailLogsInterface  $logs
     * @return void
     */
    public function __construct(MailLogsInterface $logs)
    {
        $this->logs = $logs;
    }

    /**
     * Handle the event.
     *
     * @param  SecurityCodeSend  $event
     * @return void
     */
    public function handle(SecurityCodeSend $event)
    {
        $mail = [
            'subject' => __('email.subject.settingCode', ['brand' => config('app.name', env('APP_NAME'))]),
            'brand' => config('app.name', env('APP_NAME')),
            'subTitle' => __('email.sub.settingCode'),
            'code' => $event->code,
        ];

        $event->user->load('antiPhishing');
        if ($event->user->antiPhishing) {
            $mail['antiPhishingCode'] = $event->user->antiPhishing->value;
        }

        //  Process to save Mail logs for live view
        $model = $this->logs->model();
        $model->uuid = $this->logs->checkUnique($model, 'uuid', Str::uuid());
        $model->type = 'password.recover';
        $model->subject = $mail['subject'];
        $model->to = $event->user->email;
        $model->blade = 'mail.password-recover';
        $model->user_id = $event->user->id;
        $model->content = $mail;
        //  Make Live HREF
        $mail['uuid'] = $model->uuid;

        //  Process to remove password on the mail (for security)
        $model->save();

        return Mail::to($model->to)->send(new SendMail($mail, $mail['subject'], $model->blade));
    }
}
