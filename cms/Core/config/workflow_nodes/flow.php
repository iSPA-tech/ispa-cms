<?php

/*
 * Flow-control and utility nodes (lane N6). Merged by NodeRegistry::runnerMap()/definitionRows();
 * row format as in ../workflow_nodes.php.
 */

use Cms\Core\Workflows\Runtime\Runners\Flow\SplitInBatchesRunner;
use Cms\Core\Workflows\Runtime\Runners\Flow\SwitchRunner;
use Cms\Core\Workflows\Runtime\Runners\Flow\WaitRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\CryptoRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\DatetimeRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\DedupeRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\JsonTransformRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\LockRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\RateLimitRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\RenderTemplateRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\TextTransformRunner;

$any = ['type' => ['string', 'number', 'integer', 'boolean', 'array', 'object', 'null']];
$expr = ['type' => ['string', 'number', 'integer', 'boolean', 'null'], 'x-expr' => true];
$str = ['type' => ['string', 'null']];
$int = ['type' => ['integer', 'string', 'null']];
$num = ['type' => ['number', 'integer', 'string', 'null']];
$bool = ['type' => ['boolean', 'string', 'integer', 'null']];
$list = ['type' => ['array', 'object', 'null']];
$storeAs = ['type' => ['string', 'null'], 'x-store' => true];
$enum = static fn (array $values): array => ['type' => ['string', 'null'], 'enum' => array_merge($values, [null])];
$object = static fn (array $properties): array => ['type' => 'object', 'properties' => $properties];
$label = static fn (string $type): array => [
    'label_key' => "text.workflow.node.{$type}.title",
    'description_key' => "text.workflow.node.{$type}.desc",
];

// One result field for the designer's data picker (NodeDefinition::$result); `item` = parts of each list element.
$part = static fn (string $key, string $type, string $label, array $item = []): array => ['key' => $key, 'type' => $type, 'label' => $label] + ($item === [] ? [] : ['item' => $item]);

