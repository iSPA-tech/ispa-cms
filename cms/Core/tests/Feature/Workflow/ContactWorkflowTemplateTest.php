<?php

use App\Http\Controllers\Website\AjaxController;
use App\Models\Contact;
use App\Models\User;
use App\Events\PusherContactCreate;
use App\Events\SendConfirmToContactor;
use App\Services\SpamEmailBlocklistService;
use Cms\Core\Console\Commands\InstallContactWorkflowCommand;
use Cms\Core\Interfaces\FieldInterface;
use Cms\Core\Models\Email;
use Cms\Core\Models\Field;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Ai\Usage\AiGateway;
use Cms\Core\Services\Ai\AiRuntimeConfigResolver;
use Cms\Core\Services\Ai\AiTaskRuntime;
use Cms\Core\Services\Agentic\AgenticAiProviderFactory;
use Cms\Core\Agents\Contracts\AgenticAiProviderInterface;
use Cms\Core\Services\Workflow\Hub\WorkflowBundleBuilder;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;
use Cms\Core\Services\Workflow\Triggers\TriggerChain;
use Cms\Core\Services\Workflow\Triggers\TriggerIndex;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishPolicy;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Rollout T2: the "Liên hệ mới" template (cms:workflow-install-contact). A Contact created by the public contact form
 * starts one run that ends with one admin notice; the AI spam label is a setup choice that is off by default.
 * The test queue is `sync`, so the run executes right after the form's transaction commits.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    $this->withoutMiddleware();
    // Same SQLite FK note as WorkflowTriggersTest: deferred checks never run inside the rolled-back test transaction.
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    TriggerChain::reset();
    Cache::flush();
    Event::fake([PusherContactCreate::class, SendConfirmToContactor::class]);
    contactUser('placeholder'); // user id 1 is always a webmaster
});

