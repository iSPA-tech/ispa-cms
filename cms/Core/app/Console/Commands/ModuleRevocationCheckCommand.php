<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Modules\Install\EntitlementAttentionProvider;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Modules\Install\RevocationAttentionProvider;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Illuminate\Console\Command;
use Carbon\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;
use Nwidart\Modules\Facades\Module as ModuleFacade;

/**
 * Daily check: asks the platform hub whether any enabled module version of this site was revoked and stores the answer.
 *
 * Also stores the hub's optional `entitlements` list (state per private or paid module) beside the revocations. An
 * answer without that key keeps the previous entitlement result. Never disables a module; the results only feed the
 * admin module list and the dashboard attention items.
 */
class ModuleRevocationCheckCommand extends Command
{
    protected $signature = 'cms:module-revocation-check';

    protected $description = 'Check enabled module versions against the platform hub revocation list and store the result.';

    /**
     * Run the check.
     */
    public function handle(ModuleCatalog $catalog): int
    {
        $installed = [];
        foreach (ModuleFacade::allEnabled() as $module) {
            $version = $catalog->installedState($module->getName())['installed_version'];
            if ($version !== null) {
                $installed[] = ['name' => $module->getName(), 'version' => $version];
            }
        }

        try {
            $answer = (new PlatformHubClient())->post('/api/v2/public/platform/revocations', ['installed' => $installed]);
        } catch (PlatformHubException $e) {
            if ($e->reason() === PlatformHubException::NOT_CONFIGURED) {
                $this->info('Platform hub is not configured; revocation check skipped.');

                return self::SUCCESS;
            }
            Log::warning('Module revocation check failed; previous result kept.', ['reason' => $e->reason()]);
            $this->error('Revocation check failed: ' . $e->reason());

            return self::FAILURE;
        }

        $revoked = [];
        foreach ((array) ($answer['revoked'] ?? []) as $row) {
            if (! is_array($row) || ! is_string($row['name'] ?? null) || ! is_string($row['version'] ?? null)) {
                continue;
            }
            $revoked[$row['name']] = [
                'version' => $row['version'],
                'reason' => is_string($row['reason'] ?? null) ? $row['reason'] : null,
                'revoked_at' => is_string($row['revoked_at'] ?? null) ? $row['revoked_at'] : null,
            ];
            Log::warning('Installed module version is revoked on the platform hub.', [
                'name' => $row['name'],
                'version' => $row['version'],
                'reason' => $revoked[$row['name']]['reason'],
            ]);
        }

        RevocationAttentionProvider::store($revoked);
        $this->info(count($revoked) . ' revoked module version(s) found.');

        $this->storeEntitlements($answer);

        return self::SUCCESS;
    }

    /**
     * Store the hub's `entitlements` list when the answer carries it; skip and log every malformed row.
     *
     * An absent key keeps the previous result (older hub); a value that is not a list is logged and also keeps it.
     *
     * @param array<array-key, mixed> $answer
     */
    private function storeEntitlements(array $answer): void
    {
        if (! array_key_exists('entitlements', $answer)) {
            return;
        }
        if (! is_array($answer['entitlements']) || ! array_is_list($answer['entitlements'])) {
            Log::warning('Platform hub sent entitlements in an unexpected shape; previous result kept.');

            return;
        }

        $states = [];
        foreach ($answer['entitlements'] as $row) {
            $clean = $this->entitlementRow($row);
            if ($clean === null) {
                Log::warning('Platform hub sent a malformed entitlement row; row skipped.');

                continue;
            }
            $states[$clean['name']] = ['state' => $clean['state'], 'expires_at' => $clean['expires_at'], 'grace_until' => $clean['grace_until']];
        }

        EntitlementAttentionProvider::store($states);
        $this->info(count($states) . ' module entitlement state(s) stored.');
    }

    /**
     * Strict shape of one entitlement row, or null: name (module name), state (known) and optional ISO dates.
     *
     * @return array{name: string, state: string, expires_at: ?string, grace_until: ?string}|null
     */
    private function entitlementRow(mixed $row): ?array
    {
        if (! is_array($row) || ! is_string($row['name'] ?? null) || preg_match('/^[A-Z][A-Za-z0-9]{1,63}$/', $row['name']) !== 1
            || ! in_array($row['state'] ?? null, EntitlementAttentionProvider::STATES, true)) {
            return null;
        }

        $dates = [];
        foreach (['expires_at', 'grace_until'] as $field) {
            $value = $row[$field] ?? null;
            if ($value !== null && ! $this->isIsoDate($value)) {
                return null;
            }
            $dates[$field] = $value;
        }

        return ['name' => $row['name'], 'state' => $row['state'], 'expires_at' => $dates['expires_at'], 'grace_until' => $dates['grace_until']];
    }

    /**
     * Whether a value is an ISO 8601 date-time string.
     */
    private function isIsoDate(mixed $value): bool
    {
        if (! is_string($value) || preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2})?/', $value) !== 1) {
            return false;
        }
        try {
            Carbon::parse($value);
        } catch (Throwable) {
            return false;
        }

        return true;
    }
}
