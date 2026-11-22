<?php

declare(strict_types=1);

namespace Cms\Core\Models\DataScope;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\DataScope\Expansion;
use Cms\Core\Traits\HasUuid;
use Illuminate\Database\Eloquent\Model;

/**
 * Assigns a hierarchy node of one dimension to a user or a role, with an expansion mode.
 *
 * @property int $id
 * @property string $uuid
 * @property string $subject_type 'user' or 'role'
 * @property int $subject_id
 * @property string $dimension
 * @property int $node_id
 * @property Expansion $expansion
 * @property int|null $assigned_by_user_id
 */
class DataScopeAssignment extends Model
{
    use HasUuid;

    public const SUBJECT_USER = 'user';
    public const SUBJECT_ROLE = 'role';

    /** @var string */
    protected $table = CoreTableConstant::DATA_SCOPE_ASSIGNMENTS;

    /** @var array<int, string> */
    protected $fillable = [
        'subject_type',
        'subject_id',
        'dimension',
        'node_id',
        'expansion',
        'assigned_by_user_id',
    ];

    /**
     * Attribute casts.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'expansion' => Expansion::class,
            'subject_id' => 'integer',
            'node_id' => 'integer',
            'assigned_by_user_id' => 'integer',
        ];
    }
}
