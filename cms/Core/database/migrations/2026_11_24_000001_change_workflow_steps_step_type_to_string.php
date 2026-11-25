<?php

/**
 * workflow_steps.step_type: enum of 13 legacy values -> string(64).
 *
 * The runner registry (core.workflow.step_runners) knows types such as `retrieve`, `ai_reasoning`, `create_object` or
 * `loop_each` that the old enum did not list, so the builder saved them as `script` (MySQL/MariaDB) and the step
 * lost its runner. Existing values are kept unchanged; allowed values are now checked in code
 * (Cms\Core\Workflows\Runtime\StepTypeCatalog). PostgreSQL stored the enum as varchar + CHECK constraint, which is
 * dropped. down() keeps the string column: narrowing back to the enum would fail or truncate rows that use the
 * new types.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('workflow_steps') || !Schema::hasColumn('workflow_steps', 'step_type')) {
            return;
        }

        if (DB::getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE workflow_steps DROP CONSTRAINT IF EXISTS workflow_steps_step_type_check');
        }

        Schema::table('workflow_steps', function (Blueprint $table) {
            $table->string('step_type', 64)->change();
        });
    }

    public function down(): void
    {
        // Intentionally kept as string: rows may now hold runner types the old enum cannot store.
    }
};
