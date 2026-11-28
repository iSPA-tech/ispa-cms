<?php
/*
 * Copyright (c) 2026 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace App\Support\Widget;

/**
 * Request-scoped "which page is this" for Widget Site Layout slots.
 *
 * A page view that is a registered Target Context (e.g. a project's "page"
 * target, identified by the Post uuid) announces itself here before any
 * <x-widget-slot> renders. The slot component copies it into the
 * WidgetRenderContext so "this page only" (target-scoped) placements resolve
 * on the public site. Pages that never set it render global/template
 * placements only. Bound as `scoped` in AppServiceProvider, so it resets
 * between requests.
 *
 * Blade: @php(app(\App\Support\Widget\WidgetRenderTarget::class)->set('page', $post->uuid))
 * at the top of a child view (before @section) — child views run before
 * their @extends layout, so header/footer slots see it too.
 */
final class WidgetRenderTarget
{
    private ?string $targetType = null;

    private ?string $targetId = null;

    public function set(string $targetType, string $targetId): void
    {
        if ($targetType === '' || $targetId === '') {
            return;
        }

        $this->targetType = $targetType;
        $this->targetId = $targetId;
    }

    public function targetType(): ?string
    {
        return $this->targetType;
    }

    public function targetId(): ?string
    {
        return $this->targetId;
    }
}
