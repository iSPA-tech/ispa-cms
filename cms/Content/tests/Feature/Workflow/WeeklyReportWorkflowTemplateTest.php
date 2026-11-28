<?php

declare(strict_types=1);

use App\Models\User;
use Carbon\Carbon;
use Cms\Content\Console\Commands\InstallWeeklyReportWorkflowCommand;
use Cms\Content\Models\Post;
use Cms\Core\Models\Email;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowInstance;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Hub\WorkflowBundleBuilder;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;
use Cms\Core\Services\Workflow\Versioning\WorkflowPublishPolicy;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Cms\Core\Services\Workflow\WorkflowProjectRunService;
use Cms\Core\Workflows\Security\WorkflowCallableGuard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Rollout 2026-11-27 Task R: the "Báo cáo tuần" template (content:workflow-install-weekly-report, hub key
 * ispa.weekly-report). On the site's schedule it counts the contact messages and published posts of the last 7 days
 * and tells the admins chosen at setup; a week with nothing new sends nothing. The test queue is `sync`, so a
 * scheduled run executes at once.
 */

uses(\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function () {
    // Same SQLite FK note as WorkflowTriggersTest: deferred checks never run inside the rolled-back test transaction.
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    Cache::flush();
    Carbon::setTestNow('2026-11-23 07:00:00'); // a Monday
    reportUser('placeholder'); // user id 1 is always a webmaster
});

afterEach(fn () => Carbon::setTestNow());

function reportUser(string $name, array $permissions = []): User
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

/** The user who answers setup and whom the runs act for: may run workflows and read messages and posts. */
function reportOperator(): User
{
    return reportUser('operator', ['workflow_project.viewAny', 'workflow_project.update', 'workflow_project.publish', 'email.viewAny', 'post.viewAny']);
}

/** A stored contact form message (Core `emails`, type inbox) received at $at. */
function reportMessage(string $at, string $type = 'inbox', ?string $deletedAt = null): void
{
    DB::table('emails')->insert([
        'uuid' => (string) Str::uuid(), 'subject' => 'Hỏi lịch tĩnh tâm', 'name' => 'Lan Nguyễn', 'email' => 'lan@example.test',
        'content' => 'Cho con hỏi lịch.', 'type' => $type, 'created_at' => $at, 'updated_at' => $at, 'deleted_at' => $deletedAt,
    ]);
}

function reportPost(string $name, string $status, ?string $publishedAt): void
{
    DB::table('posts')->insert([
        'uuid' => (string) Str::uuid(), 'name' => $name, 'type' => 'article', 'status' => $status,
        'published_at' => $publishedAt, 'created_at' => '2026-11-01 08:00:00', 'updated_at' => '2026-11-01 08:00:00',
    ]);
}

/** Install, answer setup as $operator (Mondays 07:00, notify `contacts.viewAny`), publish and switch on. */
function reportTemplateReady(User $operator): WorkflowProject
{
    test()->artisan('content:workflow-install-weekly-report')->assertSuccessful();
    $project = WorkflowProject::query()->where('slug', InstallWeeklyReportWorkflowCommand::PROJECT)->sole();

    test()->actingAs($operator);
    expect(app(WorkflowSetupService::class)->apply($project, [
        'schedule' => ['cron' => '0 7 * * 1', 'timezone' => null],
        'notify_admins.permission' => 'contacts.viewAny',
    ], $operator))->toBe([]);

    \Cms\Core\Tests\Support\WorkflowTestVersions::publish($project->fresh());
    $project->refresh()->forceFill(['is_active' => true])->save();

    return $project->fresh();
}

/** @return list<string> */
function reportNotices(User $user): array
{
    return DB::table('notifications')->where('notifiable_id', $user->id)->pluck('data')
        ->map(fn ($data) => (string) data_get(json_decode($data, true), 'payload.message'))->all();
}

test('the installer adds the template once: inactive, no schedule, Vietnamese names, setup asks when and who', function () {
    $this->artisan('content:workflow-install-weekly-report')->assertSuccessful();
    $project = WorkflowProject::query()->where('slug', InstallWeeklyReportWorkflowCommand::PROJECT)->sole();
    $project->forceFill(['description' => 'Sửa tay'])->save();
    $this->artisan('content:workflow-install-weekly-report')->expectsOutputToContain('left unchanged')->assertSuccessful();

    $project->refresh();
    expect($project->is_active)->toBeFalse()
        ->and($project->schedule_cron)->toBeNull()
        ->and($project->name)->toBe('Báo cáo tuần')
        ->and($project->description)->toBe('Sửa tay')
        ->and($project->steps()->orderBy('position_y')->pluck('name')->all())
        ->toBe(['Mỗi tuần', 'Tìm tin liên hệ 7 ngày qua', 'Tìm bài viết mới đăng 7 ngày qua', 'Có gì mới?', 'Báo quản trị viên'])
        ->and($project->steps()->whereIn('step_type', ['approval', 'email_send', 'change', 'create_object', 'delete_object'])->count())->toBe(0);

    $setup = app(WorkflowSetupService::class)->describe($project);
    expect(collect($setup['questions'])->map(fn ($q) => [$q['key'], $q['kind'], $q['answered']])->all())->toEqualCanonicalizing([
        ['notify_admins.permission', 'notify_channel', false],
        ['schedule', 'schedule', false],
    ])->and($setup['complete'])->toBeFalse();

    // A deleted template stays deleted.
    $project->delete();
    $this->artisan('content:workflow-install-weekly-report')->expectsOutputToContain('left deleted')->assertSuccessful();
    expect(WorkflowProject::withTrashed()->where('slug', InstallWeeklyReportWorkflowCommand::PROJECT)->count())->toBe(1);
});

