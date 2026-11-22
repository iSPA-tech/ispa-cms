<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Support\Modules\ModuleStatus;
use Cms\Core\Models\Agents\AgentProfile;
use Cms\Core\Models\Agents\AgentProfileTool;
use Cms\Core\Models\Agents\AgentToolBinding;
use Cms\Core\Models\Agents\AgentToolDefinition;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiTaskCatalog;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;
use Throwable;

/**
 * Read-only check of every activation condition of the authenticated content answer pilot.
 *
 * Prints one `[PASS]` or `[FAIL]` line per condition and exits 1 when any fails. It writes nothing and prints no
 * secret: no key, no token, no question or document text, only names, states and post uuids.
 *
 * Modes (what the feature switch and the profile must look like):
 * - `provisioned`: switch off, profile inactive; provider, project and approved posts may still be blank.
 * - `activation-ready` (default): switch off, profile active, everything else ready.
 * - `active`: as activation-ready with the switch on.
 *
 * Content classes are reached by name: Core does not depend on Content, a site without Content fails the first line.
 */
final class AgentAnswerPreflightCommand extends Command
{
    private const MODES = ['provisioned', 'activation-ready', 'active'];

    private const PROFILE = 'public-content-assistant';

    private const TOOL = 'content.answer_posts';

    private const TASK = 'content_agent_answer';

    private const UUID = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\z/';

    private const DIGEST = '/\A[0-9a-f]{64}\z/';

    private const PROJECTOR = 'Cms\\Content\\Services\\Agentic\\AgentReadablePostProjector';

    private const POST = 'Cms\\Content\\Models\\Post';

    private const ENDPOINT = '/api/v2/public/agent/answers';

    protected $signature = 'cms:agent-answer-preflight {--mode=activation-ready : provisioned, activation-ready or active}';

    protected $description = 'Read-only PASS/FAIL check of every activation condition of the content answer pilot.';

    private bool $failed = false;

    public function handle(AiRuntimeConfigResolver $runtime): int
    {
        $mode = (string) $this->option('mode');
        if (! in_array($mode, self::MODES, true)) {
            $this->error('Unknown mode "' . $mode . '". Use ' . implode(', ', self::MODES) . '.');

            return self::FAILURE;
        }
        $strict = $mode !== 'provisioned';
        $this->failed = false;

        $this->line('Agent answer preflight, mode ' . $mode);
        $this->checkContent();
        $this->checkToolAndProfile($mode);
        $this->checkPermission($strict);
        $this->checkFlags($mode, $strict);
        $this->checkProvider($runtime, $strict);
        $this->checkPrivatePrompt();
        $this->checkCorpus($strict);
        $this->checkTelescope();
        $this->line($this->failed ? 'Preflight FAILED.' : 'Preflight passed.');

        return $this->failed ? self::FAILURE : self::SUCCESS;
    }

    private function report(bool $ok, string $id, string $detail): void
    {
        $this->failed = $this->failed || ! $ok;
        $this->line(($ok ? '[PASS] ' : '[FAIL] ') . $id . ': ' . $detail);
    }

    private function checkContent(): void
    {
        $this->report(ModuleStatus::isEnabled('Content') && class_exists(self::PROJECTOR), 'module.content', 'Content module active');
    }

