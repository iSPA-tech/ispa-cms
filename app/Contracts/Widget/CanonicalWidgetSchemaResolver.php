<?php

declare(strict_types=1);

namespace App\Contracts\Widget;

/**
 * Root secure-schema contract. The bound default (App\Support\Widget\
 * CanonicalWidgetSchema) loads canonical Core-owned Widget/WidgetInput
 * relations and rejects copied-schema drift (a stale `inputs` array copied
 * into a Page/Post block's JSON is advisory only, never authoritative).
 * cms/Widget's WidgetRenderService reuses this default unchanged (it does
 * NOT rebind it) — the same secure validation applies whether the module
 * is enabled or disabled.
 */
interface CanonicalWidgetSchemaResolver
{
    /**
     * Canonical, allowlisted input definitions for a Widget/Theme pair,
     * loaded fresh from Core-owned relations. Returns an empty array when
     * the pairing is invalid (missing Widget/Theme, or the Theme does not
     * belong to the Widget) — this is the sole "reject" signal, and it
     * never throws or leaks lookup detail to the caller.
     *
     * @return array<string, array{type: string}> keyed by canonical input name
     */
    public function resolve(int $widgetId, int $themeId): array;

    /**
     * Whether the Widget/Theme pairing is canonically valid: both rows
     * exist and the Theme belongs to the Widget.
     */
    public function isValidPairing(int $widgetId, int $themeId): bool;
}
