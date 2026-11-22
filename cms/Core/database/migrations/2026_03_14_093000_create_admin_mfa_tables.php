<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Constants\CoreTableConstant;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create(CoreTableConstant::USER_MFA_FACTORS, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')
                ->constrained(CoreTableConstant::USERS)
                ->cascadeOnDelete();
            $table->string('provider', 32);
            $table->string('label')->nullable();
            $table->string('issuer')->nullable();
            $table->text('secret')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->timestamp('enabled_at')->nullable();
            $table->timestamp('disabled_at')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('last_challenged_at')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'provider']);
        });

        Schema::create(CoreTableConstant::USER_MFA_RECOVERY_CODES, static function (Blueprint $table): void {
            $table->id();
            $table->foreignId('factor_id')
                ->constrained(CoreTableConstant::USER_MFA_FACTORS)
                ->cascadeOnDelete();
            $table->foreignId('user_id')
                ->constrained(CoreTableConstant::USERS)
                ->cascadeOnDelete();
            $table->string('code_hash', 64)->unique();
            $table->string('code_hint', 16)->nullable();
            $table->timestamp('used_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'used_at']);
        });

        Schema::create(CoreTableConstant::AUTH_CHALLENGES, static function (Blueprint $table): void {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->foreignId('user_id')
                ->constrained(CoreTableConstant::USERS)
                ->cascadeOnDelete();
            $table->string('status', 32)->default('pending');
            $table->json('required_methods');
            $table->json('completed_methods')->nullable();
            $table->dateTime('expires_at');
            $table->unsignedSmallInteger('attempt_count')->default(0);
            $table->string('email_code_hash', 64)->nullable();
            $table->timestamp('email_code_sent_at')->nullable();
            $table->timestamp('email_code_expires_at')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->boolean('remember')->default(false);
            $table->json('context')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists(CoreTableConstant::AUTH_CHALLENGES);
        Schema::dropIfExists(CoreTableConstant::USER_MFA_RECOVERY_CODES);
        Schema::dropIfExists(CoreTableConstant::USER_MFA_FACTORS);
    }
};
