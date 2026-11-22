<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Creates the standard Laravel database notifications table used for in-app admin notifications.
 */
return new class extends Migration
{
    /**
     * Create the notifications table when it does not exist yet.
     */
    public function up(): void
    {
        if (Schema::hasTable('notifications')) {
            return;
        }

        Schema::create('notifications', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('type');
            $table->morphs('notifiable');
            $table->text('data');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['notifiable_type', 'notifiable_id', 'read_at', 'created_at'], 'notif_owner_read_created_idx');
        });
    }

    /**
     * Drop the notifications table when present.
     */
    public function down(): void
    {
        Schema::dropIfExists('notifications');
    }
};
