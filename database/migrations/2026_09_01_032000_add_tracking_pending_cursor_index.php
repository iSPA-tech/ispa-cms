<?php

use App\Constants\Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::TRACKING_EVENT_RECEIPTS, static function (Blueprint $table): void {
            $table->index(
                ['status', 'id'],
                'tracking_receipts_status_cursor_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table(Tables::TRACKING_EVENT_RECEIPTS, static function (Blueprint $table): void {
            $table->dropIndex('tracking_receipts_status_cursor_idx');
        });
    }
};
