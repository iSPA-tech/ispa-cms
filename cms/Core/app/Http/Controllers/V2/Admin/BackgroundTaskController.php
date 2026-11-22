<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Models\BackgroundTask;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Str;

/**
 * Lists and shows the signed-in admin's long-running background tasks.
 *
 * Numeric ids and the starting user are never exposed.
 */
class BackgroundTaskController extends AdminController
{
    /**
     * Own tasks from the last 24 hours plus any still queued or running, newest first, max 20.
     *
     * @return mixed Admin API response.
     */
    public function index(): mixed
    {
        $userId = (int) request()->user()?->getKey();

        $tasks = BackgroundTask::query()
            ->where('started_by', $userId)
            ->where(static function ($query): void {
                $query->where('created_at', '>=', now()->subDay())
                    ->orWhereIn('status', [BackgroundTask::STATUS_QUEUED, BackgroundTask::STATUS_RUNNING]);
            })
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(20)
            ->get();

        return ires()->data($tasks->map(fn (BackgroundTask $task): array => $this->present($task))->all());
    }

    /**
     * Show one of the signed-in admin's tasks; another user's uuid and a non-uuid id are both a 404.
     *
     * @param string $uuid Task uuid.
     * @return mixed Admin API response.
     */
    public function show(string $uuid): mixed
    {
        // Reject non-uuid input before the query: pgsql throws on a non-uuid string in a uuid column.
        abort_unless(Str::isUuid($uuid), 404);

        $task = BackgroundTask::query()
            ->where('uuid', $uuid)
            ->where('started_by', (int) request()->user()?->getKey())
            ->first();

        if ($task === null) {
            throw (new ModelNotFoundException())->setModel(BackgroundTask::class);
        }

        return ires()->data($this->present($task));
    }

    /**
     * Shape one task for the API. A stale queued/running task is presented as failed without touching the row.
     *
     * @param BackgroundTask $task Task to present.
     * @return array<string, mixed>
     */
    private function present(BackgroundTask $task): array
    {
        $stale = $task->isStale();

        return [
            'uuid' => $task->uuid,
            'type' => $task->type,
            'module' => $task->module,
            'status' => $stale ? BackgroundTask::STATUS_FAILED : $task->status,
            'progress' => $task->progress,
            'message' => $stale ? BackgroundTask::STALE_MESSAGE_KEY : $task->message,
            'message_params' => $stale ? null : $task->message_params,
            'result' => $task->result,
            'started_at' => $task->started_at?->toIso8601String(),
            'finished_at' => $task->finished_at?->toIso8601String(),
            'created_at' => $task->created_at?->toIso8601String(),
        ];
    }
}
