<?php

/**
 * Fixes workflow_instance_steps.step_id FK (was pointing to workflow_instances).
 * Drops incorrect FK if present, then adds FK to workflow_steps.id; data preserved.
 * Down reverts by dropping corrected FK and re-adding previous FK to workflow_instances when tables exist.
 */

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('workflow_instance_steps') || !Schema::hasColumn('workflow_instance_steps','step_id')) {
            return;
        }

        $this->dropForeignIfExists('workflow_instance_steps', 'workflow_instance_steps_step_id_foreign');

        Schema::table('workflow_instance_steps', function (Blueprint $table) {
            $table->foreign('step_id')
                ->references('id')
                ->on('workflow_steps')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('workflow_instance_steps') || !Schema::hasColumn('workflow_instance_steps','step_id')) {
            return;
        }

        $this->dropForeignIfExists('workflow_instance_steps', 'workflow_instance_steps_step_id_foreign');

        if (Schema::hasTable('workflow_instances')) {
            Schema::table('workflow_instance_steps', function (Blueprint $table) {
                $table->foreign('step_id')
                    ->references('id')
                    ->on('workflow_instances')
                    ->cascadeOnDelete();
            });
        }
    }

    private function dropForeignIfExists(string $table, string $constraint): void
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            return;
        }

        if ($driver === 'pgsql') {
            $schema = DB::selectOne('select current_schema() as schema')->schema ?? 'public';
            $exists = DB::table('information_schema.table_constraints')
                ->where('constraint_schema', $schema)
                ->where('table_name', $table)
                ->where('constraint_name', $constraint)
                ->where('constraint_type', 'FOREIGN KEY')
                ->exists();
        } else {
            $database = $connection->getDatabaseName();

            $exists = DB::table('information_schema.REFERENTIAL_CONSTRAINTS')
                ->where('CONSTRAINT_SCHEMA', $database)
                ->where('TABLE_NAME', $table)
                ->where('CONSTRAINT_NAME', $constraint)
                ->exists();
        }

        if ($exists) {
            Schema::table($table, function (Blueprint $table) use ($constraint) {
                $table->dropForeign($constraint);
            });
        }
    }
};
