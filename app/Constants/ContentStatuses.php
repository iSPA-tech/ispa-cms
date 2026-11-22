<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Constants;

use ReflectionClass;

/**
 * @deprecated Use {@see \App\Enums\ContentStatusEnum} instead.
 *             All references have been migrated to the native PHP Enum class.
 *             This class will be removed in a future release.
 */
class ContentStatuses
{
    /** @deprecated Use ContentStatusEnum::CONTENT_DRAFT->value */
    public const CONTENT_DRAFT = 'content_draft';
    /** @deprecated Use ContentStatusEnum::CONTENT_REJECTED->value */
    public const CONTENT_REJECTED = 'content_rejected';
    /** @deprecated Use ContentStatusEnum::CONTENT_PENDING_TRANSLATION->value */
    public const CONTENT_PENDING_TRANSLATION = 'content_pending_translation';
    /** @deprecated Use ContentStatusEnum::CONTENT_ON_QUEUE_TRANSLATION->value */
    public const CONTENT_ON_QUEUE_TRANSLATION = 'content_on_queue_translation';
    /** @deprecated Use ContentStatusEnum::CONTENT_TRANSLATED->value */
    public const CONTENT_TRANSLATED = 'content_translated';
    /** @deprecated Use ContentStatusEnum::CONTENT_MODERATED->value */
    public const CONTENT_MODERATED = 'content_moderated';
    /** @deprecated Use ContentStatusEnum::CONTENT_PUBLISHED->value */
    public const CONTENT_PUBLISHED = 'content_published';

    /**
     * Get all constants defined in the class.
     *
     * @deprecated Use ContentStatusEnum::cases() or array_column(ContentStatusEnum::cases(), 'value') instead.
     * @return array
     */
    public static function getConstants(): array
    {
        $className = static::class;

        return (new ReflectionClass($className))->getConstants();
    }
}
