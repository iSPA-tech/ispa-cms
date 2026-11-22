<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\View\Components;

use Closure;
use App\Enums\StatusEnum;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Core\Interfaces\FieldInterface;
use App\Repositories\Interfaces\CommentInterface;

class CommentForm extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected CommentInterface $comment,
        protected FieldInterface   $field,
        public ?string             $type = null,
        public int                 $id = 0,
        public bool                $template = false,
        public string              $formId = 'comment',
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $fields = $this->field->getFieldByFormId($this->formId);
        $cacheKey = make_key([
            'target_id' => $this->id,
            'target_type' => $this->type,
            'reply_to' => null,
            'status' => StatusEnum::PUBLISHED->value,
        ]);
        //  Get all comment
        $comments = cache()->remember(
            $cacheKey,
            config('setting.cache.place', 3600),
            function () use ($cacheKey) {

                return $this->comment->get([
                    'where' => [
                        'target_id' => $this->id,
                        'target_type' => $this->type,
                        'reply_to' => null,
                        'status' => StatusEnum::PUBLISHED->value,
                    ],
                    'latest' => true,
                    'select' => [
                        'id', 'vote', 'target_id', 'target_type', 'name', 'subject', 'contact_id', 'content', 'created_at',
                    ],
                    'with' => [
                        'contact:id,name,email',
                        'images',
                    ],
                    'page' => request('limit', 4),
                ]);
            }
        );

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.comment-form', [
            'fields' => $fields,
            'comments' => $comments,
        ]);
    }
}
