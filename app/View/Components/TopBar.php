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
use App\Repositories\Interfaces\CalendarInterface;

class TopBar extends Component
{
    public bool $template;

    protected bool $isCalendar;

    protected CalendarInterface $calendar;

    /**
     * Create a new component instance.
     *
     * @param bool $template
     * @param bool $isCalendar
     * @param CalendarInterface $calendar
     */
    public function __construct(CalendarInterface $calendar, bool $template = false, bool $isCalendar = false)
    {
        $this->template = $template;
        $this->isCalendar = $isCalendar;
        $this->calendar = $calendar;
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
        $data = [];

        if ($this->isCalendar) {
            $firstDate = now()->setTimezone(get_timezone())->firstOfMonth();
            $lastDate = now()->setTimezone(get_timezone())->addDays(30);

            $data['calendar'] = $this->calendar->filterByDateRange($firstDate, $lastDate, ['id', 'name', 'url', 'date_from']);
        }

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.top-bar', $data);
    }
}
