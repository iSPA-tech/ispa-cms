<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\SiteLayout;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetTargetContextDefinition;
use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use App\Contracts\Widget\WidgetTargetContextRegistrar;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Cms\Widget\SiteLayout\Targets\WidgetTargetContextRegistry;
use Cms\Widget\Tests\Fakes\FakeWidgetTargetContextResolver;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

/**
 * Chunk 2 Task 15: WidgetTargetContextRegistry (plan lines 848-884, 866-867;
 * docs/architecture/adr-widget-provider-lifecycle.md decision 7).
 *
 * Proves:
 * - registerTargetContext() rejects an unknown (not-yet-registered)
 *   Template Context key -- "unknown Template ... Context keys".
 * - search()/resolve() reject an unregistered target type -- "unknown
 *   ... Target Context keys" -- before ever touching a resolver.
 * - search()/resolve() DELEGATE unchanged to the registered root resolver
 *   (the {@see FakeWidgetTargetContextResolver} contract fake from Task
 *   12b) -- no opaque-target-id transformation, no Page/Post branch exists
 *   anywhere in this class.
 * - Duplicate/conflict semantics match WidgetSlotRegistry's (identical
 *   full-declaration re-registration is idempotent-OK; any field
 *   difference throws).
 * - Registration performs no database query.
 */
final class WidgetTargetContextRegistryTest extends TestCase
{
    private function templateContexts(): WidgetTemplateContextRegistry
    {
        $registry = new WidgetTemplateContextRegistry();
        $registry->registerTemplateContext('page_static', ['homepage.header']);

        return $registry;
    }

    /**
     * @param array<string, \App\Contracts\Widget\WidgetTargetContextResolver> $resolversByFqcn
     */
    private function registry(
        ?WidgetTemplateContextRegistry $templateContexts = null,
        array $resolversByFqcn = [],
    ): WidgetTargetContextRegistry {
        return new WidgetTargetContextRegistry(
            $templateContexts ?? $this->templateContexts(),
            static fn (string $fqcn) => $resolversByFqcn[$fqcn]
                ?? throw new InvalidArgumentException("No fake resolver registered for '{$fqcn}' in this test."),
        );
    }

    private function definition(array $overrides = []): WidgetTargetContextDefinition
    {
        $defaults = [
            'targetType' => 'page.static',
            'labelKey' => 'widget_site_layout.template_context.page_static',
            'templateContextKey' => 'page_static',
            'resolverService' => FakeWidgetTargetContextResolver::class,
        ];

        $unknown = array_diff_key($overrides, $defaults);
        self::assertSame([], $unknown, 'Unknown definition() override key(s): ' . implode(', ', array_keys($unknown)));

        $args = array_merge($defaults, $overrides);

        return new WidgetTargetContextDefinition(
            targetType: $args['targetType'],
            labelKey: $args['labelKey'],
            templateContextKey: $args['templateContextKey'],
            resolverService: $args['resolverService'],
        );
    }

    // ===================================================================
    // Root contract compliance + registration mechanics
    // ===================================================================

    public function test_registry_implements_the_root_widget_target_context_registrar_contract(): void
    {
        $this->assertInstanceOf(WidgetTargetContextRegistrar::class, $this->registry());
    }

    public function test_register_target_context_stores_a_definition_retrievable_by_type(): void
    {
        $registry = $this->registry();
        $registry->registerTargetContext($this->definition());

        $this->assertTrue($registry->isRegistered('page.static'));
        $stored = $registry->definitionFor('page.static');
        $this->assertNotNull($stored);
        $this->assertSame('page_static', $stored->templateContextKey);
    }

    public function test_an_unregistered_target_type_reports_false_and_null(): void
    {
        $registry = $this->registry();

        $this->assertFalse($registry->isRegistered('never.registered'));
        $this->assertNull($registry->definitionFor('never.registered'));
    }

    // ===================================================================
    // "unknown Template/Target Context keys" rejection
    // ===================================================================

    public function test_registering_a_target_context_against_an_unknown_template_context_key_is_rejected(): void
    {
        $registry = $this->registry($this->templateContexts()); // only 'page_static' is known

        $this->expectException(InvalidArgumentException::class);

        $registry->registerTargetContext($this->definition(['templateContextKey' => 'never_registered_template']));
    }

    public function test_search_against_an_unregistered_target_type_is_rejected_before_touching_a_resolver(): void
    {
        $registry = $this->registry(); // no target context registered at all

        $this->expectException(InvalidArgumentException::class);

        $registry->search(
            new AuthorizedProjectContext('ExampleProject'),
            new WidgetTargetSearchQuery(targetType: 'never.registered'),
        );
    }

    public function test_resolve_against_an_unregistered_target_type_is_rejected_before_touching_a_resolver(): void
    {
        $registry = $this->registry();

        $this->expectException(InvalidArgumentException::class);

        $registry->resolve('never.registered', new AuthorizedProjectContext('ExampleProject'), 'any-id');
    }

    // ===================================================================
    // Duplicate / conflict semantics
    // ===================================================================

    public function test_an_identical_duplicate_registration_is_idempotent_ok(): void
    {
        $registry = $this->registry();
        $registry->registerTargetContext($this->definition());
        $registry->registerTargetContext($this->definition());

        $this->assertTrue($registry->isRegistered('page.static'));
    }

