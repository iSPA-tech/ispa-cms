<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Create the data-scope grant and assignment tables (idempotent).
     */
    public function up(): void
    {
        if (!Schema::hasTable(CoreTableConstant::DATA_SCOPE_GRANTS)) {
            Schema::create(CoreTableConstant::DATA_SCOPE_GRANTS, static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('role_id')->constrained(CoreTableConstant::ROLES)->cascadeOnDelete();
                $table->string('permission_key', 191);
                $table->string('level', 64);
                $table->timestamps();

                $table->unique(['role_id', 'permission_key'], 'data_scope_grants_role_perm_unique');
            });
        }

        if (!Schema::hasTable(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS)) {
            Schema::create(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS, static function (Blueprint $table): void {
                $table->id();
                $table->uuid('uuid')->unique();
                $table->string('subject_type', 16);
                $table->unsignedBigInteger('subject_id');
                $table->string('dimension', 64);
                $table->unsignedBigInteger('node_id');
                $table->string('expansion', 16);
                $table->unsignedBigInteger('assigned_by_user_id')->nullable();
                $table->timestamps();

                $table->unique(['subject_type', 'subject_id', 'dimension', 'node_id'], 'data_scope_assignments_subject_node_unique');
                $table->index(['dimension', 'node_id'], 'data_scope_assignments_dimension_node_index');
            });
        }
    }

    /**
     * Drop both tables, assignments first.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::DATA_SCOPE_ASSIGNMENTS);
        Schema::dropIfExists(CoreTableConstant::DATA_SCOPE_GRANTS);
    }
};
