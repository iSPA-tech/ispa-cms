<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Constants\UserType;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\ApiAccessToken;
use Cms\Core\Services\Security\TokenRevoker;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;

/**
 * One-time cut-over of decision, run per site after the 14 days in `log` mode and before the
 * enforcement mode is set to `enforce`: revokes the legacy tokens (issued before tokens carried an audience, no audience) of Webmaster
 * and Admin accounts, so every staff member signs in again once and from then on holds an `admin` token.
 *
 * Dry run by default (counts only); `--force` deletes in chunks of `--chunk` rows so a large table is never locked for
 * long. Tokens that carry an audience are never touched (a staff member already signed in with a new token keeps
 * working), nor are legacy tokens of other account types (counted, kept: the `enforce` mode decides about them) or game
 * player tokens. The cached plain tokens old releases left in Redis are removed by
 * `cms:tokens-forget-cached`, not here. Prints counts only and writes one `security.tokens.revoked` audit event.
 *
 * @package Cms\Core\Console\Commands
 * @(2026-11-12)
 */
class TokensCutoverCommand extends Command
{
    protected $signature = 'cms:tokens-cutover
        {--force : Revoke the tokens (without it the command only counts them)}
        {--chunk=1000 : Rows deleted per statement}';

    protected $description = 'Revoke the legacy API tokens (no audience) of Webmaster and Admin accounts (dry run by default).';

    /**
     * Legacy tokens of platform accounts; `$types` limits them to these account types, `$exclude` to the others.
     *
     * @param list<int> $types Account types.
     * @param bool $exclude Select the accounts NOT of these types.
     * @return Builder<ApiAccessToken>
     */
    public static function legacyQuery(array $types, bool $exclude = false): Builder
    {
        $owners = User::withTrashed()->select('id');
        $exclude ? $owners->whereNotIn('type', $types) : $owners->whereIn('type', $types);

        return ApiAccessToken::query()
            ->where('tokenable_type', (new User())->getMorphClass())
            ->whereNull('audience')
            ->whereIn('tokenable_id', $owners);
    }

    /**
     * Count or revoke the legacy staff tokens.
     *
     * @return int Command exit code.
     */
    public function handle(): int
    {
        if (! ApiAccessToken::hasAudienceColumns()) {
            $this->error('The token table has no audience column yet; run the migrations first.');

            return self::FAILURE;
        }

        $staff = [UserType::WEBMASTER, UserType::ADMIN];
        $this->line('Legacy tokens of Webmaster accounts: ' . self::legacyQuery([UserType::WEBMASTER])->count());
        $this->line('Legacy tokens of Admin accounts: ' . self::legacyQuery([UserType::ADMIN])->count());
        $this->line('Legacy tokens of other accounts (kept): ' . self::legacyQuery($staff, true)->count());

        if (! $this->option('force')) {
            $this->info('Dry run: nothing was revoked. Use --force to revoke the legacy tokens of Webmaster and Admin accounts.');

            return self::SUCCESS;
        }

        $chunk = max(1, (int) $this->option('chunk'));
        $revoked = 0;
        do {
            $ids = self::legacyQuery($staff)->orderBy('id')->limit($chunk)->pluck('id')->all();
            $deleted = $ids === [] ? 0 : ApiAccessToken::query()->whereKey($ids)->whereNull('audience')->delete();
            $revoked += $deleted;
        } while ($ids !== [] && $deleted > 0);

        event(new AuditLogEvent(
            TokenRevoker::AUDIT_ACTION,
            ['scope' => 'cutover', 'audience' => null, 'revoked' => $revoked, 'spared' => 0],
            ApiAccessToken::class,
            0,
            'fa fa-key',
            'warning',
        ));
        $this->info('Revoked ' . $revoked . ' legacy staff tokens. Staff accounts sign in again once; then set the enforcement mode to enforce.');

        return self::SUCCESS;
    }
}
