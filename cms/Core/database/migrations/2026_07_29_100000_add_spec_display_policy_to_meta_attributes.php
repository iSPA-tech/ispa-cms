<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public spec formatting belongs to the attribute contract.
 *
 * `hide_zero` is opt-in because zero is a valid physical value for some
 * measurements. `display_precision` changes only the render projection; the
 * canonical value stored in meta_data keeps its full precision. String display
 * labels live on the existing attribute/value pivot because one raw value can
 * mean different things for different attributes.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
            $table->unsignedTinyInteger('display_precision')->nullable()->after('value_kind');
            $table->boolean('hide_zero')->default(false)->after('display_precision');
        });

        Schema::table(CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, function (Blueprint $table): void {
            $table->string('display_label')->nullable()->after('value_id');
        });
    }

    public function down(): void
    {
        Schema::table(CoreTableConstant::META_ATTRIBUTE_VALUE_PIVOT, function (Blueprint $table): void {
            $table->dropColumn('display_label');
        });

        Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
            $table->dropColumn(['display_precision', 'hide_zero']);
        });
    }
};
