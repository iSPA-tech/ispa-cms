<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Contact;
use Cms\Core\Models\Email;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A visitor sent a message through a public contact form: one event per stored inbox message, for a first-time and a
 * returning sender alike (the form updates an existing Contact when the e-mail or phone matches, so "Contact created"
 * misses returning senders).
 *
 * Fired by both contact writers (Website\AjaxController::storeContact and ContactSubmissionService::submit) inside
 * their transaction and dispatched only after it commits. Allowlisted for the workflow trigger `trigger_event`
 * (start only, `core.workflow_security.trigger_events`, set by CoreServiceProvider). Its public properties ARE the
 * run's payload, so it carries only what the admin notice needs: never the e-mail, phone, subject or message text.
 * A workflow that needs the message reads the inbox row by `message_uuid` (a `retrieve`, which checks the run user's
 * permission on Email).
 *
 * @package App\Events
 * @since   2026-11-25
 */
final class ContactMessageReceived implements ShouldDispatchAfterCommit
{
    /**
     * @param string $message_uuid The stored inbox message (`emails.uuid`, type `inbox`).
     * @param string $display_name The name the visitor typed (untrusted form text).
     * @param string $form         Which form: the contact's `source` (form id, e.g. `contact`).
     * @param string $received_at  When the message was stored (ISO 8601).
     */
    public function __construct(
        public readonly string $message_uuid,
        public readonly string $display_name,
        public readonly string $form,
        public readonly string $received_at,
    ) {
    }

    public static function fromMessage(Contact $contact, Email $message): self
    {
        return new self(
            (string) $message->uuid,
            trim((string) $contact->name),
            (string) ($contact->source ?: 'contact'),
            ($message->created_at ?? now())->toIso8601String(),
        );
    }

    /**
     * JSON schema of the payload, declared with the trigger allowlist entry; templates copy it into their start node so
     * the designer's data picker lists the fields.
     *
     * @return array<string, mixed>
     */
    public static function payloadSchema(): array
    {
        return ['type' => 'object', 'properties' => [
            'message_uuid' => ['type' => 'string', 'title' => 'Message ID'],
            'display_name' => ['type' => 'string', 'title' => 'Sender name'],
            'form' => ['type' => 'string', 'title' => 'Form'],
            'received_at' => ['type' => 'string', 'format' => 'date-time', 'title' => 'Received at'],
        ]];
    }
}
