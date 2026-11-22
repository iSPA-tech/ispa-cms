<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Unit\SiteLayout;

use App\Contracts\Widget\DTO\WidgetSlotDefinition;
use Cms\Widget\SiteLayout\Resolution\WidgetPlacementResolver;
use Cms\Widget\SiteLayout\Resolution\WidgetResolvedPlacementCandidate;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Chunk 2 Task 17: deterministic effective-resolution algorithm (plan lines
 * 1020-1051; architecture.md "Effective resolution").
 *
 * Pure logic only -- no database, no Eloquent, no container. Proves the two
 * named precedence families exactly:
 * - `single`/`replace`: target scope wins when present, else template, else
 *   global; at most one row is ever returned.
 * - `stack`: every matching scope concatenates in a fixed order (global,
 *   then template, then target), each internally ordered by `position`.
 *
 * {@see WidgetPlacementResolver::scopeIdentity()} is also proven here since
 * it is the same canonical string-building rule
 * {@see \Cms\Widget\SiteLayout\Drafts\WidgetLayoutDraftService} reuses
 * verbatim rather than adding a second private copy.
 */
final class WidgetPlacementResolverTest extends TestCase
{
    private function resolver(): WidgetPlacementResolver
    {
        return new WidgetPlacementResolver();
    }

    private function candidate(
        string $scopeType,
        ?string $scopeKey,
        ?string $targetType,
        ?string $targetId,
        int $position,
        mixed $identity = null,
    ): WidgetResolvedPlacementCandidate {
        return new WidgetResolvedPlacementCandidate($scopeType, $scopeKey, $targetType, $targetId, $position, $identity);
    }

    // -----------------------------------------------------------------
    // scopeIdentity()
    // -----------------------------------------------------------------

    public function test_scope_identity_builds_the_canonical_global_string(): void
    {
        $this->assertSame('global', WidgetPlacementResolver::scopeIdentity('global', null, null, null));
    }

    public function test_scope_identity_builds_the_canonical_template_string(): void
    {
        $this->assertSame('template:site.shell', WidgetPlacementResolver::scopeIdentity('template', 'site.shell', null, null));
    }

    public function test_scope_identity_builds_the_canonical_target_string(): void
    {
        $this->assertSame('target:page.static:42', WidgetPlacementResolver::scopeIdentity('target', null, 'page.static', '42'));
    }

    public function test_scope_identity_rejects_an_unknown_scope_type(): void
    {
        $this->expectException(InvalidArgumentException::class);

        WidgetPlacementResolver::scopeIdentity('bogus', null, null, null);
    }

    // -----------------------------------------------------------------
    // stack cardinality: merge global + template + target, each in
    // position order.
    // -----------------------------------------------------------------