    private function checkToolAndProfile(string $mode): void
    {
        $tool = AgentToolDefinition::query()->where('tool_name', self::TOOL)->first();
        $this->report($tool !== null && $tool->status === 'active', 'tool.definition', $tool === null ? self::TOOL . ' is not registered (run the profile seeder)' : self::TOOL . ' is ' . $tool->status);
        $bound = $tool !== null && AgentToolBinding::query()->where('tool_definition_id', $tool->getKey())->where('enabled', true)->exists();
        $this->report($bound, 'tool.binding', $bound ? 'an enabled binding exists' : 'no enabled binding for ' . self::TOOL);

        $profile = AgentProfile::query()->where('slug', self::PROFILE)->first();
        if ($profile === null) {
            $this->report(false, 'profile.state', self::PROFILE . ' does not exist (run the profile seeder)');
            $this->report(false, 'profile.grant', 'no profile, no grant');

            return;
        }
        $expectActive = $mode !== 'provisioned';
        $this->report(($profile->status === 'active') === $expectActive, 'profile.state', 'status is ' . $profile->status . ', expected ' . ($expectActive ? 'active' : 'not active'));

        $grants = AgentProfileTool::query()->where('agent_profile_id', $profile->getKey())->where('enabled', true)->with('definition')->get()
            ->map(static fn (AgentProfileTool $grant): string => (string) $grant->definition?->tool_name)->sort()->values()->all();
        $others = $tool === null ? [] : AgentProfileTool::query()->where('tool_definition_id', $tool->getKey())->where('enabled', true)
            ->where('agent_profile_id', '!=', $profile->getKey())->with('profile')->get()
            ->map(static fn (AgentProfileTool $grant): string => (string) $grant->profile?->slug)->all();
        $this->report(
            $grants === [self::TOOL] && $others === [],
            'profile.grant',
            $grants === [self::TOOL] && $others === []
                ? 'only ' . self::TOOL . ' is granted, to this profile only'
                : 'grants of the profile: [' . implode(', ', $grants) . ']; other profiles with ' . self::TOOL . ': [' . implode(', ', $others) . ']',
        );
    }

    private function checkPermission(bool $strict): void
    {
        $permission = Permission::query()->where('group', 'agent_profile')->where('name', 'answer')->first();
        if ($permission === null) {
            $this->report(false, 'permission.exists', 'agent_profile.answer is missing (run the permission seeder)');
            $this->report(! $strict, 'permission.role', 'no permission to grant');

            return;
        }
        $this->report(true, 'permission.exists', 'agent_profile.answer exists');
        $roles = Role::query()->whereHas('permissions', static fn ($query) => $query->whereKey($permission->getKey()))->pluck('name')->all();
        $this->report($roles !== [] || ! $strict, 'permission.role', $roles === [] ? 'no role has agent_profile.answer' : 'granted to ' . count($roles) . ' role(s): ' . implode(', ', $roles));
    }

    private function checkFlags(string $mode, bool $strict): void
    {
        $enabled = config('core.agent_answers.enabled') === true;
        $this->report($enabled === ($mode === 'active'), 'flag.enabled', 'AGENT_ANSWERS_ENABLED is ' . ($enabled ? 'true' : 'false') . ', expected ' . ($mode === 'active' ? 'true' : 'false'));

        $project = (string) config('ispa-cms.folder_name');
        $allowed = in_array($project, (array) config('core.agent_answers.allowed_projects', []), true);
        $this->report($allowed || ! $strict, 'flag.project', 'project "' . $project . '" is ' . ($allowed ? '' : 'not ') . 'in AGENT_ANSWERS_PROJECTS');
    }

    private function checkProvider(AiRuntimeConfigResolver $runtime, bool $strict): void
    {
        $task = $runtime->taskOverrides(self::TASK);
        $provider = $task['provider'];
        $model = $task['model'];
        $this->report(($provider !== null && $model !== null) || ! $strict, 'provider.set', 'task ' . self::TASK . ' provider ' . ($provider ?? '(blank)') . ', model ' . ($model ?? '(blank)'));

        if ($provider === null) {
            $this->report(! $strict, 'provider.allowed', 'no provider chosen');

            return;
        }
        $allowed = in_array($provider, (array) config('core.agent_answers.allowed_providers', []), true);
        $detail = $allowed ? $provider . ' is allowed' : (strtolower($provider) === 'anthropic'
            ? 'anthropic is refused until its adapter stops logging raw exception messages'
            : $provider . ' is not allowed (allowed: ' . implode(', ', (array) config('core.agent_answers.allowed_providers', [])) . ')');
        $this->report($allowed, 'provider.allowed', $detail);
    }

