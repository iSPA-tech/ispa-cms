<?php
/**********************************************************************************************************************
 * Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                       *
 **********************************************************************************************************************/

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What SHAPE of value an attribute holds, as distinct from how it is rendered.
 *
 * The review sheet has always decided this — every proposed attribute carries
 * a `kind` inferred from real sample values — but the importer dropped the
 * column, so nothing downstream could act on it. Two consequences showed up
 * the moment the values were audited:
 *
 *  - the value canonicalizer has no contract to work from. "205/45 R17 W84"
 *    and "1500" need opposite treatment, and only the attribute knows which.
 *  - the audit cannot tell a defect from a design. It reported
 *    `vehicle_tire_size` as "a measure with 2,147 non-numeric values" when
 *    tire size was never a measure at all.
 *
 * Deliberately separate from `input_type`, which is the admin form control,
 * and from `display_tier`, which is presentation. This is about parsing.
 *
 *   measure  a number, canonicalised into the attribute's unit
 *   bool     yes / no, however the source spells it
 *   enum     a small closed set worth filtering on
 *   text     free text; never filtered, never converted
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
            $table->string('value_kind', 16)->nullable()->after('unit');
            $table->index('value_kind', 'meta_attributes_value_kind_idx');
        });
    }

    public function down(): void
    {
        Schema::table(CoreTableConstant::META_ATTRIBUTES, function (Blueprint $table): void {
            $table->dropIndex('meta_attributes_value_kind_idx');
            $table->dropColumn('value_kind');
        });
    }
};
