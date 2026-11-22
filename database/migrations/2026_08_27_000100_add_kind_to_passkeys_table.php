<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('passkeys', static function (Blueprint $table): void {
            $table->string('kind')->default('passkey')->after('name');
            $table->index(['user_id', 'kind']);
        });
    }

    public function down(): void
    {
        Schema::table('passkeys', static function (Blueprint $table): void {
            $table->dropIndex(['user_id', 'kind']);
            $table->dropColumn('kind');
        });
    }
};
