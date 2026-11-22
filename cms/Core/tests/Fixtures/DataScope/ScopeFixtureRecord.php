<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Fixtures\DataScope;

use Illuminate\Database\Eloquent\Model;

/**
 * Eloquent model over the `scope_fixture_records` table created by ScopeTreeFixture (one record per tree node).
 *
 * Used by the data-scope query layer tests as the scoped resource `fixture/records`.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $node_id
 * @property int|null $owner_user_id
 */
class ScopeFixtureRecord extends Model
{
    /**
     * @var string
     */
    protected $table = 'scope_fixture_records';

    /**
     * The fixture table has no timestamp columns.
     *
     * @var bool
     */
    public $timestamps = false;

    /**
     * @var array<int, string>
     */
    protected $guarded = [];
}
