<?php

declare(strict_types=1);

namespace Cms\Widget\Definition;

use App\Contracts\Widget\CanonicalWidgetSchemaResolver;
use Cms\Core\Models\WidgetTheme;

/**
 * Read-only projection of authorized legacy Widget Definition/Theme data
 * for {@see \Cms\Widget\Instances\WidgetInstanceService} (plan Chunk 2 Task
 * 16; architecture.md "The placement-selectable Definition catalog is a
 * read-only projection of authorized legacy Definition/Theme/Input data
 * through a gateway").
 *
 * Deliberately narrow: pairing validity and canonical schema resolution
 * delegate to the ALREADY-FROZEN root {@see CanonicalWidgetSchemaResolver}
 * contract (never re-implemented here); project applicability reads
 * Core's one rule {@see WidgetTheme::projectListAllows()} (this project or
 * Default, as pages). This class never mutates legacy rows -- create/
 * update/delete of Widget Definitions/Themes remains entirely Core's own
 * `WidgetController`/`WidgetThemeController` responsibility.
 */
final class WidgetDefinitionGateway
{
    public function __construct(private readonly CanonicalWidgetSchemaResolver $schemaResolver)
    {
    }

    /**
     * Whether `$themeId` is a real Theme belonging to the real Widget
     * `$widgetId` (both rows exist and the pairing is correct).
     */
    public function isValidPairing(int $widgetId, int $themeId): bool
    {
        return $this->schemaResolver->isValidPairing($widgetId, $themeId);
    }

    /**
     * Canonical input schema for this exact (widget, theme) pairing, keyed
     * by input name. Empty when the pairing is invalid.
     *
     * @return array<string, array{type: string}>
     */
    public function canonicalSchema(int $widgetId, int $themeId): array
    {
        return $this->schemaResolver->resolve($widgetId, $themeId);
    }

    /**
     * Whether the Theme can be used on `$projectName`: its `project_name`
     * list names that project or Default (no project counts as Default).
     * The same rule as pages and Core's design lists
     * ({@see WidgetTheme::projectListAllows}; 
     * shared areas accept Default designs like pages). False when the Theme
     * does not exist. Callers keep every write scoped to `$projectName`.
     */
    public function themeApplicableToProject(int $themeId, string $projectName): bool
    {
        $theme = WidgetTheme::query()->find($themeId);

        return $theme !== null && $theme->isUsableOnProject($projectName);
    }
}
