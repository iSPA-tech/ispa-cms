<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Cms\Core\Constants\CoreTableConstant as Tables;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table(Tables::AGENT_RUN, function (Blueprint $table) {
            $table->string('operator_request_state')->nullable()->after('post_approval_outcome');
            $table->unsignedBigInteger('stop_requested_by_user_id')->nullable()->after('operator_request_state');
            $table->timestamp('stop_requested_at')->nullable()->after('stop_requested_by_user_id');
            $table->timestamp('stop_acknowledged_at')->nullable()->after('stop_requested_at');
        });
    }

    public function down(): void
    {
        Schema::table(Tables::AGENT_RUN, function (Blueprint $table) {
            $table->dropColumn([
                'operator_request_state',
                'stop_requested_by_user_id',
                'stop_requested_at',
                'stop_acknowledged_at',
            ]);
        });
    }
};
