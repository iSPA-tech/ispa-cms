<?php

declare(strict_types=1);

namespace App\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Services\RuntimeCacheMaintenanceService;
use Cms\Core\Models\Setting;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

final class RuntimeCacheMaintenanceController extends Controller
{
    public function __construct(private readonly RuntimeCacheMaintenanceService $maintenance)
    {
    }

    public function clear(): mixed
    {
        if (! user()?->can('update', new Setting())) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        return $this->locked('clear', function (): mixed {
            $result = $this->maintenance->clearApplicationCache();
            Log::notice('Admin cleared the application cache.', [
                'user_id' => user()?->getAuthIdentifier(),
                'cleared_keys' => $result['cleared'],
                'preserved_keys' => count($result['preserved']),
            ]);

            return ires()->data([
                'cleared_keys' => $result['cleared'],
                'preserved_keys' => count($result['preserved']),
            ])->message('Đã xóa cache ứng dụng và giữ lại các cache dài hạn được cấu hình.');
        });
    }

    public function optimize(): mixed
    {
        if (! user()?->can('update', new Setting())) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        return $this->locked('optimize', function (): mixed {
            $this->maintenance->optimizeApplication();
            Log::notice('Admin rebuilt application optimization caches.', [
                'user_id' => user()?->getAuthIdentifier(),
            ]);

            return ires()->message('Đã tối ưu config, route, event và view cache.');
        });
    }

    private function locked(string $action, callable $operation): mixed
    {
        $lock = Cache::store('file')->lock('admin:runtime-cache-maintenance', 300);
        if (! $lock->get()) {
            return ires()->error()->code(409)->message('Một tác vụ cache khác đang chạy.');
        }

        try {
            return $operation();
        } catch (Throwable $exception) {
            report($exception);
            Log::error('Admin runtime cache maintenance failed.', [
                'action' => $action,
                'user_id' => user()?->getAuthIdentifier(),
            ]);

            return ires()->error()->code(500)->message('Không thể hoàn tất tác vụ cache. Vui lòng kiểm tra log máy chủ.');
        } finally {
            $lock->release();
        }
    }
}
