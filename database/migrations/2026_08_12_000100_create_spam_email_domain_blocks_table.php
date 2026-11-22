<?php

use App\Constants\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable(Tables::SPAM_EMAIL_DOMAIN_BLOCKS)) {
            return;
        }

        Schema::create(Tables::SPAM_EMAIL_DOMAIN_BLOCKS, static function (Blueprint $table): void {
            $table->id();
            $table->char('domain_hash', 64)->unique();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::SPAM_EMAIL_DOMAIN_BLOCKS);
    }
};
