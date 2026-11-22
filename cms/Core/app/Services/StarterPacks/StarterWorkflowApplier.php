<?php

declare(strict_types=1);

namespace Cms\Core\Services\StarterPacks;

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\ConfigPackage\ApplyContext;
use Cms\Core\Services\ConfigPackage\DiffContext;
use Cms\Core\Services\ConfigPackage\Sections\WorkflowsSection;

/**
 * Creates a starter pack's workflow definitions on a site, add-only.
 *
 * Thin adapter over the configuration package workflow import (`WorkflowsSection`): a workflow whose slug is missing is created
 * as an inactive project with its steps and connections (one transaction per project, steps referenced by
 * `step_code`); a workflow whose slug already exists is skipped whole and never updated, so nothing an operator
 * may have edited is touched. Apply excludes every non-create item and never sets a `<slug>#graph` choice, so an
 * existing graph is never replaced. A soft-deleted project with the same slug is also skipped (never restored).
 *
 * Input is the decoded `workflows.json` list in WorkflowsSection row shape:
 * `[{ slug, name, trigger_event?, target_entity_type, tags?, description?, steps: [...], connections: [...] }]`.
 * Unknown fields are rejected, including `is_active`: new projects are always inactive, so a pack cannot ask
 * for anything else. The whole list is validated before anything is written; any blocked row (invalid graph,
 * secret, duplicate slug) fails the whole install.
 */
class StarterWorkflowApplier
{
    /** File name used in validation messages. */
    private const FILE = 'workflows.json';

    /** Reason reported for a workflow whose slug already exists on the site. */
    public const REASON_EXISTS = 'workflow already exists; left unchanged';

    /** Reason reported for a workflow whose slug belongs to a deleted project. */
    public const REASON_DELETED = 'a deleted workflow with this slug exists; left untouched';

    /** Fields allowed on one workflow entry. */
    private const FIELDS = ['slug', 'name', 'trigger_event', 'target_entity_type', 'tags', 'description', 'steps', 'connections'];

    /**
     * @param WorkflowsSection $workflows configuration package workflow import used for validation and writes.
     */
    public function __construct(private readonly WorkflowsSection $workflows)
    {
    }

    /**
     * Dry run: report what apply would do without writing anything.
     *
     * @param list<mixed> $workflows Decoded workflows.json list.
     *
     * @return array{created: list<string>, skipped: list<array{slug: string, reason: string}>}
     *
     * @throws StarterPackException When the list is invalid or any workflow is blocked.
     */
    public function plan(array $workflows): array
    {
        return $this->classify($this->validate($workflows))['result'];
    }

    /**
     * Create the pack's missing workflows; existing slugs are left exactly as they are.
     *
     * @param list<mixed> $workflows Decoded workflows.json list.
     *
     * @return array{created: list<string>, skipped: list<array{slug: string, reason: string}>}
     *
     * @throws StarterPackException When the list is invalid or any workflow is blocked (nothing is written).
     */
    public function apply(array $workflows): array
    {
        $rows = $this->validate($workflows);
        $classified = $this->classify($rows);

        // Only create items are chosen; "<slug>#graph" is never set, so no existing graph is replaced.
        $choices = [];
        foreach ($classified['excluded'] as $slug) {
            $choices[$slug] = false;
        }

        $context = new ApplyContext($choices);
        $this->workflows->apply($rows, $context);
        $this->workflows->afterApply($context);

        return $classified['result'];
    }

    /**
     * Diff the whole list; throw on blocked rows (except deleted slugs), return the result and excluded slugs.
     *
     * @param list<array<string, mixed>> $rows
     *
     * @return array{result: array{created: list<string>, skipped: list<array{slug: string, reason: string}>}, excluded: list<string>}
     */
    private function classify(array $rows): array
    {
        $result = ['created' => [], 'skipped' => []];
        $excluded = [];

        foreach ($this->workflows->diff($rows, new DiffContext())->items as $item) {
            $slug = (string) $item['key'];

            if ($item['action'] === 'create') {
                $result['created'][] = $slug;

                continue;
            }

            $excluded[] = $slug;

            if ($item['action'] === 'blocked') {
                if ($slug !== '' && WorkflowProject::onlyTrashed()->where('slug', $slug)->exists()
                    && str_contains((string) $item['reason'], 'deleted workflow')) {
                    $result['skipped'][] = ['slug' => $slug, 'reason' => self::REASON_DELETED];

                    continue;
                }

                throw StarterPackException::invalidPack(
                    self::FILE,
                    sprintf('workflow "%s" cannot be created: %s', $slug, (string) ($item['reason'] ?: 'invalid workflow')),
                );
            }

            $result['skipped'][] = ['slug' => $slug, 'reason' => self::REASON_EXISTS];
        }

        return ['result' => $result, 'excluded' => $excluded];
    }

    /**
     * Validate the list shape and field names; the graph itself is validated by the workflow import.
     *
     * @param list<mixed> $workflows
     *
     * @return list<array<string, mixed>>
     */
    private function validate(array $workflows): array
    {
        if (!array_is_list($workflows)) {
            throw StarterPackException::invalidPack(self::FILE, 'must be a list of workflows');
        }

        foreach ($workflows as $index => $entry) {
            $at = 'entry ' . $index;
            if (!is_array($entry) || ($entry !== [] && array_is_list($entry))) {
                throw StarterPackException::invalidPack(self::FILE, $at . ' must be an object');
            }

            $unknown = array_diff(array_map('strval', array_keys($entry)), self::FIELDS);
            if ($unknown !== []) {
                throw StarterPackException::invalidPack(self::FILE, $at . ' has unknown field(s): ' . implode(', ', $unknown));
            }

            if (!is_string($entry['slug'] ?? null) || trim($entry['slug']) === '') {
                throw StarterPackException::invalidField(self::FILE, $at . '.slug', 'must be a non-empty string');
            }
        }

        return $workflows;
    }
}
