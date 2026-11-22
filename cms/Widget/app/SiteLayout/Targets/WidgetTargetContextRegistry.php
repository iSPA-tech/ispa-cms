<?php

declare(strict_types=1);

namespace Cms\Widget\SiteLayout\Targets;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetResolvedTarget;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Contracts\Widget\DTO\WidgetTargetPage;
use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use App\Contracts\Widget\WidgetTargetContextRegistrar;
use App\Contracts\Widget\WidgetTargetContextResolver;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Closure;
use InvalidArgumentException;

/**
 * Module-owned code-registered Target Context registry (plan Chunk 2 Task
 * 15, lines 848-884, 866-867; docs/architecture/adr-widget-provider-
 * lifecycle.md decision 7). Implements the root
 * {@see WidgetTargetContextRegistrar} contract frozen by Task 7.
 *
 * This class is a MULTIPLEXER in front of N per-target-type resolvers, not
 * itself a {@see WidgetTargetContextResolver} -- that root interface's
 * `resolve(context, targetId)` carries no target-type parameter (each
 * per-type resolver already knows its own type implicitly), so a
 * multiplexer needs an extra type dimension the resolver contract does not
 * have. {@see self::search()} does not need that extra parameter because
 * {@see WidgetTargetSearchQuery} already carries `targetType`.
 *
 * The `resolverService` FQCN named by a {@see WidgetTargetContextDefinition}
 * is turned into a live resolver instance through an injected
 * `Closure(string): WidgetTargetContextResolver` locator rather than a hard
 * container dependency, so this class stays unit-testable without a real
 * Laravel container (the same persistence/container-agnostic testing style
 * Task 11's registry established) -- production wiring
 * (WidgetServiceProvider::boot()) supplies `fn (string $fqcn) =>
 * $app->make($fqcn)`; tests supply a small fixed map.
 *
 * Two distinct "unknown key" rejections (checklist: "reject ... unknown
 * Template/Target Context keys"):
 * - registerTargetContext() rejects an unknown (not-yet-registered)
 *   Template Context key -- a Target Context cannot reference a Template
 *   Context nobody declared.
 * - search()/resolve() reject an unregistered target type before ever
 *   touching a resolver -- calling this API for a type nobody registered a
 *   resolver for is a caller/config error, not a 403/404 the underlying
 *   resolver should ever see.
 *
 * search()/resolve() perform pure delegation: this class never transforms
 * the opaque target id, never branches on target type beyond selecting
 * which resolver to call, and returns the resolver's DTO/exception
 * unchanged -- no Page/Post-specific logic exists anywhere in this file.
 *
 * Duplicate/conflict rule matches WidgetSlotRegistry's (see that class's
 * docblock for the full reasoning): an identical full-declaration
 * re-registration is idempotent-OK; any field difference throws.
 */
final class WidgetTargetContextRegistry implements WidgetTargetContextRegistrar
{
    /** @var array<string, WidgetTargetContextDefinition> */
    private array $definitions = [];

    /**
     * @param Closure(string): WidgetTargetContextResolver $resolverLocator
     */
    public function __construct(
        private readonly WidgetTemplateContextRegistry $templateContexts,
        private readonly Closure $resolverLocator,
    ) {
    }

    /**
     * @throws InvalidArgumentException when `templateContextKey` was never
     *         registered via WidgetTemplateContextRegistry, or on a
     *         conflicting duplicate registration of the same target type.
     */
    public function registerTargetContext(WidgetTargetContextDefinition $definition): void
    {
        if (!$this->templateContexts->isRegistered($definition->templateContextKey)) {
            throw new InvalidArgumentException(
                "Target Context '{$definition->targetType}' references unknown Template Context key "
                . "'{$definition->templateContextKey}'."
            );
        }

        $existing = $this->definitions[$definition->targetType] ?? null;

        if ($existing !== null) {
            if (CanonicalJson::hash($this->identity($existing)) === CanonicalJson::hash($this->identity($definition))) {
                return; // Identical re-registration: idempotent-OK.
            }

            throw new InvalidArgumentException(
                "Target Context '{$definition->targetType}' is already registered with a conflicting declaration."
            );
        }

        $this->definitions[$definition->targetType] = $definition;
    }

    public function isRegistered(string $targetType): bool
    {
        return isset($this->definitions[$targetType]);
    }

    public function definitionFor(string $targetType): ?WidgetTargetContextDefinition
    {
        return $this->definitions[$targetType] ?? null;
    }

    /**
     * @return list<string> every registered Target Context type, insertion
     *         order. Added by plan Chunk 2 Task 22 (catalog/health APIs):
     *         mirrors the identical enumeration method that already exists
     *         on this registry's two sibling classes,
     *         {@see \Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry::keys()}
     *         and {@see \Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry::keys()}
     *         -- pure read-only addition, no existing method's behavior
     *         changes. Needed because `GET /target-contexts` (Task 22's own
     *         admin catalog route) has no other way to enumerate "every
     *         currently registered Target Context"; nothing else in this
     *         module holds that list (WidgetRegistryContractSnapshot only
     *         projects an EXPLICITLY supplied reference-key subset, never
     *         "everything registered").
     */
    public function types(): array
    {
        return array_keys($this->definitions);
    }

    /**
     * @throws InvalidArgumentException when $query->targetType was never
     *         registered.
     */
    public function search(AuthorizedProjectContext $context, WidgetTargetSearchQuery $query): WidgetTargetPage
    {
        return $this->resolverFor($query->targetType)->search($context, $query);
    }

    /**
     * @throws InvalidArgumentException when $targetType was never
     *         registered. A registered type's own 403/404 (denied vs
     *         absent-in-authorized-scope) is the resolver's own thrown
     *         exception, propagated unchanged.
     */
    public function resolve(string $targetType, AuthorizedProjectContext $context, string $targetId): WidgetResolvedTarget
    {
        return $this->resolverFor($targetType)->resolve($context, $targetId);
    }

    private function resolverFor(string $targetType): WidgetTargetContextResolver
    {
        $definition = $this->definitions[$targetType] ?? null;

        if ($definition === null) {
            throw new InvalidArgumentException("Unknown Target Context key '{$targetType}'.");
        }

        return ($this->resolverLocator)($definition->resolverService);
    }

    /**
     * Full declaration identity (including label_key) used only for
     * registration-time duplicate/conflict comparison -- mirrors
     * WidgetSlotDeclaration::toArray()'s reasoning.
     *
     * @return array<string, mixed>
     */
    private function identity(WidgetTargetContextDefinition $definition): array
    {
        return [
            'target_type' => $definition->targetType,
            'label_key' => $definition->labelKey,
            'template_context_key' => $definition->templateContextKey,
            'resolver_service' => $definition->resolverService,
        ];
    }
}
