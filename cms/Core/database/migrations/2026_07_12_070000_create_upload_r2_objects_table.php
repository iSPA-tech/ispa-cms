<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('upload_r2_objects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('upload_id')->nullable()->constrained('uploads')->nullOnDelete();
            // nullable: a filesystem-orphan file (no matching uploads row at all) still needs to be
            // tracked and synced — every discoverable file gets replicated, nothing is excluded.
            $table->string('relative_path')->unique(); // doubles as local relative path AND R2 object key
            $table->string('source_disk', 32)->default('public'); // stored independently — survives upload_id being nulled
            $table->string('kind', 16)->default('original'); // 'original' | 'variant' — plain string, not a DB enum, cheaper to extend later
            $table->unsignedBigInteger('source_size')->nullable();
            $table->timestamp('source_mtime')->nullable();
            $table->char('source_sha256', 64)->nullable(); // computed locally — a useful signal, but see the note below on what it does and doesn't prove
            $table->char('previous_sha256', 64)->nullable(); // retained when quarantined, for operator review via upload:r2-approve-change
            // status values: pending | synced | verified | failed | missing_source | content_changed
            $table->string('status', 24)->default('pending');
            $table->unsignedInteger('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamp('head_checked_at')->nullable(); // last cheap existence+size check — does NOT imply byte-level proof
            $table->timestamp('verified_at')->nullable();      // ONLY ever set by a real HASH-mode remote-bytes check — this IS the byte-accuracy proof
            $table->timestamp('next_retry_at')->nullable();
            $table->boolean('needs_cache_purge')->default(false); // manual-review signal only — no automated purge exists
            $table->timestamps();

            $table->index('status');
            $table->index('upload_id');
            $table->index('next_retry_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('upload_r2_objects');
    }
};
