<?php

/*
 * Fetch + media node set (lane N5a): http_request, html_extract, rss_read, sitemap_read, media_import, file_storage.
 * Merged as `core.workflow_nodes.fetch`; NodeRegistry::runnerMap()/definitionRows() pick up `step_runners` and
 * `definitions`. Every outbound call goes through SafeHttpFetcher: host allowlist (`core.workflow_security.http_hosts`
 * + the Webmaster's list), SSRF checks on every hop, at most 3 redirects, size and time limits.
 */

use Cms\Core\Workflows\Runtime\Runners\Integration\Feed\RssReadRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Feed\SitemapReadRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Html\HtmlExtractRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Http\HttpRequestRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Media\MediaImportRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\Storage\FileStorageRunner;

$expr = ['type' => ['string', 'number', 'integer', 'boolean', 'null'], 'x-expr' => true];
$any = ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null'], 'x-expr' => true];
$map = ['type' => ['object', 'array', 'null']];
$int = static fn (int $min, int $max): array => ['type' => ['integer', 'string', 'null'], 'minimum' => $min, 'maximum' => $max];
$bool = ['type' => ['boolean', 'string', 'integer', 'null']];
$storeAs = ['type' => ['string', 'null'], 'x-store' => true];
$object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
$enum = static fn (array $values): array => ['type' => ['string', 'null'], 'enum' => [...$values, null]];
$label = static fn (string $type): array => [
    'label_key' => "text.workflow.node.{$type}.title",
    'description_key' => "text.workflow.node.{$type}.desc",
];

// One result field for the designer's data picker (NodeDefinition::$result); `item` = parts of each list element.
$part = static fn (string $key, string $type, string $label, array $item = []): array => ['key' => $key, 'type' => $type, 'label' => $label] + ($item === [] ? [] : ['item' => $item]);

