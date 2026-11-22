<?php

use App\Constants\Tables;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table(Tables::EMAIL_TEMPLATES, static function (Blueprint $table) {
            $table->string('group', 50)->default('general')->after('name')->index();
            $table->string('blade_layout', 100)->nullable()->after('group')->index();
            $table->json('variables')->nullable()->after('content');
            $table->json('sample_data')->nullable()->after('variables');
            $table->string('description', 500)->nullable()->after('sample_data');

            // Remove unique constraint on key_name if exists
            $indexes = collect(Schema::getIndexes(Tables::EMAIL_TEMPLATES));
            $existKeyNameUnique = $indexes->firstWhere('name', 'email_templates_key_name_unique');
            if ($existKeyNameUnique) {
                $table->dropUnique('email_templates_key_name_unique');
            }
            //  Todo: remove this line after few versions
            $existGroupKeyUnique = $indexes->firstWhere('name', 'email_templates_group_key_name_unique');
            if ($existGroupKeyUnique) {
                $table->dropUnique('email_templates_group_key_name_unique');
            }
            //  Add unique constraint on group + key_name + language_id
            $existGroupKeyUnique = $indexes->firstWhere('name', 'email_templates_group_key_name_language_unique');
            if (!$existGroupKeyUnique) {
                $table->unique(['group', 'key_name', 'language_id'], 'email_templates_group_key_name_language_unique');
            }
        });

        Schema::table(Tables::EMAIL_LOGS, static function (Blueprint $table) {
            $table->after('view', static function (Blueprint $table) {
                $table->string('from_email', 100)->nullable()->index();
                $table->string('from_name', 100)->nullable();
                $table->string('error_code', 50)->nullable()->index();
                $table->text('error_message')->nullable();
                $table->string('dedupe_key', 100)->nullable()->index();
                $table->morphs('target');
            });
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table(Tables::EMAIL_TEMPLATES, static function (Blueprint $table) {
            // Remove unique constraint on group + key_name
            $indexes = collect(Schema::getIndexes(Tables::EMAIL_TEMPLATES));
            $existGroupKeyUnique = $indexes->firstWhere('name', 'email_templates_group_key_name_language_unique');
            if ($existGroupKeyUnique) {
                $table->dropUnique('email_templates_group_key_name_language_unique');
            }
            //  Todo: remove this line after few versions
            $existGroupKeyUnique = $indexes->firstWhere('name', 'email_templates_group_key_name_unique');
            if ($existGroupKeyUnique) {
                $table->dropUnique('email_templates_group_key_name_unique');
            }
            $existKeyNameUnique = $indexes->firstWhere('name', 'email_templates_key_name_unique');
            if (!$existKeyNameUnique) {
                $table->unique('key_name', 'email_templates_key_name_unique');
            }
            //  remove added columns after
            $table->dropColumn(['group', 'blade_layout', 'variables', 'sample_data', 'description']);
        });
        Schema::table(Tables::EMAIL_LOGS, static function (Blueprint $table) {
            $table->dropColumn([
                'from_email', 'from_name', 'error_code', 'error_message',
                'dedupe_key', 'target_type', 'target_id',
            ]);
        });
    }
};
