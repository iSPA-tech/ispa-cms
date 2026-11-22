<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Repositories;

use App\Constants\Constant;
use Cms\Core\Helpers\CacheHelper;
use Cms\Core\Models\AuditLog;
use Cms\Core\Interfaces\AuditLogInterface;
use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;

/**
 * Class AuditLogRepository
 * Eloquent repository for the AuditLog model with cached helpers.
 *
 * @extends RepositoriesAbstract
 * @implements AuditLogInterface
 * @see AuditLog
 *
 * @method \Illuminate\Database\Eloquent\Model|\Illuminate\Database\Eloquent\Collection|\Illuminate\Database\Eloquent\Builder|array|null find(int $id, array $select = ['*'], array $with = [])
 * @method mixed get(array $params)
 * @package Cms\Core\Repositories
 */
class AuditLogRepository extends RepositoriesAbstract implements AuditLogInterface
{
    /**
     * Initialize the repository and bind the AuditLog model.
     *
     * @author  iSPA Team
     * @since   8.0.0 (2026-03-17)
     */
    public function __construct()
    {
        $this->setModel(AuditLog::class);
    }

    /**
     * Get audit log by userId
     *
     * @param int $userId
     * @param int $page
     * @param int $pageSize
     * @param bool $freshCache
     *
     * @return mixed
     * @author malayvuong
     * @since 7.160.1175 - 2023-11-24, 21:24 ICT
     */
    public function getAuditLogListByUserId(int $userId, int $page, int $pageSize, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? config('setting.cache.cache.audit_log', 3600) : 1;

        $cacheKey = sprintf(Constant::CACHE_BY_AUDIT_LOG_USER_ID, $userId, $page, $pageSize);

        return cache()->remember($cacheKey, $ttl, function () use ($cacheKey, $ttl, $userId, $pageSize, $page) {
            CacheHelper::getInstance()->store($cacheKey, 'Audit log list by user: ' . $userId . ' and page: ' . $page . ' and pageSize: ' . $pageSize, $ttl);

            return $this->get([
                'where' => [
                    'user_id' => $userId,
                ],
                'when' => [
                    [
                        'target_id > 0',
                        fn($q) => $q->with('target'),
                    ],
                ],
                'latest' => true,
                'page' => $pageSize,
            ]);
        });
    }
}
