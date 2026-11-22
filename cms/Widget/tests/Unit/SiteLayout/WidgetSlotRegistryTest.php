<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\SiteLayout;

use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use App\Contracts\Widget\WidgetSlotRegistrar;
use App\Contracts\Widget\WidgetTemplateContextRegistrar;
use Cms\Widget\SiteLayout\Slots\WidgetSlotDeclaration;
use Cms\Widget\SiteLayout\Slots\WidgetSlotRegistry;
use Cms\Widget\SiteLayout\Slots\WidgetTemplateContextRegistry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use ReflectionClass;
use Tests\TestCase;

/**
 * Chunk 2 Task 15: WidgetSlotRegistry + WidgetTemplateContextRegistry (plan
 * lines 848-884; docs/architecture/adr-widget-provider-lifecycle.md decision
 * 7; docs/contracts/widget-site-layout-api.md:195 WidgetSlotResource field
 * list). Both classes live in the same `app/SiteLayout/Slots/` directory
 * (the plan's allowed_paths lists no separate test-file slot for
 * WidgetTemplateContextRegistry), so this file covers both -- the same
 * co-location precedent Task 11 used for WidgetProviderDeclaration inside
 * WidgetDataProviderRegistryTest.
 *
 * Duplicate-registration semantics deliberately diverge from Task 11's
 * WidgetDataProviderRegistry (which rejects EVERY duplicate, even an
 * identical one, per D-015 "fixed compatibility identity"): this task's own
 * checklist wording is "Reject duplicate CONFLICTING declarations", read
 * literally as scoping rejection to declarations that actually differ. An
 * identical re-registration (every field, including label_key) is therefore
 * idempotent-OK here; any field difference -- including a label_key-only
 * edit -- makes two registrations of the same key "conflicting" and is
 * rejected. This is a disclosed, deliberate choice, not an inconsistency.
 */
final class WidgetSlotRegistryTest extends TestCase
{
    // ===================================================================
    // WidgetSlotRegistry
    // ===================================================================

    private function declaration(array $overrides = []): WidgetSlotDeclaration
    {
        $defaults = [
            'key' => 'homepage.header',
            'labelKey' => 'widget_site_layout.slot.homepage_header',
            'cardinality' => WidgetSlotDefinition::CARDINALITY_STACK,
            'supportedScopeTypes' => ['global', 'template', 'target'],
            'fallback' => null,
            'trustLane' => 'legacy',
            'availability' => [],
        ];

        $unknown = array_diff_key($overrides, $defaults);
        self::assertSame([], $unknown, 'Unknown declaration() override key(s): ' . implode(', ', array_keys($unknown)));

        $args = array_merge($defaults, $overrides);

        return new WidgetSlotDeclaration(
            key: $args['key'],
            labelKey: $args['labelKey'],
            cardinality: $args['cardinality'],
            supportedScopeTypes: $args['supportedScopeTypes'],
            fallback: $args['fallback'],
            trustLane: $args['trustLane'],
            availability: $args['availability'],
        );
    }

    public function test_registry_implements_the_root_widget_slot_registrar_contract(): void
    {
        $this->assertInstanceOf(WidgetSlotRegistrar::class, new WidgetSlotRegistry());
    }

    public function test_register_stores_a_full_declaration_retrievable_by_key(): void
    {
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration());

