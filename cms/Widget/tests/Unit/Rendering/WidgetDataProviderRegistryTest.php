<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\Rendering;

use App\Contracts\Widget\DTO\WidgetProviderResult;
use App\Contracts\Widget\DTO\WidgetRenderContext;
use App\Contracts\Widget\WidgetDataProvider;
use App\Contracts\Widget\WidgetDataProviderRegistrar;
use Cms\Widget\Rendering\DataProviders\CanonicalJson;
use Cms\Widget\Rendering\DataProviders\NullProviderUsageReader;
use Cms\Widget\Rendering\DataProviders\ProviderUsageGuard;
use Cms\Widget\Rendering\DataProviders\ProviderUsageReader;
use Cms\Widget\Rendering\DataProviders\WidgetDataProviderRegistry;
use Cms\Widget\Rendering\DataProviders\WidgetProviderDeclaration;
use Cms\Widget\Rendering\DataProviders\WidgetProviderInUseException;
use Illuminate\Support\Facades\Exceptions;
use InvalidArgumentException;
use ReflectionClass;
use RuntimeException;
use Tests\TestCase;
use Throwable;

/**
 * Chunk 1 Task 11: versioned Data Provider Registry and retention health
 * (plan lines 614-644; docs/architecture/adr-widget-provider-lifecycle.md;
 * architecture.md "Data Provider Registry").
 *
 * Covers the pure in-memory registry/guard/hash mechanics, per the frozen
 * test contract: duplicate registration, unknown key/version, argument
 * validation, semantic-hash stability/canonical ordering, an undeclared
 * semantic change without a version bump (hash differs), the
 * WIDGET_PROVIDER_INVALID vs WIDGET_PROVIDER_UNAVAILABLE timeout/outage
 * distinction (including report() surfacing), and ProviderUsageGuard's
 * retained-reference/health behavior against an in-memory
 * ProviderUsageReader (the database reader lands Task 13b).
 *
 * The DB-backed adapter groups (the six legacy-name compatibility
 * adapters, fixture parity against WidgetQueryService, the product limit
 * cache dimension, and menu.by_group delegation) live in
 * {@see WidgetLegacyProviderAdapterTest} -- split out when this file
 * crossed the module's 1000-line guard (disclosed deviation from Task 11's
 * literal allowed_paths; see that file's docblock).
 */
final class WidgetDataProviderRegistryTest extends TestCase
{
    // -----------------------------------------------------------------
    // Test helpers
    // -----------------------------------------------------------------

