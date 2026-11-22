<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Fixtures\DataScope;

use Cms\Core\Services\DataScope\ClosureHierarchy;
use Cms\Core\Services\DataScope\FlatHierarchy;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * Shared data-scope test fixture: a small two-root organisation tree with a closure table and one record per node.
 *
 * Tables (default connection, rebuilt on every call to createScopeTreeFixture()):
 * - `scope_fixture_nodes`: id, uuid, parent_id (nullable), code, name, path ('/MKT/MKT_HOTTOY/'), deleted_at.
 * - `scope_fixture_closures`: ancestor_id, descendant_id, depth (a depth-0 self row for every node).
 * - `scope_fixture_records`: id, uuid, node_id (nullable), owner_user_id (nullable); one record per node.
 *
 * Tree:
 *   MKT
 *   ├── MKT_HOTTOY
 *   │   ├── HOTTOY_A
 *   │   └── HOTTOY_B
 *   └── MKT_HOBBY
 *       └── HOBBY_A
 *   TRADE
 *   └── TRADE_X
 *
 * Reused by the data-scope tasks (hierarchy providers, path hierarchy, query layer), so it stays generic.
 */
trait ScopeTreeFixture
{
    /**
     * Node id by code, filled by createScopeTreeFixture().
     *
     * @var array<string, int>
     */
    protected array $scopeNodeIds = [];

    /**
     * Record id by the code of the node that owns it, filled by createScopeTreeFixture().
     *
     * @var array<string, int>
     */
    protected array $scopeRecordIds = [];

    /**
     * The fixture tree as code => parent code (null for roots), in insert order (parents first).
     *
     * @return array<string, string|null>
     */
    public static function scopeTreeShape(): array
    {
        return [
            'MKT' => null,
            'MKT_HOTTOY' => 'MKT',
            'HOTTOY_A' => 'MKT_HOTTOY',
            'HOTTOY_B' => 'MKT_HOTTOY',
            'MKT_HOBBY' => 'MKT',
            'HOBBY_A' => 'MKT_HOBBY',
            'TRADE' => null,
            'TRADE_X' => 'TRADE',
        ];
    }

    /**
     * Drop and recreate the fixture tables, then seed the tree, its closure rows and one record per node.
     */
    protected function createScopeTreeFixture(): void
    {
        Schema::dropIfExists('scope_fixture_records');
        Schema::dropIfExists('scope_fixture_closures');
        Schema::dropIfExists('scope_fixture_nodes');

        Schema::create('scope_fixture_nodes', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('parent_id')->nullable()->index();
            $table->string('code')->unique();
            $table->string('name');
            $table->string('path');
            $table->softDeletes();
        });

        Schema::create('scope_fixture_closures', function (Blueprint $table): void {
            $table->unsignedBigInteger('ancestor_id');
            $table->unsignedBigInteger('descendant_id');
            $table->unsignedInteger('depth');
            $table->primary(['ancestor_id', 'descendant_id']);
        });

        Schema::create('scope_fixture_records', function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->unsignedBigInteger('node_id')->nullable()->index();
            $table->unsignedBigInteger('owner_user_id')->nullable()->index();
        });

        $this->scopeNodeIds = [];
        $this->scopeRecordIds = [];
        $paths = [];

        foreach (self::scopeTreeShape() as $code => $parentCode) {
            $parentId = $parentCode === null ? null : $this->scopeNodeIds[$parentCode];
            $paths[$code] = ($parentCode === null ? '/' : $paths[$parentCode]) . $code . '/';

            $id = (int) DB::table('scope_fixture_nodes')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'parent_id' => $parentId,
                'code' => $code,
                'name' => Str::title(str_replace('_', ' ', $code)),
                'path' => $paths[$code],
                'deleted_at' => null,
            ]);
            $this->scopeNodeIds[$code] = $id;

            DB::table('scope_fixture_closures')->insert([
                'ancestor_id' => $id,
                'descendant_id' => $id,
                'depth' => 0,
            ]);

            if ($parentId !== null) {
                $ancestorRows = DB::table('scope_fixture_closures')->where('descendant_id', $parentId)->get();

                foreach ($ancestorRows as $row) {
                    DB::table('scope_fixture_closures')->insert([
                        'ancestor_id' => (int) $row->ancestor_id,
                        'descendant_id' => $id,
                        'depth' => (int) $row->depth + 1,
                    ]);
                }
            }

            $this->scopeRecordIds[$code] = (int) DB::table('scope_fixture_records')->insertGetId([
                'uuid' => (string) Str::uuid(),
                'node_id' => $id,
                'owner_user_id' => null,
            ]);
        }
    }

    /**
     * Id of the fixture node with the given code.
     */
    public function nodeId(string $code): int
    {
        if (! isset($this->scopeNodeIds[$code])) {
            throw new RuntimeException("Unknown scope fixture node [{$code}].");
        }

        return $this->scopeNodeIds[$code];
    }

    /**
     * Fixture node codes for the given ids, sorted; ids that are not fixture nodes are kept as '#<id>'.
     *
     * @param array<int, int> $ids
     * @return array<int, string>
     */
    public function scopeCodes(array $ids): array
    {
        $byId = array_flip($this->scopeNodeIds);
        $codes = array_map(static fn (int $id): string => $byId[$id] ?? '#' . $id, $ids);
        sort($codes);

        return array_values(array_unique($codes));
    }

    /**
     * A ClosureHierarchy over the fixture tables (key = code, label = name, soft-delete column deleted_at).
     */
    public function scopeClosureHierarchy(): ClosureHierarchy
    {
        return new ClosureHierarchy(
            nodeTable: 'scope_fixture_nodes',
            closureTable: 'scope_fixture_closures',
            keyColumn: 'code',
            labelColumn: 'name',
        );
    }

    /**
     * A FlatHierarchy over the fixture node table (no parent semantics; key = code, label = name).
     */
    public function scopeFlatHierarchy(): FlatHierarchy
    {
        return new FlatHierarchy(
            table: 'scope_fixture_nodes',
            idColumn: 'id',
            keyColumn: 'code',
            labelColumn: 'name',
            deletedAtColumn: 'deleted_at',
        );
    }

    /**
     * Soft-delete the fixture node with the given code (closure rows are kept, as an application soft delete would).
     */
    public function softDelete(string $code): void
    {
        DB::table('scope_fixture_nodes')->where('id', $this->nodeId($code))->update(['deleted_at' => now()]);
    }

    /**
     * Delete every closure row that mentions the given node, simulating a node whose hierarchy data is missing.
     */
    public function deleteClosureRows(string $code): void
    {
        $id = $this->nodeId($code);

        DB::table('scope_fixture_closures')
            ->where('ancestor_id', $id)
            ->orWhere('descendant_id', $id)
            ->delete();
    }
}
