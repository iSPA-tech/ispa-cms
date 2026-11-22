<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use App\Repositories\Interfaces\GalleryInterface;

class RelatedGallery extends Component
{
    /** @var bool $template */
    public bool $template;

    /** @var GalleryInterface $gallery */
    protected GalleryInterface $gallery;

    protected int $exceptId;

    /**
     * Create a new component instance.
     *
     * @param GalleryInterface $gallery
     * @param bool $template
     * @param int $exceptId
     */
    public function __construct(GalleryInterface $gallery, bool $template = false, int $exceptId = 0)
    {
        $this->gallery = $gallery;
        $this->template = $template;
        $this->exceptId = $exceptId;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $galleries = $this->gallery->getRelatedGalleries($this->exceptId);

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.related-gallery', [
            'galleries' => $galleries,
        ]);
    }
}
