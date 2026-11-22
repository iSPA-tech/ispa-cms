<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Support\DiagnosticBundleBuilder;
use Cms\Core\Services\Modules\Support\ModuleSupportClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

/**
 * "Báo lỗi / Cần hỗ trợ" for one installed module (; Webmaster only, enforced by the route middleware).
 *
 * `preview` builds the masked diagnostic bundle ({@see DiagnosticBundleBuilder}) and returns it with line ids; it
 * never calls the hub. `send` takes a subject, a description and the ids of the lines the Webmaster kept, rebuilds
 * the bundle on the server and sends only those lines to the hub through {@see ModuleSupportClient::open()}
 * (`POST /api/v2/public/platform/support/{name}`, served by the hub). Bundle text from the browser is
 * never accepted; the subject and the description are masked with the same rules as the bundle.
 *
 * Support requests of this site, through {@see ModuleSupportClient}: `index` lists the site's tickets with
 * their status, `show` returns one ticket with its public thread (the hub never sends internal notes), `reply` masks the
 * Webmaster's text with the bundle rules ({@see DiagnosticBundleBuilder::mask()}: LogRedactor patterns, IP addresses and
 * known `.env` values) and sends it. The hub scopes tickets to this site's licence domain; a ticket it does not show
 * to this site answers MODULE_SUPPORT_TICKET_NOT_FOUND 404, and a closed ticket MODULE_SUPPORT_CLOSED 409.
 *
 * Hub refusals map to fixed codes (MODULE_SUPPORT_RENEWAL_REQUIRED 403 when the module's licence ended on the hub,
 * MODULE_SUPPORT_NOT_FOUND 404, MODULE_SUPPORT_INVALID 422, MODULE_SUPPORT_RATE_LIMITED 429, MODULE_HUB_NOT_RECOGNISED
 * 502 when the hub refuses this site's credentials with 401, otherwise MODULE_SUPPORT_UNAVAILABLE 502); the hub's own
 * text is never passed on.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 * @since   8.0.0 (2026-11-15)
 */
final class ModuleSupportController extends AdminController
{
    /** Longest subject accepted. */
    public const int MAX_SUBJECT = 200;

    /** Longest description accepted. */
    public const int MAX_DESCRIPTION = 5000;

    /** Most line ids accepted (the bundle never holds more lines than this). */
    public const int MAX_LINE_IDS = 200;

    /** Longest reply accepted. */
    public const int MAX_REPLY = 5000;

    /**
     * The masked diagnostic bundle of one installed module, for the Webmaster to review. Nothing is sent.
     *
     * @return mixed
     */
    public function preview(ModuleCatalog $catalog, DiagnosticBundleBuilder $builder, string $name)
    {
        if ($catalog->installedState($name)['installed'] === 'none') {
            return $this->notInstalled();
        }

        return ires()->data($builder->build($name));
    }

    /**
     * Send a support request with the kept lines of a freshly rebuilt bundle.
     *
     * @return mixed
     */
    public function send(Request $request, ModuleCatalog $catalog, DiagnosticBundleBuilder $builder, ModuleSupportClient $client, string $name)
    {
        $data = $request->validate([
            'subject' => ['required', 'string', 'max:' . self::MAX_SUBJECT],
            'description' => ['required', 'string', 'max:' . self::MAX_DESCRIPTION],
            'line_ids' => ['present', 'array', 'max:' . self::MAX_LINE_IDS],
            'line_ids.*' => ['string', 'regex:/^[a-f0-9]{16}$/'],
        ]);
        if ($catalog->installedState($name)['installed'] === 'none') {
            return $this->notInstalled();
        }

        $bundle = $builder->build($name);
        $lines = array_map(
            static fn (array $line): array => ['section' => $line['section'], 'key' => $line['key'], 'text' => $line['text']],
            $builder->keep($bundle, $data['line_ids']),
        );
        $payload = [
            'subject' => $builder->mask(self::clean($data['subject'])),
            'description' => $builder->mask(self::clean($data['description'])),
            'bundle' => ['module' => $name, 'generated_at' => $bundle['generated_at'], 'lines' => $lines],
        ];

        try {
            $ticket = $client->open($name, $payload);
        } catch (PlatformHubException $e) {
            return $this->hubError($e);
        }

        Log::info('Module support request sent to the platform hub.', ['module' => $name, 'lines' => count($lines), 'user_id' => $request->user()?->getKey()]);

        return ires()->created([
            'sent' => true,
            'lines' => count($lines),
            'ticket' => $ticket,
        ]);
    }

