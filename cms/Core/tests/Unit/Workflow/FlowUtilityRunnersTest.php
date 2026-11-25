<?php

namespace Cms\Core\Tests\Unit\Workflow;

use Cms\Core\Tests\TestCase;
use Cms\Core\Workflows\Runtime\Runners\Flow\WaitRunner;
use Cms\Core\Workflows\Runtime\Runners\ListOperationRunner;
use Cms\Core\Workflows\Runtime\Runners\LoopEachRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\CryptoRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\DatetimeRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\JsonTransformRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\RenderTemplateRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\TextTransformRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Database\Eloquent\Model;

class FlowUtilityRunnersTest extends TestCase
{
    public function test_render_template_escapes_values_in_html_but_not_in_text(): void
    {
        $ctx = $this->ctx(['name' => '<b>Ann</b> & co']);
        $runner = new RenderTemplateRunner();

        $runner->run($this->step('render_template', ['template' => '<p>{{ vars.name }}</p>', 'format' => 'html', 'storeAs' => 'vars.out']), $ctx);
        $this->assertSame('<p>&lt;b&gt;Ann&lt;/b&gt; &amp; co</p>', $ctx->getVarPath('out'));

        $runner->run($this->step('render_template', ['template' => 'Hi {{ vars.name }}', 'storeAs' => 'vars.out']), $ctx);
        $this->assertSame('Hi <b>Ann</b> & co', $ctx->getVarPath('out'));
    }

    public function test_text_transform_ops(): void
    {
        $cases = [
            ['slugify', 'Nhà thờ Đức Bà', [], 'nha-tho-duc-ba'],
            ['truncate', 'abcdefgh', ['length' => 3, 'suffix' => '…'], 'abc…'],
            ['strip_html', '<p>Hi <script>x()</script><b>there</b></p>', [], 'Hi there'],
            ['regex_replace', 'a1b22', ['pattern' => '/\d+/', 'replacement' => '#'], 'a#b#'],
            ['word_count', ' one  two three ', [], 3],
            ['normalize_whitespace', " a \n\t b ", [], 'a b'],
            ['markdown_to_html', "**hi** <script>x</script>", [], "<p><strong>hi</strong> x</p>\n"],
        ];
        foreach ($cases as [$op, $input, $args, $expected]) {
            $ctx = $this->ctx(['in' => $input]);
            $res = (new TextTransformRunner())->run($this->step('text_transform', ['op' => $op, 'inputExpr' => 'vars.in', 'storeAs' => 'vars.out'] + $args), $ctx);
            $this->assertSame('success', $res->status, $op);
            $this->assertSame($expected, $ctx->getVarPath('out'), $op);
        }

        $bad = (new TextTransformRunner())->run($this->step('text_transform', ['op' => 'regex_replace', 'inputExpr' => 'vars.in', 'pattern' => '(unclosed', 'storeAs' => 'vars.out']), $this->ctx(['in' => 'x']));
        $this->assertSame('failed', $bad->status);
    }

    public function test_json_transform_parse_pick_omit_and_bad_json(): void
    {
        $ctx = $this->ctx(['raw' => '{"a":1,"b":{"c":2,"d":3}}']);
        $runner = new JsonTransformRunner();

        $runner->run($this->step('json_transform', ['op' => 'parse', 'inputExpr' => 'vars.raw', 'storeAs' => 'vars.obj']), $ctx);
        $runner->run($this->step('json_transform', ['op' => 'pick', 'inputExpr' => 'vars.obj', 'fields' => ['b.c'], 'storeAs' => 'vars.picked']), $ctx);
        $runner->run($this->step('json_transform', ['op' => 'omit', 'inputExpr' => 'vars.obj', 'fields' => ['b'], 'storeAs' => 'vars.omitted']), $ctx);

        $this->assertSame(['b' => ['c' => 2]], $ctx->getVarPath('picked'));
        $this->assertSame(['a' => 1], $ctx->getVarPath('omitted'));
        $this->assertSame('failed', $runner->run($this->step('json_transform', ['op' => 'parse', 'inputExpr' => "'{oops'", 'storeAs' => 'vars.x']), $ctx)->status);
    }

