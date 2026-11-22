<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Add the named values that fill the placeholders of a translated task message.
     */
    public function up(): void
    {
        if (! Schema::hasTable(CoreTableConstant::BACKGROUND_TASKS) || Schema::hasColumn(CoreTableConstant::BACKGROUND_TASKS, 'message_params')) {
            return;
        }

        Schema::table(CoreTableConstant::BACKGROUND_TASKS, static function (Blueprint $table): void {
            $table->json('message_params')->nullable()->after('message');
        });
    }

    /**
     * Drop the message params column.
     */
    public function down(): void
    {
        if (! Schema::hasTable(CoreTableConstant::BACKGROUND_TASKS) || ! Schema::hasColumn(CoreTableConstant::BACKGROUND_TASKS, 'message_params')) {
            return;
        }

        Schema::table(CoreTableConstant::BACKGROUND_TASKS, static function (Blueprint $table): void {
            $table->dropColumn('message_params');
        });
    }
};
