<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Services\Modules\Install;

use App\Models\User;
use Cms\Core\Models\ModuleInstall;
use Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Records a queued module install row and its background task, after the caller ran the preflight and checked the
 * confirmations. Shared by the install endpoints and the upgrade-all runner, so both create exactly the same row.
 */
final class InstallRecorder
{
    public function __construct(private readonly BackgroundTaskRecorder $recorder)
    {
    }

    /**
     * Create the queued install row and its background task in one transaction.
     *
     * @param bool                 $confirmed  the person confirmed irreversible migrations (typed name)
     * @param string|null          $uuid       row uuid chosen by the caller (an upload is staged under it first)
     * @param bool                 $backup     the person chose to take the safety backup first (false stores `backup_skipped`)
     * @param array<string, mixed> $thirdParty partner fields: `third_party_publisher`, `confirmed`
     */
    public function record(User $user, string $name, string $version, string $source, PreflightResult $result, bool $confirmed = false, ?string $uuid = null, bool $backup = true, array $thirdParty = []): ModuleInstall
    {
        $irreversible = in_array(InstallPreflight::WARN_IRREVERSIBLE, $result->warningCodes(), true);
        $confirmedThirdParty = ($thirdParty['confirmed'] ?? false) === true;

        return DB::transaction(function () use ($user, $name, $version, $source, $irreversible, $confirmed, $result, $uuid, $backup, $thirdParty, $confirmedThirdParty): ModuleInstall {
            $task = $this->recorder->start(ModuleInstall::TASK_TYPE, 'Core', (int) $user->id);

            return ModuleInstall::query()->create(array_filter([
                'uuid' => $uuid,
                'name' => $name,
                'version' => $version,
                'source' => $source,
                'kind' => $result->kind,
                'previous_version' => $result->previousVersion,
                'status' => ModuleInstall::STATUS_QUEUED,
                'step_message' => 'Waiting for a queue worker.',
                'background_task_uuid' => $task->uuid,
                'requested_by' => (int) $user->id,
                'confirmed_irreversible' => $irreversible && $confirmed,
                'backup_skipped' => ! $backup,
                'warnings' => $result->warnings,
                'third_party_publisher' => $thirdParty['third_party_publisher'] ?? null,
                'third_party_confirmed_by' => $confirmedThirdParty ? (int) $user->id : null,
                'third_party_confirmed_at' => $confirmedThirdParty ? Carbon::now() : null,
            ], static fn (mixed $value): bool => $value !== null));
        });
    }
}