return [
    'step_runners' => [
        'switch' => SwitchRunner::class,
        'split_in_batches' => SplitInBatchesRunner::class,
        'wait' => WaitRunner::class,
        'render_template' => RenderTemplateRunner::class,
        'text_transform' => TextTransformRunner::class,
        'json_transform' => JsonTransformRunner::class,
        'dedupe' => DedupeRunner::class,
        'lock' => LockRunner::class,
        'rate_limit' => RateLimitRunner::class,
        'datetime' => DatetimeRunner::class,
        'crypto' => CryptoRunner::class,
    ],

    'definitions' => [
        'switch' => $label('switch') + [
            'group' => 'flow',
            'config_schema' => $object([
                'valueExpr' => $expr,
                'cases' => ['type' => ['array', 'null'], 'items' => $object(['key' => $str, 'match' => $any])],
            ]),
            'default_config' => ['valueExpr' => 'vars.category', 'cases' => [['key' => 'a', 'match' => 'a']]],
            // Plus one `case:<key>` handle per configured case.
            'outputs' => ['default'],
        ],
        'split_in_batches' => $label('split_in_batches') + [
            'result' => [], 'result_type' => 'list',
            'group' => 'flow',
            'config_schema' => $object(['collectionExpr' => $expr, 'batch_size' => $int, 'storeAs' => $storeAs]),
            'default_config' => ['collectionExpr' => 'vars.items', 'batch_size' => 10, 'storeAs' => 'vars.batch'],
            'outputs' => ['batch', 'done'],
        ],
        'wait' => $label('wait') + [
            'group' => 'flow',
            'config_schema' => $object([
                'mode' => $enum(['duration', 'until']), 'amount' => $num,
                'unit' => $enum(['seconds', 'minutes', 'hours', 'days']), 'untilExpr' => $expr, 'timezone' => $str,
            ]),
            'default_config' => ['mode' => 'duration', 'amount' => 5, 'unit' => 'minutes'],
        ],
        'render_template' => $label('render_template') + [
            'result' => [], 'result_type' => 'string',
            'group' => 'data',
            'config_schema' => $object(['template' => $str, 'format' => $enum(['text', 'markdown', 'html']), 'storeAs' => $storeAs]),
            'default_config' => ['template' => 'Hello {{ vars.name }}', 'format' => 'text', 'storeAs' => 'vars.rendered'],
        ],
        'text_transform' => $label('text_transform') + [
            'result' => [], 'result_type' => 'string',
            'group' => 'utility',
            'config_schema' => $object([
                'op' => $enum(TextTransformRunner::OPS), 'inputExpr' => $expr, 'length' => $int, 'suffix' => $str,
                'separator' => $str, 'pattern' => $str, 'replacement' => $str, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['op' => 'strip_html', 'inputExpr' => 'vars.html', 'storeAs' => 'vars.text'],
        ],
        'json_transform' => $label('json_transform') + [
            'result' => [$part('valid', 'boolean', 'Valid'), $part('errors', 'list', 'Errors')],
            'group' => 'utility',
            'config_schema' => $object([
                'op' => $enum(JsonTransformRunner::OPS), 'inputExpr' => $expr, 'fields' => $list,
                'schema' => ['type' => ['object', 'array', 'string', 'null']], 'pretty' => $bool, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['op' => 'parse', 'inputExpr' => 'vars.body', 'storeAs' => 'vars.json'],
            'outputs' => ['main', 'valid', 'invalid'],
        ],
        'dedupe' => $label('dedupe') + [
            'result' => [], 'result_type' => 'list',
            // Only stores key hashes (bookkeeping): not a taint sink, but its output stays tainted.
            'group' => 'utility', 'safety' => ['internal_write'], 'taint_sink' => false,
            'config_schema' => $object([
                'mode' => $enum(['filter', 'check', 'commit']), 'itemsExpr' => $expr, 'keyExpr' => $expr,
                'scope' => $str, 'ttl_days' => $int, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['mode' => 'filter', 'itemsExpr' => 'vars.items', 'keyExpr' => 'item.url', 'scope' => 'default', 'ttl_days' => 30, 'storeAs' => 'vars.new_items'],
            'outputs' => ['main', 'new', 'seen'],
        ],
        'lock' => $label('lock') + [
            'group' => 'utility', 'safety' => ['internal_write'],
            'config_schema' => $object([
                'mode' => $enum(['acquire', 'release']), 'keyExpr' => $expr, 'ttl_seconds' => $int,
                'on_busy' => $enum(['skip', 'fail']),
            ]),
            'default_config' => ['mode' => 'acquire', 'keyExpr' => 'vars.item.uuid', 'ttl_seconds' => 600, 'on_busy' => 'skip'],
            'outputs' => ['acquired', 'busy'],
        ],
        'rate_limit' => $label('rate_limit') + [
            'group' => 'utility', 'safety' => ['internal_write'],
            'config_schema' => $object([
                'keyExpr' => $expr, 'max' => $int, 'per_seconds' => $int, 'on_limit' => $enum(['skip', 'wait']),
            ]),
            'default_config' => ['keyExpr' => "'default'", 'max' => 10, 'per_seconds' => 60, 'on_limit' => 'wait'],
            'outputs' => ['ok', 'limited'],
        ],
        'datetime' => $label('datetime') + [
            'result' => [], 'result_type' => 'datetime',
            'group' => 'utility',
            'config_schema' => $object([
                'op' => $enum(DatetimeRunner::OPS), 'inputExpr' => $expr, 'otherExpr' => $expr, 'amount' => $int,
                'unit' => $str, 'format' => $str, 'timezone' => $str, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['op' => 'now', 'storeAs' => 'vars.now'],
        ],
        'crypto' => $label('crypto') + [
            'result' => [], 'result_type' => 'string',
            'group' => 'utility', 'bindings' => ['http_header'],
            'config_schema' => $object([
                'op' => $enum(CryptoRunner::OPS), 'algo' => $enum(['sha256', 'sha512', 'sha1', 'md5']),
                'inputExpr' => $expr, 'key' => ['type' => ['string', 'null'], 'x-secret' => 'http_header', 'x-setup' => 'credential', 'x-setup-when' => ['op' => ['hmac']]],
                'length' => $int, 'storeAs' => $storeAs,
            ]),
            'default_config' => ['op' => 'hash', 'algo' => 'sha256', 'inputExpr' => 'vars.text', 'storeAs' => 'vars.hash'],
        ],
    ],
];
