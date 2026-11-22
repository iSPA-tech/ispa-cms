<?php

namespace App\Support;

use App\Models\Contact;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Shared entry point for public contact forms: mirrors a submission into the Webmail inbox when the Webmail module is present
 * and enabled. A no-op otherwise, and never throws — a public form must not fail because of mail.
 */
final class ContactIntakeBridge
{
    /**
     * True when the site is connected to MailHub and a Webmail mailbox receives contact submissions: operators read them in the Webmail
     * inbox. False when the module is absent/disabled, not connected, without a receiving mailbox, or on any error.
     */
    public static function readableInWebmail(): bool
    {
        try {
            return class_exists(\Cms\Webmail\Services\ContactIntake::class)
                && \Cms\Webmail\Support\WebmailSettings::moduleEnabled()
                && app(\Cms\Webmail\Services\ContactIntake::class)->readableInInbox();
        } catch (Throwable $e) {
            Log::warning('contact intake bridge check failed', ['exception' => $e::class]);
            return false;
        }
    }

    /** @param array{name?: string, email?: string, phone?: ?string, subject?: ?string, message?: string, page_url?: ?string, meta?: array} $submission */
    public static function record(array $submission, ?Contact $contact = null): void
    {
        try {
            if (!class_exists(\Cms\Webmail\Services\ContactIntake::class) || !\Cms\Webmail\Support\WebmailSettings::moduleEnabled()) {
                return;
            }
            // Runs after the outermost commit so a rolled-back submission never leaves a message behind.
            DB::afterCommit(static function () use ($submission, $contact): void {
                try {
                    app(\Cms\Webmail\Services\ContactIntake::class)->record($submission, $contact);
                } catch (Throwable $e) {
                    // Class only: exception messages can carry SQL and visitor data.
                    Log::warning('contact intake bridge failed', ['exception' => $e::class]);
                }
            });
        } catch (Throwable $e) {
            Log::warning('contact intake bridge failed', ['exception' => $e::class]);
        }
    }
}
