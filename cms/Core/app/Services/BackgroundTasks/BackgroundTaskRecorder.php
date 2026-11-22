<?php

declare(strict_types=1);

namespace Cms\Core\Services\BackgroundTasks;

use Cms\Core\Models\BackgroundTask;

/**
 * Records the lifecycle of long-running admin work (queued, running, succeeded, failed).
 *
 * Messages are truncated to 500 characters (multibyte-safe). Result payloads never keep secrets:
 * any key whose name contains token, secret, password, api_key, apikey or authorization
 * (case-insensitive, at any depth) is dropped before saving.
 */
class BackgroundTaskRecorder
{
    private const MESSAGE_LIMIT = 500;

    private const PARAM_LIMIT = 200;

    private const SECRET_KEY_PATTERN = '/token|secret|password|api[_-]?key|authorization/i';

    /**
     * Create a queued task.
     *
     * @param string $type Short task type, for example "export.users".
     * @param string $module Owning module name.
     * @param int|null $userId Starting user id.
     * @return BackgroundTask The queued task.
     */
    public function start(string $type, string $module, ?int $userId): BackgroundTask
    {
        return BackgroundTask::query()->create([
            'type' => mb_substr($type, 0, 100),
            'module' => mb_substr($module, 0, 64),
            'status' => BackgroundTask::STATUS_QUEUED,
            'started_by' => $userId,
        ]);
    }

    /**
     * Mark a task as running.
     *
     * @param BackgroundTask $task Task to update.
     * @return BackgroundTask The updated task.
     */
    public function running(BackgroundTask $task): BackgroundTask
    {
        $task->forceFill([
            'status' => BackgroundTask::STATUS_RUNNING,
            'started_at' => $task->started_at ?? now(),
        ])->save();

        return $task;
    }

    /**
     * Record progress, promoting a queued task to running.
     *
     * @param BackgroundTask $task Task to update.
     * @param int $percent Progress, clamped to 0-100.
     * @param string|null $message Optional status message (an admin i18n key or plain text); null keeps the current message and params.
     * @param array<string, mixed> $params Named values for the message key; replaces the stored params whenever a message is given.
     * @return BackgroundTask The updated task.
     */
    public function progress(BackgroundTask $task, int $percent, ?string $message = null, array $params = []): BackgroundTask
    {
        if ($task->status === BackgroundTask::STATUS_QUEUED) {
            $this->running($task);
        }

        $task->progress = max(0, min(100, $percent));
        if ($message !== null) {
            $task->message = $this->truncate($message);
            $task->message_params = $this->sanitizeParams($params);
        }
        $task->save();

        return $task;
    }

    /**
     * Mark a task as succeeded.
     *
     * @param BackgroundTask $task Task to update.
     * @param array<string, mixed> $result Result summary; secret-looking keys are dropped.
     * @return BackgroundTask The updated task.
     */
    public function succeed(BackgroundTask $task, array $result = []): BackgroundTask
    {
        $task->forceFill([
            'status' => BackgroundTask::STATUS_SUCCEEDED,
            'progress' => 100,
            'result' => $this->sanitize($result),
            'finished_at' => now(),
        ])->save();

        return $task;
    }

    /**
     * Mark a task as failed.
     *
     * @param BackgroundTask $task Task to update.
     * @param string $message Failure message, an admin i18n key or plain text (truncated to 500 characters).
     * @param array<string, mixed> $params Named values for the message key.
     * @return BackgroundTask The updated task.
     */
    public function fail(BackgroundTask $task, string $message, array $params = []): BackgroundTask
    {
        $task->forceFill([
            'status' => BackgroundTask::STATUS_FAILED,
            'message' => $this->truncate($message),
            'message_params' => $this->sanitizeParams($params),
            'finished_at' => now(),
        ])->save();

        return $task;
    }

    /**
     * Truncate a message to the column limit without splitting a multibyte character.
     */
    private function truncate(string $message): string
    {
        return mb_substr($message, 0, self::MESSAGE_LIMIT);
    }

    /**
     * Keep only scalar message params: secret-looking keys are dropped and long strings truncated to 200 characters.
     *
     * @param array<int|string, mixed> $params
     * @return array<string, bool|float|int|string>|null Null when nothing is left.
     */
    private function sanitizeParams(array $params): ?array
    {
        $clean = [];
        foreach ($params as $key => $value) {
            if (! is_string($key) || preg_match(self::SECRET_KEY_PATTERN, $key) === 1 || ! is_scalar($value)) {
                continue;
            }
            $clean[$key] = is_string($value) ? mb_substr($value, 0, self::PARAM_LIMIT) : $value;
        }

        return $clean === [] ? null : $clean;
    }

    /**
     * Recursively drop keys that look like secrets.
     *
     * @param array<int|string, mixed> $data
     * @return array<int|string, mixed>
     */
    private function sanitize(array $data): array
    {
        $clean = [];
        foreach ($data as $key => $value) {
            if (is_string($key) && preg_match(self::SECRET_KEY_PATTERN, $key) === 1) {
                continue;
            }
            $clean[$key] = is_array($value) ? $this->sanitize($value) : $value;
        }

        return $clean;
    }
}
