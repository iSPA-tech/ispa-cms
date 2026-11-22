<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Registry;

use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\DataProviders\WidgetDataProviderRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;

/**
 * Canonicalizes CURRENT registrations, for an explicitly requested
 * reference set, into the `registry_contracts` JSON + `registry_contract_hash`
 * shape `widget_layout_versions` (Task 13a) persists per Draft/Release row
 * (plan Chunk 2 Task 15, lines 848-884, 871; architecture.md "Persistence
 * Model"; docs/architecture/adr-widget-provider-lifecycle.md decision 7).
 *
 * SCOPE: this class only BUILDS and HASHES a snapshot from live registry
 * state -- it never reads or writes a database row itself. That boundary
 * follows from the plan's own task ownership, not from any Task 15
 * wording: plan line 1041 (Task 17) assigns the transactional persistence
 * -- "In the same transaction and under the same Layout lock, recompute
 * canonical `registry_contracts`/hash through WidgetRegistryContractSnapshot
 * from the normalized referenced Slots, Template Contexts, Target
 * Contexts, and providers" -- and no Draft/Release-creating flow exists
 * until Task 17 builds it. No Placement resolution exists yet in this task
 * either (that is the Instance/Layout machinery Tasks 16-18 add), so there
 * is no way to auto-discover "which Slots/Template Contexts/Target
 * Contexts/providers does this Layout actually reference" from persisted
 * data. {@see self::build()} therefore takes that reference set as an
 * EXPLICIT input; Tasks 17/18 supply the real Placement-derived set when
 * they persist a snapshot into a Draft/Release `widget_layout_versions`
 * row inside their own Draft-mutating transactions.
 *
 * A requested key that is not CURRENTLY registered is silently OMITTED
 * from the built contracts (not represented as an explicit null) -- the
 * compatibility checker detects "this key used to be referenced but is
 * gone now" simply by checking whether a key present in a STORED snapshot
 * is present in a freshly built CURRENT one; omission is sufficient and
 * needs no placeholder.
 *
 * `label_key`/label-only fields never enter this snapshot (each entity's
 * own semantic-fields projection excludes them), so a label-only edit can
 * never appear in the persisted `registry_contracts` payload in the first
 * place -- compatible by construction, not by a special-cased comparison.
 *
 * Reuses {@see CanonicalJson} (Task 11) rather than adding a fifth private
 * copy of the recursive-ksort+hash discipline that class's own docblock
 * already warns against.
 */
final class WidgetRegistryContractSnapshot
{
    public function __construct(
        private readonly WidgetSlotRegistry $slots,
        private readonly WidgetTemplateContextRegistry $templateContexts,
        private readonly WidgetTargetContextRegistry $targetContexts,
        private readonly WidgetDataProviderRegistry $providers,
    ) {
    }

    /**
     * @param list<string> $slotKeys
     * @param list<string> $templateContextKeys
     * @param list<string> $targetContextTypes
     * @param list<array{key: string, version: int}> $providerRefs
     * @return array{contracts: array<string, array<string, mixed>>, hash: string}
     */
    public function build(
        array $slotKeys = [],
        array $templateContextKeys = [],
        array $targetContextTypes = [],
        array $providerRefs = [],
    ): array {
        $contracts = [
            'slots' => $this->slotContracts($slotKeys),
            'template_contexts' => $this->templateContextContracts($templateContextKeys),
            'target_contexts' => $this->targetContextContracts($targetContextTypes),
            'providers' => $this->providerContracts($providerRefs),
        ];

        return ['contracts' => $contracts, 'hash' => CanonicalJson::hash($contracts)];
    }

    /**
     * @param list<string> $slotKeys
     * @return array<string, array<string, mixed>>
     */
    private function slotContracts(array $slotKeys): array
    {
        $out = [];

        foreach ($slotKeys as $key) {
            $declaration = $this->slots->declarationFor($key);

            if ($declaration !== null) {
                $out[$key] = $declaration->semanticFields();
            }
        }

        return $out;
    }

    /**
     * @param list<string> $templateContextKeys
     * @return array<string, list<string>>
     */
    private function templateContextContracts(array $templateContextKeys): array
    {
        $out = [];

        foreach ($templateContextKeys as $key) {
            $slotKeys = $this->templateContexts->slotKeysFor($key);

            if ($slotKeys !== null) {
                $sorted = array_values(array_unique($slotKeys));
                sort($sorted);
                $out[$key] = $sorted;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $targetContextTypes
     * @return array<string, array{template_context_key: string, resolver_service: string}>
     */
    private function targetContextContracts(array $targetContextTypes): array
    {
        $out = [];

        foreach ($targetContextTypes as $type) {
            $definition = $this->targetContexts->definitionFor($type);

            if ($definition !== null) {
                $out[$type] = [
                    'template_context_key' => $definition->templateContextKey,
                    'resolver_service' => $definition->resolverService,
                ];
            }
        }

        return $out;
    }

    /**
     * @param list<array{key: string, version: int}> $providerRefs
     * @return array<string, string>
     */
    private function providerContracts(array $providerRefs): array
    {
        $out = [];

        foreach ($providerRefs as $ref) {
            $hash = $this->providers->contractHashFor($ref['key'], $ref['version']);

            if ($hash !== null) {
                $out[$ref['key'] . '@' . $ref['version']] = $hash;
            }
        }

        return $out;
    }
}