    public function test_a_conflicting_duplicate_registration_with_a_different_resolver_service_is_rejected(): void
    {
        $registry = $this->registry();
        $registry->registerTargetContext($this->definition(['resolverService' => FakeWidgetTargetContextResolver::class]));

        $this->expectException(InvalidArgumentException::class);

        $registry->registerTargetContext($this->definition(['resolverService' => 'App\\Some\\OtherResolver']));
    }

    public function test_a_conflicting_duplicate_registration_with_a_different_template_context_key_is_rejected(): void
    {
        $templateContexts = new WidgetTemplateContextRegistry();
        $templateContexts->registerTemplateContext('page_static', []);
        $templateContexts->registerTemplateContext('article_detail', []);

        $registry = $this->registry($templateContexts);
        $registry->registerTargetContext($this->definition(['templateContextKey' => 'page_static']));

        $this->expectException(InvalidArgumentException::class);

        $registry->registerTargetContext($this->definition(['templateContextKey' => 'article_detail']));
    }

    // ===================================================================
    // Delegation: typed search/resolve reach the registered root resolver
    // unchanged -- no opaque-id transform, no Page/Post branch.
    // ===================================================================

    private function fakeWithPageStaticFixture(): FakeWidgetTargetContextResolver
    {
        $fake = new FakeWidgetTargetContextResolver();
        $fake->withTarget('123', 'page.static', 'ExampleProject', 'Gioi Thieu', 'reader', ['slug' => 'gioi-thieu']);

        return $fake;
    }

    public function test_search_delegates_to_the_registered_resolver_and_returns_its_page_unchanged(): void
    {
        $fake = $this->fakeWithPageStaticFixture();
        $registry = $this->registry(resolversByFqcn: [FakeWidgetTargetContextResolver::class => $fake]);
        $registry->registerTargetContext($this->definition());

        $context = new AuthorizedProjectContext('ExampleProject');
        $expected = $fake->search($context, new WidgetTargetSearchQuery(targetType: 'page.static'));

        $viaRegistry = $registry->search($context, new WidgetTargetSearchQuery(targetType: 'page.static'));

        $this->assertEquals($expected, $viaRegistry);
        $this->assertCount(1, $viaRegistry->items);
        $this->assertSame('123', $viaRegistry->items[0]->targetId);
        $this->assertSame('string', gettype($viaRegistry->items[0]->targetId), 'Opaque target id must never be coerced.');
    }

    public function test_resolve_delegates_to_the_registered_resolver_and_returns_its_result_unchanged(): void
    {
        $fake = $this->fakeWithPageStaticFixture();
        $registry = $this->registry(resolversByFqcn: [FakeWidgetTargetContextResolver::class => $fake]);
        $registry->registerTargetContext($this->definition());

        $context = new AuthorizedProjectContext('ExampleProject');
        $resolved = $registry->resolve('page.static', $context, '123');

        $this->assertSame('123', $resolved->targetId);
        $this->assertSame('page.static', $resolved->targetType);
        $this->assertSame('Gioi Thieu', $resolved->label);
        $this->assertSame('reader', $resolved->routeName);
        $this->assertSame(['slug' => 'gioi-thieu'], $resolved->routeParameters);
    }

    public function test_resolve_propagates_the_resolvers_403_without_translation(): void
    {
        $fake = $this->fakeWithPageStaticFixture();
        $fake->withTarget('locked-1', 'page.static', 'ExampleProject', 'Locked', 'reader', [], readable: false);
        $registry = $this->registry(resolversByFqcn: [FakeWidgetTargetContextResolver::class => $fake]);
        $registry->registerTargetContext($this->definition());

        $this->expectException(\Illuminate\Auth\Access\AuthorizationException::class);

        $registry->resolve('page.static', new AuthorizedProjectContext('ExampleProject'), 'locked-1');
    }

    public function test_resolve_propagates_the_resolvers_404_without_translation(): void
    {
        $fake = $this->fakeWithPageStaticFixture();
        $registry = $this->registry(resolversByFqcn: [FakeWidgetTargetContextResolver::class => $fake]);
        $registry->registerTargetContext($this->definition());

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);

        $registry->resolve('page.static', new AuthorizedProjectContext('ExampleProject'), 'does-not-exist');
    }

    // ===================================================================
    // "Prove registration performs no database query"
    // ===================================================================

    public function test_registration_executes_zero_database_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $registry = $this->registry();
        $registry->registerTargetContext($this->definition());
        $registry->isRegistered('page.static');
        $registry->definitionFor('page.static');

        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_registry_class_performs_no_filesystem_or_database_io_by_source_scan(): void
    {
        $forbiddenTokens = [
            'file_put_contents', 'file_get_contents', 'fopen(', 'fwrite(', 'unlink(',
            'mkdir(', 'rename(', 'Storage::', 'DB::', 'Illuminate\\Support\\Facades',
            'Illuminate\\Database', '->save(', '::create(', '::query(',
        ];

        $path = (new ReflectionClass(WidgetTargetContextRegistry::class))->getFileName();
        $this->assertNotFalse($path);

        $code = $this->stripComments((string) file_get_contents($path));

        foreach ($forbiddenTokens as $token) {
            $this->assertStringNotContainsString(
                $token,
                $code,
                "Persistence-agnostic slice violation: '{$token}' found in WidgetTargetContextRegistry"
            );
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
