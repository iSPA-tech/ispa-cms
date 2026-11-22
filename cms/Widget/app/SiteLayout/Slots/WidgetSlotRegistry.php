<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Slots;

use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\WidgetSlotRegistrar;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use InvalidArgumentException;

/**
 * Module-owned code-registered Slot registry (plan Chunk 2 Task 15, lines
 * 848-884; docs/architecture/adr-widget-provider-lifecycle.md decision 7;
 * docs/contracts/widget-site-layout-api.md:195 WidgetSlotResource field
 * list). Implements the root {@see WidgetSlotRegistrar} contract frozen by
 * Task 7 -- `registerSlot(WidgetSlotDefinition $definition): void` is the
 * ONLY method the root interface mandates, so it stays a thin delegate to
 * {@see self::register()} using a conservative
 * {@see WidgetSlotDeclaration::fromRootDefinition()} declaration (no
 * fallback, every scope type supported, `legacy` trust lane, empty
 * availability) -- the same minimal-declaration precedent
 * {@see \Cms\Widget\Rendering\DataProviders\WidgetDataProviderRegistry::registerProvider()}
 * established for providers in Task 11.
 *
 * Holds declarations in one in-memory array only -- no database, cache, or
 * filesystem access anywhere in this file (checklist: "registration
 * performs no database query"; proven both by a live DB::getQueryLog()
 * assertion and a static source-token scan in WidgetSlotRegistryTest).
 *
 * Duplicate/conflict rule (disclosed choice -- see WidgetSlotRegistryTest's
 * class docblock for the full reasoning): the checklist's own wording is
 * "reject duplicate CONFLICTING declarations", read literally as scoping
 * rejection to declarations that differ. A second registration of an
 * already-used key is accepted silently (idempotent-OK) only when it is
 * field-identical to the first across every field, including `labelKey` --
 * with the set-valued fields (supportedScopeTypes, availability) compared
 * order-insensitively, since the comparison hashes each declaration's
 * canonical toArray() form, whose semanticFields() sorts those lists;
 * any single field difference throws. This deliberately diverges from Task
 * 11's WidgetDataProviderRegistry, which rejects every duplicate including
 * identical ones (D-015) -- that ADR decision is scoped to provider
 * version-as-compatibility-identity, not to this task's own registration
 * wording.
 */
final class WidgetSlotRegistry implements WidgetSlotRegistrar
{
    /** @var array<string, WidgetSlotDeclaration> */
    private array $slots = [];

    /**
     * Rich registration entry point used by cms/Widget's own pilot Slot
     * wiring (a later task), which can supply the full declaration --
     * cardinality, supported scope types, declared fallback, trust lane,
     * and availability -- rather than just the thin root DTO's three
     * fields.
     *
     * @throws InvalidArgumentException on an unknown cardinality/scope
     *         type, or a conflicting duplicate registration of the same key
     *         (any field differs from the already-registered declaration).
     */
    public function register(WidgetSlotDeclaration $declaration): void
    {
        if (!in_array($declaration->cardinality, WidgetSlotDeclaration::CARDINALITIES, true)) {
            throw new InvalidArgumentException(
                "Slot '{$declaration->key}' declares an unknown cardinality '{$declaration->cardinality}'."
            );
        }

        foreach ($declaration->supportedScopeTypes as $scopeType) {
            if (!in_array($scopeType, WidgetSlotDeclaration::SCOPE_TYPES, true)) {
                throw new InvalidArgumentException(
                    "Slot '{$declaration->key}' declares an unknown supported scope type '{$scopeType}'."
                );
            }
        }

        $existing = $this->slots[$declaration->key] ?? null;

        if ($existing !== null) {
            if (CanonicalJson::hash($existing->toArray()) === CanonicalJson::hash($declaration->toArray())) {
                return; // Identical re-registration: idempotent-OK.
            }

            throw new InvalidArgumentException(
                "Slot '{$declaration->key}' is already registered with a conflicting declaration."
            );
        }

        $this->slots[$declaration->key] = $declaration;
    }

