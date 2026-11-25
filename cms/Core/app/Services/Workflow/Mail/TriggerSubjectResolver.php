<?php

declare(strict_types=1);

namespace Cms\Core\Services\Workflow\Mail;

use App\Enums\UserStatusEnum;
use App\Models\User;
use App\Services\SpamEmailBlocklistService;
use Cms\Core\Models\Email;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Throwable;

/**
 * "The trigger's own subject": the person an allowlisted app event is about, resolved on the server (policy B,
 *). Recipient kind `trigger_subject` of the workflow `email_send` node.
 *
 * Only an app event declares a subject, in its `core.workflow_security.trigger_events` entry:
 * `'subject' => ['kind' => <kind>, 'field' => <payload field holding the uuid>]`. Kinds:
 * - `account`: the account whose `uuid` is the field (Account AccountRegistered → `user_uuid`); its own e-mail.
 * - `contact_message`: the stored inbox message whose `uuid` is the field (ContactMessageReceived → `message_uuid`);
 *   the sender address saved on that row (`from_email`, else its contact's e-mail), never form text from the payload.
 *
 * The run must have been started by that event (`trigger.meta.source` = `event`, set by the run service, and
 * `trigger.meta.event.name` = the declaring class); the payload only supplies the uuid the server looks up. Manual,
 * schedule, sub-workflow, model-event and webhook runs have no subject, whatever their data says.
 *
 * Skips (`skip`): `no_recipient` (no such account or message, or no valid address), `spam` (the account or contact is
 * banned, the message was removed or marked spam, or the address is on the spam blocklist).
 *
 * @package Cms\Core\Services\Workflow\Mail
 * @since   2026-11-25
 */
class TriggerSubjectResolver
{
    public const KINDS = ['account', 'contact_message'];

    /**
     * The subject declaration of an allowlisted app event, or null.
     *
     * @return array{kind: string, field: string}|null
     */
    public function declared(?string $eventClass): ?array
    {
        $eventClass = ltrim(trim((string) $eventClass), '\\');
        if ($eventClass === '') {
            return null;
        }

        $subject = app(WorkflowCallableGuard::class)->triggerEvents()[$eventClass]['subject'] ?? null;
        if (!is_array($subject) || !in_array($subject['kind'] ?? null, self::KINDS, true)
            || !is_string($subject['field'] ?? null) || trim($subject['field']) === '') {
            return null;
        }

        return ['kind' => (string) $subject['kind'], 'field' => trim($subject['field'])];
    }

    /**
     * The subject of the run in $context.
     *
     * @return array{email?: string, name?: string, kind?: string, skip?: string}
     */
    public function resolve(WorkflowContext $context): array
    {
        $meta = $context->getTrigger()->getMeta();
        if (($meta['source'] ?? null) !== 'event' || !is_array($meta['event'] ?? null)) {
            return ['skip' => 'no_recipient'];
        }

        $declared = $this->declared(is_string($meta['event']['name'] ?? null) ? $meta['event']['name'] : null);
        $uuid = $declared === null ? null : ($meta['event']['payload'][$declared['field']] ?? null);
        if ($declared === null || !is_string($uuid) || trim($uuid) === '') {
            return ['skip' => 'no_recipient'];
        }

        $found = match ($declared['kind']) {
            'account' => $this->account(trim($uuid)),
            'contact_message' => $this->contactMessage(trim($uuid)),
        };
        if (isset($found['skip'])) {
            return $found;
        }

        $email = strtolower(trim((string) ($found['email'] ?? '')));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return ['skip' => 'no_recipient'];
        }
        if ($this->blocked($email)) {
            return ['skip' => 'spam'];
        }

        return ['email' => $email, 'name' => (string) ($found['name'] ?? ''), 'kind' => $declared['kind']];
    }

    /**
     * @return array{email?: string, name?: string, skip?: string}
     */
    protected function account(string $uuid): array
    {
        $user = User::query()->where('uuid', $uuid)->first();
        if ($user === null) {
            return ['skip' => 'no_recipient'];
        }
        if ((string) ($user->status instanceof \BackedEnum ? $user->status->value : $user->status) === UserStatusEnum::USER_BANNED->value) {
            return ['skip' => 'spam'];
        }

        return ['email' => (string) $user->email, 'name' => (string) $user->name];
    }

    /**
     * @return array{email?: string, name?: string, skip?: string}
     */
    protected function contactMessage(string $uuid): array
    {
        // Marking a message as spam deletes it (soft): a removed or non-inbox row gets no mail.
        $message = Email::query()->where('uuid', $uuid)->where('type', 'inbox')->with('contact')->first();
        if ($message === null) {
            return ['skip' => 'spam'];
        }

        $contact = $message->contact;
        if ($contact !== null && (string) ($contact->status instanceof \BackedEnum ? $contact->status->value : $contact->status) === UserStatusEnum::USER_BANNED->value) {
            return ['skip' => 'spam'];
        }

        $email = trim((string) ($message->from_email ?: $contact?->email ?: ''));

        return ['email' => $email, 'name' => (string) ($message->from_name ?: $contact?->name ?: '')];
    }

    protected function blocked(string $email): bool
    {
        try {
            return app(SpamEmailBlocklistService::class)->isBlocked($email);
        } catch (Throwable $e) {
            report($e);

            return true; // fail closed: no auto mail when the blocklist cannot be read
        }
    }
}
