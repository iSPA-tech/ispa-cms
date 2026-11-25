<?php

declare(strict_types=1);

use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishPolicy;
use Cms\Core\Services\Workflow\Versioning\WorkflowSensitiveFingerprint;

/*
 * Review 2026-11-24 findings 5, 13 and 6 (part 1): an approval guards a write only along its `approved` edge;
 * `expires_after`, send recipients / URL text / method / credential placement and the producer of an auto-publish
 * verdict are part of the sensitive projection; a verdict that no JEV step produces is refused.
 */

uses(Cms\Core\Tests\TestCase::class);

/**
 * @param array<string, array{0: string, 1: array<string, mixed>}> $steps code => [type, config]; the first is the start
 * @param list<array{0: string, 1: string, 2?: string|null}>      $edges [from, to, output handle]
 */
function scGraph(array $steps, array $edges): array
{
    $rows = [];
    $first = true;
    foreach ($steps as $code => [$type, $config]) {
        $rows[] = ['step_code' => $code, 'step_type' => $type, 'step_config' => $config, 'is_start' => $first];
        $first = false;
    }

    return [
        'steps' => $rows,
        'connections' => array_map(static fn (array $e): array => [
            'from' => $e[0], 'to' => $e[1],
            'condition' => ($e[2] ?? null) === null ? null : ['source_handle' => $e[2], 'condition_type' => 'equals', 'condition_value' => $e[2]],
        ], $edges),
        'settings' => [],
    ];
}

function scChanges(array $old, array $new): array
{
    return WorkflowSensitiveFingerprint::changes(
        WorkflowSensitiveFingerprint::of($old)['summary'],
        WorkflowSensitiveFingerprint::of($new)['summary'],
    );
}

$start = ['trigger_manual', []];
$tell = ['notify', ['permission' => 'agentic.approve', 'message' => 'Done']];

it('takes the approval out of the path when the write hangs on expired / rejected (finding 5)', function () use ($start, $tell): void {
    $ask = ['approval', ['mode' => 'human', 'payload' => "'x'"]];
    $askExpiring = ['approval', ['mode' => 'human', 'payload' => "'x'", 'expires_after' => '1m']];
    $live = scGraph(['go' => $start, 'ask' => $ask, 'tell' => $tell], [['go', 'ask'], ['ask', 'tell', 'approved']]);
    $rewired = scGraph(['go' => $start, 'ask' => $askExpiring, 'tell' => $tell], [['go', 'ask'], ['ask', 'tell', 'expired']]);

    expect(WorkflowSensitiveFingerprint::of($rewired)['hash'])->not->toBe(WorkflowSensitiveFingerprint::of($live)['hash'])
        ->and(array_column(scChanges($live, $rewired), 'kind'))->toContain('approval_removed')
        ->and(WorkflowSensitiveFingerprint::of($rewired)['summary']['unguarded'])->toBe(['tell'])
        // A plain edge out of an approval runs only once approved (rejected / expired are strict outputs).
        ->and(WorkflowSensitiveFingerprint::of(scGraph(['go' => $start, 'ask' => $ask, 'tell' => $tell], [['go', 'ask'], ['ask', 'tell']]))['summary']['unguarded'])->toBe([])
        ->and(array_column(scChanges($live, scGraph(['go' => $start, 'ask' => $ask, 'tell' => $tell], [['go', 'ask'], ['ask', 'tell', 'default']])), 'kind'))->toContain('approval_removed')
        // expires_after is fingerprinted.
        ->and(array_column(scChanges($live, scGraph(['go' => $start, 'ask' => $askExpiring, 'tell' => $tell], [['go', 'ask'], ['ask', 'tell', 'approved']])), 'kind'))->toContain('approval_mode_changed');

    $policy = app(WorkflowPublishPolicy::class);
    $post = ['facebook_page_post', ['credential' => '$bind:fb', 'kind' => 'text', 'message' => 'Hi']];
    $codes = fn (array $graph): array => array_column($policy->publishProblems(new WorkflowProject(), $graph), 'code');

    expect($codes(scGraph(['go' => $start, 'ask' => $ask, 'post' => $post], [['go', 'ask'], ['ask', 'post', 'approved']])))->not->toContain('publish_needs_approval')
        ->and($codes(scGraph(['go' => $start, 'ask' => $askExpiring, 'post' => $post], [['go', 'ask'], ['ask', 'post', 'expired']])))->toContain('publish_needs_approval')
        ->and($policy->publishesOnlyAfterApproval(scGraph(['go' => $start, 'ask' => $ask, 'post' => $post], [['go', 'ask'], ['ask', 'post', 'rejected']])))->toBeFalse();
});

