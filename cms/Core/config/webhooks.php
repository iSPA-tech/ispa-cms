<?php

/*
 * Shared webhook inbox (`Cms\Core\Services\Webhooks\WebhookInbox`).
 *
 * Every incoming webhook event is stored once (unique provider + event id), processed by its
 * handler, and can be replayed with `cms:webhook-replay`. `cms:webhook-prune` (daily) removes
 * old processed rows; failed rows are kept until replayed or removed by hand.
 */
return [
    // 'inline' (default): the handler runs inside the webhook request, as before the inbox existed.
    // 'queue': a ProcessWebhookReceiptJob runs the handler on the default queue with retries
    //          (5 tries, backoff 10s/60s/5m/15m). Only switch a project to 'queue' when a worker
    //          is running the default queue: a queue without a worker silently piles up, the
    //          webhook still answers 200 and the events are never handled (see the 2026-10-23
    //          Redis incident). Also needs a non-sync QUEUE_CONNECTION.
    'mode' => env('CORE_WEBHOOKS_MODE', 'inline'),

    // provider => class implementing Cms\Core\Contracts\WebhookHandler. Modules may also call
    // WebhookInbox::registerHandler() from their service provider instead.
    'handlers' => [],
];