    /** The AI call log stores no prompt hash for a private-prompt task; the answer task must be one. */
    private function checkPrivatePrompt(): void
    {
        $private = app(AiTaskCatalog::class)->isPromptPrivate(self::TASK);
        $this->report($private, 'ai.private_prompt', self::TASK . ($private ? ' is a private-prompt task: core_ai_call_logs keeps no prompt_hash for it' : ' is not listed in ai_runtime_private_prompt_tasks: the call log would keep a prompt_hash of the question'));
    }

    private function checkCorpus(bool $strict): void
    {
        $map = config('content.agent_answers.approved_posts', []);
        $problems = [];
        if (! is_array($map)) {
            $problems[] = 'not a JSON object';
            $map = [];
        }
        foreach ($map as $uuid => $digest) {
            if (! is_string($uuid) || preg_match(self::UUID, strtolower($uuid)) !== 1 || ! is_string($digest) || preg_match(self::DIGEST, $digest) !== 1) {
                $problems[] = 'bad entry ' . (is_string($uuid) ? '"' . $uuid . '"' : '(non-string key)');
            }
        }
        if ($map === [] && $problems === []) {
            $this->report(! $strict, 'corpus.map', 'CONTENT_AGENT_ANSWER_APPROVED_POSTS is empty');
        } else {
            $this->report($problems === [], 'corpus.map', $problems === [] ? count($map) . ' approved post(s), every entry well formed' : implode('; ', $problems) . ' (one bad entry voids the whole map)');
        }
        if ($map === [] || $problems !== []) {
            $this->report(! $strict && $problems === [], 'corpus.digests', 'nothing to compare');

            return;
        }

        $stale = [];
        foreach ($map as $uuid => $digest) {
            $stale[] = $this->staleReason(strtolower($uuid), (string) $digest, $uuid);
        }
        $stale = array_values(array_filter($stale));
        $this->report($stale === [], 'corpus.digests', $stale === [] ? 'every approved digest matches its post now' : implode('; ', $stale));
    }

    /** Why the approval of this post no longer works, or null when the corpus would accept it now. */
    private function staleReason(string $uuid, string $digest, string $label): ?string
    {
        if (! class_exists(self::POST) || ! class_exists(self::PROJECTOR)) {
            return $label . ' cannot be checked (Content is missing)';
        }
        try {
            $post = (self::POST)::query()->withoutGlobalScope((self::POST)::APPENDED_RELATIONS_SCOPE)->with(['categories.slugs', 'language'])->where('uuid', $uuid)->first();
            if ($post === null) {
                return $label . ' no such post';
            }
            $public = (self::POST)::query()->withoutGlobalScope((self::POST)::APPENDED_RELATIONS_SCOPE)->publicAt(now())->whereKey($post->getKey())->exists();
            if ($post->type !== 'post' || ! $public) {
                return $label . ' is not a published post any more';
            }

            return hash_equals($digest, (string) app(self::PROJECTOR)->project($post)['content_digest']) ? null : $label . ' changed since it was approved (digest differs)';
        } catch (Throwable) {
            return $label . ' could not be read';
        }
    }

    private function checkTelescope(): void
    {
        $telescope = 'Laravel\\Telescope\\Telescope';
        if (! class_exists($telescope)) {
            $this->report(true, 'telescope.filter', 'Telescope is not installed, nothing records the request');

            return;
        }
        $entry = new \Laravel\Telescope\IncomingEntry(['uri' => 'https://site.example' . self::ENDPOINT]);
        $entry->type(\Laravel\Telescope\EntryType::REQUEST);
        $drops = false;
        foreach ((array) $telescope::$filterBatchUsing as $filter) {
            try {
                if ($filter(new Collection([$entry])) === false) {
                    $drops = true;
                }
            } catch (Throwable) {
                // A filter that throws does not protect the route.
            }
        }
        $this->report($drops, 'telescope.filter', $drops ? 'a batch filter drops the answer endpoint batches' : 'no Telescope batch filter drops the answer endpoint batches');
    }
}
