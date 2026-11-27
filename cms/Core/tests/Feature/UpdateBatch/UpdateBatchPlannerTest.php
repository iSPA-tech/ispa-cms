<?php

declare(strict_types=1);

use Cms\Core\Models\Widget;
use Cms\Core\Services\UpdateBatch\UpdateBatchPlanner;
use Cms\Core\Services\Widget\Hub\WidgetHubCatalog;
use Cms\Core\Services\Widget\Hub\WidgetHubImpact;
use Cms\Core\Services\Workflow\Hub\WorkflowHubCatalog;
use Cms\Core\Tests\Feature\UpdateBatch\UpdateBatchFixtures;

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

// Upgrade-all spec 2026-11-26 decisions 2 and 3: what goes into a run, in which order, and what is left out.

beforeEach(function () {
    $this->tmp = UpdateBatchFixtures::site($this);
    $this->user = UpdateBatchFixtures::webmaster();
    $this->widgets = Mockery::mock(WidgetHubCatalog::class);
    $this->widgets->shouldReceive('list')->andReturn([])->byDefault();
    $this->impact = Mockery::mock(WidgetHubImpact::class);
    $this->workflows = Mockery::mock(WorkflowHubCatalog::class);
    $this->workflows->shouldReceive('list')->andReturn([])->byDefault();
    app()->instance(WidgetHubCatalog::class, $this->widgets);
    app()->instance(WidgetHubImpact::class, $this->impact);
    app()->instance(WorkflowHubCatalog::class, $this->workflows);
});

afterEach(function () {
    UpdateBatchFixtures::tearDown($this->tmp);
});

function upaKeys(array $rows): array
{
    return array_map(static fn (array $row): string => $row['key'], $rows);
}

function upaCodes(array $rows): array
{
    return array_column($rows, 'reason_code', 'key');
}

function upaWorkflowRow(string $key, bool $modified, bool $available = true): array
{
    return [
        'key' => $key, 'name' => 'Template ' . $key, 'available' => $available, 'update_available' => true,
        'latest_version' => 3, 'latest_version_label' => 'v3',
        'installed' => ['project_id' => 7, 'version' => 2, 'version_label' => 'v2', 'modified' => $modified, 'setup_url' => '/workflows/7/setup'],
    ];
}

it('orders Account before SocialAuth', function (): void {
    $plan = app(UpdateBatchPlanner::class)->plan($this->user, ['module']);

    expect(upaKeys($plan['items']))->toBe(['Account', 'SocialAuth'])
        ->and(array_unique(array_column($plan['items'], 'state')))->toBe(['pending'])
        ->and($plan['items'][1])->toMatchArray(['kind' => 'module', 'from' => '2026.11.01', 'to' => '2026.11.06']);
});

it('leaves out irreversible versions and versions that need a newer Core, never Core itself', function (): void {
    $plan = app(UpdateBatchPlanner::class)->plan($this->user, ['module']);

    expect(upaCodes($plan['left_out']))->toBe(['Webmail' => 'IRREVERSIBLE', 'Forum' => 'NEEDS_NEWER_CORE'])
        ->and(upaKeys($plan['items']))->not->toContain('Core');
});

it('leaves only the modules out when module installs are switched off', function (): void {
    config(['core.module_packages.install_enabled' => false]);
    $widget = Widget::query()->forceCreate(['widget_key' => 'ispa.slider', 'name' => 'Slider', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'ispa', 'version' => 20261101]);
    $this->widgets->shouldReceive('list')->andReturn([['widget_key' => 'ispa.slider', 'display_name' => 'Slider', 'latest_version' => 20261102, 'installed_widget_id' => $widget->id, 'installed_version' => 20261101]]);
    $this->impact->shouldReceive('forUpdate')->with($widget->id)->andReturn(['pages' => 2, 'areas' => 0, 'removed_designs_in_use' => [], 'new_input_labels' => []]);

    $plan = app(UpdateBatchPlanner::class)->plan($this->user, ['module', 'widget']);

    expect(array_unique(array_values(upaCodes($plan['left_out']))))->toBe(['INSTALL_NOT_ALLOWED'])
        ->and(upaKeys($plan['items']))->toBe(['ispa.slider']);
});

