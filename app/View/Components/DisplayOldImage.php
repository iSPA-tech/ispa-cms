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
use Cms\Core\Interfaces\UploadInterface;

class DisplayOldImage extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected UploadInterface $upload,
        public array|object       $image,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        if (isset($this->image['url']) && str($this->image['url'])->contains('/img/')) {
            $image = $this->upload->find($this->image['id']);
            if ($image) {
                $this->image = $image->toArray();
            }

        }

        return view('components.display-old-image');
    }
}
