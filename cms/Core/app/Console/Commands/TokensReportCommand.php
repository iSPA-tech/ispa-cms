<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Models\User;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Prints counts of risky API tokens: per audience and per account type, never-expiring, unused for N days, legacy
 * (no audience) and accounts over the live-token cap. Counts only: no token, hash, uuid or account is ever printed.
 *
 * @package Cms\Core\Console\Commands
 */
class TokensReportCommand extends Command
{
    protected $signature = 'cms:tokens-report {--days= : Unused threshold in days (default: the cleanup setting, else 30)} {--max= : Live-token cap per account and audience (default: the setting)}';

    protected $description = 'Count API tokens per audience and account type, never-expiring, unused, legacy and over the cap (counts only).';

    /**
     * Print the report.
     *
     * @param TokenSettings $settings Token settings.
     * @return int Command exit code.
     */
    public function handle(TokenSettings $settings): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : ($settings->unusedDays() ?: 30);
        $max = $this->option('max') !== null ? max(1, (int) $this->option('max')) : $settings->maxLiveTokens();
        $morph = (new User())->getMorphClass();
        $base = static fn (): Builder => ApiAccessToken::query()->where('tokenable_type', $morph);

        $this->line('Total: ' . $base()->count());
        $this->line('Per audience:');
        foreach ($base()->whereNotNull('audience')->selectRaw('audience, count(*) as c')->groupBy('audience')->orderBy('audience')->get() as $row) {
            $this->line('  ' . $row->audience . ': ' . $row->c);
        }
        $this->line('Per account type:');
        $types = $base()->join('users', 'users.id', '=', 'personal_access_tokens.tokenable_id')
            ->selectRaw('users.type as account_type, count(*) as c')->groupBy('users.type')->orderBy('users.type')->get();
        foreach ($types as $row) {
            $this->line('  type ' . $row->account_type . ': ' . $row->c);
        }
        $this->line('Legacy (no audience): ' . $base()->whereNull('audience')->count());
        $this->line('Never expire: ' . $base()->whereNull('expires_at')->count());
        $this->line('Unused for ' . $days . ' days: ' . TokensRevokeUnusedCommand::unusedQuery($days)->count());
        $over = $base()->selectRaw('tokenable_id, audience, count(*) as c')->groupBy('tokenable_id', 'audience')->havingRaw('count(*) > ?', [$max])->get()->count();
        $this->line('Accounts over the cap of ' . $max . ': ' . $over);

        return self::SUCCESS;
    }
}
