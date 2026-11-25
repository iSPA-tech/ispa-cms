<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\Setting;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpHostAllowlist;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

/**
 * The Webmaster's list of outside hosts workflow steps may call (Webmaster only, enforced
 * by the route middleware).
 *
 * `GET  workflow/http-hosts` answers {hosts: the Webmaster's list, server_hosts: entries from
 * `core.workflow_security.http_hosts` (read only)}.
 * `PUT  workflow/http-hosts` with `{ "hosts": ["example.com", "*.example.org", "api.example.net:8443"] }` replaces
 * the list (audited `workflow.http_hosts_changed`). Every entry is checked with HttpHostAllowlist::normalizeEntry();
 * a bad entry answers 422 under `hosts.<index>`. The generic settings endpoints never write this key
 * (SettingController::PROTECTED_KEYS).
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @since   2026-11-24
 */
final class WorkflowHttpHostsController extends AdminController
{
    public const AUDIT_CHANGED = 'workflow.http_hosts_changed';

    /**
     * @return mixed
     */
    public function show(HttpHostAllowlist $allowlist)
    {
        return ires()->data($this->payload($allowlist));
    }

    /**
     * @return mixed
     */
    public function update(Request $request, HttpHostAllowlist $allowlist)
    {
        $data = $request->validate([
            'hosts' => ['present', 'array', 'max:' . HttpHostAllowlist::MAX_ENTRIES],
            'hosts.*' => ['nullable', 'string', 'max:260'],
        ]);

        $entries = [];
        $errors = [];
        foreach (array_values($data['hosts']) as $index => $raw) {
            if (trim((string) $raw) === '') {
                continue;
            }
            $entry = HttpHostAllowlist::normalizeEntry($raw);
            if ($entry === null) {
                $errors["hosts.{$index}"] = ['Enter a host name such as example.com or *.example.com (a public address, no path).'];
                continue;
            }
            $entries[] = $entry;
        }
        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
        $entries = array_values(array_unique($entries));

        $before = $allowlist->settingEntries();
        $setting = $allowlist->saveSettingEntries($entries);

        if ($before !== $entries) {
            event(new AuditLogEvent(
                self::AUDIT_CHANGED,
                ['from' => $before, 'to' => $entries],
                Setting::class,
                (int) $setting->id,
                'fa fa-globe',
                'warning',
            ));
        }

        return ires()->data($this->payload($allowlist));
    }

    /**
     * @return array{hosts: list<string>, server_hosts: list<string>}
     */
    private function payload(HttpHostAllowlist $allowlist): array
    {
        return [
            'hosts' => $allowlist->settingEntries(),
            'server_hosts' => $allowlist->serverEntries(),
        ];
    }
}
