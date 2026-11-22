<?php

namespace Cms\Content\View\Components;

use Closure;
use Illuminate\View\Component;
use Illuminate\Contracts\View\View;
use Cms\Content\Interfaces\PostInterface;

class LatestDocument extends Component
{
    /**
     * Create a new component instance.
     */
    public function __construct(
        protected PostInterface $post,
        public bool             $template = false,
        public int              $limit = 5,
        public int              $theme_id = 0,
    )
    {
        //
    }

    /**
     * Get the view / contents that represent the component.
     */
    public function render(): View|Closure|string
    {
        $documents = $this->post->getLatestPostByType('document', $this->limit, getenv('CACHE_ENABLE'));

        return view(($this->template ? config('ispa-cms.folder') : '') . 'components.latest-documents-' . $this->theme_id, [
            'documents' => $documents,
        ]);
    }
}
