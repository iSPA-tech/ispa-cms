<?php

use Cms\Content\Interfaces\TagInterface;
use Cms\Content\Interfaces\PostInterface;
use Cms\Content\Interfaces\ContentInterface;
use Cms\Content\Interfaces\BookmarkInterface;
use Cms\Content\Repositories\TagRepository;
use Cms\Content\Repositories\PostRepository;
use Cms\Content\Repositories\ContentRepository;
use Cms\Content\Repositories\BookmarkRepository;

return [
    'name' => 'Content',
    'bindings' => [
        BookmarkInterface::class => BookmarkRepository::class,
        ContentInterface::class => ContentRepository::class,
        PostInterface::class => PostRepository::class,
        TagInterface::class => TagRepository::class,
    ],

    // Operator-facing labels of the AI tasks owned by this module (task_key => label).
    'ai_runtime_tasks' => [
        'content_post_translate_title_desc' => 'Dịch tiêu đề và mô tả bài viết',
        'content_agent_answer' => 'Trả lời câu hỏi từ bài viết đã duyệt',
    ],
    // Tasks whose prompt holds a user's question: the AI call log keeps no prompt hash for them.
    'ai_runtime_private_prompt_tasks' => ['content_agent_answer'],
];
