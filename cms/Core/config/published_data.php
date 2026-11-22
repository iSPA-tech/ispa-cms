<?php

return [
    'place_embeddings' => [
        'enabled' => (bool) env('CORE_PUBLISHED_DATA_PLACE_EMBEDDINGS_ENABLED', false),
        'model' => env('CORE_PUBLISHED_DATA_PLACE_EMBEDDINGS_MODEL', 'text-embedding-3-small'),
        'model_version' => env('CORE_PUBLISHED_DATA_PLACE_EMBEDDINGS_MODEL_VERSION', 'default'),
        'dimensions' => (int) env('CORE_PUBLISHED_DATA_EMBEDDING_DIMENSIONS', 1536),
        'scan_limit' => (int) env('CORE_PUBLISHED_DATA_PLACE_EMBEDDINGS_SCAN_LIMIT', 500),
        'max_text_chars' => (int) env('CORE_PUBLISHED_DATA_PLACE_EMBEDDINGS_MAX_TEXT_CHARS', 2000),
    ],
];
