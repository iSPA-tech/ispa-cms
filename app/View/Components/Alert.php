<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Illuminate\Contracts\View\View;
use Illuminate\View\Component;

class Alert extends Component
{
    /** @var string $type */
    public string $type;

    /** @var string|null $xId */
    public ?string $xId;

    /** @var string|null $icon */
    public ?string $icon;

    /** @var string|null $message */
    public ?string $message;

    /** @var string|null $title */
    public ?string $title;

    /** @var string|null $xShow */
    public ?string $xShow;

    /** @var bool $noJs */
    public bool $noJs;

    /**
     * Create a new component instance.
     *
     * @param string $type
     * @param string|null $xShow
     * @param string|null $title
     * @param string|null $message
     * @param string|null $icon
     * @param string|null $xId
     * @param bool $noJs
     */
    public function __construct(
    string $type = 'default', string $xShow = null,
    string $title = null, string $message = null,
    string $icon = null, string $xId = null, bool $noJs = false,
  ) {
        $this->type = $type;
        $this->xShow = $xShow;
        $this->title = $title;
        $this->message = $message;
        $this->icon = $icon;
        $this->xId = $xId;
        $this->noJs = $noJs;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|string
     */
    public function render(): View|string
    {
        return view('components.alert');
    }
}
