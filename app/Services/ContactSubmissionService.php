<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Services;

use Throwable;
use App\Models\Contact;
use App\Enums\UserStatusEnum;
use App\Events\PusherContactCreate;
use App\Events\SendConfirmToContactor;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Interfaces\EmailInterface;
use App\Repositories\Interfaces\ContactInterface;

class ContactSubmissionService
{
    public function __construct(
        protected ContactInterface $contacts,
        protected EmailInterface $emails,
        protected SpamEmailBlocklistService $spamEmailBlocks,
    )
    {
    }

    /**
     * Persist a public contact submission into contacts + inbox emails.
     *
     * Optional `$data['is_subscribe']` (bool): newsletter opt-in for a newly created contact only. Without it every
     * saved contact is marked subscribed, as before.
     *
     * @throws Throwable
     */
    public function submit(array $data, string $context = 'contact'): array
    {
        if ($this->spamEmailBlocks->isBlocked($data['email'] ?? null)) {
            return ['blocked' => true];
        }

        DB::beginTransaction();

        try {
            $email = $data['email'] ?? null;
            $mobile = $data['phone_number'] ?? null;

            $contact = null;
            if (!empty($email) || !empty($mobile)) {
                $query = $this->contacts->model()->newQuery();

                if (!empty($email)) {
                    $query->where('email', $email);
                }

                if (!empty($mobile)) {
                    if (!empty($email)) {
                        $query->orWhere('mobile', $mobile);
                    } else {
                        $query->where('mobile', $mobile);
                    }
                }

                $contact = $query->first();
            }

            if (!$contact) {
                $contact = $this->contacts->model();
            }

            $contact->organization = $data['company'] ?? null;
            $contact->email = $email;
            $contact->mobile = $mobile;
            $contact->name = $data['name'];
            // `is_subscribe`, when given, decides only for a new contact; an existing contact keeps its choice.
            // Callers that do not pass it keep the old behaviour (always subscribed).
            if (! array_key_exists('is_subscribe', $data)) {
                $contact->is_subscribe = true;
            } elseif (! $contact->exists) {
                $contact->is_subscribe = (bool) $data['is_subscribe'];
            }
            $contact->source = $data['source'] ?? 'contact';
            $contact->type = $data['type'] ?? 'visitor';
            $contact->url = $data['page_url'] ?? request()->header('Referer');
            $contact->status = $this->resolveContactStatus();

            if (auth()->check()) {
                $contact->user_id = user('id');
            }

            $contact->save();

            $inbox = $this->emails->model();
            $inbox->raw_data = $data;
            $inbox->content = $data['message'];
            $inbox->from_name = $contact->name;
            $inbox->from_email = $contact->email;
            $inbox->reply_to = $contact->email;
            $inbox->subject = $data['subject'] ?? ('New Contact from ' . $contact->name);
            $inbox->contact_id = $contact->id;
            $inbox->type = 'inbox';
            $inbox->uuid = (string) str()->uuid();

            if (auth()->check()) {
                $inbox->user_id = user('id');
            }

            $inbox->save();

            DB::commit();
        } catch (Throwable $exception) {
            DB::rollBack();

            throw $exception;
        }

        // Mirror into the Webmail inbox (no-op when the module is absent/disabled; never throws).
        \App\Support\ContactIntakeBridge::record([
            'name' => (string) $contact->name,
            'email' => (string) $contact->email,
            'phone' => $contact->mobile,
            'subject' => $data['subject'] ?? null,
            'message' => (string) ($data['message'] ?? ''),
            'page_url' => $contact->url,
            'meta' => ['source' => $data['source'] ?? 'contact', 'context' => $context, 'legacy_email_id' => $inbox->id, 'submission_key' => $inbox->uuid],
        ], $contact);

        event(new PusherContactCreate());

        if (
            !empty($contact->email) &&
            is_string($contact->email) &&
            filter_var($contact->email, FILTER_VALIDATE_EMAIL)
        ) {
            event(new SendConfirmToContactor($contact->email, Contact::class, $contact->id, $context));
        }

        return [
            'contact' => $contact,
            'email' => $inbox,
        ];
    }

    protected function resolveContactStatus(): ?string
    {
        if (!Schema::hasTable(CoreTableConstant::ENUMERATES)) {
            return null;
        }

        $exists = DB::table(CoreTableConstant::ENUMERATES)
            ->where('enum_key', UserStatusEnum::USER_ACTIVE->value)
            ->exists();

        return $exists ? UserStatusEnum::USER_ACTIVE->value : null;
    }
}
