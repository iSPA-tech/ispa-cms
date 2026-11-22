<?php

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::SEED_FINGERPRINTS)) {
            return;
        }

        Schema::create(CoreTableConstant::SEED_FINGERPRINTS, static function (Blueprint $table): void {
            $table->id();
            $table->string('fingerprint_key', 160)->unique();
            $table->string('seeder_class', 255);
            $table->char('source_hash', 64);
            $table->unsignedInteger('source_count')->default(0);
            $table->unsignedInteger('seeder_version')->default(1);
            $table->string('status', 20)->default('completed')->index();
            $table->json('metadata')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['seeder_class', 'status'], 'core_seed_fp_class_status_idx');
            $table->index(['source_hash', 'seeder_version'], 'core_seed_fp_hash_version_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::SEED_FINGERPRINTS);
    }
};
