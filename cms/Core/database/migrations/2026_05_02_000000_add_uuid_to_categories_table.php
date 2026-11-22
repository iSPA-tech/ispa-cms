<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up(): void
    {
        if (Schema::hasColumn(CoreTableConstant::CATEGORIES, 'uuid')) {
            return;
        }

        Schema::table(CoreTableConstant::CATEGORIES, function (Blueprint $table) {
            $table->char('uuid', 36)->nullable()->unique()->after('uid');
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down(): void
    {
        Schema::table(CoreTableConstant::CATEGORIES, function (Blueprint $table) {
            $table->dropColumn('uuid');
        });
    }
};
