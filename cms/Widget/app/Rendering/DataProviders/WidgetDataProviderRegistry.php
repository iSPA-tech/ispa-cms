<?php

declare(strict_types=1);

namespace Cms\Widget\Rendering\DataProviders;

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetDataProvider;
use App\Contracts\Widget\WidgetDataProviderRegistrar;
use Cms\Widget\Observability\WidgetRuntimeTelemetry;
use InvalidArgumentException;
use Throwable;

/**
 * Module-owned versioned provider registry (plan Chunk 1 Task 11, lines
 * 614-644; docs/architecture/adr-widget-provider-lifecycle.md decisions
 * 1-3; architecture.md "Data Provider Registry").
 *
 * Implements the root {@see WidgetDataProviderRegistrar} contract frozen by
 * Task 7 -- `registerProvider(WidgetDataProvider $provider): void` is the
 * ONLY method the root interface mandates, so it stays a thin delegate to
 * {@see self::register()} using a conservative {@see WidgetProviderDeclaration::minimal()}
 * declaration. Every provider implementation (including the six legacy
 * compatibility adapters) keeps implementing the root `WidgetDataProvider`
 * contract and returning the root `WidgetProviderResult` DTO unchanged --
 * this registry never introduces a competing Cms\Widget-namespaced result
 * type (boundary rule).
 *
 * `provider_contract_hash` canonicalizes exactly the semantic fields
 * architecture.md:597 lists -- key/version, argument schema, output schema,
 * allowed Render Context fields, cache/public-safety policy, and
 * capabilities -- via {@see CanonicalJson}. `label`/`description` are
 * declared metadata but structurally excluded from
 * {@see WidgetProviderDeclaration::semanticFields()}, so editing them alone
 * never moves the hash (D-041).
 *
 * Unregistering a provider is intentionally NOT exposed here: {@see
 * self::forget()} performs a raw removal with no usage check, and the only
 * sanctioned caller is {@see ProviderUsageGuard::unregister()}, which
 * verifies the version is unreferenced first (D-015). This registry owns
 * storage/hash/resolve only; retention enforcement is the Guard's job --
 * splitting them mirrors the plan's own task title, "versioned Data
 * Provider Registry AND retention health", as two distinct concerns.
 */
final class WidgetDataProviderRegistry implements WidgetDataProviderRegistrar
{
    public const ERROR_INVALID = 'WIDGET_PROVIDER_INVALID';
    public const ERROR_UNAVAILABLE = 'WIDGET_PROVIDER_UNAVAILABLE';

    /** @var array<string, WidgetDataProvider> */
    private array $providers = [];

    /** @var array<string, WidgetProviderDeclaration> */
    private array $declarations = [];

    /** @var array<string, string> */
    private array $hashes = [];

    private readonly WidgetRuntimeTelemetry $telemetry;

    /**
     * DELIBERATELY constructible with a nullable, self-defaulting telemetry
     * argument (Chunk 2 Task 24, DoD 1279: "provider... telemetry into the
     * real... registry") -- this task's allowed_paths does not include
     * WidgetServiceProvider.php, so the EXISTING
     * `$this->app->singleton(WidgetDataProviderRegistry::class)` bare
     * class-string binding (zero-argument reflection resolution) keeps
     * working unmodified while still receiving a real telemetry instance.
     */
    public function __construct(?WidgetRuntimeTelemetry $telemetry = null)
    {
        $this->telemetry = $telemetry ?? new WidgetRuntimeTelemetry();
    }

    /**
     * Primary registration entry point used by cms/Widget's own wiring
     * (e.g. the six legacy compatibility adapters + menu.by_group), which
     * hold the concrete WidgetDataProviderRegistry type and can supply a
     * full, typed declaration -- not just the thin root interface.
     *
     * @throws InvalidArgumentException on a key/version mismatch between
     *         the provider and its declaration, or on a duplicate
     *         {key, version} registration (D-015 treats a registered
     *         key/version as a fixed compatibility identity, not something
     *         re-registration can silently overwrite).
     */
    public function register(WidgetDataProvider $provider, WidgetProviderDeclaration $declaration): void
    {
        if ($declaration->key !== $provider->key() || $declaration->version !== $provider->version()) {
            throw new InvalidArgumentException(
                'WidgetProviderDeclaration key/version must match the provider it describes '
                . "(declaration: {$declaration->key}@{$declaration->version}, "
                . "provider: {$provider->key()}@{$provider->version()})."
            );
        }

        $identity = self::identity($provider->key(), $provider->version());

        if (isset($this->providers[$identity])) {
            throw new InvalidArgumentException(
                "Provider '{$provider->key()}' version {$provider->version()} is already registered."
            );
        }

        $this->providers[$identity] = $provider;
        $this->declarations[$identity] = $declaration;
        $this->hashes[$identity] = CanonicalJson::hash($declaration->semanticFields());
    }

