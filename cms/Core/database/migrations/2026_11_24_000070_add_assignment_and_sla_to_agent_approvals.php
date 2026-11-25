<?php

declare(strict_types=1);

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Approval assignee and SLA (2026-11-24): who a workflow step assigned an approval to, when it is due, and when and by
 * whom a person decided it (response time, SLA met). Additive and nullable: approvals created before keep null columns.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable(Tables::AGENT_APPROVAL) && !Schema::hasColumn(Tables::AGENT_APPROVAL, 'due_at')) {
            Schema::table(Tables::AGENT_APPROVAL, static function (Blueprint $table): void {
                $table->timestamp('due_at')->nullable()->index();
                $table->unsignedInteger('sla_seconds')->nullable();
                $table->timestamp('decided_at')->nullable();
                $table->unsignedInteger('response_seconds')->nullable();
                $table->boolean('sla_met')->nullable();
                $table->timestamp('sla_breached_at')->nullable()->index();
                $table->foreignId('decided_by_user_id')->nullable()->constrained(Tables::USERS)->nullOnDelete();
            });
        }

        if (Schema::hasTable(Tables::AGENT_APPROVAL) && !Schema::hasTable(Tables::AGENT_APPROVAL_ASSIGNEE)) {
            Schema::create(Tables::AGENT_APPROVAL_ASSIGNEE, static function (Blueprint $table): void {
                $table->id();
                $table->foreignId('approval_id')->constrained(Tables::AGENT_APPROVAL)->cascadeOnDelete();
                $table->foreignId('user_id')->constrained(Tables::USERS)->cascadeOnDelete();
                $table->timestamp('created_at')->nullable();

                $table->unique(['approval_id', 'user_id'], 'agent_approval_assignees_pair_unique');
                $table->index('user_id', 'agent_approval_assignees_user_index');
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists(Tables::AGENT_APPROVAL_ASSIGNEE);

        if (Schema::hasTable(Tables::AGENT_APPROVAL) && Schema::hasColumn(Tables::AGENT_APPROVAL, 'due_at')) {
            Schema::table(Tables::AGENT_APPROVAL, static function (Blueprint $table): void {
                $table->dropConstrainedForeignId('decided_by_user_id');
                $table->dropIndex(['due_at']);
                $table->dropIndex(['sla_breached_at']);
                $table->dropColumn(['due_at', 'sla_seconds', 'decided_at', 'response_seconds', 'sla_met', 'sla_breached_at']);
            });
        }
    }
};
