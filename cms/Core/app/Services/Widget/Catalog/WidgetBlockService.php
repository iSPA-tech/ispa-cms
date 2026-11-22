<?php

/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget\Catalog;

use App\Support\Widget\WidgetBlockState;
use Cms\Core\Models\Widget;
use Illuminate\Support\Facades\DB;

/**
 * Emergency block: a blocked widget renders nothing wherever it is placed, because every render path
 * (page blocks, Site Layout areas) goes through LegacyWidgetRenderer, which checks the block state.
 *
 * Blocking is site runtime state, so hub widgets can be blocked too; it applies to the given widget
 * only (no cascade to its clones or its hub). The state change and its audit row share one transaction.
 *
 * Rendered widget HTML is never cached (WidgetQueryService renders on each call, the Widget module
 * placement cache holds placement metadata only), so the only cache to flush is the per-request
 * block-state memo.
 */
class WidgetBlockService
{
    public function __construct(private readonly WidgetAuditRecorder $audit)
    {
    }

    /**
     * Block the widget. Returns false (no write, no audit) when it is already blocked; the existing reason stays.
     * The row is re-read under a row lock, so two concurrent blocks write one state change and one audit row.
     * `$widget` is refreshed with the stored state either way.
     */
    public function block(Widget $widget, string $reason, int $userId): bool
    {
        return $this->change($widget, function (Widget $locked) use ($reason, $userId): bool {
            if ($locked->isBlocked()) {
                return false;
            }

            $locked->forceFill([
                'blocked_at' => now(),
                'blocked_by' => $userId,
                'blocked_reason' => $reason,
            ])->save();
            $this->audit->record(WidgetAuditRecorder::ACTION_BLOCKED, $locked, $userId, ['reason' => $reason]);

            return true;
        });
    }

    /** Unblock the widget. Returns false (no write, no audit) when it is not blocked. Same locking as block(). */
    public function unblock(Widget $widget, int $userId): bool
    {
        return $this->change($widget, function (Widget $locked) use ($userId): bool {
            if (!$locked->isBlocked()) {
                return false;
            }

            $locked->forceFill([
                'blocked_at' => null,
                'blocked_by' => null,
                'blocked_reason' => null,
            ])->save();
            $this->audit->record(WidgetAuditRecorder::ACTION_UNBLOCKED, $locked, $userId);

            return true;
        });
    }

    /** @param callable(Widget): bool $apply Runs on the locked row inside the transaction. */
    private function change(Widget $widget, callable $apply): bool
    {
        $changed = false;
        $locked = DB::transaction(function () use ($widget, $apply, &$changed): Widget {
            /** @var Widget $locked */
            $locked = Widget::query()->whereKey($widget->getKey())->lockForUpdate()->firstOrFail();
            $changed = $apply($locked);

            return $locked;
        });

        $widget->setRawAttributes($locked->getAttributes(), true);
        if ($changed) {
            app(WidgetBlockState::class)->forget((int) $widget->getKey());
        }

        return $changed;
    }
}
