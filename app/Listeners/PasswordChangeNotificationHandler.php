<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use Cms\Core\Events\PasswordChangeNotification;
use App\Mail\SendMail;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Jenssegers\Agent\Agent;

class PasswordChangeNotificationHandler implements ShouldQueue
{
    public $logs;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(EmailLogInterface $logs)
    {
        $this->logs = $logs;
    }

    /**
     * Handle the event.
     *
     * @param  PasswordChangeNotification  $event
     * @return void
     */
    public function handle(PasswordChangeNotification $event): void
    {
        $agent = new Agent();
        $agent->setUserAgent($event->agent);

        $mail = [
            'subject' => __('mgs.mail.password-changed-subject'),
            'brand' => \App\Support\Mail\MailBrand::name(),
            'subTitle' => __('mgs.mail.password-changed-subject'),
            'platform' => $agent->platform().' '.$agent->version($agent->platform()),
            'device' => $agent->device().' - '.$agent->browser().' '.$agent->version($agent->browser()),
            'time' => now()->setTimezone(\App\Support\Mail\MailBrand::timezone())->format('d/m/Y H:i'),
            'ipAddress' => $event->ip,
            'support' => config('mail.reply'),
        ];

//    $event->user->load('antiPhishing');
//    if ($event->user->antiPhishing) {
//      $mail['antiPhishingCode'] = $event->user->antiPhishing->value;
//    }

        //  Process to save Mail logs for live view
        $model = $this->logs->model();
        $model->uuid = $this->logs->checkUnique('uuid', Str::uuid());
        $model->type = 'password-change';
        $model->subject = $mail['subject'];
        $model->to = $event->user->email;
        $model->blade = 'mail.password-change';
        $model->user_id = $event->user->id;
        $model->target_type = \App\Models\User::class;
        $model->target_id = $event->user->id;
        $model->content = $mail;
        //  Make Live HREF
        $mail['uuid'] = $model->uuid;

        //  Process to remove password on the mail (for security)
        $model->save();

        Mail::to($model->to)->send(new SendMail($mail, $mail['subject'], $model->blade));
    }
}
