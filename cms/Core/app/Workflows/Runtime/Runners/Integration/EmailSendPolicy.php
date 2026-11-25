<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration;

/**
 * Owner e-mail policy B (2026-11-25) for the `email_send` node: when a step may send without an approval before it.
 *
 * A step is "managed" (B-compliant) when all hold:
 * - `mode` is `template` with a literal `template_key` (the mail text is a managed template an admin wrote; the step
 *   types no subject, body, address or reply-to of its own: `to`, `subject`, `body`, `reply_to` stay empty);
 * - `recipient` is `trigger_subject` (the trigger's own account / sender, resolved on the server by
 *   TriggerSubjectResolver) or `staff` (holders of a literal `recipient_permission`).
 *
 * The taint lint (NodeConfigValidator) then accepts data from the run's trigger in the step's `variables` without an
 * approval (filled values are escaped and treated as untrusted), and still asks for one when a variable carries data
 * from any other untrusted source (AI output, fetched pages). Every other e-mail step (free text, an address taken
 * from data) keeps the normal rule.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration
 * @since   2026-11-25
 */
final class EmailSendPolicy
{
    public const MODE_TEXT = 'text';

    public const MODE_TEMPLATE = 'template';

    public const RECIPIENT_ADDRESS = 'address';

    public const RECIPIENT_TRIGGER_SUBJECT = 'trigger_subject';

    public const RECIPIENT_STAFF = 'staff';

    /** Free-text keys a managed step must leave empty. */
    public const FREE_TEXT_KEYS = ['to', 'subject', 'body', 'reply_to'];

    /**
     * @param array<string, mixed> $config
     */
    public static function mode(array $config): string
    {
        return ($config['mode'] ?? null) === self::MODE_TEMPLATE ? self::MODE_TEMPLATE : self::MODE_TEXT;
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function recipient(array $config): string
    {
        $recipient = $config['recipient'] ?? null;

        return in_array($recipient, [self::RECIPIENT_TRIGGER_SUBJECT, self::RECIPIENT_STAFF], true) ? $recipient : self::RECIPIENT_ADDRESS;
    }

    /** A literal template key (no expression), or null. */
    public static function templateKey(array $config): ?string
    {
        $key = $config['template_key'] ?? null;
        if (!is_string($key) || preg_match('/^[A-Za-z0-9_.\-]{1,255}$/', trim($key)) !== 1) {
            return null;
        }

        return trim($key);
    }

    /** A literal permission name (`group.ability`), or null. */
    public static function staffPermission(array $config): ?string
    {
        $permission = $config['recipient_permission'] ?? null;
        if (!is_string($permission) || preg_match('/^[A-Za-z0-9_\-]+(?:\.[A-Za-z0-9_\-]+)+$/', trim($permission)) !== 1) {
            return null;
        }

        return trim($permission);
    }

    /**
     * Whether the step meets every limit of policy B (see the class comment).
     *
     * @param array<string, mixed> $config
     */
    public static function isManaged(array $config): bool
    {
        if (self::mode($config) !== self::MODE_TEMPLATE || self::templateKey($config) === null) {
            return false;
        }

        foreach ([...self::FREE_TEXT_KEYS, 'cc', 'bcc'] as $key) {
            $value = $config[$key] ?? null;
            if ($value !== null && $value !== '' && $value !== []) {
                return false;
            }
        }

        return match (self::recipient($config)) {
            self::RECIPIENT_TRIGGER_SUBJECT => true,
            self::RECIPIENT_STAFF => self::staffPermission($config) !== null,
            default => false,
        };
    }

    /** Whether a `business` template may send from this step (its switch; off unless set). */
    public static function businessEnabled(array $config): bool
    {
        return filter_var($config['business_enabled'] ?? false, FILTER_VALIDATE_BOOLEAN);
    }
}
