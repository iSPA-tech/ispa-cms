<?php

declare(strict_types=1);

namespace Cms\Core\Models\DataScope;

use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Models\Role;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The data-scope level a role holds for one permission key (for example "all", "own" or a dimension name).
 *
 * @property int $id
 * @property int $role_id
 * @property string $permission_key
 * @property string $level
 */
class DataScopeGrant extends Model
{
    /** @var string */
    protected $table = CoreTableConstant::DATA_SCOPE_GRANTS;

    /** @var array<int, string> */
    protected $fillable = ['role_id', 'permission_key', 'level'];

    /**
     * The role that holds this grant.
     */
    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }
}