    /**
     * Root WidgetDataProviderRegistrar contract method. A caller holding
     * only the root interface (e.g. a project/module other than
     * cms/Widget) gets a safe minimal declaration -- empty schemas,
     * non-cacheable, not public-safe -- so the provider still participates
     * in hash/retention bookkeeping without cms/Widget needing to know
     * anything about the caller's module.
     */
    public function registerProvider(WidgetDataProvider $provider): void
    {
        $this->register($provider, WidgetProviderDeclaration::minimal($provider->key(), $provider->version()));
    }

    /**
     * @return list<WidgetDataProvider>
     */
    public function providers(): array
    {
        return array_values($this->providers);
    }

    public function isRegistered(string $key, int $version): bool
    {
        return isset($this->providers[self::identity($key, $version)]);
    }

    public function declarationFor(string $key, int $version): ?WidgetProviderDeclaration
    {
        return $this->declarations[self::identity($key, $version)] ?? null;
    }

    public function contractHashFor(string $key, int $version): ?string
    {
        return $this->hashes[self::identity($key, $version)] ?? null;
    }

    /**
     * Runtime resolution entry point. Never throws for an expected failure
     * mode -- unknown key/version and argument-schema validation failures
     * both return a non-ok WidgetProviderResult with
     * {@see self::ERROR_INVALID}; any Throwable escaping the provider's
     * own fetch() (a real outage/timeout/unexpected failure) is caught here
     * and converted to {@see self::ERROR_UNAVAILABLE}. This is the
     * WIDGET_PROVIDER_INVALID vs WIDGET_PROVIDER_UNAVAILABLE distinction
     * architecture.md:744 requires.
     *
     * @param array<string, mixed> $arguments
     */
    public function resolve(string $key, int $version, array $arguments, WidgetRenderContext $context): WidgetProviderResult
    {
        $identity = self::identity($key, $version);
        $provider = $this->providers[$identity] ?? null;
        $declaration = $this->declarations[$identity] ?? null;

        if ($provider === null || $declaration === null) {
            return $this->recordAndReturn($context, $key, $version, new WidgetProviderResult(items: [], ok: false, errorCode: self::ERROR_INVALID));
        }

        if (!$this->argumentsAreValid($declaration, $arguments)) {
            return $this->recordAndReturn($context, $key, $version, new WidgetProviderResult(items: [], ok: false, errorCode: self::ERROR_INVALID));
        }

        try {
            return $this->recordAndReturn($context, $key, $version, $provider->fetch($arguments, $context));
        } catch (Throwable $e) {
            // Surface the failure to the configured exception handler
            // BEFORE degrading to the typed UNAVAILABLE result: a
            // production database/backend outage must never become an
            // invisible empty widget (quality-review follow-up on the
            // Task 11 slice).
            report($e);

            return $this->recordAndReturn($context, $key, $version, new WidgetProviderResult(items: [], ok: false, errorCode: self::ERROR_UNAVAILABLE));
        }
    }

    /**
     * Records safe provider telemetry (DoD 1279) -- project/key/version/
     * outcome ONLY, never the caller-supplied $arguments or the resolved
     * $result->items -- then returns $result unchanged, so every
     * self::resolve() return point stays a single expression.
     */
    private function recordAndReturn(WidgetRenderContext $context, string $key, int $version, WidgetProviderResult $result): WidgetProviderResult
    {
        $this->telemetry->recordProvider($context->projectName, $key, $version, $result->ok, $result->errorCode);

        return $result;
    }