    /**
     * This site's support requests with their status.
     *
     * @return mixed
     */
    public function index(ModuleSupportClient $client)
    {
        try {
            return ires()->data(['items' => $client->tickets()]);
        } catch (PlatformHubException $e) {
            return $this->hubError($e, true);
        }
    }

    /**
     * One support request of this site with its public thread.
     *
     * @return mixed
     */
    public function show(ModuleSupportClient $client, string $uuid)
    {
        if (preg_match(ModuleSupportClient::UUID_PATTERN, $uuid) !== 1) {
            return $this->ticketNotFound();
        }

        try {
            return ires()->data($client->ticket($uuid));
        } catch (PlatformHubException $e) {
            return $this->hubError($e, true);
        }
    }

    /**
     * Send the Webmaster's reply to one support request, masked on this site first.
     *
     * @return mixed
     */
    public function reply(Request $request, DiagnosticBundleBuilder $builder, ModuleSupportClient $client, string $uuid)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:' . self::MAX_REPLY],
        ]);
        if (preg_match(ModuleSupportClient::UUID_PATTERN, $uuid) !== 1) {
            return $this->ticketNotFound();
        }

        $body = trim($builder->mask(self::clean($data['body'])));
        if ($body === '') {
            return ires()->error()->code(422)->errorCode('MODULE_SUPPORT_INVALID')->message('Write a reply first.');
        }

        try {
            $answer = $client->reply($uuid, $body);
        } catch (PlatformHubException $e) {
            return $this->hubError($e, true);
        }

        Log::info('Module support reply sent to the platform hub.', ['ticket' => $uuid, 'user_id' => $request->user()?->getKey()]);

        return ires()->created($answer);
    }

    /**
     * The answer for a support request the hub does not show to this site (or a malformed uuid).
     */
    private function ticketNotFound(): mixed
    {
        return ires()->error()->code(404)->errorCode('MODULE_SUPPORT_TICKET_NOT_FOUND')
            ->message('This support request was not found.');
    }

    /**
     * The answer for a module that is not installed on this website.
     */
    private function notInstalled(): mixed
    {
        return ires()->error()->code(404)->errorCode('MODULE_SUPPORT_NOT_INSTALLED')
            ->message('This module is not installed on this website.');
    }

    /**
     * Map a hub failure to a fixed code; the hub's text is never passed on.
     *
     * @param bool $ticket True for the ticket endpoints, where a hub 404 means a ticket this site cannot see.
     */
    private function hubError(PlatformHubException $e, bool $ticket = false): mixed
    {
        Log::warning('Module support request could not be completed through the platform hub.', ['reason' => $e->reason(), 'status' => $e->status()]);
        if ($ticket && $e->reason() === PlatformHubException::HTTP_STATUS && $e->status() === 404) {
            return $this->ticketNotFound();
        }
        if ($e->siteNotRecognised()) {
            return ires()->error()->code(502)->errorCode(PlatformHubException::SITE_NOT_RECOGNISED_CODE)
                ->message('iSPA Hub does not recognise the connection of this website.');
        }

        [$status, $code, $message] = $e->reason() === PlatformHubException::HTTP_STATUS
            ? match ($e->status()) {
                // The hub's support intake and replies answer 403 only for MODULE_RENEWAL_REQUIRED.
                403 => [403, 'MODULE_SUPPORT_RENEWAL_REQUIRED', 'The licence for this module has ended, so support requests cannot be sent. The module keeps working on this website; renew the licence to get support again.'],
                404 => [404, 'MODULE_SUPPORT_NOT_FOUND', 'The platform hub does not offer support for this module.'],
                409 => [409, 'MODULE_SUPPORT_CLOSED', 'This support request is closed. Send a new request instead.'],
                422 => [422, 'MODULE_SUPPORT_INVALID', 'The platform hub refused the request.'],
                429 => [429, 'MODULE_SUPPORT_RATE_LIMITED', 'Too many support requests from this website. Try again later.'],
                default => [502, 'MODULE_SUPPORT_UNAVAILABLE', 'The platform hub is not available. Try again later.'],
            }
            : [502, 'MODULE_SUPPORT_UNAVAILABLE', 'The platform hub is not available. Try again later.'];

        return ires()->error()->code($status)->errorCode($code)->message($message);
    }

    /**
     * Text without control characters (new lines and tabs kept), trimmed.
     */
    private static function clean(string $value): string
    {
        return trim((string) preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', str_replace(["\r\n", "\r"], "\n", $value)));
    }
}
