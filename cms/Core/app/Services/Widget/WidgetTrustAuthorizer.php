<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Services\Widget;

/**
 * Chunk 1 Task 9b trust boundary (FR-14, adr-widget-project-authorization.md
 * decision 9): a pure, stateless decision helper that tells a controller
 * whether a legacy Widget/Theme mutation touches an EXECUTABLE field, so
 * the controller can combine that verdict with its own
 * `user()?->can(self::WIDGET_TRUST_PERMISSION)` /
 * `->can(self::WIDGET_THEME_TRUST_PERMISSION)` check. This class never
 * reads the database or the authenticated user itself — every method
 * takes the already-persisted value(s) and the incoming payload as plain
 * arguments, which keeps it trivially unit-testable without HTTP, Auth,
 * or a real model.
 *
 * Executable fields (frozen contract): `widgets.js` (Widget JavaScript)
 * and `widget_themes.content` / `widget_themes.css` (Blade/HTML/CSS
 * rendered via Blade::render plus inline <style> injection). Every other
 * field — name, description, query, project_name, and the whole of
 * widget_inputs — is never executable and is never gated by this class.
 *
 * Transition semantics: a field is "touched" only when its key is present
 * in the incoming payload (a field absent from the request is untouched
 * and never gated, regardless of its persisted value). A touched field
 * requires trust when the transition ADDS (empty/absent -> non-empty),
 * CHANGES (non-empty -> a different non-empty value), or CLEARS
 * (non-empty -> empty/null) the executable value. A transition between
 * two empty values is a no-op and is never gated.
 *
 * Deletion semantics: DELETING executable content is gated too. Removing
 * a Theme row whose persisted content/css is non-empty requires
 * widget_theme.trust on every deletion path — standalone destroy, the
 * aggregate save's staged deletion arrays, and the legacy
 * whole-array-replace sweep. Deleting a Widget whose js is non-empty
 * requires widget.trust, and because widget deletion cascade-removes its
 * Theme rows (widget_themes.widget_id is cascadeOnDelete), it also
 * requires widget_theme.trust whenever any owned Theme carries non-empty
 * content/css. Rows whose executable fields are all empty delete freely.
 *
 * @package Cms\Core\Services\Widget
 * @author  iSPA Team
 * @since   8.0.0 (2026-07-17)
 */
final class WidgetTrustAuthorizer
{
    /** Core-owned permission guarding `widgets.js` mutation. */
    public const WIDGET_TRUST_PERMISSION = 'widget.trust';

    /** Core-owned permission guarding `widget_themes.content` / `.css` mutation. */
    public const WIDGET_THEME_TRUST_PERMISSION = 'widget_theme.trust';

    /**
     * True when the incoming $payload adds, changes, or clears the
     * widget's `js` field relative to its persisted value. False when
     * `js` is absent from $payload (untouched).
     *
     * @param array<string, mixed> $payload
     */
    public function widgetJsTouched(?string $persistedJs, array $payload): bool
    {
        if (!array_key_exists('js', $payload)) {
            return false;
        }

        return $this->isExecutableTransition($persistedJs, $payload['js']);
    }

    /**
     * True when deleting a widget whose persisted `js` is non-empty:
     * removing an executable field counts as touching it.
     */
    public function widgetDeletionTouchesJs(?string $persistedJs): bool
    {
        return !$this->isEmpty($persistedJs);
    }

    /**
     * True when a Theme row being ADDED (standalone store, or a
     * brand-new row in the aggregate-update payload with no persisted
     * counterpart) carries non-empty executable content or css.
     */
    public function newThemeRowTouchesExecutable(?string $content, ?string $css): bool
    {
        return $this->hasExecutableThemeContent($content, $css);
    }

    /**
     * True when deleting a Theme row whose persisted content or css is
     * non-empty: removing executable content counts as touching it.
     * Mirrors widgetDeletionTouchesJs() for the theme table; applies to
     * standalone destroy, staged deletion arrays, the legacy sweep, and
     * the widget-destroy cascade.
     */
    public function themeDeletionTouchesExecutable(?string $persistedContent, ?string $persistedCss): bool
    {
        return $this->hasExecutableThemeContent($persistedContent, $persistedCss);
    }

    /**
     * True when the incoming $payload adds, changes, or clears an
     * EXISTING theme's `content` or `css` relative to its persisted
     * values. Only keys actually present in $payload are considered, so
     * a request that never mentions `content`/`css` (e.g. renaming a
     * theme) is never gated.
     *
     * @param array<string, mixed> $payload
     */
    public function themeFieldsTouched(?string $persistedContent, ?string $persistedCss, array $payload): bool
    {
        if (array_key_exists('content', $payload)
            && $this->isExecutableTransition($persistedContent, $payload['content'])) {
            return true;
        }

        return array_key_exists('css', $payload)
            && $this->isExecutableTransition($persistedCss, $payload['css']);
    }

    /**
     * True when $before -> $after is an ADD, CHANGE, or CLEAR of an
     * executable value. Two empty values are always a no-op, regardless
     * of exact empty representation (null vs '').
     */
    private function isExecutableTransition(?string $before, mixed $after): bool
    {
        $afterString = $after === null ? null : (string) $after;

        if ($this->isEmpty($before) && $this->isEmpty($afterString)) {
            return false;
        }

        return $before !== $afterString;
    }

    private function hasExecutableThemeContent(?string $content, ?string $css): bool
    {
        return !$this->isEmpty($content) || !$this->isEmpty($css);
    }

    private function isEmpty(?string $value): bool
    {
        return $value === null || $value === '';
    }
}