    private function context(): WidgetRenderContext
    {
        return new WidgetRenderContext(projectName: 'Default');
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function declaration(array $overrides = []): WidgetProviderDeclaration
    {
        $defaults = [
            'key' => 'fake.provider',
            'version' => 1,
            'argumentSchema' => [],
            'outputSchema' => [],
            'allowedContextFields' => [],
            'cachePolicy' => ['cacheable' => false, 'publicSafe' => false],
            'capabilities' => [],
            'label' => 'Fake Provider',
            'description' => 'A fake provider used for registry mechanics tests.',
            'deprecated' => false,
        ];

        $unknownKeys = array_diff_key($overrides, $defaults);
        self::assertSame(
            [],
            $unknownKeys,
            'Unknown declaration() override key(s): ' . implode(', ', array_keys($unknownKeys))
        );

        $args = array_merge($defaults, $overrides);

        return new WidgetProviderDeclaration(
            key: $args['key'],
            version: $args['version'],
            argumentSchema: $args['argumentSchema'],
            outputSchema: $args['outputSchema'],
            allowedContextFields: $args['allowedContextFields'],
            cachePolicy: $args['cachePolicy'],
            capabilities: $args['capabilities'],
            label: $args['label'],
            description: $args['description'],
            deprecated: $args['deprecated'],
        );
    }

    // ===================================================================
    // Group A: registration mechanics
    // ===================================================================

    public function test_register_computes_and_stores_a_contract_hash_for_a_new_provider(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $provider = new FakeWidgetDataProvider('fake.provider', 1);

        $registry->register($provider, $this->declaration());

        $this->assertTrue($registry->isRegistered('fake.provider', 1));
        $this->assertNotNull($registry->contractHashFor('fake.provider', 1));
        $this->assertSame(64, strlen((string) $registry->contractHashFor('fake.provider', 1)), 'Expected a sha256 hex digest.');
    }

    public function test_duplicate_registration_of_the_same_key_and_version_is_rejected(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(new FakeWidgetDataProvider('fake.provider', 1), $this->declaration());

        $this->expectException(InvalidArgumentException::class);

        $registry->register(new FakeWidgetDataProvider('fake.provider', 1), $this->declaration());
    }

    public function test_register_rejects_a_declaration_whose_key_or_version_does_not_match_the_provider(): void
    {
        $registry = new WidgetDataProviderRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->register(
            new FakeWidgetDataProvider('fake.provider', 1),
            $this->declaration(['key' => 'different.key'])
        );
    }

    public function test_the_same_key_can_be_registered_again_at_a_different_version(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(new FakeWidgetDataProvider('fake.provider', 1), $this->declaration(['version' => 1]));
        $registry->register(new FakeWidgetDataProvider('fake.provider', 2), $this->declaration(['version' => 2]));

        $this->assertTrue($registry->isRegistered('fake.provider', 1));
        $this->assertTrue($registry->isRegistered('fake.provider', 2));
    }

    // ===================================================================
    // Group B: resolve() -- unknown key/version, argument validation,
    // WIDGET_PROVIDER_INVALID vs WIDGET_PROVIDER_UNAVAILABLE distinction
    // ===================================================================

    public function test_resolve_returns_invalid_for_an_entirely_unknown_key(): void
    {
        $registry = new WidgetDataProviderRegistry();

        $result = $registry->resolve('never.registered', 1, [], $this->context());

        $this->assertFalse($result->ok);
        $this->assertSame(WidgetDataProviderRegistry::ERROR_INVALID, $result->errorCode);
        $this->assertSame([], $result->items);
    }

    public function test_resolve_returns_invalid_for_a_known_key_at_an_unregistered_version(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(new FakeWidgetDataProvider('fake.provider', 1), $this->declaration());

        $result = $registry->resolve('fake.provider', 2, [], $this->context());

        $this->assertFalse($result->ok);
        $this->assertSame(WidgetDataProviderRegistry::ERROR_INVALID, $result->errorCode);
    }

    public function test_resolve_returns_invalid_when_a_required_argument_is_missing(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(
            new FakeWidgetDataProvider('fake.provider', 1),
            $this->declaration(['argumentSchema' => ['group' => ['type' => 'string', 'required' => true]]])
        );

        $result = $registry->resolve('fake.provider', 1, [], $this->context());

        $this->assertFalse($result->ok);
        $this->assertSame(WidgetDataProviderRegistry::ERROR_INVALID, $result->errorCode);
    }

    public function test_resolve_returns_invalid_when_an_argument_type_does_not_match_the_declared_schema(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(
            new FakeWidgetDataProvider('fake.provider', 1),
            $this->declaration(['argumentSchema' => ['limit' => ['type' => 'int', 'required' => false]]])
        );

        $result = $registry->resolve('fake.provider', 1, ['limit' => 'not-an-int'], $this->context());

        $this->assertFalse($result->ok);
        $this->assertSame(WidgetDataProviderRegistry::ERROR_INVALID, $result->errorCode);
    }

    public function test_resolve_reports_and_returns_unavailable_when_the_provider_throws_during_fetch(): void
    {
        // report() surfacing (quality-review follow-up): the catch that
        // degrades a provider Throwable into the typed UNAVAILABLE result
        // must FIRST hand the exception to the configured exception
        // handler, so a production outage is never an invisible empty
        // widget. Exceptions::fake() swaps the handler for Laravel's
        // ExceptionHandlerFake, which records what report() receives.
        Exceptions::fake();

        $registry = new WidgetDataProviderRegistry();
        $throwing = FakeWidgetDataProvider::throwing('fake.provider', 1, new RuntimeException('simulated outage'));
        $registry->register($throwing, $this->declaration());

        $result = $registry->resolve('fake.provider', 1, [], $this->context());

        $this->assertFalse($result->ok);
        $this->assertSame(WidgetDataProviderRegistry::ERROR_UNAVAILABLE, $result->errorCode);
        Exceptions::assertReported(RuntimeException::class);
    }

    public function test_resolve_returns_the_providers_successful_result_when_arguments_are_valid(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $expected = new WidgetProviderResult(items: [['id' => 1]], ok: true);
        $registry->register(FakeWidgetDataProvider::returning('fake.provider', 1, $expected), $this->declaration());

        $result = $registry->resolve('fake.provider', 1, [], $this->context());

        $this->assertSame($expected, $result);
    }

    // ===================================================================
    // Group C: semantic-hash stability, canonical ordering, undeclared
    // change detection
    // ===================================================================

    public function test_contract_hash_is_deterministic_for_two_identically_declared_registries(): void
    {
        $registryA = new WidgetDataProviderRegistry();
        $registryA->register(new FakeWidgetDataProvider('fake.provider', 1), $this->declaration());

        $registryB = new WidgetDataProviderRegistry();
        $registryB->register(new FakeWidgetDataProvider('fake.provider', 1), $this->declaration());

        $this->assertSame(
            $registryA->contractHashFor('fake.provider', 1),
            $registryB->contractHashFor('fake.provider', 1)
        );
    }

    public function test_contract_hash_is_independent_of_declaration_array_key_insertion_order(): void
    {
        $forward = $this->declaration([
            'argumentSchema' => ['a' => ['type' => 'string'], 'b' => ['type' => 'int']],
        ]);
        $reversed = $this->declaration([
            'argumentSchema' => ['b' => ['type' => 'int'], 'a' => ['type' => 'string']],
        ]);

        $this->assertSame(
            CanonicalJson::hash($forward->semanticFields()),
            CanonicalJson::hash($reversed->semanticFields()),
            'Canonical JSON must recursively sort keys so insertion order never affects the hash.'
        );
    }

    public function test_contract_hash_changes_when_argument_schema_changes_alone(): void
    {
        $baseline = $this->declaration(['argumentSchema' => ['limit' => ['type' => 'int']]]);
        $changed = $this->declaration(['argumentSchema' => ['limit' => ['type' => 'string']]]);

        $this->assertNotSame(
            CanonicalJson::hash($baseline->semanticFields()),
            CanonicalJson::hash($changed->semanticFields())
        );
    }

    public function test_contract_hash_changes_when_output_schema_changes_alone(): void
    {
        $baseline = $this->declaration(['outputSchema' => ['items' => 'list<array>']]);
        $changed = $this->declaration(['outputSchema' => ['items' => 'list<array>', 'total' => 'int']]);

        $this->assertNotSame(
            CanonicalJson::hash($baseline->semanticFields()),
            CanonicalJson::hash($changed->semanticFields())
        );
    }

    public function test_contract_hash_changes_when_allowed_context_fields_change_alone(): void
    {
        $baseline = $this->declaration(['allowedContextFields' => ['projectName']]);
        $changed = $this->declaration(['allowedContextFields' => ['projectName', 'isPreview']]);

        $this->assertNotSame(
            CanonicalJson::hash($baseline->semanticFields()),
            CanonicalJson::hash($changed->semanticFields())
        );
    }

    public function test_contract_hash_changes_when_cache_policy_changes_alone(): void
    {
        $baseline = $this->declaration(['cachePolicy' => ['cacheable' => false, 'publicSafe' => false]]);
        $changed = $this->declaration(['cachePolicy' => ['cacheable' => true, 'publicSafe' => false]]);

        $this->assertNotSame(
            CanonicalJson::hash($baseline->semanticFields()),
            CanonicalJson::hash($changed->semanticFields())
        );
    }

    public function test_contract_hash_changes_when_capabilities_change_alone(): void
    {
        $baseline = $this->declaration(['capabilities' => ['list']]);
        $changed = $this->declaration(['capabilities' => ['list', 'search']]);

        $this->assertNotSame(
            CanonicalJson::hash($baseline->semanticFields()),
            CanonicalJson::hash($changed->semanticFields())
        );
    }

    public function test_contract_hash_is_unchanged_when_only_label_or_description_changes(): void
    {
        $baseline = $this->declaration(['label' => 'Original label', 'description' => 'Original description']);
        $changed = $this->declaration(['label' => 'Completely different label', 'description' => 'Completely different description']);

        $this->assertSame(
            CanonicalJson::hash($baseline->semanticFields()),
            CanonicalJson::hash($changed->semanticFields()),
            'label/description are declared metadata explicitly EXCLUDED from provider_contract_hash (D-041).'
        );
    }

    public function test_an_undeclared_semantic_change_without_a_version_bump_is_detectable_via_hash_difference(): void
    {
        // Simulates a developer editing a provider's argument schema in
        // code without remembering to bump `version` -- D-041's exact
        // failure mode. Task 11 does not implement the pre-deploy preflight
        // command itself (Task 15's cms:widget-registry-preflight); this
        // proves the hash INPUT that command will compare is sensitive
        // enough to catch it: a retained hash vs a newly-computed hash for
        // the identical key/version differ, so a future preflight comparing
        // them would fail before traffic exactly as D-033/D-041 require.
        $retained = $this->declaration([
            'key' => 'product.latest',
            'version' => 1,
            'argumentSchema' => ['limit' => ['type' => 'int', 'required' => false]],
        ]);
        $currentCode = $this->declaration([
            'key' => 'product.latest',
            'version' => 1,
            // Same key/version, but the argument schema silently grew a new
            // required field -- a breaking change a version bump should
            // have accompanied.
            'argumentSchema' => [
                'limit' => ['type' => 'int', 'required' => false],
                'category_id' => ['type' => 'int', 'required' => true],
            ],
        ]);

        $this->assertSame($retained->key, $currentCode->key);
        $this->assertSame($retained->version, $currentCode->version);
        $this->assertNotSame(
            CanonicalJson::hash($retained->semanticFields()),
            CanonicalJson::hash($currentCode->semanticFields()),
            'An undeclared semantic change without a version bump must still change provider_contract_hash.'
        );
    }

    // ===================================================================
    // Group D: root contract compliance
    // ===================================================================

    public function test_registry_implements_the_root_widget_data_provider_registrar_contract(): void
    {
        $registry = new WidgetDataProviderRegistry();

        $this->assertInstanceOf(WidgetDataProviderRegistrar::class, $registry);
    }

    public function test_registering_through_the_root_registrar_method_applies_a_safe_minimal_declaration(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registrar = $registry; // Same object, referenced via the interface below.

        /** @var WidgetDataProviderRegistrar $registrar */
        $registrar->registerProvider(new FakeWidgetDataProvider('external.provider', 1));

        $declaration = $registry->declarationFor('external.provider', 1);
        $this->assertNotNull($declaration);
        $this->assertSame([], $declaration->argumentSchema);
        $this->assertSame(['cacheable' => false, 'publicSafe' => false], $declaration->cachePolicy);
    }

    // ===================================================================
    // Group E: ProviderUsageGuard retention + health (in-memory reader)
    // ===================================================================

    public function test_guard_blocks_unregister_of_a_referenced_provider_version(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(new FakeWidgetDataProvider('product.latest', 1), $this->declaration(['key' => 'product.latest']));

        $reader = new InMemoryProviderUsageReader(referenced: [['key' => 'product.latest', 'version' => 1]]);
        $guard = new ProviderUsageGuard($registry, $reader);

        $this->expectException(WidgetProviderInUseException::class);

        try {
            $guard->unregister('product.latest', 1);
        } finally {
            $this->assertTrue($registry->isRegistered('product.latest', 1), 'A referenced version must remain registered after a rejected unregister attempt.');
        }
    }

    public function test_guard_allows_unregister_of_an_unreferenced_provider_version_and_the_registry_forgets_it(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(new FakeWidgetDataProvider('product.latest', 1), $this->declaration(['key' => 'product.latest']));

        $reader = new InMemoryProviderUsageReader(referenced: []);
        $guard = new ProviderUsageGuard($registry, $reader);

        $guard->unregister('product.latest', 1);

        $this->assertFalse($registry->isRegistered('product.latest', 1));
    }

    public function test_null_provider_usage_reader_never_blocks_unregister_and_reports_no_usage(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(new FakeWidgetDataProvider('product.latest', 1), $this->declaration(['key' => 'product.latest']));

        $guard = new ProviderUsageGuard($registry, new NullProviderUsageReader());

        $guard->unregister('product.latest', 1);

        $this->assertFalse($registry->isRegistered('product.latest', 1));
        $this->assertSame(['missing' => [], 'deprecated' => []], $guard->health());
    }

    public function test_health_reports_referenced_but_missing_provider_versions(): void
    {
        $registry = new WidgetDataProviderRegistry();
        // 'product.latest' v1 is referenced by a retained Draft/Release but
        // was never (re-)registered -- e.g. its provider class was removed
        // from code.
        $reader = new InMemoryProviderUsageReader(referenced: [['key' => 'product.latest', 'version' => 1]]);
        $guard = new ProviderUsageGuard($registry, $reader);

        $health = $guard->health();

        $this->assertSame([['key' => 'product.latest', 'version' => 1]], $health['missing']);
        $this->assertSame([], $health['deprecated']);
    }

    public function test_health_reports_referenced_and_deprecated_provider_versions(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(
            new FakeWidgetDataProvider('product.latest', 1),
            $this->declaration(['key' => 'product.latest', 'version' => 1, 'deprecated' => true])
        );
        $reader = new InMemoryProviderUsageReader(referenced: [['key' => 'product.latest', 'version' => 1]]);
        $guard = new ProviderUsageGuard($registry, $reader);

        $health = $guard->health();

        $this->assertSame([], $health['missing']);
        $this->assertSame([['key' => 'product.latest', 'version' => 1]], $health['deprecated']);
    }

    public function test_health_reports_nothing_for_referenced_active_non_deprecated_usage(): void
    {
        $registry = new WidgetDataProviderRegistry();
        $registry->register(new FakeWidgetDataProvider('product.latest', 1), $this->declaration(['key' => 'product.latest']));
        $reader = new InMemoryProviderUsageReader(referenced: [['key' => 'product.latest', 'version' => 1]]);
        $guard = new ProviderUsageGuard($registry, $reader);

        $this->assertSame(['missing' => [], 'deprecated' => []], $guard->health());
    }

    // ===================================================================
    // Group I: persistence-agnostic guard -- scoped to the registry/guard/
    // reader/hash-helper files only. The legacy adapters are DELIBERATELY
    // DB-backed compatibility wrappers and are out of scope for this scan
    // (see WidgetLegacyProviderAdapterTest).
    // ===================================================================

    public function test_registry_guard_and_usage_reader_classes_perform_no_filesystem_or_database_io(): void
    {
        $forbiddenTokens = [
            'file_put_contents', 'file_get_contents', 'fopen(', 'fwrite(', 'unlink(',
            'mkdir(', 'rename(', 'Storage::', 'DB::', 'Illuminate\\Support\\Facades',
            'Illuminate\\Database', '->save(', '::create(', '::query(',
        ];

        $classes = [
            WidgetDataProviderRegistry::class,
            WidgetProviderDeclaration::class,
            CanonicalJson::class,
            ProviderUsageReader::class,
            NullProviderUsageReader::class,
            ProviderUsageGuard::class,
            WidgetProviderInUseException::class,
        ];

        foreach ($classes as $class) {
            $path = (new ReflectionClass($class))->getFileName();
            $this->assertNotFalse($path);

            $code = $this->stripComments((string) file_get_contents($path));

            foreach ($forbiddenTokens as $token) {
                $this->assertStringNotContainsString(
                    $token,
                    $code,
                    "Persistence-agnostic slice violation: '{$token}' found in " . basename($path) . " ({$class})"
                );
            }
        }
    }

    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }

