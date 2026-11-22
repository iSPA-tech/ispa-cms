<?php

use Cms\Core\Constants\CoreTableConstant as Tables;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Migrations\Migration;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create(Tables::WORKFLOW_PROJECT, static function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();                    // Định danh ví dụ: order_flow
            $table->string('trigger_event')->nullable();         // Ví dụ: order.created
            $table->string('target_entity_type');                // App\Models\Order
            $table->boolean('is_active')->default(true);         // Cho phép chạy hoặc tắt flow
            $table->json('tags')->nullable();                    // ["order", "assistant"]
            $table->text('description')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['created_at']);
        });

        Schema::create(Tables::WORKFLOW_STEP, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()
                ->constrained(Tables::WORKFLOW_PROJECT)->onDelete('cascade');
            $table->string('step_code')->index()->nullable(); // step_a, step_b
            $table->string('name')->nullable()->index();  // Tên bước, ví dụ: "Xử lý đơn hàng"
            $table->enum('step_type', [
                'event', 'job', 'call_workflow', 'decision', 'webhook', 'ai_action', 'script', 'set_variable',
                'delay', 'schedule_at', 'approval', 'loop', 'parallel',
            ]);
            $table->json('step_config')->nullable();             // { job: ..., delay: ..., etc }
            $table->json('input_schema')->nullable();            // Gợi ý UI input
            $table->json('output_schema')->nullable();           // Gợi ý dữ liệu đầu ra
            $table->integer('position_x')->default(0);           // Tọa độ trong VueFlow
            $table->integer('position_y')->default(0);
            $table->boolean('is_start')->default(false);         // Có phải điểm bắt đầu
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['created_at']);
        });

        Schema::create(Tables::WORKFLOW_CONNECTION, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()
                ->constrained(Tables::WORKFLOW_PROJECT)->onDelete('cascade');
            $table->foreignId('from_step_id')->nullable()
                ->constrained(Tables::WORKFLOW_STEP)->onDelete('cascade');
            $table->foreignId('to_step_id')
                ->nullable()->constrained(Tables::WORKFLOW_STEP)->onDelete('cascade');
            $table->json('condition')->nullable();               // { "if": "target.status == 'paid'" }
            $table->timestamps();
            $table->softDeletes()->index();

            $table->index(['created_at']);
        });

        Schema::create(Tables::WORKFLOW_INSTANCE, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()
                ->constrained(Tables::WORKFLOW_PROJECT)->onDelete('cascade');
            $table->morphs('target');                            // target_type, target_id
            $table->enum('status', ['pending', 'running', 'completed', 'failed'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
        });

        Schema::create(Tables::WORKFLOW_INSTANCE_STEP, static function (Blueprint $table) {
            $table->id();
            $table->foreignId('instance_id')->nullable()
                ->constrained(Tables::WORKFLOW_INSTANCE)->onDelete('cascade');
            $table->foreignId('step_id')->nullable()
                ->constrained(Tables::WORKFLOW_INSTANCE)->onDelete('cascade');
            $table->enum('status', ['pending', 'running', 'success', 'failed', 'skipped'])->default('pending');
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->unsignedTinyInteger('retry_count')->default(0);
            $table->json('input_data')->nullable();
            $table->json('result_data')->nullable();
            $table->text('error_message')->nullable();
            $table->timestamps();
            $table->softDeletes()->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists(Tables::WORKFLOW_INSTANCE_STEP);
        Schema::dropIfExists(Tables::WORKFLOW_INSTANCE);
        Schema::dropIfExists(Tables::WORKFLOW_CONNECTION);
        Schema::dropIfExists(Tables::WORKFLOW_STEP);
        Schema::dropIfExists(Tables::WORKFLOW_PROJECT);
    }
};
