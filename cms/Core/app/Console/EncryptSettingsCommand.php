<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Console;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Crypt;
use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Contracts\Encryption\DecryptException;

/**
 * Encrypt legacy Setting values that were stored as plain text.
 * @package Cms\Core\Console
 */
class EncryptSettingsCommand extends Command
{
    /**
     * {@inheritdoc}
     */
    protected $signature = 'cms:settings-encrypt {--dry-run : Only report which settings would be encrypted} {--chunk=500 : Number of rows to process per batch}';

    /**
     * {@inheritdoc}
     */
    protected $description = 'Encrypt plain-text values stored in the settings table.';

    /**
     * Scan the settings table and encrypt any plain-text values using Laravel's Crypt facade.
     *
     * Already-encrypted values are skipped. Supports --dry-run to preview which settings
     * would be encrypted, and --chunk to control batch size for large tables.
     *
     * @return int Command exit code (SUCCESS).
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function handle(): int
    {
        $dryRun = (bool)$this->option('dry-run');
        $chunkSize = max((int)$this->option('chunk'), 1);

        $this->components->info(
            $dryRun ? 'Scanning for plaintext settings…' : 'Encrypting plaintext settings…'
        );

        $affected = 0;

        DB::table(CoreTableConstant::SETTINGS)
            ->select('id', 'setting_key', 'value')
            ->orderBy('id')
            ->chunkById($chunkSize, function ($rows) use (&$affected, $dryRun) {
                foreach ($rows as $row) {
                    $original = $row->value;

                    if ($original === null || $original === '') {
                        continue;
                    }

                    if ($this->isEncrypted($original)) {
                        continue;
                    }

                    $affected++;

                    if ($dryRun) {
                        $this->components->twoColumnDetail('Would encrypt', $row->setting_key);

                        continue;
                    }

                    DB::table(CoreTableConstant::SETTINGS)
                        ->where('id', $row->id)
                        ->update([
                            'value' => Crypt::encryptString($original),
                            'updated_at' => now(),
                        ]);

                    $this->components->twoColumnDetail('Encrypted', $row->setting_key);
                }
            }, 'id');

        $message = $dryRun
            ? "Found {$affected} plaintext setting" . ($affected === 1 ? '' : 's') . '.'
            : "Encrypted {$affected} setting" . ($affected === 1 ? '' : 's') . '.';

        $this->components->info($message);

        return self::SUCCESS;
    }

    private function isEncrypted(string $value): bool
    {
        try {
            Crypt::decryptString($value);

            return true;
        } catch (DecryptException) {
            return false;
        }
    }
}
