<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Repositories\Eloquent;

use Cms\Core\Repositories\Eloquent\RepositoriesAbstract;
use App\Models\Comment;
use App\Constants\Constant;
use App\Enums\StatusEnum;
use Cms\Core\Helpers\CacheHelper;
use App\Repositories\Interfaces\CommentInterface;

class CommentRepository extends RepositoriesAbstract implements CommentInterface
{
    public function __construct()
    {
        $this->setModel(Comment::class);
    }

    /**
     * Get root comment by target_id and target_type
     *
     * @author malayvuong
     * @since 7.160.1175 - 2023-10-29, 22:44 ICT
     */
    public function getCommentList(string $targetType, int $targetId, int $replyTo = 0, bool $freshCache = false): mixed
    {
        $ttl = $freshCache ? 1 : config('setting.cache.comment', 3600 * 24);
        $cacheKey = sprintf(Constant::CACHE_COMMENTS[Constant::COMMENT_BY_TARGET], $targetId, $targetType, $replyTo);

        return cache()->remember($cacheKey, $ttl, function () use ($targetType, $targetId, $replyTo, $cacheKey, $ttl) {
            CacheHelper::getInstance()->store($cacheKey, 'Comment ' . $targetType . ' ' . $targetId . ' ' . $replyTo, $ttl);

            $comments = $this->get([
                'where' => [
                    'target_type' => $targetType,
                    'target_id' => $targetId,
                    'reply_to' => $replyTo,
                    'status' => StatusEnum::PUBLISHED->value,
                ],
                'with' => [
                    'user' => function ($query) {
                        $query->select('id', 'name', 'avatar');
                    },
                    'children' => function ($query) {
                        $query->where('status', true)->orderBy('created_at', 'desc');
                    },
                ],
                'orderBy' => [
                    'created_at' => 'desc',
                ],

            ]);

            return $comments;
        });
    }
}
