<?php

declare(strict_types=1);

namespace App\Events;

use App\Models\Contact;
use Cms\Core\Models\Email;
use Cms\Core\Services\Workflow\Triggers\WorkflowTakeover;
use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;

/**
 * A visitor sent a message through a public contact form: one event per stored inbox message, for a first-time and a
 * returning sender alike (the form updates an existing Contact when the e-mail or phone matches, so "Contact created"
 * misses returning senders).
 *
 * Fired by both contact writers (Website\AjaxController::storeContact and ContactSubmissionService::submit) through
 * dispatchFor(), inside their transaction and dispatched only after it commits. A newsletter sign-up (the `subscribe`
 * form) is stored the same way but is not a message to answer, so it fires nothing (forms are told
 * apart in code, no form setting). Allowlisted for the workflow trigger `trigger_event`
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
    /** Form ids of newsletter sign-ups: kept as contacts, but no message for a workflow to answer. */
    public const NEWSLETTER_FORMS = ['subscribe', 'newsletter'];

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

    /** Fire the event for a stored message, unless its form is a newsletter sign-up. */
    public static function dispatchFor(Contact $contact, Email $message): void
    {
        if (self::isMessageForm($contact->source)) {
            event(self::fromMessage($contact, $message));
        }
    }

    /** Whether a submission of this form (the contact's `source`) is a message; empty counts as the contact form. */
    public static function isMessageForm(?string $form): bool
    {
        return !in_array(strtolower(trim((string) $form)), self::NEWSLETTER_FORMS, true);
    }

    /**
     * Whether an active workflow started by this event does $duty (a WorkflowTakeover duty) for a submission of $form,
     * so the built-in contact mail steps aside: one mail per job. False for a newsletter sign-up (it starts no run) and
     * when the form is unknown (null), so the built-in mail sends.
     */
    public static function workflowDoes(string $duty, ?string $form): bool
    {
        return $form !== null && self::isMessageForm($form) && WorkflowTakeover::covers(self::class, $duty);
    }

    /** The form (`source`) of the contact a SendConfirmToContactor names, or null when it names no stored contact. */
    public static function formOfContact(?string $targetType, int|string|null $targetId): ?string
    {
        if ($targetType !== Contact::class || $targetId === null || $targetId === '') {
            return null;
        }
        $contact = Contact::query()->whereKey($targetId)->first(['id', 'source']);

        return $contact === null ? null : (string) $contact->source;
    }

    /**
     * The record this event is about (the inbox message the visitor sent). Workflow setup suggests the people who may see such records
     * (its policy's `viewAny`) as the ones told by a notice step.
     *
     * @return class-string
     */
    public static function subjectModel(): string
    {
        return Email::class;
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
