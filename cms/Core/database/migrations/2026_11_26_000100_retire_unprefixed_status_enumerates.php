<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Finishes the enum_key prefix change (draft → status_draft, …) on installs that never ran the one-off
 * cms:migrate-enumerate-prefix command. The enumerate seeder upserts by enum_key, so on those installs it
 * added the prefixed rows next to the old ones and every status list (the post form's "Trạng thái" radios
 * among them) showed Công khai / Chờ duyệt / Lưu nháp twice.
 *
 * For each old key: make sure the prefixed row exists (copy the old row when it does not), point the status
 * columns that still hold the old key at the prefixed one, then soft-delete the old row. Idempotent.
 */
return new class extends Migration {
    /** type => [old enum_key => prefixed enum_key] */
    private const KEY_MAPPING = [
        'status' => [
            'draft' => 'status_draft',
            'pending' => 'status_pending',
            'published' => 'status_published',
            'archived' => 'status_archived',
        ],
        'organization_status' => [
            'awaiting_review' => 'organization_status_awaiting_review',
            'is_reviewed' => 'organization_status_is_reviewed',
        ],
        'organization_verified' => [
            'awaiting_verified' => 'organization_verified_awaiting',
            'is_verified' => 'organization_verified_confirmed',
        ],
    ];

    /** Tables whose `status` column holds a `status`-type enum_key (same list the prefix command used). */
    private const STATUS_TABLES = [
        'categories', 'languages', 'translates', 'menus', 'fields', 'uploads', 'storages', 'posts', 'galleries',
        'calendars', 'contacts', 'provinces', 'banners', 'contents', 'items', 'places', 'products',
        'product_variants', 'brands', 'product_bundles', 'semesters', 'subjects', 'instructors',
        'research_periods', 'research_questions', 'period_classes', 'timetables', 'period_class_students',
        'real_estate_projects', 'real_estate_investors', 'real_estate_properties', 'coupons', 'assessments',
    ];

    public function up(): void
    {
        if (!Schema::hasTable('enumerates')) {
            return;
        }

        foreach (self::KEY_MAPPING as $type => $mapping) {
            foreach ($mapping as $oldKey => $newKey) {
                $old = DB::table('enumerates')->where('enum_key', $oldKey)->where('type', $type)->first();
                if ($old === null) {
                    continue;
                }

                if (!DB::table('enumerates')->where('enum_key', $newKey)->exists()) {
                    DB::table('enumerates')->insert([
                        'name' => $old->name,
                        'name_alias' => $old->name_alias,
                        'enum_key' => $newKey,
                        'type' => $old->type,
                        'type_id' => $old->type_id,
                        'input_type' => $old->input_type,
                        'is_filter' => $old->is_filter,
                        'status' => $old->status,
                        'created_at' => now(),
                        'updated_at' => now(),
                        'deleted_at' => null,
                    ]);
                }

                if ($type === 'status') {
                    foreach (self::STATUS_TABLES as $table) {
                        if (Schema::hasTable($table) && Schema::hasColumn($table, 'status')) {
                            DB::table($table)->where('status', $oldKey)->update(['status' => $newKey]);
                        }
                    }
                }

                if ($old->deleted_at === null) {
                    DB::table('enumerates')->where('id', $old->id)->update(['deleted_at' => now()]);
                }
            }
        }
    }

    /** Data-only migration: the old keys are retired for good, nothing to restore. */
    public function down(): void
    {
    }
};
