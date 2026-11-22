<?php

declare(strict_types=1);

namespace Cms\Widget\Tests\Fakes;

use App\Contracts\Widget\DTO\AuthorizedProjectContext;
use App\Contracts\Widget\DTO\WidgetResolvedTarget;
use App\Contracts\Widget\DTO\WidgetTargetPage;
use App\Contracts\Widget\DTO\WidgetTargetSearchQuery;
use App\Contracts\Widget\DTO\WidgetTargetSummary;
use App\Contracts\Widget\WidgetTargetContextResolver;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * In-memory WidgetTargetContextResolver test double (Chunk 1 Task 12b;
 * the site-layout pilot map in docs/evidence).
 *
 * Every row is held in one PHP array for the lifetime of a single test
 * process -- no database, no filesystem. This is the CONTRACT fake the
 * plan asks for: it exists to prove the shape and edge behavior of
 * {@see WidgetTargetContextResolver} before Task 23 writes the real
 * project-owned `PageWidgetTargetContextResolver` adapter for ExampleProject. It is
 * NOT that adapter and must never be registered against a live
 * WidgetTargetContextRegistrar.
 *
 * Behavior this fake exists to prove (see WidgetPilotAuthorizationContractTest):
 * - `targetId` is opaque and stored/compared/returned as a PHP string
 *   end-to-end -- never cast to/from int, including deliberately
 *   numeric-looking ids like "123" (D-046).
 * - `search()` respects the already-capped page/perPage carried by
 *   {@see WidgetTargetSearchQuery} (the DTO itself clamps perPage to
 *   WidgetTargetSearchQuery::MAX_PER_PAGE), filters by target type AND the
 *   caller's authorized project, and never returns an unreadable row.
 * - `resolve()` distinguishes exactly two failure states:
 *     - absent-in-authorized-scope (unknown id, OR an id that exists but
 *       under a DIFFERENT project) -> Illuminate\Database\Eloquent\ModelNotFoundException
 *       (maps to 404). A cross-project id is deliberately indistinguishable
 *       from an unknown id so a caller can never learn that a target
 *       exists in someone else's project.
 *     - denied/unreadable (row exists in the caller's own project but is
 *       flagged unreadable) -> Illuminate\Auth\Access\AuthorizationException
 *       (maps to 403), with a message that carries no target metadata
 *       (label/route/params never appear in the exception message).
 *   Both choices reuse exception types already established elsewhere in
 *   this module ({@see \Cms\Widget\Security\WidgetProjectContextResolver::assertProject()}
 *   for AuthorizationException; the codebase's UUID-first
 *   `Model::query()->where('uuid', $uuid)->firstOrFail()` idiom for
 *   ModelNotFoundException) rather than inventing a bespoke Widget-only
 *   exception class outside this task's allowed_paths.
 */
final class FakeWidgetTargetContextResolver implements WidgetTargetContextResolver
{
    /**
     * @var array<string, array{
     *     targetId: string,
     *     targetType: string,
     *     projectName: string,
     *     label: string,
     *     routeName: string,
     *     routeParameters: array<string, mixed>,
     *     readable: bool,
     * }>
     */
    private array $targets = [];

    /**
     * Registers one fixture target row, keyed by its opaque string id.
     * Fluent: returns $this so a test can chain several calls while
     * building a fixture.
     *
     * @param array<string, mixed> $routeParameters
     */
    public function withTarget(
        string $targetId,
        string $targetType,
        string $projectName,
        string $label,
        string $routeName,
        array $routeParameters = [],
        bool $readable = true,
    ): self {
        $this->targets[$targetId] = [
            'targetId' => $targetId,
            'targetType' => $targetType,
            'projectName' => $projectName,
            'label' => $label,
            'routeName' => $routeName,
            'routeParameters' => $routeParameters,
            'readable' => $readable,
        ];

        return $this;
    }

    public function search(AuthorizedProjectContext $context, WidgetTargetSearchQuery $query): WidgetTargetPage
    {
        $matches = array_values(array_filter(
            $this->targets,
            static function (array $row) use ($context, $query): bool {
                if ($row['targetType'] !== $query->targetType) {
                    return false;
                }
                if ($row['projectName'] !== $context->projectName) {
                    return false;
                }
                if ($row['readable'] !== true) {
                    // Search is a catalog/picker surface: an unreadable row
                    // is never shown, not even as a disabled entry -- that
                    // would itself leak its existence/label.
                    return false;
                }
                if ($query->keywords !== '' && !str_contains(
                    mb_strtolower($row['label']),
                    mb_strtolower($query->keywords)
                )) {
                    return false;
                }

                return true;
            }
        ));

        $total = count($matches);
        // $query->page/$query->perPage are already clamped by the DTO's own
        // constructor (page >= 1, 1 <= perPage <= MAX_PER_PAGE); this fake
        // trusts that invariant rather than re-clamping it a second time.
        $offset = ($query->page - 1) * $query->perPage;
        $slice = array_slice($matches, $offset, $query->perPage);

        $items = array_map(
            static fn (array $row): WidgetTargetSummary => new WidgetTargetSummary(
                targetId: $row['targetId'],
                label: $row['label'],
                routeName: $row['routeName'],
                routeParameters: $row['routeParameters'],
            ),
            $slice,
        );

        return new WidgetTargetPage(
            items: $items,
            page: $query->page,
            perPage: $query->perPage,
            total: $total,
        );
    }

    public function resolve(AuthorizedProjectContext $context, string $targetId): WidgetResolvedTarget
    {
        // PHP int-casts canonical-integer string array keys ('123' becomes
        // key 123), so this lookup works only because both writer and reader
        // cast identically. The D-046 opaque-string guarantee therefore does
        // NOT come from the array key: it comes from the row VALUE
        // ($row['targetId'], stored as string at seed time), which is what
        // reaches WidgetResolvedTarget. Never treat an array key as the
        // authoritative target identity in a real adapter.
        $row = $this->targets[$targetId] ?? null;

        if ($row === null || $row['projectName'] !== $context->projectName) {
            throw new ModelNotFoundException(
                "Widget target '{$targetId}' was not found in the authorized project scope."
            );
        }

        if ($row['readable'] !== true) {
            throw new AuthorizationException(
                'Widget target is not readable in the authorized project scope.'
            );
        }

        return new WidgetResolvedTarget(
            targetId: $row['targetId'],
            targetType: $row['targetType'],
            label: $row['label'],
            routeName: $row['routeName'],
            routeParameters: $row['routeParameters'],
        );
    }

    /**
     * Test-only introspection helper (not part of the production
     * interface): total fixture row count, used to prove search()/resolve()
     * never mutate fixture state.
     */
    public function fixtureCount(): int
    {
        return count($this->targets);
    }
}
