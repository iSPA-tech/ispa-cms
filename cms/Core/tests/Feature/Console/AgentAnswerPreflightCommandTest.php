<?php

declare(strict_types=1);

use App\Enums\StatusEnum;
use Cms\Core\Database\Seeders\AgenticProfileTemplateSeeder;
use Cms\Core\Database\Seeders\PermissionTableSeed;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentProfileTool;
use Cms\Core\Models\Agents\AgentToolBinding;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Setting;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Content\Services\Agentic\AgentReadablePostProjector;
use Cms\Content\Models\Post;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Laravel\Telescope\Telescope;
use Symfony\Component\Console\Output\BufferedOutput;

uses(Tests\TestCase::class, RefreshDatabase::class);

/*
 * `cms:agent-answer-preflight` reads every activation condition of the content answer pilot and prints one
 * PASS/FAIL line each. It writes nothing and prints no secret.
 */

const PREFLIGHT_UUID = '00000001-0000-4000-8000-000000000001';

beforeEach(function (): void {
    config()->set('ispa-cms.skip_languages_query', true);
    config()->set('ispa-cms.folder_name', 'PilotSite');
    $this->seed(PermissionTableSeed::class);
    $this->seed(AgenticProfileTemplateSeeder::class);
    config()->set('core.agent_answers.enabled', false);
    config()->set('core.agent_answers.allowed_projects', []);
    config()->set('content.agent_answers.approved_posts', []);
    preflightTask(null, null);
});

/** Store the AI Runtime task settings the way the admin screen does (null removes the row). */
function preflightTask(?string $provider, ?string $model): void
{
    foreach (['provider' => $provider, 'model' => $model] as $suffix => $value) {
        $key = 'core.ai_runtime.tasks.content_agent_answer.' . $suffix;
        DB::table('settings')->where('setting_key', $key)->delete();
        if ($value !== null) {
            Setting::query()->create([
                'setting_key' => $key, 'type' => 'text', 'group_name' => 'core.ai_runtime', 'name' => 'content_agent_answer.' . $suffix,
                'input_setting' => ['title' => $suffix, 'isPublic' => true, 'isEnv' => false], 'value' => $value,
            ]);
        }
    }
    // The resolver is a singleton that caches what it read.
    app()->forgetInstance(AiRuntimeConfigResolver::class);
}

/** @return array{0: int, 1: string} */
function preflight(string $mode = 'activation-ready'): array
{
    $out = new BufferedOutput();
    $code = Artisan::call('cms:agent-answer-preflight', ['--mode' => $mode], $out);

    return [$code, $out->fetch()];
}

function preflightLine(string $output, string $id): string
{
    foreach (preg_split('/\R/', $output) ?: [] as $line) {
        if (str_contains($line, ' ' . $id . ':')) {
            return trim($line);
        }
    }

    return '';
}

/** Put the site in a fully ready state (feature still off). */
function preflightReady(): void
{
    DB::table('languages')->insertOrIgnore(['id' => 0, 'name' => 'Tiếng Việt', 'code' => 'vi', 'path' => 'vi']);
    foreach ([StatusEnum::PUBLISHED->value, StatusEnum::DRAFT->value] as $status) {
        DB::table('enumerates')->insertOrIgnore(['name' => $status, 'enum_key' => $status, 'type' => 'status']);
    }
    DB::table('posts')->insert([
        'uuid' => PREFLIGHT_UUID, 'name' => 'Thay dầu nhớt định kỳ', 'description' => null,
        'content' => json_encode([['type' => 'text', 'value' => 'Nội dung.']], JSON_UNESCAPED_UNICODE),
        'type' => 'post', 'language_id' => 0, 'status' => StatusEnum::PUBLISHED->value,
        'published_at' => now()->subDay()->format('Y-m-d H:i:s'), 'created_at' => now(), 'updated_at' => now(),
    ]);
    $post = Post::query()->withoutGlobalScope(Post::APPENDED_RELATIONS_SCOPE)->with(['categories.slugs', 'language'])->where('uuid', PREFLIGHT_UUID)->firstOrFail();
    config()->set('content.agent_answers.approved_posts', [PREFLIGHT_UUID => app(AgentReadablePostProjector::class)->project($post)['content_digest']]);

    AgentProfile::query()->where('slug', 'public-content-assistant')->update(['status' => 'active']);
    config()->set('core.agent_answers.allowed_projects', ['PilotSite']);
    preflightTask('openai', 'gpt-test');
    $role = Role::query()->create(['name' => 'Pilot users']);
    $role->permissions()->attach(Permission::query()->where('group', 'agent_profile')->where('name', 'answer')->firstOrFail()->getKey());
}

it('passes the provisioned mode right after seeding, with everything still off and blank', function (): void {
    [$code, $out] = preflight('provisioned');

    expect($out)->not->toContain('[FAIL]')->and($code)->toBe(0);
    expect(preflightLine($out, 'profile.state'))->toContain('[PASS]');
});

it('fails the activation-ready mode while the profile is disabled, nothing is chosen and no role has the permission', function (): void {
    [$code, $out] = preflight('activation-ready');

    expect($code)->toBe(1);
    foreach (['profile.state', 'flag.project', 'provider.set', 'corpus.map', 'permission.role'] as $id) {
        expect(preflightLine($out, $id))->toContain('[FAIL]');
    }
});

it('passes the activation-ready mode when everything is prepared and the feature is still off', function (): void {
    preflightReady();

    [$code, $out] = preflight('activation-ready');

    expect($out)->not->toContain('[FAIL]')->and($code)->toBe(0);
    expect(preflightLine($out, 'corpus.digests'))->toContain('[PASS]')
        ->and(preflightLine($out, 'telescope.filter'))->toContain('[PASS]');
});

