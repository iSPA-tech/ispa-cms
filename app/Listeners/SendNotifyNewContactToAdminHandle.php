<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Listeners;

use App\Events\SendConfirmToContactor;
use App\Mail\SendMail;
use App\Models\Contact;
use App\Support\ContactIntakeBridge;
use Cms\Core\Models\Setting;
use Cms\Core\Repositories\Interfaces\EmailLogInterface;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use RuntimeException;

class SendNotifyNewContactToAdminHandle implements ShouldQueue
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
     * @param  SendConfirmToContactor  $event
     */
    public function handle(SendConfirmToContactor $event): void
    {
        // A site connected to MailHub keeps every submission in its Webmail inbox, so the per-submission admin e-mail is left out.
        // Not connected, or no mailbox receiving contacts: the e-mail stays, it is then the only signal.
        if (ContactIntakeBridge::readableInWebmail()) {
            return;
        }

        $brand = iSeo('brand') ?: config('setting.brand');
        $mail = [
            'subject' => __('mgs.mail.new-contact-subject'),
            'brand' => $brand,
            'subTitle' => __('mgs.mail.new-contact-subject'),
            'email' => $event->data,
        ];

        //  Process to save Mail logs for live view
        $model = $this->log->model();
        $model->uuid = $this->log->checkUnique('uuid', Str::uuid());
        $model->type = 'notify';
        $model->subject = $mail['subject'];
        $model->to = $this->recipientEmail($event->context);
        $model->blade = 'mail.'.$model->type;
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
     * Resolve the admin recipient for the given submission context.
     *
     * Routes to a per-source setting (contact.notify_contact_email /
     * contact.notify_feedback_email) and falls back to the global admin email
     * so existing deployments keep working when the new settings are empty.
     */
    protected function recipientEmail(string $context = 'contact'): string
    {
        // Read the per-source recipient straight from the settings table so it is
        // reliable regardless of config/setting.php regeneration or runtime cache
        // state (notifications are queued/low-frequency). Falls back to the global
        // admin email so existing deployments keep working when it is empty.
        $name = $context === 'feedback' ? 'notify_feedback_email' : 'notify_contact_email';

        $candidates = [
            $this->settingValue('contact.' . $name),
            config('setting.contact.admin_email'),
            config('setting.admin_email'),
        ];

        foreach ($candidates as $value) {
            if (is_string($value) && trim($value) !== '') {
                return trim($value);
            }
        }

        return '';
    }

    private function settingValue(string $settingKey): ?string
    {
        try {
            // first()->value applies the model cast (decrypts when needed),
            // unlike ->value('value') which returns the raw column.
            $value = Setting::query()->where('setting_key', $settingKey)->first()?->value;

            return is_string($value) && trim($value) !== '' ? $value : null;
        } catch (\Throwable) {
            return null;
        }
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

        throw new RuntimeException('Unable to resolve contact target for admin notification email log.');
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