test('the installer lays the graph out top to bottom with each branch in the order of its outputs, as the designer tidies it', function () {
    $this->artisan('content:workflow-install-weekly-report')->assertSuccessful();
    $at = WorkflowProject::query()->where('slug', InstallWeeklyReportWorkflowCommand::PROJECT)->sole()->steps()->get()->keyBy('step_code');

    $children = [];
    foreach (InstallWeeklyReportWorkflowCommand::edges() as [$from, $to]) {
        // Links enter a card at the top and leave from its bottom: every link goes down.
        expect($at[$to]->position_y)->toBeGreaterThan($at[$from]->position_y, "{$from} -> {$to}");
        $children[$from][] = $to;
    }
    foreach ($children as $from => $next) {
        $xs = array_map(static fn (string $code): int => (int) $at[$code]->position_x, $next);
        $sorted = $xs;
        sort($sorted);
        expect($xs)->toBe($sorted, "outputs of {$from}");
    }
    // A single next step stays in the same column, on the designer's origin.
    expect($at->pluck('position_x')->unique()->values()->all())->toBe([40])
        ->and((int) $at['schedule']->position_y)->toBe(32);
});

test('the guard allows messages and posts, never users; the graph passes the draft checks', function () {
    $guard = app(WorkflowCallableGuard::class);
    expect($guard->allowsModel(Email::class))->toBeTrue()
        ->and($guard->allowsModel(Post::class))->toBeTrue()
        ->and($guard->allowsModel(User::class))->toBeFalse();

    $this->artisan('content:workflow-install-weekly-report')->assertSuccessful();
    $project = WorkflowProject::query()->where('slug', InstallWeeklyReportWorkflowCommand::PROJECT)->sole();
    $graph = WorkflowVersionSnapshot::fromDraft($project);
    $policy = app(WorkflowPublishPolicy::class);

    expect($policy->draftProblems($graph, reportOperator(), $project))->toBe([])
        ->and($policy->publishProblems($project, $graph))->toBe([]);
});

test('runs act for the person who set the schedule, who must be allowed to read messages and posts', function () {
    $staff = reportUser('staff', ['contacts.viewAny']);
    reportMessage('2026-11-22 20:15:00');
    // May run workflows and read messages, but not posts: the post step is refused and nobody is told.
    $project = reportTemplateReady(reportUser('operator', ['workflow_project.viewAny', 'workflow_project.update', 'workflow_project.publish', 'email.viewAny']));
    auth()->logout();

    app(WorkflowProjectRunService::class)->runDue(now());

    $run = WorkflowInstance::query()->where('project_id', $project->id)->sole();
    expect($run->status)->toBe('failed')
        ->and(reportNotices($staff))->toBe([]);
});

test('a scheduled run counts the last 7 days of messages and published posts and tells only the chosen admins', function () {
    $staff = reportUser('staff', ['contacts.viewAny']);
    reportUser('other', ['posts.viewAny']);
    reportMessage('2026-11-22 20:15:00');
    reportMessage('2026-11-17 09:00:00');
    reportMessage('2026-11-15 09:00:00'); // 8 days ago
    reportMessage('2026-11-20 09:00:00', 'sent'); // a reply, not a message
    reportMessage('2026-11-21 09:00:00', 'inbox', '2026-11-21 10:00:00'); // removed
    reportPost('Mừng lễ Chúa Kitô Vua', 'status_published', '2026-11-22 06:00:00');
    reportPost('Tin cũ chưa đổi khoá trạng thái', 'published', '2026-11-19 06:00:00');
    reportPost('Bản nháp', 'status_draft', '2026-11-21 06:00:00');
    reportPost('Tuần trước nữa', 'status_published', '2026-11-13 06:00:00');
    reportPost('Hẹn giờ đăng', 'status_published', '2026-11-24 06:00:00');
    $project = reportTemplateReady(reportOperator());
    auth()->logout();

    expect(app(WorkflowProjectRunService::class)->runDue(now())['started'])->toBe([$project->id]);

    $run = WorkflowInstance::query()->where('project_id', $project->id)->sole();
    expect($run->status)->toBe('completed')
        ->and(data_get($run->context_snapshot, 'vars.notified.count'))->toBe(1);
    $notices = reportNotices($staff);
    expect($notices)->toBe(['7 ngày qua (16/11 – 23/11): 2 tin liên hệ mới, 2 bài viết mới đăng. Xem ở mục Liên hệ và Bài viết trong trang quản trị.'])
        ->and(DB::table('notifications')->where('notifiable_id', '!=', $staff->id)->count())->toBe(0);
    // Counts only: no sender, address or message text in the notice.
    expect($notices[0])->not->toContain('Lan')->not->toContain('@')->not->toContain('tĩnh tâm');

    // The next Monday the post that was scheduled for 24/11 is counted, once.
    Carbon::setTestNow('2026-11-30 07:00:00');
    app(WorkflowProjectRunService::class)->runDue(now());
    expect(reportNotices($staff))->toHaveCount(2)
        ->and(reportNotices($staff))->toContain('7 ngày qua (23/11 – 30/11): 0 tin liên hệ mới, 1 bài viết mới đăng. Xem ở mục Liên hệ và Bài viết trong trang quản trị.');

    // The Monday after, nothing is new: the run completes and nobody is told.
    Carbon::setTestNow('2026-12-07 07:00:00');
    app(WorkflowProjectRunService::class)->runDue(now());
    $runs = WorkflowInstance::query()->where('project_id', $project->id)->orderBy('id')->get();
    expect($runs)->toHaveCount(3)
        ->and($runs[2]->status)->toBe('completed')
        ->and(data_get($runs[2]->context_snapshot, 'vars.notified'))->toBeNull()
        ->and(reportNotices($staff))->toHaveCount(2);
});

