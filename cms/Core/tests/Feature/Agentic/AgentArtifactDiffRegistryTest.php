<?php

declare(strict_types=1);

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\Agents\AgentArtifact;
use Cms\Core\Services\Agentic\AgentApprovalDiffBuilder;
use Cms\Core\Services\Agentic\AgentToolProvisioner;

/*
 * a module contributes the approval diff of its own artifact types and its starter tool grants; open Core
 * names no client module and keeps a generic fallback diff.
 */

test('a registered builder renders its artifact type and unknown types get the generic diff', function () {
    $builder = app(AgentApprovalDiffBuilder::class);
    $builder->register('example_module_patch', static fn (AgentArtifact $artifact): array => ['type' => 'example', 'subject' => $artifact->subject_id]);

    $artifact = (new AgentArtifact())->forceFill(['artifact_type' => 'example_module_patch', 'subject_id' => 7, 'payload' => []]);
    expect($builder->build($artifact))->toBe(['type' => 'example', 'subject' => 7]);

    $unknown = (new AgentArtifact())->forceFill(['artifact_type' => 'not_registered_patch', 'payload' => ['proposed_change' => ['a' => 1]], 'original_data_snapshot' => ['a' => 0]]);
    expect($builder->build($unknown)['type'] ?? null)->toBe('json_patch');
});

test('a module adds starter tool grants to a profile after the Core ones', function () {
    config(['core.agentic.starter_grants' => ['ops-maintainer' => ['example.open_reviews']]]);

    expect(app(AgentToolProvisioner::class)->starterGrantMap()['ops-maintainer'])
        ->toBe(['core.agentic_health_overview', 'product.inventory_alerts_summary', 'example.open_reviews']);
});

test('the Core agentic code names no client module', function () {
    $hits = [];
    foreach ([base_path('cms/Core/app/Services/Agentic'), base_path('cms/Core/admin/pages/setting/agentic')] as $dir) {
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS)) as $file) {
            if (str_contains(strtolower((string) file_get_contents($file->getPathname())), 'guu' . 'xe')) {
                $hits[] = $file->getPathname();
            }
        }
    }
    if (str_contains(strtolower((string) file_get_contents(base_path('cms/Core/admin/pages/setting/agentic.vue'))), 'guu' . 'xe')) {
        $hits[] = 'agentic.vue';
    }

    expect($hits)->toBe([]);
});
