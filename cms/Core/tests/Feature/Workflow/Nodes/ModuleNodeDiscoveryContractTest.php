<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\Workflow\Nodes;

use Cms\Core\Workflows\Nodes\NodeRegistry;
use Symfony\Component\Finder\Finder;

uses(\Cms\Core\Tests\TestCase::class);

/*
 * Contract: every module node service in the repository registers ALL of its #[WorkflowNode] methods. A node with an
 * invalid definition (unknown safety class, bad type name, duplicate type) is dropped on its own at run time
 * (NodeRegistry::rejected(), reported by cms:workflow-integrity-check); this test stops it in CI before a deploy.
 *
 * Services = `core.workflow_nodes.module_services` of this test site plus every class under cms/<Module>/app that
 * declares a #[WorkflowNode], so a module this test site does not enable is still checked.
 */

/** @return list<class-string> */
function repositoryModuleNodeServices(): array
{
    $classes = array_values(array_filter((array) config('core.workflow_nodes.module_services', []), 'is_string'));

    $files = Finder::create()->files()->name('*.php')->in(base_path('cms'))->path('/^[A-Za-z]+\/app\//')->contains('#[WorkflowNode(');
    foreach ($files as $file) {
        $source = $file->getContents();
        if (preg_match('/^namespace\s+([^;]+);/m', $source, $ns) && preg_match('/^(?:final\s+|abstract\s+)?class\s+(\w+)/m', $source, $cls)) {
            $classes[] = $ns[1] . '\\' . $cls[1];
        }
    }

    return array_values(array_unique(array_map(static fn (string $class): string => ltrim($class, '\\'), $classes)));
}

it('registers every workflow node of every module service in the repository', function (): void {
    $services = repositoryModuleNodeServices();
    // Discovery must find at least every node service named by the module convention in this tree (the private tree
    // has several modules, the open-source release ships Content only), so the floor follows the modules present.
    $conventional = glob(base_path('cms/*/app/Services/Workflow/*WorkflowNodes.php')) ?: [];
    expect($conventional)->not->toBe([]);
    expect(count($services))->toBeGreaterThanOrEqual(count($conventional));

    config([
        'core.workflow_nodes.module_services' => $services,
        'core.workflow_nodes.allowlisted_namespaces' => array_values(array_unique(array_merge(
            (array) config('core.workflow_nodes.allowlisted_namespaces', []),
            array_map(static fn (string $class): string => substr($class, 0, strrpos($class, '\\') + 1), $services),
        ))),
    ]);
    $registry = NodeRegistry::current();

    $dropped = array_map(
        static fn (array $item): string => $item['class'] . ($item['method'] !== null ? '::' . $item['method'] : '') . ' (' . ($item['type'] ?? '-') . '): ' . $item['reason'],
        $registry->rejected(),
    );
    expect($dropped)->toBe([]);

    foreach ($services as $class) {
        $types = array_keys(array_filter($registry->all(), static fn ($definition): bool => $definition->service === $class));
        expect($types)->not->toBe([], "{$class} registers no workflow node");
    }
});
