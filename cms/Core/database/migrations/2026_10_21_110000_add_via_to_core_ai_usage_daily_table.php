<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the `via` column telling whether a row was written by AiTaskRuntime or a directly metered call.
     */
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::AI_USAGE_DAILY) || Schema::hasColumn(CoreTableConstant::AI_USAGE_DAILY, 'via')) {
            return;
        }

        Schema::table(CoreTableConstant::AI_USAGE_DAILY, static function (Blueprint $table): void {
            $table->string('via', 20)->default('runtime');
        });
    }

    /**
     * Drop the `via` column.
     */
    public function down(): void
    {
        if (Schema::hasTable(CoreTableConstant::AI_USAGE_DAILY) && Schema::hasColumn(CoreTableConstant::AI_USAGE_DAILY, 'via')) {
            Schema::table(CoreTableConstant::AI_USAGE_DAILY, static function (Blueprint $table): void {
                $table->dropColumn('via');
            });
        }
    }
};
