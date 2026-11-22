<?php

use App\Constants\Tables;

return [
    /*
    |--------------------------------------------------------------------------
    | Runtime safety
    |--------------------------------------------------------------------------
    |
    | The database singleton in tracking_runtime_state is the sole authority
    | for write mode and generation. These values only bootstrap a new row or
    | disable admission during an incident; they never reinterpret receipts.
    |
    */
    'enabled' => env('TRACKING_ENABLED', true),
    'bootstrap_mode' => 'legacy',
    'bootstrap_processing_state' => 'open',
    'read_mode' => env('TRACKING_READ_MODE', 'raw'),
    'aggregate_contract_version' => 1,
    'raw_listing_retention_days' => 30,

    'analytics_timezone' => 'Asia/Ho_Chi_Minh',
    'canonical_target_daily_table' => Tables::TRACKING_TARGET_DAILY,

    /*
    |--------------------------------------------------------------------------
    | Public ingest limits
    |--------------------------------------------------------------------------
    |
    | Applied by the 'tracking-ingest' rate limiter. The per-visitor tier is the
    | real abuse control; the per-address tier is a backstop and is deliberately
    | loose, because one address can legitimately be a whole office, a mobile
    | carrier, or the server side rendering frontend.
    |
    */
    'ingest' => [
        'per_visitor_per_minute' => env('TRACKING_INGEST_PER_VISITOR', 30),
        'per_address_per_minute' => env('TRACKING_INGEST_PER_ADDRESS', 600),
    ],

    'envelope_version' => 1,
    'throttle_contract_version' => 1,
    'throttle_seconds' => 30,
    'daily_dedupe_seconds' => 172800,
    'max_counter_delta' => 1,
    'manual_replay_days' => 7,
    'receipt_safety_hours' => 24,
    'pending_dispatch' => [
        'batch' => 500,
        'backoff_seconds' => 60,
        // Re-enqueue backoff doubles per attempt up to this cap, so a stopped worker cannot pile up duplicates.
        'max_backoff_seconds' => 3600,
        // Lifetime of the per-receipt unique queue lock (ProcessTrackingEvent). null = the replay window
        // (tracking.manual_replay_days), which keeps exactly one job queued per receipt even when no worker runs
        // for days. Never below max_backoff_seconds + 300. After purging the tracking queue by hand, delete the
        // `laravel_unique_job:*` lock keys too, or the receipts wait until their lock expires.
        'unique_for_seconds' => null,
        'max_runtime_seconds' => 50,
        'lock' => 'tracking:pending-dispatch',
    ],
    'buffer' => [
        'enabled' => env('TRACKING_REDIS_COUNTER_ENABLED', false),
        'connection' => env('TRACKING_REDIS_COUNTER_CONNECTION', 'tracking'),
        'prefix' => env('TRACKING_REDIS_COUNTER_PREFIX', 'tracking:buffer'),
        'require_aof' => env('TRACKING_REDIS_COUNTER_REQUIRE_AOF', true),
        'retention_seconds' => 259200,
        'seal_after_minutes' => 70,
        'flush_lock_seconds' => 300,
    ],
    'finalizer' => [
        'lock' => 'tracking:finalizer',
        'max_days' => 31,
    ],

    'generation_rotation_enabled' => env('TRACKING_GENERATION_ROTATION_ENABLED', false),
    'mode_transition_enabled' => env('TRACKING_MODE_TRANSITION_ENABLED', false),
    'generation_drain_verification_seconds' => 60,
    'generation_manifest_disk' => 'local',
    'generation_manifest_path' => 'tracking/manifests',
    'prune_enabled' => env('TRACKING_PRUNE_ENABLED', false),
    'prune' => [
        'minimum_retention_days' => 365,
        'freeze_max_id' => env('TRACKING_PRUNE_FREEZE_MAX_ID'),
        'freeze_manifest_checksum' => env('TRACKING_PRUNE_FREEZE_MANIFEST_CHECKSUM'),
        'worker_contract_version' => env('TRACKING_PRUNE_WORKER_CONTRACT_VERSION'),
        'legacy_jobs_disabled' => env('TRACKING_PRUNE_LEGACY_JOBS_DISABLED', false),
        'aggregate_consumers_only' => env('TRACKING_PRUNE_AGGREGATE_CONSUMERS_ONLY', false),
        'raw_caches_invalidated' => env('TRACKING_PRUNE_RAW_CACHES_INVALIDATED', false),
        'backup_restore_proof' => env('TRACKING_PRUNE_BACKUP_RESTORE_PROOF'),
        'reconciliation_hash' => env('TRACKING_PRUNE_RECONCILIATION_HASH'),
        'pause_milliseconds' => 250,
    ],
    'receipt_prune_enabled' => false,

    /*
    |--------------------------------------------------------------------------
    | Stable target identity
    |--------------------------------------------------------------------------
    */
    'target_aliases' => [
        'App\\Models\\Category' => 'category',
        'App\\Models\\Gallery' => 'gallery',
        'App\\Models\\Upload' => 'upload',
        'Cms\\Content\\Models\\Post' => 'post',
        'Cms\\Core\\Models\\Category' => 'category',
        'Cms\\Core\\Models\\Upload' => 'upload',
        'Cms\\ClassOnline\\Models\\ClassOnlineChapter' => 'class-online-chapter',
        'Cms\\ClassOnline\\Models\\ClassOnlineCourse' => 'class-online-course',
        'Cms\\DigitalLibrary\\Models\\DigitalLibraryBook' => 'digital-library-book',
        'Cms\\Helpdesk\\Models\\HelpdeskArticle' => 'helpdesk-article',
        'Cms\\Helpdesk\\Models\\HelpdeskCategory' => 'helpdesk-category',
        'Cms\\Helpdesk\\Models\\HelpdeskProduct' => 'helpdesk-product',
        'Cms\\RealEstate\\Models\\RealEstateInvestor' => 'real-estate-investor',
        'Cms\\RealEstate\\Models\\RealEstateProject' => 'real-estate-project',
        'Cms\\RealEstate\\Models\\RealEstateProperty' => 'real-estate-property',
        'Cms\\GeoPlaces\\Models\\Place' => 'place',
        'Cms\\GeoPlaces\\Models\\Item' => 'item',
        'Cms\\Product\\Models\\Product' => 'product',
        'Cms\\Product\\Models\\ProductVariant' => 'product-variant',
    ],

    //  Aliases whose public payloads expose only a uuid. The ingest endpoint
    //  accepts a uuid for these and resolves it to the integer key itself; the
    //  pipeline always stores integer target ids.
    //  Modules add their own aliases from their provider's boot().
    'uuid_addressable_aliases' => [],
    /*
    |--------------------------------------------------------------------------
    | Relayed page views (headless frontend)
    |--------------------------------------------------------------------------
    |
    | frontend_base_url anchors the reader facing url that the ingest endpoint
    | rebuilds from a beacon's path. Its host joins public_hosts so the target
    | resolver accepts it; extra hosts (www, dev) come from the env list.
    |
    */
    'frontend_base_url' => env('TRACKING_FRONTEND_BASE_URL', ''),

    'public_hosts' => array_values(array_filter(array_unique([
        parse_url((string) config('app.url'), PHP_URL_HOST),
        parse_url((string) env('TRACKING_FRONTEND_BASE_URL', ''), PHP_URL_HOST),
        ...array_map('trim', explode(',', (string) env('TRACKING_FRONTEND_EXTRA_HOSTS', ''))),
    ]))),
    //  Parameterised routes that are tracked by name: only the listed identity parameters enter the key, and a
    //  request carrying any other route parameter is rejected, so no free-form value can mint targets.
    //  Modules add their own routes from their provider (e.g. the help centre home, one target per language).
    'dynamic_route_resolvers' => [],

    //  Allowlist for path targets. Every pattern is a bounded, real frontend
    //  route shape: a hash target is minted per unique path, so an open pattern
    //  would let crawler junk mint unlimited catalog rows.
    //  Generic content pages only; a project with its own frontend routes adds its patterns from its provider.
    'public_path_patterns' => [
        '#^/$#',
        //  Article detail and category listings, not just the index: the index-only
        //  pattern silently rejected every article page view, which is most of the site's
        //  search traffic. Depth is capped so crawler junk cannot mint hash targets.
        '#^/bai-viet(?:/[a-z0-9\-]+){0,2}$#',
        '#^/tag(?:/[a-z0-9\-]+)?$#',
        '#^/contact$#',
        '#^/about/[a-z0-9\-]+$#',
        '#^/terms/[a-z0-9\-]+$#',
        '#^/account-deletion(?:/status)?$#',
    ],
    'excluded_route_prefixes' => [
        'admin',
        'api.admin',
        'auth',
        'login',
        'logout',
        'password',
        'preview',
        'signed',
        'user',
    ],
    'excluded_path_prefixes' => [
        '/admin',
        '/api/admin',
        '/login',
        '/logout',
        '/password',
        '/preview',
        '/user',
    ],
];