test('a full list shows as 100+ so a flood of messages never makes a huge run', function () {
    $staff = reportUser('staff', ['contacts.viewAny']);
    foreach (range(1, InstallWeeklyReportWorkflowCommand::LIMIT + 5) as $i) {
        reportMessage(sprintf('2026-11-22 %02d:%02d:00', intdiv($i, 60), $i % 60));
    }
    reportTemplateReady(reportOperator());
    auth()->logout();

    app(WorkflowProjectRunService::class)->runDue(now());

    expect(reportNotices($staff))->toBe(['7 ngày qua (16/11 – 23/11): 100+ tin liên hệ mới, 0 bài viết mới đăng. Xem ở mục Liên hệ và Bài viết trong trang quản trị.']);
});

test('the template builds as an unsigned hub bundle that asks when and who, from config version 1 nodes only', function () {
    $this->artisan('content:workflow-install-weekly-report')->assertSuccessful();

    $bundle = app(WorkflowBundleBuilder::class)->build(InstallWeeklyReportWorkflowCommand::PROJECT, 'ispa.weekly-report', 20261201, 'iSPA', group: 'notifications');
    expect(collect($bundle->summary['setup_questions'])->pluck('kind')->sort()->values()->all())->toBe(['notify_channel', 'schedule'])
        // The manifest names the modules of nodes and app events only; Post is Content's, which every release ships.
        ->and($bundle->requires['modules'])->toBe(['Core'])
        // Every node is one a site from before the hub library already runs: nothing above config version 1.
        ->and(collect($bundle->requires['node_types'])->filter(fn ($version) => (int) $version > 1)->all())->toBe([])
        ->and(array_keys($bundle->requires['node_types']))->toEqualCanonicalizing(['trigger_schedule', 'retrieve', 'decision', 'notify']);

    $dir = sys_get_temp_dir() . '/weekly-report-bundle-' . bin2hex(random_bytes(4));
    try {
        $this->artisan('cms:workflow-bundle-build', ['slug' => InstallWeeklyReportWorkflowCommand::PROJECT, '--key' => 'ispa.weekly-report', '--hub-version' => '2026.12.01', '--out' => $dir])
            ->expectsOutputToContain('Built ispa.weekly-report version 2026.12.01')->assertExitCode(0);
        expect(is_file($dir . '/ispa.weekly-report-20261201.workflow-bundle.json'))->toBeTrue()
            ->and(is_file($dir . '/ispa.weekly-report-20261201.workflow-bundle.json.sig.json'))->toBeFalse();
    } finally {
        array_map('unlink', array_filter((array) glob($dir . '/*'), 'is_file'));
        @rmdir($dir);
    }
});

test('the English copy builds into the same bundle so English sites list and install "Weekly report"', function () {
    $this->artisan('content:workflow-install-weekly-report', ['--locale' => 'vi'])->assertSuccessful();
    $this->artisan('content:workflow-install-weekly-report', ['--locale' => 'en', '--suffix' => '-en'])->assertSuccessful();

    $bundle = app(WorkflowBundleBuilder::class)->build(InstallWeeklyReportWorkflowCommand::PROJECT, 'ispa.weekly-report', 20261201, 'iSPA', group: 'notifications', translations: ['en' => InstallWeeklyReportWorkflowCommand::PROJECT . '-en']);
    $english = \Cms\Core\Services\Workflow\Hub\WorkflowTemplateTranslations::localizePackage($bundle->package, 'en')['workflow'];
    expect($bundle->name)->toBe('Báo cáo tuần')
        ->and($english['name'])->toBe('Weekly report')
        ->and(collect($english['steps'])->firstWhere('step_code', 'notify_admins')['step_config']['message'])->toBe(InstallWeeklyReportWorkflowCommand::MESSAGE_EN);
});
