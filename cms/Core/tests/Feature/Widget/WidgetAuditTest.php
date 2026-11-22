<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * Unified widget catalog part 1, Task 8: every widget catalog event is written to the audit log.
 */

use App\Models\User;
use Cms\Core\Models\AuditLog;
use Cms\Core\Models\Widget;
use Cms\Core\Services\Widget\Catalog\WidgetAuditRecorder;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(TestCase::class, RefreshDatabase::class);

dataset('widget audit actions', [
    'cloned' => [WidgetAuditRecorder::ACTION_CLONED, 'widget.cloned', ['hub_key' => 'ispa.a', 'clone_key' => 'site.ispa.a-1', 'pages_updated' => 3]],
    'design saved' => [WidgetAuditRecorder::ACTION_DESIGN_SAVED, 'widget.design_saved', []],
    'blocked' => [WidgetAuditRecorder::ACTION_BLOCKED, 'widget.blocked', ['reason' => 'security']],
    'unblocked' => [WidgetAuditRecorder::ACTION_UNBLOCKED, 'widget.unblocked', []],
]);

it('records an audit row for each widget action', function (string $action, string $name, array $details) {
    $widget = Widget::query()->forceCreate(['widget_key' => 'ispa.a', 'name' => 'A', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'ispa']);

    $user = User::factory()->create();

    app(WidgetAuditRecorder::class)->record($action, $widget, $user->id, $details);

    $row = AuditLog::query()->sole();
    expect($action)->toBe($name)
        ->and($row->action)->toBe($name)
        ->and($row->target_type)->toBe('widget')
        ->and((int) $row->target_id)->toBe($widget->id)
        ->and((int) $row->user_id)->toBe($user->id)
        ->and($row->options)->toBe($details)
        ->and($row->ip_address)->toBeNull();
})->with('widget audit actions');
