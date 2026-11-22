<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use Cms\Core\Services\Platform\HubCredentials;
use Illuminate\Console\Command;

/**
 * Prints a new install setup code for connecting this site to the platform hub. Only the code's hash is stored; any
 * earlier code stops working. The code is printed to the terminal only, never logged; with --quiet it is not printed at
 * all (to invalidate a code that leaked).
 */
class HubSetupCodeCommand extends Command
{
    protected $signature = 'cms:hub-setup-code';

    protected $help = 'With --quiet the new code replaces the old one but is not printed: use it to stop a code that leaked into a log.';

    protected $description = 'Print a new one-time setup code for connecting this site to the iSPA hub (valid 7 days).';

    /**
     * Issue and print the code.
     */
    public function handle(HubCredentials $credentials): int
    {
        $code = $credentials->issueSetupCode();
        if ($this->output->isQuiet()) {
            // replace a leaked code silently; the earlier one stops working, the new one is never printed.
            return self::SUCCESS;
        }

        $this->line('iSPA hub setup code (valid '.HubCredentials::SETUP_CODE_TTL_DAYS.' days, works once):');
        $this->line('  '.$code);
        if ($credentials->source() === HubCredentials::SOURCE_ENV) {
            $this->warn('This site already has hub credentials in .env; they keep winning over a connect from the admin.');
        } elseif ($credentials->isConfigured()) {
            $this->warn('This site is already connected; using the code replaces the stored hub credentials.');
        }

        return self::SUCCESS;
    }
}
