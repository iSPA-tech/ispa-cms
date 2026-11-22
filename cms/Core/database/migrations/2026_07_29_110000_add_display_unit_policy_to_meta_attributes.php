<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep canonical units stable while allowing reader-friendly render units.
 *
 * The multiplier applies only to the disposable display projection. Stored
 * meta_data and canonical measurement units remain unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
            $table->string('display_unit', 32)->nullable()->after('display_precision');
            $table->decimal('display_multiplier', 20, 10)->nullable()->after('display_unit');
        });
    }

    public function down(): void
    {
        Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
            $table->dropColumn(['display_unit', 'display_multiplier']);
        });
    }
};
