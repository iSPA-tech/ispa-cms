<?php

declare(strict_types=1);

namespace App\Support\Widget;

use App\Constants\Tables;
use Illuminate\Support\Facades\DB;

/**
 * Emergency block state of widgets, read with one primary-key lookup per widget id and memoized.
 *
 * Bound as a scoped instance, so the memo lives for one request (or one queued job / Octane
 * request) only. Resolve it at render time, never hold it in a singleton. Whoever changes the block
 * state calls forget() so a render later in the same request sees the new state.
 */
final class WidgetBlockState
{
    /** @var array<int, bool> widget id => blocked */
    private array $blocked = [];

    public function isBlocked(int $widgetId): bool
    {
        if ($widgetId < 1) {
            return false;
        }

        return $this->blocked[$widgetId] ??= DB::table(Tables::WIDGET)
            ->where('id', $widgetId)
            ->whereNotNull('blocked_at')
            ->exists();
    }

    public function forget(int $widgetId): void
    {
        unset($this->blocked[$widgetId]);
    }
}