it('never offers a site copy and leaves out an update that drops a design in use', function (): void {
    $kept = Widget::query()->forceCreate(['widget_key' => 'ispa.news', 'name' => 'News', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'ispa', 'version' => 20261101]);
    $dropping = Widget::query()->forceCreate(['widget_key' => 'ispa.menu', 'name' => 'Menu', 'origin' => Widget::ORIGIN_HUB, 'publisher' => 'ispa', 'version' => 20261101]);
    $copy = Widget::query()->forceCreate(['widget_key' => 'ispa.copy', 'name' => 'Copy', 'origin' => Widget::ORIGIN_SITE, 'publisher' => 'ispa', 'version' => 20261101]);
    $row = static fn (Widget $w, string $label): array => ['widget_key' => $w->widget_key, 'display_name' => $label, 'latest_version' => 20261102, 'installed_widget_id' => $w->id, 'installed_version' => 20261101];
    $this->widgets->shouldReceive('list')->andReturn([$row($kept, 'Tin tức'), $row($dropping, 'Menu'), $row($copy, 'Copy')]);
    $this->impact->shouldReceive('forUpdate')->with($kept->id)->andReturn(['pages' => 3, 'areas' => 1, 'removed_designs_in_use' => [], 'new_input_labels' => []]);
    $this->impact->shouldReceive('forUpdate')->with($dropping->id)->andReturn(['pages' => 1, 'areas' => 0, 'removed_designs_in_use' => ['Dọc'], 'new_input_labels' => []]);

    $plan = app(UpdateBatchPlanner::class)->plan($this->user, ['widget']);

    expect(upaKeys($plan['items']))->toBe(['ispa.news'])
        ->and($plan['items'][0])->toMatchArray(['widget_id' => $kept->id, 'pages' => 3, 'from' => '2026.11.01', 'to' => '2026.11.02'])
        ->and(upaCodes($plan['left_out']))->toBe(['ispa.menu' => 'WIDGET_UPDATE_DESIGN_IN_USE']);
});

it('leaves out a workflow changed on the site and keeps an unchanged one', function (): void {
    $this->workflows->shouldReceive('list')->with(true)->andReturn([upaWorkflowRow('church-posts', false), upaWorkflowRow('blog-posts', true), upaWorkflowRow('shop', false, false)]);

    $plan = app(UpdateBatchPlanner::class)->plan($this->user, ['workflow']);

    expect(upaKeys($plan['items']))->toBe(['church-posts'])
        ->and($plan['items'][0])->toMatchArray(['project_id' => 7, 'from' => 'v2', 'to' => 'v3'])
        ->and(upaCodes($plan['left_out']))->toBe(['blog-posts' => 'WORKFLOW_MODIFIED', 'shop' => 'WORKFLOW_TEMPLATE_NOT_AVAILABLE']);
});

it('plans only the kinds asked for', function (): void {
    $this->workflows->shouldReceive('list')->never();

    $plan = app(UpdateBatchPlanner::class)->plan($this->user, ['widget']);

    expect($plan['items'])->toBe([])->and($plan['left_out'])->toBe([]);
});

it('signs module versions but only widget and workflow keys', function (): void {
    $planner = app(UpdateBatchPlanner::class);
    $items = [
        ['kind' => 'module', 'key' => 'SocialAuth', 'to' => '2026.11.06'],
        ['kind' => 'widget', 'key' => 'ispa.news', 'to' => '2026.11.02'],
    ];
    $otherWidget = $items;
    $otherWidget[1]['to'] = '2026.11.03';
    $otherModule = $items;
    $otherModule[0]['to'] = '2026.11.07';

    expect($planner->signature($otherWidget))->toBe($planner->signature($items))
        ->and($planner->signature($otherModule))->not->toBe($planner->signature($items))
        ->and($planner->signature($items))->toBe(['module:SocialAuth@2026.11.06', 'widget:ispa.news']);
});
