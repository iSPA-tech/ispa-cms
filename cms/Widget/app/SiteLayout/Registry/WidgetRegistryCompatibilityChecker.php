<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Registry;

use Cms\Widget\Rendering\DataProviders\WidgetDataProviderRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;

/**
 * Compares a STORED `registry_contracts` snapshot (the 'contracts' half of
 * a previous {@see WidgetRegistryContractSnapshot::build()} result --
 * typically read back from a retained Draft/Release `widget_layout_versions`
 * row) against CURRENT registry state (plan Chunk 2 Task 15, lines 848-884,
 * 871; docs/architecture/adr-widget-provider-lifecycle.md decision 7).
 * Consumed by both {@see \Cms\Widget\Console\Commands\WidgetRegistryPreflightCommand}
 * and, later, publish-time validation.
 *
 * Breaking-vs-compatible matrix (ADR decision 7, verbatim): "breaking
 * semantic change (cardinality, fallback, trust lane, scope support,
 * applicability, target routing, provider version, or
 * provider_contract_hash) exits non-zero before traffic reaches code;
 * label-only changes pass." Concretely:
 * - Slot: missing key, OR any of
 *   cardinality/supported_scope_types/fallback/trust_lane/availability
 *   differs -- breaking. label_key alone never appears in the stored
 *   payload (excluded by WidgetSlotDeclaration::semanticFields()), so it
 *   can never trigger a breaking report.
 * - Template Context: missing key, OR the slot-key set (order-independent)
 *   differs -- breaking.
 * - Target Context: missing key, OR its routing
 *   (template_context_key/resolver_service) differs -- breaking. label_key
 *   is likewise excluded from the stored payload.
 * - Provider: the {key,version} pair is no longer registered, OR its
 *   current provider_contract_hash differs from the stored one (a semantic
 *   edit without a version bump) -- breaking.
 */
final class WidgetRegistryCompatibilityChecker
{
    public function __construct(
        private readonly WidgetSlotRegistry $slots,
        private readonly WidgetTemplateContextRegistry $templateContexts,
        private readonly WidgetTargetContextRegistry $targetContexts,
        private readonly WidgetDataProviderRegistry $providers,
    ) {
    }

    /**
     * @param array<string, mixed> $storedContracts
     * @return list<string> human-readable breaking-change descriptions;
     *         an empty array means fully compatible (including the
     *         trivially-compatible case of an empty stored snapshot).
     */
    public function compare(array $storedContracts): array
    {
        $issues = [];

        $issues = [...$issues, ...$this->compareSlots((array) ($storedContracts['slots'] ?? []))];
        $issues = [...$issues, ...$this->compareTemplateContexts((array) ($storedContracts['template_contexts'] ?? []))];
        $issues = [...$issues, ...$this->compareTargetContexts((array) ($storedContracts['target_contexts'] ?? []))];
        $issues = [...$issues, ...$this->compareProviders((array) ($storedContracts['providers'] ?? []))];

        return $issues;
    }

    /**
     * @param array<string, array<string, mixed>> $storedSlots
     * @return list<string>
     */
    private function compareSlots(array $storedSlots): array
    {
        $issues = [];

        foreach ($storedSlots as $key => $storedFields) {
            if (!$this->slots->isRegistered($key)) {
                $issues[] = "slot '{$key}' is no longer registered";

                continue;
            }

            $current = $this->slots->declarationFor($key)->semanticFields();
            $differences = $this->fieldDifferences((array) $storedFields, $current);

            if ($differences !== []) {
                $issues[] = "slot '{$key}' semantic contract changed: " . implode('; ', $differences);
            }
        }

        return $issues;
    }