return [
    'step_runners' => [
        'http_request' => HttpRequestRunner::class,
        'html_extract' => HtmlExtractRunner::class,
        'rss_read' => RssReadRunner::class,
        'sitemap_read' => SitemapReadRunner::class,
        'media_import' => MediaImportRunner::class,
        'file_storage' => FileStorageRunner::class,
    ],

    'definitions' => [
        'http_request' => $label('http_request') + [
            'result' => [
                $part('status', 'number', 'Status'),
                $part('ok', 'boolean', 'Succeeded'),
                $part('url', 'string', 'Link'),
                $part('headers', 'object', 'Headers'),
                $part('body', 'object', 'Body'),
                $part('bytes', 'number', 'Size (bytes)'),
                $part('redirects', 'number', 'Redirects followed'),
                $part('tainted', 'boolean', 'Came from outside'),
            ],
            'group' => 'integration', 'icon' => 'globe',
            // GET-only use would be `read`; one definition covers every method, so it carries the stricter class.
            'safety' => ['external_call'], 'approval_default' => 'on', 'taint_output' => true,
            'bindings' => ['http_header', 'http_basic'],
            'outputs' => ['success', 'error'],
            'config_schema' => $object([
                'method' => $enum(['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE']),
                'url' => $expr, 'headers' => $map, 'query' => $map,
                'body_type' => $enum(['none', 'json', 'form', 'raw']), 'body' => $any,
                'content_type' => ['type' => ['string', 'null']],
                'auth' => $enum(['none', 'header', 'basic']),
                'credential' => ['type' => ['string', 'null'], 'x-secret' => 'http_auth', 'x-setup' => 'credential', 'x-setup-when' => ['auth' => ['header', 'basic']]],
                'timeout' => $int(1, 60), 'max_bytes' => $int(1, 10 * 1024 * 1024),
                'retries' => $int(0, 3), 'retry_delay_ms' => $int(0, 5000),
                'follow_redirects' => $bool, 'response' => $enum(['auto', 'json', 'text']),
                'storeAs' => $storeAs,
            ]),
            'default_config' => ['method' => 'GET', 'url' => '', 'auth' => 'none', 'body_type' => 'none', 'response' => 'auto', 'storeAs' => 'vars.http'],
        ],
        'html_extract' => $label('html_extract') + [
            'result' => [
                $part('title', 'string', 'Title'),
                $part('text', 'string', 'Text'),
                $part('fields', 'object', 'Picked parts'),
                $part('links', 'list', 'Links', [$part('url', 'string', 'Link'), $part('text', 'string', 'Text')]),
                $part('images', 'list', 'Images', [$part('src', 'string', 'Image link'), $part('alt', 'string', 'Image description')]),
                $part('tainted', 'boolean', 'Came from outside'),
            ],
            'group' => 'integration', 'icon' => 'code',
            'safety' => ['read'], 'approval_default' => 'off', 'taint_output' => true,
            'config_schema' => $object([
                'html' => $any, 'base_url' => $expr, 'selectors' => $map, 'max_links' => $int(0, 1000), 'storeAs' => $storeAs,
            ]),
            'default_config' => ['html' => '{{ vars.http.body }}', 'selectors' => [], 'storeAs' => 'vars.page'],
        ],
        'rss_read' => $label('rss_read') + [
            'result' => [
                $part('feed.title', 'string', 'Feed title'),
                $part('feed.link', 'string', 'Feed link'),
                $part('items', 'list', 'Items', [
                    $part('title', 'string', 'Title'),
                    $part('link', 'string', 'Link'),
                    $part('guid', 'string', 'Item code'),
                    $part('published_at', 'datetime', 'Published at'),
                    $part('summary', 'string', 'Summary'),
                    $part('content', 'string', 'Content'),
                    $part('author', 'string', 'Author'),
                ]),
                $part('count', 'number', 'Count'),
                $part('tainted', 'boolean', 'Came from outside'),
            ],
            'group' => 'integration', 'icon' => 'rss',
            'safety' => ['read'], 'approval_default' => 'off', 'taint_output' => true,
            'config_schema' => $object([
                'url' => $expr, 'since' => $expr, 'max_items' => $int(1, 200), 'max_bytes' => $int(1, 10 * 1024 * 1024), 'storeAs' => $storeAs,
            ]),
            'default_config' => ['url' => '', 'max_items' => 50, 'storeAs' => 'vars.feed'],
        ],
        'sitemap_read' => $label('sitemap_read') + [
            'result' => [
                $part('urls', 'list', 'Pages', [$part('loc', 'string', 'Page link'), $part('lastmod', 'datetime', 'Last changed')]),
                $part('count', 'number', 'Count'),
                $part('sitemaps_read', 'number', 'Sitemaps read'),
                $part('truncated', 'boolean', 'Cut short'),
                $part('tainted', 'boolean', 'Came from outside'),
            ],
            'group' => 'integration', 'icon' => 'sitemap',
            'safety' => ['read'], 'approval_default' => 'off', 'taint_output' => true,
            'config_schema' => $object([
                'url' => $expr, 'pattern' => $expr, 'max_urls' => $int(1, 5000), 'max_sitemaps' => $int(1, 10),
                'max_bytes' => $int(1, 10 * 1024 * 1024), 'storeAs' => $storeAs,
            ]),
            'default_config' => ['url' => '', 'pattern' => '', 'max_urls' => 500, 'storeAs' => 'vars.sitemap'],
        ],
        'media_import' => $label('media_import') + [
            'result' => [
                $part('upload_id', 'number', 'Media number'),
                $part('uuid', 'string', 'ID'),
                $part('url', 'string', 'Link'),
                $part('mime_type', 'string', 'File type'),
                $part('bytes', 'number', 'Size (bytes)'),
                $part('duplicate', 'boolean', 'Already in the media library'),
            ],
            'group' => 'integration', 'icon' => 'image',
            'safety' => ['external_call', 'internal_write'], 'approval_default' => 'on',
            'config_schema' => $object([
                'url' => $expr, 'alt' => $expr, 'max_bytes' => $int(1, 20 * 1024 * 1024), 'storeAs' => $storeAs,
            ]),
            'default_config' => ['url' => '', 'alt' => '', 'storeAs' => 'vars.media'],
        ],
        'file_storage' => $label('file_storage') + [
            'result' => [
                $part('path', 'string', 'File path'),
                $part('content', 'string', 'Content'),
                $part('bytes', 'number', 'Size (bytes)'),
                $part('files', 'list', 'Files', [$part('path', 'string', 'File path'), $part('bytes', 'number', 'Size (bytes)')]),
                $part('count', 'number', 'Count'),
            ],
            'group' => 'integration', 'icon' => 'folder',
            'safety' => ['internal_write'], 'approval_default' => 'off',
            'config_schema' => $object([
                'op' => $enum(['write', 'read', 'list']), 'disk' => ['type' => ['string', 'null']], 'path' => $expr,
                'format' => $enum(['text', 'json']), 'content' => $any, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['op' => 'write', 'path' => '', 'format' => 'text', 'content' => '', 'storeAs' => 'vars.file'],
        ],
    ],

    'settings' => [
        'default_timeout_seconds' => 20,
        'max_timeout_seconds' => 60,
        'default_max_bytes' => 2 * 1024 * 1024,
        'hard_max_bytes' => 10 * 1024 * 1024,
        'user_agent' => 'iSPA-CMS-Workflow/1.0',
        'media' => ['disk' => 'public', 'max_bytes' => 10 * 1024 * 1024, 'max_pixels' => 40_000_000],
        'storage' => ['disks' => ['local'], 'max_bytes' => 5 * 1024 * 1024],
    ],
];