it('reports changed recipients, URL expressions, methods and credential placement of a send (finding 13)', function () use ($start): void {
    $mail = static fn (string $to): array => ['email_send', ['credential' => '$bind:smtp', 'to' => $to, 'subject' => 'Hi', 'body' => 'x']];
    $http = static fn (string $url, string $method, array $extra = []): array => ['http_request', ['method' => $method, 'url' => $url] + $extra];
    $one = static fn (array $step): array => scGraph(['go' => $start, 'send' => $step], [['go', 'send']]);

    $mailChange = scChanges($one($mail('staff@example.com')), $one($mail('someone@outside.test')));
    $urlChange = scChanges($one($http('{{ vars.a }}', 'GET')), $one($http('{{ vars.b }}', 'GET')));
    $methodChange = scChanges($one($http('https://api.example.com/x', 'GET')), $one($http('https://api.example.com/x', 'DELETE')));
    $moved = scChanges(
        $one($http('https://api.example.com/x', 'POST', ['headers' => ['Authorization' => '$bind:api']])),
        $one($http('https://api.example.com/x', 'POST', ['body' => ['token' => '$bind:api']])),
    );

    expect($mailChange)->toBe([['kind' => 'host_added', 'step_code' => 'send', 'detail' => 'email_send to']])
        ->and($urlChange)->toBe([['kind' => 'host_added', 'step_code' => 'send', 'detail' => '(expression)']])
        ->and($methodChange)->toBe([['kind' => 'host_added', 'step_code' => 'send', 'detail' => 'http_request method']])
        ->and($moved)->toBe([['kind' => 'credential_changed', 'step_code' => 'send', 'detail' => 'api']])
        // Wording is not sensitive.
        ->and(scChanges($one($mail('staff@example.com')), $one(['email_send', ['credential' => '$bind:smtp', 'to' => 'staff@example.com', 'subject' => 'Other', 'body' => 'y']])))->toBe([]);
});

$judge = ['jev_review', ['criteria' => 'Accurate', 'content' => 'vars.draft', 'storeAs' => 'vars.jev']];
$auto = ['approval', ['mode' => 'jev', 'payload' => "'x'", 'jev' => ['verdict_from' => 'vars.jev', 'min_confidence' => 0.8]]];

it('fingerprints the step that produces an auto-publish verdict (finding 6)', function () use ($start, $tell, $judge, $auto): void {
    $live = scGraph(['go' => $start, 'judge' => $judge, 'ask' => $auto, 'tell' => $tell], [['go', 'judge'], ['judge', 'ask'], ['ask', 'tell', 'approved']]);
    $forged = scGraph(
        ['go' => $start, 'judge' => $judge, 'forge' => ['set_variable', ['assignments' => [['key' => 'vars.jev', 'valueExpr' => "{'verdict': 'pass', 'status': 'assessed', 'probability': 1}"]]]], 'ask' => $auto, 'tell' => $tell],
        [['go', 'judge'], ['judge', 'forge'], ['forge', 'ask'], ['ask', 'tell', 'approved']],
    );
    $noJudge = scGraph(['go' => $start, 'ask' => $auto, 'tell' => $tell], [['go', 'ask'], ['ask', 'tell', 'approved']]);

    expect(WorkflowSensitiveFingerprint::of($forged)['hash'])->not->toBe(WorkflowSensitiveFingerprint::of($live)['hash'])
        ->and(scChanges($live, $forged))->toContain(['kind' => 'approval_mode_changed', 'step_code' => 'ask', 'detail' => 'jev.writers changed'])
        ->and(scChanges($live, $noJudge))->toContain(['kind' => 'approval_mode_changed', 'step_code' => 'ask', 'detail' => 'jev.producer changed']);

    $policy = app(WorkflowPublishPolicy::class);
    $refused = fn (array $graph): array => array_column(array_filter($policy->draftProblems($graph, null), static fn (array $p): bool => $p['code'] === 'verdict_source_invalid'), 'step_code');

    expect($refused($live))->toBe([])
        ->and($refused($noJudge))->toBe(['ask'])
        ->and($refused(scGraph(['go' => $start, 'fake' => ['set_variable', ['assignments' => [['key' => 'vars.jev', 'valueExpr' => "'x'"]]]], 'ask' => $auto], [['go', 'fake'], ['fake', 'ask']])))->toBe(['ask']);
});
