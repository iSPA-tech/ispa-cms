<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Create the shared webhook inbox (one row per provider and event id).
     */
    public function up(): void
    {
        if (Schema::hasTable(CoreTableConstant::WEBHOOK_RECEIPTS)) {
            return;
        }

        Schema::create(CoreTableConstant::WEBHOOK_RECEIPTS, static function (Blueprint $table): void {
            $table->id();
            $table->string('provider', 40);
            $table->string('event_id', 191);
            $table->string('status', 20);
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->longText('payload');
            // dateTime, not timestamp: production MariaDB 10.6 runs with explicit_defaults_for_timestamp OFF.
            $table->dateTime('received_at');
            $table->dateTime('processed_at')->nullable();
            $table->text('last_error')->nullable();
            $table->timestamps();

            $table->unique(['provider', 'event_id'], 'core_webhook_receipts_event_unique');
            $table->index(['status', 'received_at'], 'core_webhook_receipts_status_idx');
        });
    }

    /**
     * Drop the shared webhook inbox.
     */
    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::WEBHOOK_RECEIPTS);
    }
};
