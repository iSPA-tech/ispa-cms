<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use App\Models\Contact;
use App\Mail\SendMail;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;
use Illuminate\Support\Str;
use Illuminate\Bus\Queueable;
use Illuminate\Support\Facades\Mail;
use App\Events\SendConfirmToContactor;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Contracts\Queue\ShouldQueue;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;

class SendConfirmToContactorHandle implements ShouldQueue
{
    use InteractsWithQueue, Queueable;

    protected EmailLogInterface $log;

    /**
     * Create the event listener.
     *
     * @return void
     */
    public function __construct(EmailLogInterface $log)
    {
        $this->log = $log;
    }

    /**
     * Handle the event.
     *
     * @param SendConfirmToContactor $event
     */
    public function handle(SendConfirmToContactor $event): void
    {
        $mail = [
            'subject' => __('mgs.mail.contact-received-subject'),
            'brand' => iSeo('brand'),
            'subTitle' => __('mgs.mail.contact-received-subject'),
            'email' => $event->data,
        ];

        //  Process to save Mail logs for live view
        $model = $this->log->model();
        $model->uuid = $this->log->checkUnique('uuid', Str::uuid());
        $model->type = 'customer-contact';
        $model->subject = $mail['subject'];
        $model->to = $event->data;
        $model->blade = 'mail.' . $model->type;
        $model->content = $mail;
        [$targetType, $targetId] = $this->resolveLogTarget($event);
        $model->target_type = $targetType;
        $model->target_id = $targetId;
        //  Make Live HREF
        $mail['uuid'] = $model->uuid;

        //  Process to remove password on the mail (for security)
        $model->save();

        Mail::to($model->to)->send(new SendMail($mail, $mail['subject'], $model->blade));
    }

    /**
     * @return array{0:string,1:int}
     */
    protected function resolveLogTarget(SendConfirmToContactor $event): array
    {
        if ($this->isValidTargetPair($event->targetType, $event->targetId)) {
            return [$event->targetType, (int) $event->targetId];
        }

        $contactId = Contact::query()
            ->withTrashed()
            ->where('email', $event->data)
            ->latest('id')
            ->value('id');

        if (is_numeric((string) $contactId) && (int) $contactId > 0) {
            return [Contact::class, (int) $contactId];
        }

        throw new RuntimeException('Unable to resolve contact target for customer contact email log.');
    }

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
        return (bool) $targetType::query()->find((int) $targetId);
    }
}
