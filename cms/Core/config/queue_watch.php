<?php

/*
 * Queue watch (`cms:queue-watch`, scheduled every 5 minutes in app/Console/Kernel.php).
 *
 * Alerts through CMSLogger (Telegram) when a Redis queue this app dispatches to backs up
 * or keeps growing, or when failed_jobs fills up within an hour. Nothing is read when the
 * default queue connection uses the `sync` (or `null`) driver.
 */
return [
    'enabled' => (bool) env('QUEUE_WATCH_ENABLED', true),

    // Queues this app dispatches to, comma separated: "name" or "name:limit" for a per-queue
    // length limit. The default connection's own queue (REDIS_QUEUE) is always added.
    'queues' => env(
        'QUEUE_WATCH_QUEUES',
        'default,tracking,emails,mail-high,mail-normal,mail-low,mail-webhooks,mail-inbound'
    ),

    // Alert when a queue holds more jobs than this (ready + delayed + reserved).
    'max_length' => (int) env('QUEUE_WATCH_MAX_LENGTH', 10000),

    // Alert when a queue grew at each of the last N checks and holds at least growth_min_length jobs.
    'growth_checks' => (int) env('QUEUE_WATCH_GROWTH_CHECKS', 6),
    'growth_min_length' => (int) env('QUEUE_WATCH_GROWTH_MIN_LENGTH', 100),

    // Alert when more than this many jobs failed within one hour (0 turns the check off).
    'failed_jobs_per_hour' => (int) env('QUEUE_WATCH_FAILED_JOBS_PER_HOUR', 100),

    // Alert when the queue connection's Redis uses this share of its maxmemory (0 turns the check
    // off), and again at the critical share. The alert names the biggest keys from a SCAN sample
    // of at most redis_scan_limit keys. Apps sharing one Redis send one alert per level between them.
    'redis_memory_percent' => (int) env('QUEUE_WATCH_REDIS_MEMORY_PERCENT', 80),
    'redis_memory_critical_percent' => (int) env('QUEUE_WATCH_REDIS_MEMORY_CRITICAL_PERCENT', 95),
    'redis_scan_limit' => (int) env('QUEUE_WATCH_REDIS_SCAN_LIMIT', 5000),

    // Seconds before the same queue (or failed_jobs, or Redis memory level) may alert again.
    'alert_ttl' => (int) env('QUEUE_WATCH_ALERT_TTL', 3600),

    // Cache store for size history and alert dedupe; null uses the default store.
    'cache_store' => env('QUEUE_WATCH_CACHE_STORE'),
];
