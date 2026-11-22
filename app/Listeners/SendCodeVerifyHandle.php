<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use App\Mail\SendMail;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Illuminate\Bus\Queueable;
use Cms\Core\Events\SendCodeVerify;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Traits\Localizable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;

class SendCodeVerifyHandle implements ShouldQueue
{
    use InteractsWithQueue, Localizable, Queueable;

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
     * @param SendCodeVerify $event
     */
    public function handle(SendCodeVerify $event): void
    {
        $locale = isset($event->locale) && is_string($event->locale) && $event->locale !== ''
            ? $event->locale
            : config('app.locale');

        $this->withLocale($locale, function () use ($event, $locale): void {
            $mail = [
                'subject' => '[' . iSeo('brand') . '] ' . __('mgs.code.' . $event->type),
                'brand' => iSeo('brand'),
                'subTitle' => __('mgs.code.' . $event->type),
                'code' => $event->code,
                'user' => $event->user,
                'locale' => $locale,
            ];

            //  Process to save Mail logs for live view
            $model = $this->log->model()->fill([
                'user_id' => $event->user?->id ?: null,
                'type' => $event->type,
                'uuid' => str()->uuid(),
                'subject' => $mail['subject'],
                'to' => $event->user?->email,
                'blade' => 'mail.' . $event->type,
                // The plain code is never written to the e-mail log.
                'content' => array_merge($mail, ['code' => '******']),
            ]);
            $model->uuid = $this->log->checkUnique('uuid', str()->uuid());
            [$targetType, $targetId] = $this->resolveLogTarget($event);
            $model->target_type = $targetType;
            $model->target_id = $targetId;
            //  Make Live HREF
            $mail['uuid'] = $model->uuid;

            //  Process to remove password on the mail (for security)
            $model->save();

            try {
                $mailable = (new SendMail($mail, $mail['subject'], $model->blade))->locale($locale);
                Mail::to($model->to)->send($mailable);
            } catch (\Throwable $e) {
                report($e);
                throw new RuntimeException('Không thể gửi mã xác thực qua email. Vui lòng kiểm tra lại dịch vụ gửi mail hoặc thử lại sau.', previous: $e);
            }
        });
    }

    /**
     * @return array{0:string,1:int}
     */
    protected function resolveLogTarget(SendCodeVerify $event): array
    {
        $user = $event->user;

        if ($user instanceof Model && $user->exists) {
            $targetId = $user->getKey();

            if (is_numeric((string) $targetId) && (int) $targetId > 0) {
                return [$user::class, (int) $targetId];
            }
        }

        throw new RuntimeException('Unable to resolve verification target for email log.');
    }
}
