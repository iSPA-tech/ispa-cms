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
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Services\Security\TokenRevoker;
use Cms\Core\Services\Security\TokenSettings;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * Revokes account API tokens that were not used for N days (a token never used counts from its creation). Dry run by
 * default; `--force` deletes. Without `--days` the cleanup setting decides and "off" (0) does nothing. Game player
 * tokens are never touched. Writes one audit event with counts only.
 *
 * @package Cms\Core\Console\Commands
 */
class TokensRevokeUnusedCommand extends Command
{
    protected $signature = 'cms:tokens-revoke-unused {--days= : Unused threshold in days (default: the cleanup setting)} {--force : Revoke the tokens (without it the command only counts them)}';

    protected $description = 'Revoke API tokens unused for N days (dry run by default).';

    /**
     * Query of account tokens unused for at least the given number of days.
     *
     * @param int $days Threshold in days.
     * @return Builder<ApiAccessToken>
     */
    public static function unusedQuery(int $days): Builder
    {
        $cutoff = now()->subDays($days);

        return ApiAccessToken::query()
            ->where('tokenable_type', (new User())->getMorphClass())
            ->where(static fn (Builder $q) => $q
                ->where('last_used_at', '<', $cutoff)
                ->orWhere(static fn (Builder $n) => $n->whereNull('last_used_at')->where('created_at', '<', $cutoff)));
    }

    /**
     * Count or revoke the unused tokens.
     *
     * @param TokenSettings $settings Token settings.
     * @return int Command exit code.
     */
    public function handle(TokenSettings $settings): int
    {
        $days = $this->option('days') !== null ? max(1, (int) $this->option('days')) : $settings->unusedDays();
        if ($days <= 0) {
            $this->info('The unused-token cleanup is off; nothing to do.');

            return self::SUCCESS;
        }

        $count = self::unusedQuery($days)->count();
        if (! $this->option('force')) {
            $this->info('Would revoke ' . $count . ' tokens unused for ' . $days . ' days. Use --force to revoke.');

            return self::SUCCESS;
        }

        $revoked = self::unusedQuery($days)->delete();
        event(new AuditLogEvent(
            TokenRevoker::AUDIT_ACTION,
            ['scope' => 'unused', 'audience' => null, 'revoked' => $revoked, 'days' => $days],
            ApiAccessToken::class,
            0,
            'fa fa-key',
            'warning',
        ));
        $this->info('Revoked ' . $revoked . ' tokens unused for ' . $days . ' days.');

        return self::SUCCESS;
    }
}