    public function test_stack_cardinality_merges_global_then_template_then_target_in_position_order(): void
    {
        $candidates = [
            $this->candidate('target', null, 'page.static', '1', 0, 'target-a'),
            $this->candidate('global', null, null, null, 5, 'global-b'),
            $this->candidate('global', null, null, null, 1, 'global-a'),
            $this->candidate('template', 'site.shell', null, null, 0, 'template-a'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_STACK,
            templateContextKey: 'site.shell',
            targetType: 'page.static',
            targetId: '1',
        );

        $this->assertSame(
            ['global-a', 'global-b', 'template-a', 'target-a'],
            array_map(static fn (WidgetResolvedPlacementCandidate $c): mixed => $c->identity, $resolved),
        );
    }

    public function test_stack_cardinality_ignores_a_candidate_scoped_to_a_different_template_context(): void
    {
        $candidates = [
            $this->candidate('template', 'site.shell', null, null, 0, 'matching-template'),
            $this->candidate('template', 'page.static', null, null, 0, 'other-template'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_STACK,
            templateContextKey: 'site.shell',
            targetType: null,
            targetId: null,
        );

        $this->assertSame(['matching-template'], array_map(static fn ($c) => $c->identity, $resolved));
    }

    public function test_stack_cardinality_ignores_a_candidate_scoped_to_a_different_target(): void
    {
        $candidates = [
            $this->candidate('target', null, 'page.static', '1', 0, 'matching-target'),
            $this->candidate('target', null, 'page.static', '2', 0, 'other-target'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_STACK,
            templateContextKey: null,
            targetType: 'page.static',
            targetId: '1',
        );

        $this->assertSame(['matching-target'], array_map(static fn ($c) => $c->identity, $resolved));
    }

    public function test_resolve_returns_empty_when_no_candidate_matches_any_scope(): void
    {
        $candidates = [
            $this->candidate('template', 'other.context', null, null, 0, 'unrelated'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_STACK,
            templateContextKey: 'site.shell',
            targetType: null,
            targetId: null,
        );

        $this->assertSame([], $resolved);
    }

    // -----------------------------------------------------------------
    // single/replace cardinality: target > template > global, at most one row.
    // -----------------------------------------------------------------

    public function test_single_cardinality_prefers_target_over_template_over_global(): void
    {
        $candidates = [
            $this->candidate('global', null, null, null, 0, 'global-row'),
            $this->candidate('template', 'site.shell', null, null, 0, 'template-row'),
            $this->candidate('target', null, 'page.static', '1', 0, 'target-row'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_SINGLE,
            templateContextKey: 'site.shell',
            targetType: 'page.static',
            targetId: '1',
        );

        $this->assertCount(1, $resolved);
        $this->assertSame('target-row', $resolved[0]->identity);
    }

    public function test_single_cardinality_falls_back_to_template_when_no_target_placement_exists(): void
    {
        $candidates = [
            $this->candidate('global', null, null, null, 0, 'global-row'),
            $this->candidate('template', 'site.shell', null, null, 0, 'template-row'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_SINGLE,
            templateContextKey: 'site.shell',
            targetType: 'page.static',
            targetId: '1',
        );

        $this->assertCount(1, $resolved);
        $this->assertSame('template-row', $resolved[0]->identity);
    }

    public function test_single_cardinality_falls_back_to_global_when_no_template_or_target_placement_exists(): void
    {
        $candidates = [
            $this->candidate('global', null, null, null, 0, 'global-row'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_SINGLE,
            templateContextKey: 'site.shell',
            targetType: 'page.static',
            targetId: '1',
        );

        $this->assertCount(1, $resolved);
        $this->assertSame('global-row', $resolved[0]->identity);
    }

    public function test_single_cardinality_returns_empty_when_nothing_matches(): void
    {
        $resolved = $this->resolver()->resolve(
            [],
            WidgetSlotDefinition::CARDINALITY_SINGLE,
            templateContextKey: 'site.shell',
            targetType: null,
            targetId: null,
        );

        $this->assertSame([], $resolved);
    }

    public function test_replace_cardinality_behaves_identically_to_single_precedence(): void
    {
        $candidates = [
            $this->candidate('global', null, null, null, 0, 'global-row'),
            $this->candidate('target', null, 'page.static', '1', 0, 'target-row'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_REPLACE,
            templateContextKey: null,
            targetType: 'page.static',
            targetId: '1',
        );

        $this->assertCount(1, $resolved);
        $this->assertSame('target-row', $resolved[0]->identity);
    }

    public function test_single_cardinality_picks_the_lowest_position_when_defensively_given_more_than_one_row_in_the_same_tier(): void
    {
        // Storage-time invariant (WidgetLayoutDraftService's own cardinality
        // check) means this never happens for a real persisted Draft, but
        // the resolver itself stays a defensive pure function.
        $candidates = [
            $this->candidate('global', null, null, null, 5, 'later'),
            $this->candidate('global', null, null, null, 1, 'earlier'),
        ];

        $resolved = $this->resolver()->resolve(
            $candidates,
            WidgetSlotDefinition::CARDINALITY_SINGLE,
            templateContextKey: null,
            targetType: null,
            targetId: null,
        );

        $this->assertCount(1, $resolved);
        $this->assertSame('earlier', $resolved[0]->identity);
    }
}
