<?php

declare(strict_types=1);

namespace Cms\Core\Models;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * One long-running admin job (export, import, scan) as shown in the admin header task bar.
 *
 * Written through {@see \Cms\Core\Services\BackgroundTasks\BackgroundTaskRecorder}.
 */
class BackgroundTask extends Model
{
    use HasUuid;

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    /** Hours after which a queued or running task is treated as dead. */
    public const STALE_HOURS = 6;

    /** Admin i18n key shown as the message of a task marked stale. */
    public const STALE_MESSAGE_KEY = 'text.background_tasks.stale';

    protected $table = CoreTableConstant::BACKGROUND_TASKS;

    protected $fillable = [
        'uuid',
        'type',
        'module',
        'status',
        'progress',
        'message',
        'message_params',
        'result',
        'started_by',
        'started_at',
        'finished_at',
    ];

    protected $casts = [
        'message_params' => 'array',
        'result' => 'array',
        'progress' => 'integer',
        'started_by' => 'integer',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    /**
     * Whether the task is queued or running but has shown no start for longer than the stale window.
     *
     * The age is measured from started_at, or from created_at when the task never started.
     *
     * @param int|null $hours Stale window in hours; defaults to {@see self::STALE_HOURS}.
     * @return bool
     */
    public function isStale(?int $hours = null): bool
    {
        if (! in_array($this->status, [self::STATUS_QUEUED, self::STATUS_RUNNING], true)) {
            return false;
        }

        $since = $this->started_at ?? $this->created_at;

        return $since !== null && $since->lt(now()->subHours($hours ?? self::STALE_HOURS));
    }
}