    /**
     * Names every field whose stored and current values differ, with both
     * values rendered -- the operator reading a failed preflight must see
     * WHICH of cardinality/supported_scope_types/fallback/trust_lane/
     * availability moved and from what to what, not just that "something"
     * changed.
     *
     * @param array<string, mixed> $stored
     * @param array<string, mixed> $current
     * @return list<string>
     */
    private function fieldDifferences(array $stored, array $current): array
    {
        $differences = [];

        foreach (array_unique([...array_keys($stored), ...array_keys($current)]) as $field) {
            $storedValue = $stored[$field] ?? null;
            $currentValue = $current[$field] ?? null;

            // json_encode comparison keeps list-vs-list and null-vs-string
            // differences honest without a strict-type false positive on
            // decoded-JSON stored values.
            if (json_encode($storedValue) !== json_encode($currentValue)) {
                $differences[] = sprintf(
                    '%s (stored: %s, current: %s)',
                    $field,
                    json_encode($storedValue, JSON_UNESCAPED_SLASHES),
                    json_encode($currentValue, JSON_UNESCAPED_SLASHES),
                );
            }
        }

        return $differences;
    }

    /**
     * @param array<string, list<string>> $storedTemplateContexts
     * @return list<string>
     */
    private function compareTemplateContexts(array $storedTemplateContexts): array
    {
        $issues = [];

        foreach ($storedTemplateContexts as $key => $storedSlotKeys) {
            if (!$this->templateContexts->isRegistered($key)) {
                $issues[] = "template context '{$key}' is no longer registered";

                continue;
            }

            $current = $this->sortedUnique((array) $this->templateContexts->slotKeysFor($key));
            $stored = $this->sortedUnique((array) $storedSlotKeys);

            if ($current !== $stored) {
                $removed = array_values(array_diff($stored, $current));
                $added = array_values(array_diff($current, $stored));

                $parts = [];
                if ($removed !== []) {
                    $parts[] = 'removed: ' . implode(', ', $removed);
                }
                if ($added !== []) {
                    $parts[] = 'added: ' . implode(', ', $added);
                }

                $issues[] = "template context '{$key}' slot set changed (" . implode('; ', $parts) . ')';
            }
        }

        return $issues;
    }

    /**
     * @param array<string, array{template_context_key: string, resolver_service: string}> $storedTargetContexts
     * @return list<string>
     */
    private function compareTargetContexts(array $storedTargetContexts): array
    {
        $issues = [];

        foreach ($storedTargetContexts as $type => $storedRouting) {
            if (!$this->targetContexts->isRegistered($type)) {
                $issues[] = "target context '{$type}' is no longer registered";

                continue;
            }

            $definition = $this->targetContexts->definitionFor($type);
            $currentRouting = [
                'template_context_key' => $definition->templateContextKey,
                'resolver_service' => $definition->resolverService,
            ];

            $differences = $this->fieldDifferences((array) $storedRouting, $currentRouting);

            if ($differences !== []) {
                $issues[] = "target context '{$type}' routing changed: " . implode('; ', $differences);
            }
        }

        return $issues;
    }

    /**
     * @param array<string, string> $storedProviders identity "key@version" => provider_contract_hash
     * @return list<string>
     */
    private function compareProviders(array $storedProviders): array
    {
        $issues = [];

        foreach ($storedProviders as $identity => $storedHash) {
            $identity = (string) $identity;

            // A stored identity is always written by WidgetRegistryContractSnapshot
            // as "key@version"; a retained row carrying anything else is
            // corrupt data and must surface as a NAMED breach, never as an
            // undefined-array-key crash inside a deployment gate.
            if (!str_contains($identity, '@')) {
                $issues[] = "malformed provider identity '{$identity}'";

                continue;
            }

            [$key, $version] = explode('@', $identity, 2);
            $version = (int) $version;

            if (!$this->providers->isRegistered($key, $version)) {
                $issues[] = "provider '{$identity}' is no longer registered";

                continue;
            }

            if ($this->providers->contractHashFor($key, $version) !== $storedHash) {
                $issues[] = "provider '{$identity}' contract hash drifted";
            }
        }

        return $issues;
    }

    /**
     * @param list<string> $values
     * @return list<string>
     */
    private function sortedUnique(array $values): array
    {
        $unique = array_values(array_unique(array_map('strval', $values)));
        sort($unique);

        return $unique;
    }
}