function contactUser(string $name, array $permissions = []): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => $name,
        'username' => $name . '-' . Str::lower(Str::random(6)),
        'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 1,
        'level' => 0,
        'status' => null,
    ]);

    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        foreach ($permissions as $key) {
            [$group, $ability] = explode('.', $key, 2);
            $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

/** Fake AI provider: answers queued contents and records every payload. No network. */
final class ContactTemplateFakeAi implements AgenticAiProviderInterface
{
    /** @var list<array<string, mixed>> */
    public array $payloads = [];

    public function __construct(public array $answers = [])
    {
    }

    public function createCompletion(array $payload = []): array
    {
        $this->payloads[] = $payload;

        return ['choices' => [['message' => ['content' => array_shift($this->answers) ?? ''], 'finish_reason' => 'stop']], 'usage' => ['prompt_tokens' => 100, 'completion_tokens' => 10]];
    }

    public function getContent(array $response = []): string
    {
        return (string) ($response['choices'][0]['message']['content'] ?? '');
    }
}

function contactAi(array $answers = []): ContactTemplateFakeAi
{
    $fake = new ContactTemplateFakeAi($answers);
    app()->instance(AiTaskRuntime::class, new AiTaskRuntime(app(AiRuntimeConfigResolver::class), app(AgenticAiProviderFactory::class), $fake, app(AiGateway::class)));

    return $fake;
}

/**
 * Install, answer setup as $operator (notify `contacts.viewAny`, AI on or off) and publish + switch on. The run acts for
 * the operator, who must be allowed to read what it reads: the `retrieve` of the contact's Email needs `email.viewAny`.
 */
function contactTemplateReady(User $operator, bool $ai = false): WorkflowProject
{
    test()->artisan('cms:workflow-install-contact')->assertSuccessful();
    $project = WorkflowProject::query()->where('slug', InstallContactWorkflowCommand::PROJECT)->sole();

    test()->actingAs($operator);
    expect(app(WorkflowSetupService::class)->apply($project, [
        'notify_admins.permission' => 'contacts.viewAny',
        'use_ai.expression' => $ai,
    ], $operator))->toBe([]);

    \Cms\Core\Tests\Support\WorkflowTestVersions::publish($project->fresh());
    $project->refresh()->forceFill(['is_active' => true])->save();
    TriggerIndex::forget();

    return $project->fresh();
}

/** A visitor sends the public contact form through the site's own controller action (storeContact). */
function contactSubmit(string $subject = 'Hỏi lịch tĩnh tâm', string $email = 'lan@example.test'): void
{
    $form = new Field(['form_id' => 'contact']);
    $form->setRelation('items', collect());
    $fields = Mockery::mock(FieldInterface::class);
    $fields->shouldReceive('findBy')->andReturn($form);
    app()->instance(FieldInterface::class, $fields);
    $blocklist = Mockery::mock(SpamEmailBlocklistService::class);
    $blocklist->shouldReceive('isBlocked')->andReturn(false);
    app()->instance(SpamEmailBlocklistService::class, $blocklist);

    app()->instance('request', Request::create('/ajax/contact-request', 'POST', [
        'id' => 'contact', 'fullname' => 'Lan Nguyễn', 'email' => $email, 'mobile' => '09' . random_int(10000000, 99999999),
        'subject' => $subject, 'message' => "Cho con hỏi lịch.\nCảm ơn.",
    ], server: ['HTTP_REFERER' => 'https://acme.test/lien-he']));

    contactFreshRequest();
    $response = app(AjaxController::class)->storeContact();
    expect($response->toApiResponse()->getData(true)['status'] ?? null)->not->toBe('error');
}

test('the installer adds the template once: inactive, no schedule, Vietnamese names, two setup questions', function () {
    $this->artisan('cms:workflow-install-contact')->assertSuccessful();
    $project = WorkflowProject::query()->where('slug', InstallContactWorkflowCommand::PROJECT)->sole();
    $project->forceFill(['description' => 'Sửa tay'])->save();
    $this->artisan('cms:workflow-install-contact')->expectsOutputToContain('left unchanged')->assertSuccessful();

    $project->refresh();
    expect($project->is_active)->toBeFalse()
        ->and($project->schedule_cron)->toBeNull()
        ->and($project->name)->toBe('Liên hệ mới')
        ->and($project->description)->toBe('Sửa tay')
        ->and($project->steps()->orderBy('position_x')->pluck('name')->all())
        ->toBe(['Có liên hệ mới', 'Đọc tin nhắn vừa gửi', 'Dùng AI đánh dấu thư rác?', 'AI đánh dấu thư rác', 'Báo quản trị viên', 'AI cho là thư rác?', 'Trả lời tự động cho người gửi'])
        ->and($project->steps()->where('step_type', 'approval')->count())->toBe(0);

    // 2026.11.03: the auto-reply template ships as a `system` row of group `workflow`, created once and never
    // overwritten by a second run of the installer.
    $reply = \Cms\Core\Models\EmailTemplate::query()->where('group', 'workflow')->where('key_name', InstallContactWorkflowCommand::AUTO_REPLY_TEMPLATE)->sole();
    expect($reply->mail_class)->toBe('system');
    $reply->forceFill(['name' => 'Cảm ơn bạn'])->save();
    contactForget();
    $this->artisan('cms:workflow-install-contact')->assertSuccessful();
    expect(\Cms\Core\Models\EmailTemplate::query()->where('key_name', InstallContactWorkflowCommand::AUTO_REPLY_TEMPLATE)->pluck('name')->all())->toBe(['Cảm ơn bạn']);
    $project = WorkflowProject::query()->where('slug', InstallContactWorkflowCommand::PROJECT)->sole();
    $project->forceFill(['description' => 'Sửa tay'])->save();

    // Only who is told (open) and whether to use the AI label (answered: off). The AI step's labels stay out while off.
    $setup = app(WorkflowSetupService::class)->describe($project);
    expect(collect($setup['questions'])->map(fn ($q) => [$q['key'], $q['kind'], $q['answered']])->all())->toEqualCanonicalizing([
        ['notify_admins.permission', 'notify_channel', false],
        ['use_ai.expression', 'choice', true],
    ])->and(collect($setup['questions'])->firstWhere('kind', 'choice')['current'])->toBeFalse()
        ->and($setup['complete'])->toBeFalse();

    // A deleted template stays deleted.
    $project->delete();
    $this->artisan('cms:workflow-install-contact')->expectsOutputToContain('left deleted')->assertSuccessful();
    expect(WorkflowProject::withTrashed()->where('slug', InstallContactWorkflowCommand::PROJECT)->count())->toBe(1);
});

test('the guard allows the Contact trigger and the message read, never users; the graph passes the draft checks', function () {
    $guard = app(WorkflowCallableGuard::class);
    expect($guard->allowsModel(Contact::class))->toBeTrue()
        ->and($guard->allowsModel(Email::class))->toBeTrue()
        ->and($guard->allowsModel(User::class))->toBeFalse();

    $this->artisan('cms:workflow-install-contact')->assertSuccessful();
    $project = WorkflowProject::query()->where('slug', InstallContactWorkflowCommand::PROJECT)->sole();
    $webmaster = User::query()->findOrFail(1);
    $graph = WorkflowVersionSnapshot::fromDraft($project);
    $policy = app(WorkflowPublishPolicy::class);

    expect($policy->draftProblems($graph, $webmaster, $project))->toBe([])
        ->and($policy->publishProblems($project, $graph))->toBe([]);
});

test('a Contact sent through the site form starts one run that ends with one admin notice; the AI label stays off', function () {
    \Illuminate\Support\Facades\Mail::fake();
    $ai = contactAi();
    $operator = contactUser('operator', ['workflow_project.viewAny', 'workflow_project.update', 'workflow_project.publish', 'email.viewAny']);
    $staff = contactUser('staff', ['contacts.viewAny']);
    contactUser('other', ['posts.viewAny']);
    $project = contactTemplateReady($operator);
    expect($project->scheduled_by_user_id)->toBe($operator->id);
    auth()->logout();

    contactSubmit('Hỏi lịch tĩnh tâm');

    $run = WorkflowInstance::query()->where('project_id', $project->id)->sole();
    expect($run->status)->toBe('completed')
        ->and(data_get($run->context_snapshot, 'vars.notified.count'))->toBe(1)
        ->and(data_get($run->context_snapshot, 'vars.spam'))->toBeNull()
        ->and($ai->payloads)->toBe([]);

    $notices = DB::table('notifications')->where('notifiable_id', $staff->id)->get();
    expect($notices)->toHaveCount(1);
    $message = (string) data_get(json_decode($notices[0]->data, true), 'payload.message');
    expect($message)->toStartWith('Liên hệ mới từ Lan Nguyễn (biểu mẫu “contact”).')
        ->and($message)->toContain('Khách tự nhập, chưa kiểm tra: Hỏi lịch tĩnh tâm')
        ->and($message)->not->toContain('[AI');
    expect(DB::table('notifications')->where('notifiable_id', '!=', $staff->id)->count())->toBe(0);

    // 2026.11.03: the system auto-reply goes to the stored sender with no approval step; the name is escaped.
    expect(data_get($run->context_snapshot, 'vars.auto_reply'))->toMatchArray(['sent' => true, 'recipients' => 1, 'skipped' => false, 'mail_class' => 'system']);
    \Illuminate\Support\Facades\Mail::assertSent(\Cms\Core\Mail\WorkflowMessageMail::class, fn ($mail): bool => $mail->hasTo('lan@example.test')
        && str_contains($mail->render(), 'Chào Lan Nguyễn'));
    \Illuminate\Support\Facades\Mail::assertSentCount(1);
});

test('switched on at setup, the AI labels the message and the notice shows a spam label first', function () {
    $ai = contactAi([json_encode(['label' => 'spam', 'confidence' => 0.93])]);
    $operator = contactUser('operator', ['workflow_project.viewAny', 'workflow_project.update', 'workflow_project.publish', 'email.viewAny']);
    $staff = contactUser('staff', ['contacts.viewAny']);
    $project = contactTemplateReady($operator, true);
    auth()->logout();

    // With the branch on, the AI step's labels show as an (answered) setup question.
    expect(collect(app(WorkflowSetupService::class)->describe($project)['questions'])->pluck('key')->all())->toContain('spam_label.labels');

    contactSubmit('Mua backlink giá rẻ', 'seo@spam.test');

    $run = WorkflowInstance::query()->where('project_id', $project->id)->sole();
    expect($run->status)->toBe('completed')
        ->and(data_get($run->context_snapshot, 'vars.spam.label'))->toBe('spam')
        ->and($ai->payloads)->toHaveCount(1)
        // No auto-reply to a message the AI labelled spam.
        ->and(data_get($run->context_snapshot, 'vars.auto_reply'))->toBeNull();
    $message = (string) data_get(json_decode(DB::table('notifications')->where('notifiable_id', $staff->id)->value('data'), true), 'payload.message');
    expect($message)->toStartWith('[AI: có thể là thư rác] Liên hệ mới từ Lan Nguyễn');
});

test('the template builds as an unsigned hub bundle that asks who is told and whether to use the AI label', function () {
    $this->artisan('cms:workflow-install-contact')->assertSuccessful();

    $bundle = app(WorkflowBundleBuilder::class)->build(InstallContactWorkflowCommand::PROJECT, 'ispa.contact', 20261201, 'iSPA', group: 'notifications');
    expect(collect($bundle->summary['setup_questions'])->pluck('kind')->sort()->values()->all())->toBe(['choice', 'notify_channel'])
        ->and($bundle->requires['modules'] ?? [])->not->toContain('Webmail');

    $dir = sys_get_temp_dir() . '/contact-bundle-' . bin2hex(random_bytes(4));
    try {
        $this->artisan('cms:workflow-bundle-build', ['slug' => InstallContactWorkflowCommand::PROJECT, '--key' => 'ispa.contact', '--hub-version' => '2026.12.01', '--out' => $dir])
            ->expectsOutputToContain('Built ispa.contact version 2026.12.01')->assertExitCode(0);
        expect(is_file($dir . '/ispa.contact-20261201.workflow-bundle.json'))->toBeTrue()
            ->and(is_file($dir . '/ispa.contact-20261201.workflow-bundle.json.sig.json'))->toBeFalse();
    } finally {
        array_map('unlink', array_filter((array) glob($dir . '/*'), 'is_file'));
        @rmdir($dir);
    }
});

/*
 * Owner list item 12 (returning senders): the form updates an existing Contact when the email or phone matches, so a
 * "Contact created" trigger never fired for a returning sender. Template 2026.11.02 starts on the allowlisted app event
 * App\Events\ContactMessageReceived, fired once per stored message by both contact writers.
 */
const CONTACT_EVENT_KEYS = ['message_uuid', 'display_name', 'form', 'received_at'];

test('a returning sender\'s second message starts its own run and its own notice', function () {
    contactAi();
    $operator = contactUser('operator', ['workflow_project.viewAny', 'workflow_project.update', 'workflow_project.publish', 'email.viewAny']);
    $staff = contactUser('staff', ['contacts.viewAny']);
    $project = contactTemplateReady($operator);
    auth()->logout();

    contactSubmit('Hỏi lịch tĩnh tâm', 'lan@example.test');
    contactSubmit('Hỏi thêm giờ lễ', 'lan@example.test');

    expect(Contact::query()->count())->toBe(1);
    $runs = WorkflowInstance::query()->where('project_id', $project->id)->orderBy('id')->get();
    expect($runs)->toHaveCount(2)
        ->and($runs->pluck('status')->unique()->all())->toBe(['completed'])
        // Abuse guard: one auto-reply per address per day; the second run records why it sent nothing.
        ->and(data_get($runs[0]->context_snapshot, 'vars.auto_reply.sent'))->toBeTrue()
        ->and(data_get($runs[1]->context_snapshot, 'vars.auto_reply'))->toMatchArray(['sent' => false, 'skipped' => true, 'skip_reason' => 'rate_limited']);

    // Notification ids are uuids (no send order), so match each message by its subject.
    $messages = DB::table('notifications')->where('notifiable_id', $staff->id)->pluck('data')
        ->map(fn ($data) => (string) data_get(json_decode($data, true), 'payload.message'));
    $second = $messages->first(fn (string $m) => str_contains($m, 'Hỏi thêm giờ lễ'));
    expect($messages)->toHaveCount(2)
        ->and($messages->filter(fn (string $m) => str_contains($m, 'Hỏi lịch tĩnh tâm')))->toHaveCount(1)
        ->and($second)->toStartWith('Liên hệ mới từ Lan Nguyễn (biểu mẫu “contact”).');
});

test('every stored message fires one clean event: no e-mail, phone or message text in the payload', function () {
    $captured = [];
    Event::listen(\App\Events\ContactMessageReceived::class, function ($event) use (&$captured) {
        $captured[] = get_object_vars($event);
    });

    contactSubmit('Hỏi lịch tĩnh tâm', 'lan@example.test');
    contactSubmit('Hỏi thêm giờ lễ', 'lan@example.test');

    // The shared writer used by project and API forms (cms.ispa.io, the pilot, goes through it).
    $blocklist = Mockery::mock(SpamEmailBlocklistService::class);
    $blocklist->shouldReceive('isBlocked')->andReturn(false);
    app()->instance(SpamEmailBlocklistService::class, $blocklist);
    contactFreshRequest();
    // Built at runtime like the other fixture numbers, so the export scan finds no phone-shaped literal.
    $phone = '09' . random_int(10000000, 99999999);
    app(\App\Services\ContactSubmissionService::class)->submit([
        'name' => 'Bình Trần', 'email' => 'binh@example.test', 'phone_number' => $phone,
        'subject' => 'Xin báo giá', 'message' => 'Nội dung riêng tư', 'source' => 'demo',
    ]);

    expect($captured)->toHaveCount(3);
    $uuids = Email::query()->where('type', 'inbox')->orderBy('id')->pluck('uuid')->all();
    foreach ($captured as $i => $payload) {
        expect(array_keys($payload))->toBe(CONTACT_EVENT_KEYS)
            ->and($payload['message_uuid'])->toBe($uuids[$i])
            ->and(strtotime($payload['received_at']))->not->toBeFalse();
        $encoded = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
        foreach (['lan@example.test', 'binh@example.test', $phone, 'Hỏi lịch', 'Xin báo giá', 'Nội dung riêng tư', 'Cho con hỏi'] as $secret) {
            expect($encoded)->not->toContain($secret);
        }
    }
    expect($captured[0]['display_name'])->toBe('Lan Nguyễn')
        ->and($captured[0]['form'])->toBe('contact')
        ->and($captured[2]['display_name'])->toBe('Bình Trần')
        ->and($captured[2]['form'])->toBe('demo');

    // Start only: no step may fire it.
    $guard = app(WorkflowCallableGuard::class);
    expect($guard->allowsTriggerEvent(\App\Events\ContactMessageReceived::class))->toBeTrue()
        ->and($guard->allowsCallable('event', \App\Events\ContactMessageReceived::class, null))->toBeFalse();
});

test('a site on the first template version updates in place to 2026.11.02, then 2026.11.03, and keeps its setup answers', function () {
    $webmaster = User::query()->findOrFail(1);
    contactUser('staff', ['contacts.viewAny']);
    test()->actingAs($webmaster);
    $build = app(WorkflowBundleBuilder::class);

    // 2026.11.01 = the first graph: started on the Contact model event, read the message by contact id.
    test()->artisan('cms:workflow-install-contact')->assertSuccessful();
    $old = WorkflowProject::query()->where('slug', InstallContactWorkflowCommand::PROJECT)->sole();
    $start = $old->steps()->where('is_start', true)->sole();
    $start->forceFill(['step_code' => 'contact_created', 'step_type' => 'trigger_model_event', 'step_config' => [
        'model' => Contact::class, 'events' => ['created'], 'fields' => [], 'filter' => [], 'debounce_seconds' => 0, 'storeAs' => 'vars.trigger',
    ]])->save();
    contactDropAutoReply();
    $v1 = $build->build(InstallContactWorkflowCommand::PROJECT, 'ispa.contact', 20261101, 'iSPA', group: 'notifications');
    contactForget();

    // 2026.11.02 = the app event start, before the auto-reply.
    test()->artisan('cms:workflow-install-contact')->assertSuccessful();
    contactDropAutoReply();
    $v2 = $build->build(InstallContactWorkflowCommand::PROJECT, 'ispa.contact', 20261102, 'iSPA', group: 'notifications');
    contactForget();

    // 2026.11.03 = the current graph (auto-reply). The site has no e-mail template rows yet.
    test()->artisan('cms:workflow-install-contact')->assertSuccessful();
    $v3 = $build->build(InstallContactWorkflowCommand::PROJECT, 'ispa.contact', 20261103, 'iSPA', group: 'notifications');
    contactForget();
    \Cms\Core\Models\EmailTemplate::withTrashed()->where('group', 'workflow')->forceDelete();

    $hub = new class(app(\Cms\Core\Services\Workflow\Hub\WorkflowBundleVerifier::class), app(\Cms\Core\Services\Workflow\Hub\WorkflowTemplateHash::class)) extends \Cms\Core\Services\Workflow\Hub\WorkflowHubCatalog {
        public ?\Cms\Core\Services\Workflow\Hub\WorkflowBundle $served = null;

        public function verifiedBundle(string $key): array
        {
            return ['bundle' => $this->served];
        }

        public function assertNotOlderThanAdvertised(\Cms\Core\Services\Workflow\Hub\WorkflowBundle $bundle): void
        {
        }

        public function missingModules(array $modules): array
        {
            return [];
        }
    };
    app()->instance(\Cms\Core\Services\Workflow\Hub\WorkflowHubCatalog::class, $hub);
    $installer = app(\Cms\Core\Services\Workflow\Hub\WorkflowHubInstaller::class);

    $hub->served = $v1;
    $first = $installer->install('ispa.contact', $webmaster);
    $project = WorkflowProject::query()->findOrFail($first['project_id']);
    expect(app(WorkflowSetupService::class)->apply($project, ['notify_admins.permission' => 'contacts.viewAny', 'use_ai.expression' => true], $webmaster))->toBe([]);

    $hub->served = $v2;
    $result = $installer->install('ispa.contact', $webmaster);
    expect($result)->toMatchArray(['project_id' => $project->id, 'updated_in_place' => true, 'version' => 20261102]);

    $steps = WorkflowStep::query()->where('project_id', $project->id)->get()->keyBy('step_code');
    expect($steps->keys()->sort()->values()->all())->toBe(['load_message', 'message_received', 'notify_admins', 'spam_label', 'use_ai'])
        ->and($steps['message_received']->step_type)->toBe('trigger_event')
        ->and($steps['message_received']->is_start)->toBeTrue()
        ->and($steps['message_received']->step_config['event'])->toBe(\App\Events\ContactMessageReceived::class)
        ->and($steps['notify_admins']->step_config['permission'])->toBe('contacts.viewAny')
        ->and(in_array($steps['use_ai']->step_config['expression'], [true, 'true'], true))->toBeTrue()
        ->and(\Cms\Core\Models\EmailTemplate::query()->where('group', 'workflow')->count())->toBe(0);

    $hub->served = $v3;
    expect($installer->install('ispa.contact', $webmaster))->toMatchArray(['project_id' => $project->id, 'updated_in_place' => true, 'version' => 20261103]);
    $steps = WorkflowStep::query()->where('project_id', $project->id)->get()->keyBy('step_code');
    expect($steps->keys()->sort()->values()->all())->toBe(['auto_reply', 'is_spam', 'load_message', 'message_received', 'notify_admins', 'spam_label', 'use_ai'])
        ->and($steps['auto_reply']->step_config)->toMatchArray(['mode' => 'template', 'template_key' => 'contact_auto_reply', 'recipient' => 'trigger_subject', '_v' => 2])
        ->and($steps['notify_admins']->step_config['permission'])->toBe('contacts.viewAny')
        ->and(in_array($steps['use_ai']->step_config['expression'], [true, 'true'], true))->toBeTrue();
    // The update created the default auto-reply row the new step needs.
    expect(\Cms\Core\Models\EmailTemplate::query()->where('group', 'workflow')->where('key_name', 'contact_auto_reply')->pluck('mail_class')->unique()->all())->toBe(['system']);
});

/** The graph before 2026.11.03: no spam check and no auto-reply. */
function contactDropAutoReply(): void
{
    $id = WorkflowProject::query()->where('slug', InstallContactWorkflowCommand::PROJECT)->value('id');
    $ids = WorkflowStep::query()->where('project_id', $id)->whereIn('step_code', ['is_spam', 'auto_reply'])->pluck('id');
    \Cms\Core\Models\Workflows\WorkflowConnection::query()->where('project_id', $id)
        ->where(fn ($q) => $q->whereIn('from_step_id', $ids)->orWhereIn('to_step_id', $ids))->forceDelete();
    WorkflowStep::query()->whereIn('id', $ids)->forceDelete();
}

/** Remove the template's project for good (a bundle source must not stay on the "site"). */
function contactForget(): void
{
    $id = WorkflowProject::withTrashed()->where('slug', InstallContactWorkflowCommand::PROJECT)->value('id');
    \Cms\Core\Models\Workflows\WorkflowConnection::withTrashed()->where('project_id', $id)->forceDelete();
    WorkflowStep::withTrashed()->where('project_id', $id)->forceDelete();
    WorkflowProject::withTrashed()->whereKey($id)->forceDelete();
}

/**
 * The repositories are singletons that keep their model instance, so a second submission in the same process would
 * update the first message row; on a site every submission is its own request. Start each one fresh.
 */
function contactFreshRequest(): void
{
    app(\Cms\Core\Interfaces\EmailInterface::class)->reset();
    app(\App\Repositories\Interfaces\ContactInterface::class)->reset();
}

/*
 * 2026-11-25 hub publish check: a site from before allowlisted app events (trigger_event v1) must refuse this template
 * on purpose, not by accident. The bundle declares trigger_event config version 2, which such a site answers with
 * `node_version_unsupported`; a newer site where the event is not on the trigger_events allowlist (its module off)
 * answers `event_unavailable`.
 */
test('the bundle declares trigger_event config version 2 so a site with the version 1 node refuses it', function () {
    $this->artisan('cms:workflow-install-contact')->assertSuccessful();

    $bundle = app(WorkflowBundleBuilder::class)->build(InstallContactWorkflowCommand::PROJECT, 'ispa.contact', 20261201, 'iSPA', group: 'notifications');

    expect(\Cms\Core\Workflows\Nodes\NodeRegistry::current()->get('trigger_event')->version)->toBe(2)
        ->and($bundle->requires['node_types']['trigger_event'] ?? null)->toBe(2)
        ->and(data_get($bundle->package, 'manifest.node_types.trigger_event'))->toBe(2)
        // 2026.11.03: the auto-reply step needs email_send version 2 (template mode), so a site without it refuses.
        ->and($bundle->requires['node_types']['email_send'] ?? null)->toBe(2);
});

test('a site where the trigger event is not allowlisted refuses the package with event_unavailable', function () {
    $this->artisan('cms:workflow-install-contact')->assertSuccessful();
    $bundle = app(WorkflowBundleBuilder::class)->build(InstallContactWorkflowCommand::PROJECT, 'ispa.contact', 20261201, 'iSPA', group: 'notifications');
    $inspector = app(\Cms\Core\Services\Workflow\Package\WorkflowPackageInspector::class);

    $codes = static fn (array $report): array => array_column($report['problems'], 'code');
    expect($codes($inspector->inspect($bundle->package, null, [], 'contact-copy', null, false)))->not->toContain('event_unavailable');

    config()->set('core.workflow_security.trigger_events', []);
    config()->set('core.workflow_security.events', []);
    $report = $inspector->inspect($bundle->package, null, [], 'contact-copy', null, false);

    expect($report['valid'])->toBeFalse()
        ->and($codes($report))->toContain('event_unavailable');
});

test('a stored trigger_event config without a version still validates and upgrades to version 2', function () {
    $config = ['event' => \App\Events\ContactMessageReceived::class];
    $problems = (new \Cms\Core\Workflows\Nodes\NodeConfigValidator())->validate(
        [['step_code' => 'start', 'step_type' => 'trigger_event', 'step_config' => $config]], [], null
    );

    expect($problems)->toBe([])
        ->and((new \Cms\Core\Workflows\Upgrade\StepConfigUpgrader())->upgrade($config, 'trigger_event')['config']['_v'] ?? null)->toBe(2);
});
