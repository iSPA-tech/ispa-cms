<?php

declare(strict_types=1);

namespace Cms\Core\Models;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use RuntimeException;

/**
 * One config package run (export, dry run or apply).
 *
 * The package itself lives in `payload` as base64(gzip(json)); it is hidden from serialization and pruned by
 * `cms:config-package-prune` while the metadata row is kept.
 */
class ConfigPackageRun extends Model
{
    use HasUuid;

    public const KIND_EXPORT = 'export';
    public const KIND_DRY_RUN = 'dry_run';
    public const KIND_APPLY = 'apply';

    public const KINDS = [self::KIND_EXPORT, self::KIND_DRY_RUN, self::KIND_APPLY];

    public const STATUS_QUEUED = 'queued';
    public const STATUS_RUNNING = 'running';
    public const STATUS_SUCCEEDED = 'succeeded';
    public const STATUS_FAILED = 'failed';

    public const STATUSES = [self::STATUS_QUEUED, self::STATUS_RUNNING, self::STATUS_SUCCEEDED, self::STATUS_FAILED];

    protected $table = CoreTableConstant::CONFIG_PACKAGE_RUNS;

    protected $fillable = [
        'uuid',
        'kind',
        'status',
        'sections',
        'package_checksum',
        'payload',
        'summary',
        'plan',
        'parent_run_id',
        'background_task_uuid',
        'backup_ref',
        'created_by',
        'expires_at',
    ];

    protected $hidden = ['payload'];

    protected $casts = [
        'sections' => 'array',
        'summary' => 'array',
        'plan' => 'array',
        'parent_run_id' => 'integer',
        'created_by' => 'integer',
        'expires_at' => 'datetime',
    ];

    /**
     * Encode a package as base64(gzip(json)) into the payload attribute. Does not save.
     *
     * @param array<string, mixed> $package
     */
    public function setPayload(array $package): static
    {
        $json = json_encode($package, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        $this->attributes['payload'] = base64_encode(gzencode($json, 6));

        return $this;
    }

    /**
     * Decode the stored package; an empty array when no payload is stored.
     *
     * @return array<string, mixed>
     *
     * @throws RuntimeException When the stored payload is corrupt (message never contains payload data).
     */
    public function payload(): array
    {
        $raw = $this->attributes['payload'] ?? null;
        if ($raw === null || $raw === '') {
            return [];
        }

        $binary = base64_decode((string) $raw, true);
        $json = $binary === false ? false : @gzdecode($binary);
        if ($json === false) {
            throw new RuntimeException('Config package payload is corrupt.');
        }

        try {
            $data = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new RuntimeException('Config package payload is corrupt.');
        }

        if (!is_array($data)) {
            throw new RuntimeException('Config package payload is corrupt.');
        }

        return $data;
    }

    /**
     * Whether a payload is currently stored.
     */
    public function hasPayload(): bool
    {
        $raw = $this->attributes['payload'] ?? null;

        return $raw !== null && $raw !== '';
    }

    /**
     * The run this one was derived from (an apply points to its dry run).
     */
    public function parentRun(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_run_id');
    }
}
