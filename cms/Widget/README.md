# Widget module

Widget module for iSPA CMS: widget slots, instances, versions, layouts, site-layout runtime and signed preview sessions (`Cms\Widget`).

## Running the tests

From the `ispa-cms` root, run files or directories, never the whole suite:

```bash
php -d memory_limit=256M vendor/bin/pest cms/Widget/tests/Unit
php -d memory_limit=256M vendor/bin/pest cms/Widget/tests/Feature/Preview/WidgetPreviewSessionTest.php
```

## Redis-backed tests

These test files need an ephemeral Redis on `127.0.0.1:6399`:

- `tests/Feature/Preview/WidgetPreviewCanonicalRouteTest.php`
- `tests/Feature/Preview/WidgetPreviewInfrastructureContractTest.php`
- `tests/Feature/Preview/WidgetPreviewMiddlewareOrderTest.php`
- `tests/Feature/Preview/WidgetPreviewSessionTest.php`
- `tests/Feature/SiteLayout/WidgetRuntimeCacheTelemetryIntegrationTest.php`
- `tests/Unit/SiteLayout/WidgetPlacementCacheTest.php`
- `tests/Feature/Http/WidgetSiteLayoutApiConformanceTest.php` (only `test_preview_session_returns_a_url_not_a_persisted_resource`)

The tests use databases 13, 14 and 15 and flush them in `setUp()`. Never point this port at a real Redis.

Start and stop a throwaway instance:

```bash
redis-server --port 6399 --save '' --appendonly no --daemonize yes --pidfile /tmp/redis6399.pid
kill "$(cat /tmp/redis6399.pid)"
```

Without it the tests are reported as skipped with the reason `Redis 127.0.0.1:6399 not reachable`.
