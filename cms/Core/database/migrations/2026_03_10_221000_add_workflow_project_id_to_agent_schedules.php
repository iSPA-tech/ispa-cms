<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table(Tables::AGENT_SCHEDULE, static function (Blueprint $table) {
            $table->foreignId('workflow_project_id')
                ->nullable()
                ->after('tool_definition_id')
                ->constrained(Tables::WORKFLOW_PROJECT)
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table(Tables::AGENT_SCHEDULE, static function (Blueprint $table) {
            $table->dropConstrainedForeignId('workflow_project_id');
        });
    }
};