    public function test_datetime_add_diff_and_format(): void
    {
        $ctx = $this->ctx(['at' => '2026-01-31T10:00:00+07:00', 'later' => '2026-02-03T10:00:00+07:00']);
        $runner = new DatetimeRunner();

        $runner->run($this->step('datetime', ['op' => 'add', 'inputExpr' => 'vars.at', 'amount' => 2, 'unit' => 'days', 'format' => 'Y-m-d', 'storeAs' => 'vars.d']), $ctx);
        $runner->run($this->step('datetime', ['op' => 'diff', 'inputExpr' => 'vars.at', 'otherExpr' => 'vars.later', 'unit' => 'day', 'storeAs' => 'vars.n']), $ctx);
        $runner->run($this->step('datetime', ['op' => 'start_of', 'inputExpr' => 'vars.at', 'unit' => 'month', 'format' => 'Y-m-d H:i', 'storeAs' => 'vars.s']), $ctx);

        $this->assertSame('2026-02-02', $ctx->getVarPath('d'));
        $this->assertSame(3, $ctx->getVarPath('n'));
        $this->assertSame('2026-01-01 00:00', $ctx->getVarPath('s'));
        $this->assertSame('failed', $runner->run($this->step('datetime', ['op' => 'add', 'inputExpr' => 'vars.at', 'unit' => 'fortnight', 'storeAs' => 'vars.x']), $ctx)->status);
    }

    public function test_crypto_hashes_and_refuses_a_literal_or_unbound_hmac_key(): void
    {
        $ctx = $this->ctx(['t' => 'abc']);
        $runner = new CryptoRunner();

        $runner->run($this->step('crypto', ['op' => 'hash', 'algo' => 'sha256', 'inputExpr' => 'vars.t', 'storeAs' => 'vars.h']), $ctx);
        $this->assertSame(hash('sha256', 'abc'), $ctx->getVarPath('h'));

        $literal = $runner->run($this->step('crypto', ['op' => 'hmac', 'inputExpr' => 'vars.t', 'key' => 'secret', 'storeAs' => 'vars.m']), $ctx);
        $unbound = $runner->run($this->step('crypto', ['op' => 'hmac', 'inputExpr' => 'vars.t', 'key' => '$bind:hook', 'storeAs' => 'vars.m']), $ctx);
        $this->assertSame('failed', $literal->status);
        $this->assertSame('failed', $unbound->status);

        $runner->run($this->step('crypto', ['op' => 'uuid', 'storeAs' => 'vars.u']), $ctx);
        $this->assertMatchesRegularExpression('/^[0-9a-f-]{36}$/', $ctx->getVarPath('u'));
    }

    public function test_list_operation_unique_flatten_pluck_split_out(): void
    {
        $ctx = $this->ctx(['rows' => [['id' => 1, 'tags' => ['a', 'b']], ['id' => 1, 'tags' => ['c']], ['id' => 2, 'tags' => []]]]);
        $runner = new ListOperationRunner();
        $run = function (array $config) use ($runner, $ctx) {
            $runner->run($this->step('list_operation', ['collectionExpr' => 'vars.rows', 'storeAs' => 'vars.out'] + $config), $ctx);

            return $ctx->getVarPath('out');
        };

        $this->assertCount(2, $run(['operation' => 'unique', 'field' => 'id']));
        $this->assertSame([1, 1, 2], $run(['operation' => 'pluck', 'field' => 'id']));
        $this->assertSame(['a', 'b', 'c'], $run(['operation' => 'split_out', 'field' => 'tags']));
        $ctx->setVarPath('rows', [[1, 2], 3, [4]]);
        $this->assertSame([1, 2, 3, 4], $run(['operation' => 'flatten']));
    }

    public function test_loop_each_max_items_never_raises_the_site_cap(): void
    {
        config(['core.workflow.max_loop_items' => 3]);
        $ctx = $this->ctx(['items' => [1, 2, 3, 4]]);
        $step = fn (int $max) => $this->step('loop_each', ['collectionExpr' => 'vars.items', 'itemVar' => 'vars.item', 'maxItems' => $max]);

        $this->assertSame('failed', (new LoopEachRunner())->run($step(1000), $ctx)->status);
        $this->assertSame('failed', (new LoopEachRunner())->run($step(2), $ctx)->status);
        $ctx->setVarPath('items', [1, 2]);
        $this->assertSame('success', (new LoopEachRunner())->run($step(2), $ctx)->status);
    }

    public function test_wait_duration_reschedules_and_respects_the_cap(): void
    {
        $runner = new WaitRunner();
        $res = $runner->run($this->step('wait', ['mode' => 'duration', 'amount' => 2, 'unit' => 'minutes']), $this->ctx([]));
        $this->assertSame('rescheduled', $res->status);
        $this->assertSame(120, $res->delaySeconds);

        config(['core.workflow.max_wait_seconds' => 60]);
        $this->assertSame('failed', $runner->run($this->step('wait', ['mode' => 'duration', 'amount' => 2, 'unit' => 'minutes']), $this->ctx([]))->status);
        $this->assertSame('failed', $runner->run($this->step('wait', ['mode' => 'webhook']), $this->ctx([]))->status);
    }

    protected function step(string $type, array $config): StepDefinition
    {
        return new StepDefinition(null, 1, $type, $type, null, $type, $config);
    }

    protected function ctx(array $vars): WorkflowContext
    {
        $root = new class extends Model {
        };

        return new WorkflowContext($root, [], $vars);
    }
}