    /**
     * Root WidgetSlotRegistrar contract method. A caller holding only the
     * root interface gets a safe minimal declaration -- no fallback, every
     * scope type supported, `legacy` trust lane, empty availability -- so
     * the Slot still participates in registry/compatibility bookkeeping
     * without cms/Widget needing to know anything about the caller.
     */
    public function registerSlot(WidgetSlotDefinition $definition): void
    {
        $this->register(WidgetSlotDeclaration::fromRootDefinition($definition));
    }

    public function isRegistered(string $key): bool
    {
        return isset($this->slots[$key]);
    }

    public function declarationFor(string $key): ?WidgetSlotDeclaration
    {
        return $this->slots[$key] ?? null;
    }

    /**
     * @return list<string> every registered Slot key, insertion order.
     */
    public function keys(): array
    {
        return array_keys($this->slots);
    }
}

/**
 * Typed Slot declaration (docs/contracts/widget-site-layout-api.md:195
 * WidgetSlotResource: key, label_key, cardinality, supported_scope_types,
 * fallback, trust_lane, availability). Co-located in this file because Task
 * 15's allowed_paths has no separate value-object file slot for it --
 * mirrors the WidgetProviderDeclaration-inside-WidgetDataProviderRegistry
 * precedent from Task 11. Autoload footgun that precedent also carries:
 * under plain dev PSR-4 (no optimized classmap), this class resolves only
 * AFTER WidgetSlotRegistry.php has been loaded, because the file is named
 * after the registry class -- code that touches WidgetSlotDeclaration
 * before anything has referenced WidgetSlotRegistry will fail to autoload
 * it.
 *
 * {@see self::semanticFields()} is the exact, compatibility-relevant subset
 * feeding WidgetRegistryContractSnapshot/WidgetRegistryCompatibilityChecker
 * -- cardinality, supported scope types, fallback, trust lane, and
 * availability, per adr-widget-provider-lifecycle.md decision 7's named
 * Slot field list. `labelKey` is real declared metadata but is structurally
 * absent from that method, so a label-only edit never changes registry
 * compatibility (label-only changes are compatible, per the same ADR
 * decision). {@see self::toArray()} is the FULL identity (including
 * labelKey) used only for registration-time duplicate/conflict detection.
 */
final readonly class WidgetSlotDeclaration
{
    public const SCOPE_GLOBAL = 'global';
    public const SCOPE_TEMPLATE = 'template';
    public const SCOPE_TARGET = 'target';

    public const SCOPE_TYPES = [self::SCOPE_GLOBAL, self::SCOPE_TEMPLATE, self::SCOPE_TARGET];

    public const CARDINALITIES = [
        WidgetSlotDefinition::CARDINALITY_SINGLE,
        WidgetSlotDefinition::CARDINALITY_REPLACE,
        WidgetSlotDefinition::CARDINALITY_STACK,
    ];

    /**
     * @param list<string> $supportedScopeTypes subset of self::SCOPE_TYPES
     * @param list<string> $availability Template Context keys (or
     *        self::SCOPE_GLOBAL) this Slot currently declares itself
     *        available for; empty means "not yet scoped" (no availability
     *        claim), not "available everywhere".
     */
    public function __construct(
        public string $key,
        public string $labelKey,
        public string $cardinality,
        public array $supportedScopeTypes = self::SCOPE_TYPES,
        public ?string $fallback = null,
        public string $trustLane = 'legacy',
        public array $availability = [],
    ) {
    }

    /**
     * Conservative default used when a Slot is registered through only the
     * thin root WidgetSlotRegistrar::registerSlot() contract (no rich
     * declaration supplied).
     */
    public static function fromRootDefinition(WidgetSlotDefinition $definition): self
    {
        return new self(
            key: $definition->key,
            labelKey: $definition->labelKey,
            cardinality: $definition->cardinality,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function semanticFields(): array
    {
        return [
            'cardinality' => $this->cardinality,
            'supported_scope_types' => $this->sortedUnique($this->supportedScopeTypes),
            'fallback' => $this->fallback,
            'trust_lane' => $this->trustLane,
            'availability' => $this->sortedUnique($this->availability),
        ];
    }

    /**
     * Full declaration identity, including label_key -- used only for
     * registration-time duplicate/conflict comparison, never for the
     * compatibility hash.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge(['key' => $this->key, 'label_key' => $this->labelKey], $this->semanticFields());
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
