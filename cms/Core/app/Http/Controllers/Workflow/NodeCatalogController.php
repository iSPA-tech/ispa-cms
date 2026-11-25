<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Workflow;

use App\Http\Controllers\Controller;
use Cms\Core\Services\Workflow\Mail\TriggerSubjectResolver;
use Cms\Core\Workflows\Nodes\NodeDefinition;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;

/**
 * Workflow node catalog for the designer palette: `GET api/v2/admin/workflow/node-catalog`.
 *
 * Needs `workflow_project.viewAny`. Lists the palette groups and every palette node the user may use (a node whose
 * `permission` the user lacks is left out), with schema, defaults, output handles, safety facts and labels.
 *
 * @package Cms\Core\Http\Controllers\Workflow
 * @since   2026-11-23
 */
class NodeCatalogController extends Controller
{
    public function index()
    {
        $user = user();
        if (!$user?->can('workflow_project.viewAny')) {
            return ires()->forbidden(__('mgs.no.permission'));
        }

        $registry = NodeRegistry::current();

        $nodes = array_values(array_map(
            static fn (NodeDefinition $definition): array => $definition->toCatalogArray(),
            array_filter(
                $registry->all(),
                static fn (NodeDefinition $definition): bool => $definition->palette
                    && ($definition->permission === null || $user->can($definition->permission))
            )
        ));

        $groups = array_map(static function (array $group): array {
            $label = $group['label_key'] !== null ? __($group['label_key']) : null;

            return $group + ['label' => is_string($label) && $label !== $group['label_key'] ? $label : ucfirst($group['key'])];
        }, $registry->groups());

        return ires()->data([
            'groups' => $groups,
            'nodes' => $nodes,
            'approval_types' => $registry->approvalTypes(),
            'safety_classes' => NodeDefinition::SAFETY_CLASSES,
            // App events that name "the trigger's own person" (e-mail policy B): the designer offers that recipient of an
            // `email_send` step only in a workflow that starts on one of them.
            'trigger_subject_events' => array_values(array_filter(
                array_keys(app(WorkflowCallableGuard::class)->triggerEvents()),
                static fn (string $event): bool => app(TriggerSubjectResolver::class)->declared($event) !== null
            )),
        ]);
    }
}