        $this->assertTrue($registry->isRegistered('homepage.header'));
        $stored = $registry->declarationFor('homepage.header');
        $this->assertNotNull($stored);
        $this->assertSame('homepage.header', $stored->key);
        $this->assertSame(WidgetSlotDefinition::CARDINALITY_STACK, $stored->cardinality);
    }

    public function test_declaration_stores_label_key_not_a_hard_coded_locale_label(): void
    {
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration(['labelKey' => 'widget_site_layout.slot.homepage_header']));

        $stored = $registry->declarationFor('homepage.header');

        // The stored value is a translation KEY (dot path), never rendered
        // English/Vietnamese text -- proven negatively: it must not contain
        // spaces (a real locale label like "Homepage Header" would).
        $this->assertSame('widget_site_layout.slot.homepage_header', $stored->labelKey);
        $this->assertStringNotContainsString(' ', $stored->labelKey);
    }

    public function test_an_unregistered_key_reports_false_and_null(): void
    {
        $registry = new WidgetSlotRegistry();

        $this->assertFalse($registry->isRegistered('never.registered'));
        $this->assertNull($registry->declarationFor('never.registered'));
    }

    public function test_root_registrar_method_applies_a_safe_minimal_declaration(): void
    {
        $registry = new WidgetSlotRegistry();
        $registrar = $registry;

        /** @var WidgetSlotRegistrar $registrar */
        $registrar->registerSlot(new WidgetSlotDefinition('external.slot', 'widget_site_layout.slot.external_slot', WidgetSlotDefinition::CARDINALITY_SINGLE));

        $stored = $registry->declarationFor('external.slot');
        $this->assertNotNull($stored);
        $this->assertSame(WidgetSlotDefinition::CARDINALITY_SINGLE, $stored->cardinality);
        $this->assertNull($stored->fallback);
        $this->assertSame([], $stored->availability);
    }

    // -------------------------------------------------------------
    // Validation
    // -------------------------------------------------------------

    public function test_an_unknown_cardinality_is_rejected(): void
    {
        $registry = new WidgetSlotRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->declaration(['cardinality' => 'not-a-real-cardinality']));
    }

    public function test_an_unknown_supported_scope_type_is_rejected(): void
    {
        $registry = new WidgetSlotRegistry();

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->declaration(['supportedScopeTypes' => ['global', 'not-a-real-scope']]));
    }

    // -------------------------------------------------------------
    // Duplicate / conflict semantics
    // -------------------------------------------------------------

    public function test_an_identical_duplicate_registration_is_idempotent_ok(): void
    {
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration());

        // Second, byte-identical registration must not throw.
        $registry->register($this->declaration());

        $this->assertTrue($registry->isRegistered('homepage.header'));
    }

    public function test_a_duplicate_registration_with_a_different_cardinality_is_rejected(): void
    {
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration(['cardinality' => WidgetSlotDefinition::CARDINALITY_STACK]));

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->declaration(['cardinality' => WidgetSlotDefinition::CARDINALITY_SINGLE]));
    }

    public function test_a_duplicate_registration_with_a_different_fallback_is_rejected(): void
    {
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration(['fallback' => 'Fallback A']));

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->declaration(['fallback' => 'Fallback B']));
    }

    public function test_a_duplicate_registration_with_a_different_trust_lane_is_rejected(): void
    {
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration(['trustLane' => 'legacy']));

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->declaration(['trustLane' => 'native']));
    }

    public function test_a_duplicate_registration_with_a_different_supported_scope_types_is_rejected(): void
    {
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration(['supportedScopeTypes' => ['global']]));

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->declaration(['supportedScopeTypes' => ['global', 'template']]));
    }

    public function test_a_duplicate_registration_with_a_different_availability_is_rejected(): void
    {
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration(['availability' => ['site_shell']]));

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->declaration(['availability' => ['page_static']]));
    }

    public function test_a_duplicate_registration_with_only_a_different_label_key_is_still_rejected(): void
    {
        // label_key is excluded from the COMPATIBILITY hash (semanticFields()),
        // but registration-time conflict detection compares the FULL
        // declaration -- two call sites disagreeing on anything for the same
        // key, including only the label, is still a real authoring conflict
        // worth surfacing rather than silently picking a winner.
        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration(['labelKey' => 'widget_site_layout.slot.a']));

        $this->expectException(InvalidArgumentException::class);

        $registry->register($this->declaration(['labelKey' => 'widget_site_layout.slot.b']));
    }

    // -------------------------------------------------------------
    // semanticFields() -- compatibility-relevant subset (ADR decision 7)
    // -------------------------------------------------------------

    public function test_semantic_fields_exclude_label_key(): void
    {
        $declaration = $this->declaration(['labelKey' => 'widget_site_layout.slot.homepage_header']);

        $this->assertArrayNotHasKey('label_key', $declaration->semanticFields());
        $this->assertArrayNotHasKey('labelKey', $declaration->semanticFields());
    }

    public function test_semantic_fields_include_every_adr_named_compatibility_field(): void
    {
        // adr-widget-provider-lifecycle.md decision 7: "cardinality, fallback,
        // trust lane, scope support, applicability" for Slot.
        $fields = $this->declaration()->semanticFields();

        $this->assertArrayHasKey('cardinality', $fields);
        $this->assertArrayHasKey('fallback', $fields);
        $this->assertArrayHasKey('trust_lane', $fields);
        $this->assertArrayHasKey('supported_scope_types', $fields);
        $this->assertArrayHasKey('availability', $fields);
    }

    // ===================================================================
    // WidgetTemplateContextRegistry (co-located: see class docblock)
    // ===================================================================

    public function test_template_context_registry_implements_the_root_registrar_contract(): void
    {
        $this->assertInstanceOf(WidgetTemplateContextRegistrar::class, new WidgetTemplateContextRegistry());
    }

    public function test_register_template_context_stores_its_slot_key_list(): void
    {
        $registry = new WidgetTemplateContextRegistry();
        $registry->registerTemplateContext('site_shell', ['site_header_before', 'site_footer_after']);

        $this->assertTrue($registry->isRegistered('site_shell'));
        $this->assertSame(['site_header_before', 'site_footer_after'], $registry->slotKeysFor('site_shell'));
    }

    public function test_an_unregistered_template_context_key_reports_false_and_null(): void
    {
        $registry = new WidgetTemplateContextRegistry();

        $this->assertFalse($registry->isRegistered('never.registered'));
        $this->assertNull($registry->slotKeysFor('never.registered'));
    }

    public function test_an_identical_duplicate_template_context_registration_is_idempotent_ok(): void
    {
        $registry = new WidgetTemplateContextRegistry();
        $registry->registerTemplateContext('site_shell', ['a', 'b']);

        // Same set, different insertion order -- still "identical" (order is
        // not semantic for a Template Context's slot set).
        $registry->registerTemplateContext('site_shell', ['b', 'a']);

        $this->assertTrue($registry->isRegistered('site_shell'));
    }

    public function test_a_conflicting_duplicate_template_context_registration_is_rejected(): void
    {
        $registry = new WidgetTemplateContextRegistry();
        $registry->registerTemplateContext('site_shell', ['a', 'b']);

        $this->expectException(InvalidArgumentException::class);

        $registry->registerTemplateContext('site_shell', ['a', 'b', 'c']);
    }

    // ===================================================================
    // "Prove registration performs no database query" (checklist item 4)
    // ===================================================================

    public function test_slot_registration_executes_zero_database_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $registry = new WidgetSlotRegistry();
        $registry->register($this->declaration());
        $registry->registerSlot(new WidgetSlotDefinition('external.slot', 'widget_site_layout.slot.external_slot'));
        $registry->isRegistered('homepage.header');
        $registry->declarationFor('homepage.header');

        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    public function test_template_context_registration_executes_zero_database_queries(): void
    {
        DB::enableQueryLog();
        DB::flushQueryLog();

        $registry = new WidgetTemplateContextRegistry();
        $registry->registerTemplateContext('site_shell', ['a', 'b']);
        $registry->isRegistered('site_shell');
        $registry->slotKeysFor('site_shell');

        $this->assertSame([], DB::getQueryLog());
        DB::disableQueryLog();
    }

    /**
     * Static-source companion proof (same technique
     * WidgetDataProviderRegistryTest uses): neither class's own source may
     * even mention a DB/Eloquent/filesystem facade or call, independent of
     * whatever a specific test exercises at runtime.
     */
    public function test_registry_classes_perform_no_filesystem_or_database_io_by_source_scan(): void
    {
        $forbiddenTokens = [
            'file_put_contents', 'file_get_contents', 'fopen(', 'fwrite(', 'unlink(',
            'mkdir(', 'rename(', 'Storage::', 'DB::', 'Illuminate\\Support\\Facades',
            'Illuminate\\Database', '->save(', '::create(', '::query(',
        ];

        foreach ([WidgetSlotRegistry::class, WidgetSlotDeclaration::class, WidgetTemplateContextRegistry::class] as $class) {
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
