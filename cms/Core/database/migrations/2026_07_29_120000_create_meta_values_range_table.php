<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::META_VALUES_RANGE)) {
            return;
        }

        Schema::create(CoreTableConstant::META_VALUES_RANGE, static function (Blueprint $table): void {
            $table->id();
            $table->decimal('minimum', 18, 6);
            $table->decimal('maximum', 18, 6);
            $table->string('relation', 16);
            $table->char('value_hash', 40)->unique();
            $table->timestamps();

            $table->index(['relation', 'minimum', 'maximum'], 'idx_meta_values_range_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::META_VALUES_RANGE);
    }
};
