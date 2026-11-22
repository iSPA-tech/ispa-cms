<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformHubException;
use Cms\Core\Services\Platform\SiteStatusReport;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Sends the signed site status report to the platform hub at the site's report level: `basic` sends the
 * versions and modules, `full` adds health, backups and other operations data, `off` (or an unconnected site) sends
 * nothing.
 */
class PlatformReportCommand extends Command
{
    protected $signature = 'cms:platform-report {--dry-run : Print the report as JSON and send nothing}';

    protected $description = 'Send the signed site status report to the platform hub.';

    /**
     * Build and send (or print) the report.
     */
    public function handle(SiteStatusReport $report): int
    {
        $payload = $report->payload();
        if ($payload === null) {
            $this->info('Site report level is off; nothing is sent.');

            return self::SUCCESS;
        }

        if ($this->option('dry-run')) {
            $this->line(self::pretty($payload));

            return self::SUCCESS;
        }

        try {
            (new PlatformHubClient())->post('/api/v2/public/platform/reports', $payload);
        } catch (PlatformHubException $e) {
            if ($e->reason() === PlatformHubException::NOT_CONFIGURED) {
                $this->info('Platform hub is not configured; status report skipped.');

                return self::SUCCESS;
            }
            Log::warning('Platform status report failed.', ['reason' => $e->reason()]);
            $this->error('Status report failed: ' . $e->reason());

            return self::FAILURE;
        }

        $this->info('Status report sent.');

        return self::SUCCESS;
    }

    /**
     * The report as pretty-printed JSON, exactly as `--dry-run` and the admin preview show it.
     *
     * @param array<string, mixed> $payload
     */
    public static function pretty(array $payload): string
    {
        return (string) json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }
}
