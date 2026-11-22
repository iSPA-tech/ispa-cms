<?php

namespace App\Jobs;

use App\Services\Moderation\AutoModerationService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class AutoModerateContentJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $newData;
    public $oldData;
    public $modelId;
    public $modelClass;

    /**
     * Create a new job instance.
     */
    public function __construct(string $modelClass, int $modelId, array $newData, ?array $oldData = null)
    {
        $this->modelClass = $modelClass;
        $this->modelId = $modelId;
        $this->newData = $newData;
        $this->oldData = $oldData;
    }

    /**
     * Execute the job.
     */
    public function handle(AutoModerationService $service): void
    {
        try {
            $model = $this->modelClass::find($this->modelId);
            if (!$model) {
                Log::warning("Model {$this->modelClass} with ID {$this->modelId} not found in AutoModerateContentJob.");
                return;
            }

            // Gọi service 4 lớp để duyệt
            $result = $service->moderate($this->newData, $this->oldData);

            // Cập nhật trạng thái vào DB
            $model->moderation_status = $result['status'];
            $model->moderation_reason = $result['status'] === 'moderation_rejected' ? $result['message'] : null;
            $model->moderation_layer = $result['layer'] ?? null;
            
            // Xử lý các logic khác nếu cần thiết khi model thay đổi
            // (vd: bắn event, gửi mail cho user...)

            $model->save();

            Log::info("Auto Moderation finished for {$this->modelClass} ID {$this->modelId}: {$result['status']}", $result);
        } catch (\Exception $e) {
            Log::error('AutoModerateContentJob failed: ' . $e->getMessage());
            // Tuỳ thuộc vào nghiệp vụ, nếu Job sập, bạn có thể để PENDING_AUTO_MODERATION 
            // để admin tay duyệt.
        }
    }
}
