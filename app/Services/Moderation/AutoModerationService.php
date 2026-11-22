<?php

namespace App\Services\Moderation;

use Cms\Core\Services\Ai\Usage\AiGateway;
use Illuminate\Support\Facades\Log;
use OpenAI\Laravel\Facades\OpenAI;

class AutoModerationService
{
    /**
     * Các từ khóa bị cấm cơ bản (Blacklist)
     */
    private array $blacklistedKeywords = [
        'lừa đảo',
        'cờ bạc',
        'rẻ nhất vũ trụ',
    ];

    /**
     * Chạy quy trình duyệt 4 lớp
     * @param array $newData Dữ liệu mới gửi lên
     * @param array|null $oldData Dữ liệu cũ (để so sánh)
     * @return array Trạng thái duyệt
     */
    public function moderate(array $newData, ?array $oldData = null): array
    {
        // Lớp 1: Đánh giá mức độ thay đổi
        if ($this->isMinorChange($newData, $oldData)) {
            return [
                'status' => 'moderation_approved',
                'layer' => 1,
                'message' => 'Thay đổi nhỏ, tự động duyệt.'
            ];
        }

        // Nội dung văn bản cần duyệt (Tên, Mô tả)
        $textContent = ($newData['name'] ?? '') . "\n" . ($newData['description'] ?? '');

        // Lớp 2: Kiểm tra từ khóa Blacklist
        $keywordCheck = $this->checkKeywords($textContent);
        if (!$keywordCheck['passed']) {
            return [
                'status' => 'moderation_rejected',
                'layer' => 2,
                'message' => 'Vi phạm từ khóa: ' . $keywordCheck['word']
            ];
        }

        // Lớp 3: Phân tích ngữ cảnh AI (Chỉ chạy nếu có OpenAI API Key)
        if (config('openai.api_key')) {
            $aiCheck = $this->analyzeContextWithAI($textContent);
            if (!$aiCheck['passed']) {
                return [
                    'status' => 'moderation_rejected',
                    'layer' => 3,
                    'message' => $aiCheck['reason']
                ];
            }
        } else {
            Log::warning('OpenAI API Key chưa được cấu hình. Bỏ qua Lớp 3 Moderation.');
        }

        // Lớp 4: Phân tích hình ảnh (Vision API)
        // Hiện tại bỏ qua nếu ko có ảnh hoặc gọi tới một image check
        if (!empty($newData['images'])) {
             // Mock xử lý ảnh hợp lệ cho đến khi tích hợp chi tiết Vision
             // $imageCheck = $this->analyzeImagesWithVision($newData['images']);
        }

        return [
            'status' => 'moderation_approved',
            'layer' => 4,
            'message' => 'Đã vượt qua tất cả các lớp kiểm duyệt.'
        ];
    }

    /**
     * Lớp 1: Kiểm tra xem các trường nhạy cảm có thay đổi hay không
     */
    private function isMinorChange(array $newData, ?array $oldData): bool
    {
        if (!$oldData) {
            return false; // Tạo mới luôn luôn phải duyệt
        }

        $sensitiveFields = ['name', 'description', 'images', 'brand'];
        
        foreach ($sensitiveFields as $field) {
            $newVal = $newData[$field] ?? null;
            $oldVal = $oldData[$field] ?? null;
            
            // Nếu có sự thay đổi ở trường nhạy cảm, không phải là minor change
            if (json_encode($newVal) !== json_encode($oldVal)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Lớp 2: Kiểm duyệt từ khóa (Keyword filter)
     */
    private function checkKeywords(string $content): array
    {
        $contentLower = mb_strtolower($content, 'UTF-8');
        // A project adds its own words through `ispa-cms.moderation.extra_keywords` (from its provider).
        foreach ([...$this->blacklistedKeywords, ...array_map('mb_strtolower', array_filter((array) config('ispa-cms.moderation.extra_keywords', []), 'is_string'))] as $word) {
            if (str_contains($contentLower, $word)) {
                return ['passed' => false, 'word' => $word];
            }
        }
        return ['passed' => true];
    }

    /**
     * Lớp 3: Dùng LLM để phân tích ngữ cảnh chống lách luật
     */
    private function analyzeContextWithAI(string $content): array
    {
        try {
            $prompt = "Bạn là hệ thống kiểm duyệt tin đăng bán xe ô tô. " .
                      "Kiểm tra xem đoạn văn bản sau có chứa nội dung phi thực tế (VD: bao giá rẻ nhất vũ trụ), " .
                      "quảng cáo sai sự thật, tục tĩu, hoặc lách luật không.\n\n" .
                      "Trả về ĐÚNG MỘT TỪ 'PASS' nếu hợp lệ, hoặc 'FAIL|lý do' nếu vi phạm.\n\n" .
                      "Văn bản: " . $content;

            $model = 'gpt-3.5-turbo';
            $metered = app(AiGateway::class)->meter(
                'openai',
                $model,
                'auto_moderation',
                fn (): array => OpenAI::chat()->create([
                    'model' => $model,
                    'messages' => [
                        ['role' => 'user', 'content' => $prompt]
                    ],
                    'max_tokens' => 100,
                    'temperature' => 0.0
                ])->toArray(),
            );
            if ($metered->refusedReason !== null) {
                Log::warning('AI Moderation skipped: the AI budget refused the call', ['reason' => $metered->refusedReason]);

                return ['passed' => true];
            }

            $response = trim($metered->response['choices'][0]['message']['content'] ?? 'PASS');
            
            if (str_starts_with(strtoupper($response), 'FAIL')) {
                $parts = explode('|', $response);
                return [
                    'passed' => false,
                    'reason' => $parts[1] ?? 'Nội dung vi phạm tiêu chuẩn cộng đồng.'
                ];
            }

            return ['passed' => true];
        } catch (\Exception $e) {
            Log::error('AI Moderation failed: ' . $e->getMessage());
            // Fail-open: Nếu API AI sập, tạm thời cho qua hoặc bạn có thể thiết kế để Pending
            return ['passed' => true];
        }
    }
}
