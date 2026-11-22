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
use Illuminate\Support\Facades\DB;
use Illuminate\Contracts\View\View;
use Cms\Core\Interfaces\FieldInterface;
use App\Repositories\Interfaces\CommentInterface;

class CommentRating extends Component
{
    /**
     * Create a new component instance.
     *
     * @param CommentInterface $comment
     * @param FieldInterface $field
     * @param string|null $type
     * @param int $id
     * @param bool $template
     * @param bool $showRate
     */
    public function __construct(
        protected CommentInterface $comment,
        protected FieldInterface   $field,
        public ?string             $type = null,
        public int                 $id = 0,
        public bool                $template = false,
        public bool                $showRate = true,
    )
    {
    }

    /**
     * Get the view / contents that represent the component.
     *
     * @return View|Closure|string
     */
    public function render(): View|string|Closure
    {
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

        $rates = $this->comment->model()->select('vote', DB::raw('count(id) as count'))->groupBy('vote')->where([
            ['vote', '>', 0],
            'target_id' => $this->id,
            'target_type' => $this->type,
            'status' => StatusEnum::PUBLISHED->value,
        ])->get();

        $review = collect([
            'averageRating' => 0, 'totalRating' => 0,
            'total5Star' => 0, 'total4Star' => 0, 'total43tar' => 0,
            'total2Star' => 0, 'total1Star' => 0,
        ]);

        $totalRate = 0;
        foreach ($rates as $vote) {
            $review->put('totalRating', $review->get('totalRating') + $vote->count);
            $review->put('total' . $vote->vote . 'Star', $vote->count);
            $totalRate += ($vote->vote * $vote->count);
        }
        if ($review->get('totalRating') > 0) {
            $review->put('averageRating', number_format($totalRate / $review->get('totalRating'), 1));
        }

        //  Get field of comment form
        $fields = cache()->remember(make_key(['type' => 'form', 'form_id' => 'comment']), config('setting.cache.form', 3600), function () {
            //  Todo: change this query here fill about expires_from and expires_to
            return $this->field->findBy(['form_id' => 'comment'], ['id', 'form_id', 'custom', 'styles'], [
                'items',
            ]);
        });

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.comment-rating', [
            'comments' => $comments,
            'review' => $review,
            'fields' => $fields,
        ]);
    }
}