    /**
     * Raw removal -- performs NO usage/retention check. The only sanctioned
     * caller is ProviderUsageGuard::unregister(), which verifies the
     * version is unreferenced BEFORE calling this. Calling this directly
     * bypasses D-015's "a referenced version cannot be unregistered" rule,
     * so it is deliberately not named `unregister()`.
     */
    public function forget(string $key, int $version): void
    {
        $identity = self::identity($key, $version);
        unset($this->providers[$identity], $this->declarations[$identity], $this->hashes[$identity]);
    }

    /**
     * @param array<string, mixed> $arguments
     */
    private function argumentsAreValid(WidgetProviderDeclaration $declaration, array $arguments): bool
    {
        foreach ($declaration->argumentSchema as $name => $rule) {
            $required = (bool) ($rule['required'] ?? false);
            $type = $rule['type'] ?? null;

            if (!array_key_exists($name, $arguments)) {
                if ($required) {
                    return false;
                }

                continue;
            }

            if ($type !== null && !$this->matchesType($arguments[$name], $type)) {
                return false;
            }
        }

        return true;
    }

    private function matchesType(mixed $value, string $type): bool
    {
        return match ($type) {
            'int' => is_int($value),
            'string' => is_string($value),
            'bool' => is_bool($value),
            'array' => is_array($value),
            default => true,
        };
    }

    private static function identity(string $key, int $version): string
    {
        return $key . '@' . $version;
    }
}

/**
 * Typed provider declaration (architecture.md "Data Provider Registry":
 * "Each provider declares: stable key and integer version; owner
 * module/project; argument schema and validation; allowed Render Context
 * fields; output schema; cache/context-safety policy; public-safety and
 * declared capability set; deprecation status and compatibility notes.").
 * Co-located with WidgetDataProviderRegistry because Task 11's allowed_paths
 * has no separate value-object file slot -- the same co-location precedent
 * as WidgetEligibilityDecision inside WidgetSiteLayoutEligibility.php (Task
 * 9c) and the in-memory Manifest repository test double inside
 * WidgetArtifactRepositoryTest.php (Task 10).
 *
 * {@see self::semanticFields()} is the exact, exhaustive set of fields that
 * feed `provider_contract_hash`; every other property here (label,
 * description, deprecated) is real declared metadata but is structurally
 * absent from that method, so it can never influence the hash.
 */
final readonly class WidgetProviderDeclaration
{
    /**
     * @param array<string, array{type?: string, required?: bool}> $argumentSchema keyed by argument name
     * @param array<string, mixed> $outputSchema descriptive shape of a successful result's items
     * @param list<string> $allowedContextFields WidgetRenderContext fields this provider may read
     * @param array{cacheable: bool, publicSafe: bool, varyBy?: list<string>} $cachePolicy
     * @param list<string> $capabilities
     */
    public function __construct(
        public string $key,
        public int $version,
        public array $argumentSchema = [],
        public array $outputSchema = [],
        public array $allowedContextFields = [],
        public array $cachePolicy = ['cacheable' => false, 'publicSafe' => false],
        public array $capabilities = [],
        public string $label = '',
        public string $description = '',
        public bool $deprecated = false,
    ) {
    }

    /**
     * Conservative default used when a provider is registered through only
     * the thin root WidgetDataProviderRegistrar::registerProvider()
     * contract (no rich declaration supplied).
     */
    public static function minimal(string $key, int $version): self
    {
        return new self(key: $key, version: $version);
    }

    /**
     * The exhaustive semantic-hash input set (architecture.md:597): key,
     * version, argument schema, output schema, allowed Render Context
     * fields, cache/public-safety policy, and capabilities. list-valued
     * fields are sorted so value ORDER never affects the hash, matching the
     * WidgetArtifactBuilder::sortedUnique() precedent (Task 10) for the
     * same canonical-ordering guarantee. label/description/deprecated are
     * declared metadata that intentionally never appear below.
     *
     * @return array<string, mixed>
     */
    public function semanticFields(): array
    {
        return [
            'key' => $this->key,
            'version' => $this->version,
            'argument_schema' => $this->argumentSchema,
            'output_schema' => $this->outputSchema,
            'allowed_context_fields' => $this->sortedUnique($this->allowedContextFields),
            'cache_policy' => $this->cachePolicy,
            'capabilities' => $this->sortedUnique($this->capabilities),
        ];
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
