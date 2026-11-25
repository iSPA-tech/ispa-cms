<?php

declare(strict_types=1);

namespace Cms\Core\Models\Workflows;

use App\Models\User;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * An immutable, sealed snapshot of a workflow project's graph (B1/B2 guardrails).
 *
 * `graph` holds the canonical JSON text exactly as WorkflowVersionSnapshot::encode() wrote it; it is never cast, so
 * the stored bytes are what the content hash and seal cover. Read it with graphData().
 *
 * @property int                 $id
 * @property string              $uuid
 * @property int                 $project_id
 * @property int                 $number
 * @property string              $graph                 Canonical JSON text
 * @property string              $content_hash          sha256 of `graph`
 * @property string|null         $sensitive_fingerprint Sensitive projection hash (lane 3)
 * @property array|null          $sensitive_summary
 * @property string              $seal                  HMAC over project_id|number|content_hash
 * @property string              $status                published|superseded|pending_review|cooling_off|cancelled|rejected
 * @property \Carbon\Carbon|null $effective_at
 * @property int|null            $created_by
 * @property int|null            $approved_by
 * @property \Carbon\Carbon|null $approved_at
 * @property string|null         $note
 * @property list<int>|null      $editor_ids            Draft editors frozen into the version at publish (WorkflowAuthors)
 *
 * @package Cms\Core\Models\Workflows
 * @since   2026-11-24
 */
class WorkflowVersion extends Model
{
    use HasUuid;

    public const STATUS_PUBLISHED = 'published';
    public const STATUS_SUPERSEDED = 'superseded';
    public const STATUS_PENDING_REVIEW = 'pending_review';
    public const STATUS_COOLING_OFF = 'cooling_off';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_REJECTED = 'rejected';

    /** Columns that never change once a version row exists (the reseal command updates `seal` with a query). */
    public const FROZEN = ['project_id', 'number', 'graph', 'content_hash', 'seal'];

    protected $table = 'workflow_versions';

    /**
     * What the publish that returned this model did (`published`, `unchanged`, `pending_review`, `cooling_off`); a plain
     * property, never stored.
     */
    public ?string $publishPath = null;

    /**
     * Sensitive changes the publish found against the version published before (WorkflowSensitiveFingerprint::changes());
     * a plain property, never stored.
     *
     * @var list<array{kind: string, step_code?: string, detail: string}>
     */
    public array $publishChanges = [];

    protected $fillable = [
        'project_id', 'number', 'graph', 'content_hash', 'sensitive_fingerprint', 'sensitive_summary', 'seal',
        'status', 'effective_at', 'created_by', 'approved_by', 'approved_at', 'note',
    ];

    protected $casts = [
        'project_id' => 'integer',
        'number' => 'integer',
        'sensitive_summary' => 'array',
        'editor_ids' => 'array',
        'effective_at' => 'datetime',
        'created_by' => 'integer',
        'approved_by' => 'integer',
        'approved_at' => 'datetime',
    ];

    /** The JSON text is served decoded through graphData(), never raw. */
    protected $hidden = ['graph', 'seal', 'editor_ids'];

    protected static function booted(): void
    {
        static::updating(static function (self $version): void {
            foreach (self::FROZEN as $column) {
                if ($version->isDirty($column)) {
                    throw new LogicException("Workflow version {$version->id}: {$column} can not change.");
                }
            }
        });
    }

    /**
     * Decoded canonical graph.
     *
     * @return array<string, mixed>
     */
    public function graphData(): array
    {
        $decoded = json_decode((string) ($this->getAttributes()['graph'] ?? ''), true);

        return is_array($decoded) ? $decoded : [];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(WorkflowProject::class, 'project_id');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
