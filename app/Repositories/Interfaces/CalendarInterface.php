<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Interfaces;

use Cms\Core\Repositories\Interfaces\RepositoryInterface as BaseRepositoryInterface;

use DateTimeInterface;
use Illuminate\Database\Eloquent\Collection;

interface CalendarInterface extends BaseRepositoryInterface
{
    /**
     * Get calendar by month and year
     *
     * @author malayvuong
     * @since 6.62.6 - 2022-08-15, 01:23 ICT
     */
    public function getCalendar(): Collection|array;

    /**
     * Get calendar filter by date Range
     *
     * @param DateTimeInterface $from
     * @param DateTimeInterface $to
     * @param array $select
     * @param string $status
     *
     * @return array|Collection
     * @author malayvuong
     * @since 6.19.6 - 2022-08-02, 12:16 ICT
     */
    public function filterByDateRange(DateTimeInterface $from, DateTimeInterface $to, array $select = [], string $status = 'status_published'): array|Collection;

    /**
     * Get calendar filter by $date (admin use)
     *
     * @param DateTimeInterface $date
     * @param array $select
     *
     * @return array|Collection
     * @author malayvuong
     * @since 6.19.6 - 2022-08-01, 12:28 ICT
     */
    public function filterByDate(DateTimeInterface $date, array $select = []): array|Collection;
}