                $code .= $token[1];
            } else {
                $code .= $token;
            }
        }

        return $code;
    }
}

/**
 * Configurable WidgetDataProvider test double (root contract compliance --
 * implements App\Contracts\Widget\WidgetDataProvider and returns the root
 * App\Contracts\Widget\DTO\WidgetProviderResult unchanged). Co-located in
 * this test file because Task 11's allowed_paths has no separate Fakes-file
 * slot, mirroring the co-located in-memory repository precedent in
 * Cms\Widget\Tests\Unit\Rendering\WidgetArtifactRepositoryTest (Task 10).
 */
final class FakeWidgetDataProvider implements WidgetDataProvider
{
    public function __construct(
        private readonly string $providerKey,
        private readonly int $providerVersion,
        private readonly ?WidgetProviderResult $result = null,
        private readonly ?Throwable $throwable = null,
    ) {
    }

    public static function returning(string $key, int $version, WidgetProviderResult $result): self
    {
        return new self($key, $version, $result, null);
    }

    public static function throwing(string $key, int $version, Throwable $throwable): self
    {
        return new self($key, $version, null, $throwable);
    }

    public function key(): string
    {
        return $this->providerKey;
    }

    public function version(): int
    {
        return $this->providerVersion;
    }

    public function fetch(array $arguments, WidgetRenderContext $context): WidgetProviderResult
    {
        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        return $this->result ?? new WidgetProviderResult(items: [], ok: true);
    }
}

/**
 * In-memory ProviderUsageReader test double. Co-located for the same
 * allowed_paths reason as FakeWidgetDataProvider above.
 */
final class InMemoryProviderUsageReader implements ProviderUsageReader
{
    /**
     * @param list<array{key: string, version: int}> $referenced
     */
    public function __construct(
        private readonly array $referenced = [],
    ) {
    }

    public function isReferenced(string $key, int $version): bool
    {
        foreach ($this->referenced as $usage) {
            if ($usage['key'] === $key && $usage['version'] === $version) {
                return true;
            }
        }

        return false;
    }

    public function referencedProviderVersions(): array
    {
        return $this->referenced;
    }
}
