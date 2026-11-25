<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\Workflow\Nodes;

use Cms\Core\Workflows\Nodes\NodeConfigValidator;
use Cms\Core\Workflows\Nodes\NodeDefinition;
use Cms\Core\Workflows\Nodes\NodeRegistry;

uses(\Cms\Core\Tests\TestCase::class);

/**
 * Taint lint problem codes of a graph given as code => [type, config], with edges [from, to].
 *
 * @param array<string, array{0: string, 1: array<string, mixed>}> $steps
 * @param list<array{0: string, 1: string}> $edges
 * @return list<string> "<step_code>:<code>"
 */
function taintLint(array $steps, array $edges): array
{
    $rows = [];
    foreach ($steps as $code => [$type, $config]) {
        $rows[] = ['step_code' => $code, 'step_type' => $type, 'step_config' => $config];
    }

    $problems = (new NodeConfigValidator(NodeRegistry::current()))->validate(
        $rows,
        array_map(static fn (array $e): array => ['from' => $e[0], 'to' => $e[1]], $edges),
        null,
    );

    return array_values(array_map(
        static fn (array $p): string => $p['step_code'] . ':' . $p['code'],
        array_filter($problems, static fn (array $p): bool => $p['code'] === 'taint_needs_approval'),
    ));
}

it('does not treat dedupe as a taint sink, but keeps its output tainted', function (): void {
    $ai = ['ai_prompt', ['instructions' => 'List links', 'output' => 'json', 'storeAs' => 'vars.found']];
    $dedupe = ['dedupe', ['mode' => 'filter', 'itemsExpr' => 'vars.found.data', 'keyExpr' => 'item.url', 'storeAs' => 'vars.fresh']];
    $write = ['change', ['targetExpr' => 'root', 'changes' => [['field' => 'name', 'valueExpr' => 'vars.fresh']]]];

    expect(NodeRegistry::current()->get('dedupe')->isGuardedSink())->toBeFalse()
        ->and(NodeRegistry::current()->get('dedupe')->toCatalogArray()['taint_sink'])->toBeFalse()
        ->and(NodeRegistry::current()->get('change')->toCatalogArray()['taint_sink'])->toBeTrue()
        ->and(taintLint(['ai' => $ai, 'dedupe' => $dedupe], [['ai', 'dedupe']]))->toBe([])
        // A guarded sink reading the dedupe output still needs an approval.
        ->and(taintLint(['ai' => $ai, 'dedupe' => $dedupe, 'write' => $write], [['ai', 'dedupe'], ['dedupe', 'write']]))
        ->toBe(['write:taint_needs_approval'])
        ->and(taintLint(
            ['ai' => $ai, 'dedupe' => $dedupe, 'ok' => ['approval', ['mode' => 'human']], 'write' => $write],
            [['ai', 'dedupe'], ['dedupe', 'ok'], ['ok', 'write']],
        ))->toBe([]);
});

it('reads taint_sink from a node-set row and defaults it to true', function (): void {
    expect(NodeDefinition::fromArray('x_write', ['safety' => ['internal_write']])->isGuardedSink())->toBeTrue()
        ->and(NodeDefinition::fromArray('x_write', ['safety' => ['internal_write'], 'taint_sink' => false])->isGuardedSink())->toBeFalse()
        ->and(NodeDefinition::fromArray('x_read', ['taint_sink' => true])->isGuardedSink())->toBeFalse();
});

it('carries taint through the loop_each item variable', function (): void {
    $ai = ['ai_prompt', ['instructions' => 'List names', 'output' => 'json', 'storeAs' => 'vars.list']];
    $loop = ['loop_each', ['collectionExpr' => 'vars.list.data', 'itemVar' => 'vars.entry', 'indexVar' => 'vars.i']];
    $write = ['change', ['targetExpr' => 'root', 'changes' => [['field' => 'name', 'valueExpr' => 'vars.entry.name']]]];

    expect(taintLint(['ai' => $ai, 'loop' => $loop, 'write' => $write], [['ai', 'loop'], ['loop', 'write']]))
        ->toBe(['write:taint_needs_approval'])
        ->and(taintLint(
            ['ai' => $ai, 'loop' => $loop, 'ok' => ['approval', ['mode' => 'human']], 'write' => $write],
            [['ai', 'loop'], ['loop', 'ok'], ['ok', 'write']],
        ))->toBe([]);
});

it('treats sending AI text out as a release that needs an approval', function (): void {
    // ("chỉ duyệt release"): a message to people or services is guarded like a publish.
    $ai = ['ai_prompt', ['instructions' => 'Write a notice', 'storeAs' => 'vars.note']];
    $send = ['telegram_send', ['credential' => '$bind:tg_main', 'chat' => "'@channel'", 'text' => 'vars.note.text']];

    expect(NodeRegistry::current()->get('telegram_send')->isGuardedSink())->toBeTrue()
        ->and(NodeRegistry::current()->get('email_send')->isGuardedSink())->toBeTrue()
        ->and(NodeRegistry::current()->get('http_request')->isGuardedSink())->toBeTrue()
        ->and(taintLint(['ai' => $ai, 'send' => $send], [['ai', 'send']]))->toBe(['send:taint_needs_approval'])
        ->and(taintLint(
            ['ai' => $ai, 'ok' => ['approval', ['mode' => 'human']], 'send' => $send],
            [['ai', 'ok'], ['ok', 'send']],
        ))->toBe([])
        // A fetch-only node that declares itself no sink stays allowed after untrusted input.
        ->and(NodeDefinition::fromArray('x_fetch', ['safety' => ['external_call'], 'taint_sink' => false])->isGuardedSink())->toBeFalse();
});