it('the active mode needs the feature on and the other modes need it off', function (): void {
    preflightReady();
    expect(preflightLine(preflight('active')[1], 'flag.enabled'))->toContain('[FAIL]');

    config()->set('core.agent_answers.enabled', true);
    [$code, $out] = preflight('active');
    expect($out)->not->toContain('[FAIL]')->and($code)->toBe(0)
        ->and(preflightLine(preflight('activation-ready')[1], 'flag.enabled'))->toContain('[FAIL]');
});

it('refuses Anthropic and any provider outside the allowed list, naming the reason', function (string $provider, string $needle): void {
    preflightReady();
    preflightTask($provider, 'some-model');

    $line = preflightLine(preflight()[1], 'provider.allowed');

    expect($line)->toContain('[FAIL]')->and(strtolower($line))->toContain($needle);
})->with([['anthropic', 'anthropic'], ['mistral', 'not allowed']]);

it('fails when the task has a provider but no model', function (): void {
    preflightReady();
    preflightTask('openai', null);

    expect(preflightLine(preflight()[1], 'provider.set'))->toContain('[FAIL]');
});

it('fails when another profile, or an extra tool on the target profile, holds the answer grant', function (): void {
    preflightReady();
    $tool = AgentToolDefinition::query()->where('tool_name', 'content.answer_posts')->firstOrFail();
    $other = AgentProfile::query()->where('slug', '!=', 'public-content-assistant')->firstOrFail();
    AgentProfileTool::query()->create(['agent_profile_id' => $other->getKey(), 'tool_definition_id' => $tool->getKey(), 'enabled' => true]);
    expect(preflightLine(preflight()[1], 'profile.grant'))->toContain('[FAIL]');
    AgentProfileTool::query()->where('agent_profile_id', $other->getKey())->where('tool_definition_id', $tool->getKey())->delete();
    expect(preflightLine(preflight()[1], 'profile.grant'))->toContain('[PASS]');

    $extra = AgentToolDefinition::query()->where('tool_name', '!=', 'content.answer_posts')->firstOrFail();
    $target = AgentProfile::query()->where('slug', 'public-content-assistant')->firstOrFail();
    AgentProfileTool::query()->create(['agent_profile_id' => $target->getKey(), 'tool_definition_id' => $extra->getKey(), 'enabled' => true]);
    expect(preflightLine(preflight()[1], 'profile.grant'))->toContain('[FAIL]');
});

it('fails when the tool has no enabled binding', function (): void {
    preflightReady();
    $tool = AgentToolDefinition::query()->where('tool_name', 'content.answer_posts')->firstOrFail();
    AgentToolBinding::query()->where('tool_definition_id', $tool->getKey())->update(['enabled' => false]);

    expect(preflightLine(preflight()[1], 'tool.binding'))->toContain('[FAIL]');
});

it('fails on a malformed approved map and on a digest that no longer matches the post', function (): void {
    preflightReady();
    $good = config('content.agent_answers.approved_posts');

    config()->set('content.agent_answers.approved_posts', $good + ['not-a-uuid' => 'abc']);
    expect(preflightLine(preflight()[1], 'corpus.map'))->toContain('[FAIL]');

    config()->set('content.agent_answers.approved_posts', $good);
    DB::table('posts')->where('uuid', PREFLIGHT_UUID)->update(['name' => 'Thay dầu nhớt (đã sửa)']);
    $line = preflightLine(preflight()[1], 'corpus.digests');
    expect($line)->toContain('[FAIL]')->and($line)->toContain(PREFLIGHT_UUID);

    // A draft is never served either, so its approval is stale too.
    DB::table('posts')->where('uuid', PREFLIGHT_UUID)->update(['name' => 'Thay dầu nhớt định kỳ', 'status' => StatusEnum::DRAFT->value]);
    expect(preflightLine(preflight()[1], 'corpus.digests'))->toContain('[FAIL]');
});

it('fails when the Telescope filter that drops the answer batches is not registered', function (): void {
    preflightReady();
    $saved = Telescope::$filterBatchUsing;
    Telescope::$filterBatchUsing = [];
    try {
        expect(preflightLine(preflight()[1], 'telescope.filter'))->toContain('[FAIL]');
    } finally {
        Telescope::$filterBatchUsing = $saved;
    }
});

it('fails when the answer task is not listed as a private-prompt task, so the call log would keep a prompt hash', function (): void {
    preflightReady();
    expect(preflightLine(preflight()[1], 'ai.private_prompt'))->toContain('[PASS]');

    config()->set('content.ai_runtime_private_prompt_tasks', []);
    expect(preflightLine(preflight()[1], 'ai.private_prompt'))->toContain('[FAIL]');
});

it('is read only and prints no credential or question text', function (): void {
    preflightReady();
    config()->set('services.openai.key', 'sk-canary-should-never-print');
    $before = [DB::table('agent_profiles')->count(), DB::table('roles')->count(), DB::table('posts')->count()];

    [, $out] = preflight();

    expect($out)->not->toContain('sk-canary')->and($out)->not->toContain('Thay dầu')
        ->and([DB::table('agent_profiles')->count(), DB::table('roles')->count(), DB::table('posts')->count()])->toBe($before);
});

it('rejects an unknown mode', function (): void {
    [$code, $out] = preflight('whatever');

    expect($code)->toBe(1)->and($out)->toContain('provisioned');
});
