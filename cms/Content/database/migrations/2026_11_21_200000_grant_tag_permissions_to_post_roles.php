<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * The admin tag API checks `tag.viewAny`, `tag.create`, `tag.update` and `tag.delete` from 2026-11-21 (before, any
 * admin user could change tags). This creates those permissions and gives every role the tag rights matching the post
 * rights it already has (`post.update` -> `tag.update`, ...), so the people who edit posts keep managing tags.
 * Idempotent; a fresh install gets the rows from Core's PermissionTableSeed and Webmaster gets them all from RoleTableSeed.
 */
return new class extends Migration {
    private const TITLES = [
        'viewAny' => 'View All | Xem tất cả',
        'create' => 'Create new | Tạo mới',
        'update' => 'Post Update | Cập nhật - Post',
        'delete' => 'Delete | Xóa',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('permission_role')) {
            return;
        }

        foreach (self::TITLES as $ability => $title) {
            $tagId = DB::table('permissions')->where('group', 'tag')->where('name', $ability)->value('id');
            if ($tagId === null) {
                $tagId = DB::table('permissions')->insertGetId([
                    'group' => 'tag', 'name' => $ability, 'title' => $title, 'created_at' => now(), 'updated_at' => now(),
                ]);
            }

            $postIds = DB::table('permissions')->where('group', 'post')->where('name', $ability)->whereNull('deleted_at')->pluck('id');
            $roleIds = DB::table('permission_role')->whereIn('permission_id', $postIds)->distinct()->pluck('role_id');
            $granted = DB::table('permission_role')->where('permission_id', $tagId)->pluck('role_id')->all();
            foreach ($roleIds->diff($granted) as $roleId) {
                DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $tagId]);
            }
        }
    }

    public function down(): void
    {
        if (!Schema::hasTable('permissions') || !Schema::hasTable('permission_role')) {
            return;
        }

        $ids = DB::table('permissions')->where('group', 'tag')->whereIn('name', array_keys(self::TITLES))->pluck('id');
        DB::table('permission_role')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();
    }
};
