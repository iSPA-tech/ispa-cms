<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * User tags move into the shared `tags` table: `type` (post|user) and `uuid` are added to `tags`, `source` to `tags_data`,
 * rows of the short-lived `user_tags` / `user_tag_user` tables are copied over and those tables dropped. Every step is guarded, so the
 * migration is idempotent and safe on sites that never created the old tables.
 */
return new class extends Migration
{
    /** Add the columns and indexes, copy the old user tags, drop the old tables. */
    public function up(): void
    {
        if (!Schema::hasTable('tags')) {
            return;
        }
        if (!Schema::hasColumn('tags', 'type')) {
            Schema::table('tags', static function (Blueprint $table): void {
                $table->string('type', 20)->default('post');
            });
        }
        if (!Schema::hasColumn('tags', 'uuid')) {
            Schema::table('tags', static function (Blueprint $table): void {
                $table->uuid('uuid')->nullable();
            });
        }
        DB::table('tags')->whereNull('uuid')->orderBy('id')->chunkById(500, static function ($rows): void {
            foreach ($rows as $row) {
                DB::table('tags')->where('id', $row->id)->update(['uuid' => (string) Str::uuid()]);
            }
        });
        $this->ensureIndexes();

        if (Schema::hasTable('tags_data') && !Schema::hasColumn('tags_data', 'source')) {
            Schema::table('tags_data', static function (Blueprint $table): void {
                $table->string('source', 16)->nullable();
            });
        }
        $this->copyUserTags();
        Schema::dropIfExists('user_tag_user');
        Schema::dropIfExists('user_tags');
    }

    /** Drop the added indexes and columns; user tags are not restored (the old tables are not recreated). */
    public function down(): void
    {
        if (Schema::hasTable('tags_data') && Schema::hasColumn('tags_data', 'source')) {
            Schema::table('tags_data', static fn (Blueprint $table) => $table->dropColumn('source'));
        }
        if (Schema::hasTable('tags')) {
            DB::table('tags')->where('type', 'user')->delete();
            foreach (['tags_type_slug_index', 'tags_type_slug_unique', 'tags_uuid_unique'] as $index) {
                if (Schema::hasIndex('tags', $index)) {
                    Schema::table('tags', static fn (Blueprint $table) => $table->dropIndex($index));
                }
            }
            if (Schema::hasColumn('tags', 'type')) {
                Schema::table('tags', static fn (Blueprint $table) => $table->dropColumn('type'));
            }
        }
    }

    /** uuid unique, slug unique per type (replacing the global slug unique), a (type, slug) lookup index. */
    private function ensureIndexes(): void
    {
        if (Schema::hasIndex('tags', 'tags_slug_unique')) {
            Schema::table('tags', static fn (Blueprint $table) => $table->dropUnique('tags_slug_unique'));
        }
        if (!Schema::hasIndex('tags', 'tags_type_slug_unique')) {
            Schema::table('tags', static fn (Blueprint $table) => $table->unique(['type', 'slug'], 'tags_type_slug_unique'));
        }
        if (!Schema::hasIndex('tags', 'tags_uuid_unique')) {
            Schema::table('tags', static fn (Blueprint $table) => $table->unique('uuid', 'tags_uuid_unique'));
        }
    }

    /** Copy `user_tags` (keeping uuid, name, slug) as type `user` and the pairs as `tags_data` rows targeting App\Models\User. */
    private function copyUserTags(): void
    {
        if (!Schema::hasTable('user_tags')) {
            return;
        }
        $map = [];
        foreach (DB::table('user_tags')->orderBy('id')->get() as $old) {
            $existing = DB::table('tags')->where('type', 'user')->where('slug', $old->slug)->first();
            $map[$old->id] = $existing->id ?? DB::table('tags')->insertGetId([
                'type' => 'user', 'uuid' => $old->uuid, 'name' => $old->name, 'slug' => $old->slug,
                'created_at' => $old->created_at, 'updated_at' => $old->updated_at,
            ]);
        }
        if (!Schema::hasTable('user_tag_user') || !Schema::hasTable('tags_data')) {
            return;
        }
        DB::table('user_tag_user')->orderBy('tag_id')->orderBy('user_id')->each(function ($pair) use ($map): void {
            if (!isset($map[$pair->tag_id])) {
                return;
            }
            $key = ['target_type' => \App\Models\User::class, 'target_id' => $pair->user_id, 'tag_id' => $map[$pair->tag_id]];
            if (!DB::table('tags_data')->where($key)->exists()) {
                DB::table('tags_data')->insert($key + ['source' => $pair->source, 'created_at' => $pair->created_at, 'updated_at' => $pair->created_at]);
            }
        });
    }
};
