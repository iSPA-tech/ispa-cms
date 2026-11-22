<?php

use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    public function up(): void
    {
        Schema::table(Cms\Content\Constants\ContentTableConstant::POSTS, static function (Blueprint $table) {
            $table->json('review_pipeline')->nullable()->after('config');
        });
    }

    public function down(): void
    {
        Schema::table(Cms\Content\Constants\ContentTableConstant::POSTS, static function (Blueprint $table) {
            $table->dropColumn('review_pipeline');
        });
    }
};
