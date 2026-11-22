<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Support;

use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;

/**
 * The site's side of marketplace support on the platform hub (send, threads).
 *
 * Every call goes through {@see PlatformHubClient} (signed request, verified answer) to the hub's
 * `/api/v2/public/platform/support` endpoints. The hub scopes tickets to this site's licence domain and returns only
 * public site and support messages; this client keeps only the known fields of each answer, so an extra or unexpected
 * field from the hub never reaches the admin. Text sent from here must already be masked by the caller.
 *
 * @package Cms\Core\Services\Modules\Support
 * @author  iSPA Team
 * @since   8.0.0 (2026-11-15)
 */
class ModuleSupportClient
{
    /** Hub path of the support endpoints. */
    public const string HUB_PATH = '/api/v2/public/platform/support';

    /** Pattern of a ticket or message uuid. */
    public const string UUID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Ticket statuses the admin knows how to show (Helpdesk ticket statuses). */
    public const array STATUSES = ['new', 'open', 'pending_customer', 'resolved', 'closed'];

    /** Message authors a site may see. */
    public const array AUTHORS = ['site', 'support'];

    /**
     * Open a ticket for a module with the report built by the controller.
     *
     * @param string                                                                                                                 $name   Module name.
     * @param array{subject: string, description: string, bundle: array{module: string, generated_at: ?string, lines: list<array>}} $report Masked report.
     *
     * @return string|null The ticket uuid, or null when the hub did not return one.
     *
     * @throws PlatformHubException
     */
    public function open(string $name, array $report): ?string
    {
        return self::uuid($this->hub()->post(self::HUB_PATH . '/' . $name, $report)['ticket'] ?? null);
    }

    /**
     * This site's support tickets, as the hub lists them.
     *
     * @return list<array{uuid: string, number: ?string, module: ?string, subject: ?string, status: ?string, created_at: ?string, last_activity_at: ?string, last_reply_at: ?string}>
     *
     * @throws PlatformHubException When the hub fails or answers with another shape.
     */
    public function tickets(): array
    {
        $answer = $this->hub()->get(self::HUB_PATH);
        if (!isset($answer['tickets']) || !is_array($answer['tickets'])) {
            throw new PlatformHubException(PlatformHubException::BAD_RESPONSE, 'Unexpected support list from the platform hub.');
        }

        return array_values(array_filter(array_map(self::summary(...), $answer['tickets'])));
    }

    /**
     * One ticket of this site with its public thread.
     *
     * @param string $uuid Ticket uuid (checked by the caller).
     *
     * @return array{ticket: array<string, ?string>, messages: list<array{uuid: string, author: string, body: string, created_at: ?string}>}
     *
     * @throws PlatformHubException When the hub refuses (404 for a ticket of another site), fails or answers with another shape.
     */
    public function ticket(string $uuid): array
    {
        $answer = $this->hub()->get(self::HUB_PATH . '/' . $uuid);
        $ticket = self::summary($answer['ticket'] ?? null);
        if ($ticket === null || !isset($answer['messages']) || !is_array($answer['messages'])) {
            throw new PlatformHubException(PlatformHubException::BAD_RESPONSE, 'Unexpected support thread from the platform hub.');
        }

        return ['ticket' => $ticket, 'messages' => array_values(array_filter(array_map(self::message(...), $answer['messages'])))];
    }

    /**
     * Send this site's reply to one of its tickets.
     *
     * @param string $uuid Ticket uuid (checked by the caller).
     * @param string $body Reply text, already masked.
     *
     * @return array{message: ?string, status: ?string}
     *
     * @throws PlatformHubException
     */
    public function reply(string $uuid, string $body): array
    {
        $answer = $this->hub()->post(self::HUB_PATH . '/' . $uuid . '/reply', ['body' => $body]);

        return ['message' => self::uuid($answer['message'] ?? null), 'status' => self::status($answer['status'] ?? null)];
    }

    /**
     * The signed hub client (created per call, so missing credentials surface as a PlatformHubException).
     *
     * @throws PlatformHubException When the hub is not configured.
     */
    protected function hub(): PlatformHubClient
    {
        return new PlatformHubClient();
    }

    /**
     * The known fields of a ticket summary, or null when it has no valid uuid.
     *
     * @return array{uuid: string, number: ?string, module: ?string, subject: ?string, status: ?string, created_at: ?string, last_activity_at: ?string, last_reply_at: ?string}|null
     */
    private static function summary(mixed $row): ?array
    {
        $uuid = is_array($row) ? self::uuid($row['uuid'] ?? null) : null;
        if ($uuid === null) {
            return null;
        }

        $module = self::text($row['module'] ?? null, 64);

        return [
            'uuid' => $uuid,
            'number' => self::text($row['number'] ?? null, 32),
            'module' => $module !== null && preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $module) === 1 ? $module : null,
            'subject' => self::text($row['subject'] ?? null, 200),
            'status' => self::status($row['status'] ?? null),
            'created_at' => self::time($row['created_at'] ?? null),
            'last_activity_at' => self::time($row['last_activity_at'] ?? null),
            'last_reply_at' => self::time($row['last_reply_at'] ?? null),
        ];
    }

    /**
     * The known fields of a thread message, or null for anything that is not a site or support message.
     *
     * @return array{uuid: string, author: string, body: string, created_at: ?string}|null
     */
    private static function message(mixed $row): ?array
    {
        if (!is_array($row) || !in_array($row['author'] ?? null, self::AUTHORS, true)) {
            return null;
        }
        $uuid = self::uuid($row['uuid'] ?? null);
        if ($uuid === null) {
            return null;
        }

        return [
            'uuid' => $uuid,
            'author' => (string) $row['author'],
            'body' => self::text($row['body'] ?? null, 20000) ?? '',
            'created_at' => self::time($row['created_at'] ?? null),
        ];
    }

    /**
     * A lower-case uuid, or null.
     */
    private static function uuid(mixed $value): ?string
    {
        return is_string($value) && preg_match(self::UUID_PATTERN, $value) === 1 ? $value : null;
    }

    /**
     * A known ticket status, or null.
     */
    private static function status(mixed $value): ?string
    {
        return is_string($value) && in_array($value, self::STATUSES, true) ? $value : null;
    }

    /**
     * An ISO 8601 time string as the hub sent it, or null.
     */
    private static function time(mixed $value): ?string
    {
        return is_string($value) && strlen($value) <= 40 && strtotime($value) !== false ? $value : null;
    }

    /**
     * A string cut to a length, or null.
     */
    private static function text(mixed $value, int $max): ?string
    {
        return is_string($value) ? mb_substr(mb_scrub($value, 'UTF-8'), 0, $max) : null;
    }
}
