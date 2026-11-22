<?php

declare(strict_types=1);

/*
 * Content answer pilot corpus (merged as `content.agent_answers`).
 *
 * `approved_posts` maps a Post UUID to the approved projection `content_digest` (lowercase SHA-256 hex).
 * Empty or malformed configuration means an empty corpus; any invalid pair disables the whole map at runtime.
 */

$approved = json_decode((string) env('CONTENT_AGENT_ANSWER_APPROVED_POSTS', '{}'), true);

return [
    'approved_posts' => is_array($approved) ? $approved : [],
    'max_documents' => (int) env('CONTENT_AGENT_ANSWER_MAX_DOCUMENTS', 5),
    'max_section_chars' => (int) env('CONTENT_AGENT_ANSWER_MAX_SECTION_CHARS', 8000),
    'max_document_chars' => (int) env('CONTENT_AGENT_ANSWER_MAX_DOCUMENT_CHARS', 30000),
    'max_corpus_chars' => (int) env('CONTENT_AGENT_ANSWER_MAX_CORPUS_CHARS', 60000),
    'citation_excerpt_chars' => (int) env('CONTENT_AGENT_ANSWER_EXCERPT_CHARS', 320),
];
