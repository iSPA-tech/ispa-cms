<?php

declare(strict_types=1);

/**
 * Workflow template library from the hub (roadmap phase C), site side: build and sign a template bundle from a local
 * workflow, verify it, list the library, install (inactive, provenance), update an unmodified install in place, modified
 * copies install as copies, missing modules, access rules. The hub is faked: a PlatformHubClient pointed at https://hub.test whose answers are signed like
 * the real hub; bundles are signed with a test key the test trusts. Nothing leaves the process.
 */

use App\Models\User;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\ConfigPackage\PackageFormat;
use Cms\Core\Services\Modules\Packages\KeyId;
use Cms\Core\Services\Modules\Packages\PackageSigner;
use Cms\Core\Services\Platform\PlatformHubClient;
use Cms\Core\Services\Platform\PlatformSignature;
use Cms\Core\Services\Workflow\Hub\WorkflowBundle;
use Cms\Core\Services\Workflow\Hub\WorkflowBundleBuilder;
use Cms\Core\Services\Workflow\Hub\WorkflowBundleVerifier;
use Cms\Core\Services\Workflow\Hub\WorkflowHubException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageFormat;
use Cms\Core\Services\Workflow\Versioning\WorkflowVersionSnapshot;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Laravel\Sanctum\Sanctum;

uses(\Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const WLIB_SECRET = 'workflow-library-test-secret';
const WLIB_HUB = '/api/v2/public/platform/workflows';
const WLIB_API = '/api/v2/admin/workflows/library';

function wlibUser(array $keys = ['workflow_project.viewAny', 'workflow_project.create', 'workflow_project.update', 'workflow_project.view']): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'Lib ' . Str::random(4), 'username' => 'lib-' . Str::lower(Str::random(8)),
        'email' => 'lib-' . Str::lower(Str::random(8)) . '@example.com', 'password' => 'x', 'type' => 0, 'level' => 0,
    ]);
    if ($keys !== []) {
        $roleId = DB::table('roles')->insertGetId(['name' => 'Lib ' . $user->id, 'created_at' => now(), 'updated_at' => now()]);
        foreach ($keys as $key) {
            [$group, $name] = explode('.', $key, 2);
            $permissionId = DB::table('permissions')->where('group', $group)->where('name', $name)->value('id')
                ?? DB::table('permissions')->insertGetId(['group' => $group, 'name' => $name, 'title' => $name, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('permission_role')->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }
        DB::table('role_user')->insert(['role_id' => $roleId, 'user_id' => $user->id]);
    }

    return $user;
}

/** The template workflow: start → review (approval) → note, laid out right to left so run order differs from position order. */
function wlibWorkflow(string $slug, array $reviewConfig = []): WorkflowProject
{
    $project = WorkflowProject::query()->create(['name' => 'Story ' . $slug, 'slug' => $slug, 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => false, 'description' => 'Writes a story', 'tags' => ['sample']]);
    $steps = [
        'start' => ['set_variable', 'Pick the churches', ['assignments' => [['key' => 'vars.title', 'valueExpr' => "'Hello'"]]], 300, true],
        'review' => ['approval', 'A person decides', ['mode' => 'human', 'title' => 'Check'] + $reviewConfig, 200, false],
        'note' => ['log_message', 'Tell the editors', ['message' => 'done', 'level' => 'info'], 100, false],
    ];
    $ids = [];
    foreach ($steps as $code => [$type, $name, $config, $x, $start]) {
        $ids[$code] = WorkflowStep::query()->create(['project_id' => $project->id, 'step_type' => $type, 'step_code' => $code, 'name' => $name, 'step_config' => $config, 'position_x' => $x, 'position_y' => 50, 'is_start' => $start])->id;
    }
    WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $ids['start'], 'to_step_id' => $ids['review']]);
    WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $ids['review'], 'to_step_id' => $ids['note'],
        'condition' => ['source_handle' => 'approved', 'condition_type' => 'equals', 'condition_value' => 'approved']]);

    return $project;
}

/** Remove a workflow for good (the bundle source must not exist on the "other" site). */
function wlibForget(string $slug): void
{
    $id = WorkflowProject::withTrashed()->where('slug', $slug)->value('id');
    WorkflowConnection::withTrashed()->where('project_id', $id)->forceDelete();
    WorkflowStep::withTrashed()->where('project_id', $id)->forceDelete();
    WorkflowProject::withTrashed()->whereKey($id)->forceDelete();
}

beforeEach(function (): void {
    URL::forceScheme('https');
    config(['cache.default' => 'array']);
    Cache::flush();
    $pair = sodium_crypto_sign_keypair();
    $public = sodium_crypto_sign_publickey($pair);
    $this->secretKey = sodium_crypto_sign_secretkey($pair);
    $this->keyId = KeyId::fromPublicKey($public);
    config([
        'core.module_packages.trusted_keys' => [$this->keyId => base64_encode($public)],
        'core.module_packages.widget_bundle_key_ids' => [$this->keyId],
        'core.module_packages.revoked_key_ids' => [],
        'core.module_packages.countersign_key_ids' => [],
    ]);

    // Build the template on the "owner" site, then forget the source so this test site starts without it.
    wlibWorkflow('church-story');
    $this->bundle = app(WorkflowBundleBuilder::class)->build('church-story', 'ispa.church-story', 20261201, 'iSPA', 'Church story', null, 'posts', ['vi' => 'Bản đầu tiên']);
    wlibForget('church-story');

    $this->sign = fn (WorkflowBundle $bundle): string => (new PackageSigner())->envelope($bundle->toCanonicalJson(), $this->secretKey);
    $this->hub = [];
    $this->app->instance(PlatformHubClient::class, new PlatformHubClient('site.test', WLIB_SECRET, 'https://hub.test'));
    Http::fake(function (ClientRequest $request) {
        $path = (string) parse_url($request->url(), PHP_URL_PATH);
        [$status, $payload] = $this->hub[$path] ?? [404, ['error_code' => 'WORKFLOW_HUB_NOT_FOUND']];
        $body = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $nonce = (string) ($request->header(PlatformSignature::HEADER_NONCE)[0] ?? '');

        return Http::response($body, $status, [
            'Content-Type' => 'application/json',
            PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response(WLIB_SECRET, $status, $nonce, $body),
        ]);
    });
    // Serve a bundle as the latest version of its key (catalog row + signed bundle), like the hub does.
    $this->serve = function (WorkflowBundle $bundle, ?string $sig = null): void {
        $this->hub[WLIB_HUB] = [200, ['workflows' => [[
            'key' => $bundle->key, 'publisher' => $bundle->publisher, 'name' => $bundle->name, 'description' => $bundle->description,
            'group' => $bundle->group, 'latest_version' => $bundle->version, 'content_sha256' => $bundle->contentSha256,
            'requires' => $bundle->requires, 'summary' => $bundle->summary, 'notes' => $bundle->notes,
        ]]]];
        $this->hub[WLIB_HUB . '/' . $bundle->key] = [200, ['key' => $bundle->key, 'version' => $bundle->version, 'sig_json' => $sig ?? ($this->sign)($bundle), 'notes' => $bundle->notes]];
        Cache::flush();
    };
});

it('derives requires and a plain summary in run order, and the bundle verifies', function (): void {
    $bundle = $this->bundle;

    expect($bundle->requires)->toBe(['modules' => ['Core'], 'node_types' => ['approval' => 1, 'log_message' => 1, 'set_variable' => 1]])
        ->and(array_column($bundle->summary['steps'], 'name'))->toBe(['Pick the churches', 'A person decides', 'Tell the editors'])
        ->and($bundle->summary['needs_approval'])->toBeTrue()
        ->and(array_column($bundle->summary['setup_questions'], 'kind'))->toBe(['reviewers', 'schedule'])
        ->and($bundle->summary['setup_questions'][0])->toBe(['kind' => 'reviewers', 'step_code' => 'review', 'field' => 'reviewer_permission', 'step_name' => 'A person decides'])
        ->and($bundle->package)->not->toHaveKey('created_at')
        ->and($bundle->notes)->toBe(['vi' => 'Bản đầu tiên']);

    $verified = app(WorkflowBundleVerifier::class)->verify(($this->sign)($bundle));
    expect($verified['bundle']->contentSha256)->toBe($bundle->contentSha256)->and($verified['key_id'])->toBe($this->keyId);
});

it('refuses a template that names site users', function (): void {
    $reviewer = wlibUser();
    wlibWorkflow('bound', ['assignees' => [$reviewer->uuid]]);

    expect(fn () => app(WorkflowBundleBuilder::class)->build('bound', 'ispa.bound', 20261201, 'iSPA'))
        ->toThrow(\Cms\Core\Services\Workflow\Package\WorkflowPackageException::class, 'cannot name this site');
});

it('builds and signs through the commands with the release key file', function (): void {
    wlibWorkflow('cmd-story');
    $dir = sys_get_temp_dir() . '/wlib-' . bin2hex(random_bytes(4));
    mkdir($dir, 0700, true);
    $keyFile = $dir . '/release.key';
    file_put_contents($keyFile, base64_encode($this->secretKey));
    chmod($keyFile, 0600);

    try {
        $this->artisan('cms:workflow-bundle-build', ['slug' => 'cmd-story', '--key' => 'ispa.cmd-story', '--hub-version' => '2026.12.03', '--out' => $dir . '/out', '--notes' => 'Thêm bước'])
            ->expectsOutputToContain('2026.12.03')->assertExitCode(0);
        $path = $dir . '/out/ispa.cmd-story-20261203.workflow-bundle.json';
        $this->artisan('cms:workflow-bundle-sign', ['bundle' => $path, '--key-file' => $keyFile])->assertExitCode(0);

        ['bundle' => $bundle] = app(WorkflowBundleVerifier::class)->verify((string) file_get_contents($path . '.sig.json'));
        expect($bundle->key)->toBe('ispa.cmd-story')->and($bundle->version)->toBe(20261203)->and($bundle->notes)->toBe(['vi' => 'Thêm bước']);

        // A second sign never overwrites the signature.
        $this->artisan('cms:workflow-bundle-sign', ['bundle' => $path, '--key-file' => $keyFile])->assertExitCode(1);
    } finally {
        array_map('unlink', array_filter((array) glob($dir . '/{,out/}*', GLOB_BRACE), 'is_file'));
        @rmdir($dir . '/out');
        @rmdir($dir);
    }
});

it('refuses tampered, foreign-key and inconsistent bundles', function (): void {
    $verifier = app(WorkflowBundleVerifier::class);
    $sig = json_decode(($this->sign)($this->bundle), true);

    // Changed bytes under the old signature.
    $doc = json_decode(base64_decode($sig['manifest']), true);
    $doc['package']['workflow']['steps'][0]['step_config']['message'] = 'evil';
    $tampered = json_encode(['key_id' => $sig['key_id'], 'signature' => $sig['signature'], 'manifest' => base64_encode(PackageFormat::canonicalJson($doc))]);
    expect(fn () => $verifier->verify($tampered))->toThrow(WorkflowHubException::class, 'MODULE_PACKAGE_SIGNATURE');

    // Signed by a key the site does not trust.
    $other = sodium_crypto_sign_secretkey(sodium_crypto_sign_keypair());
    expect(fn () => $verifier->verify((new PackageSigner())->envelope($this->bundle->toCanonicalJson(), $other)))
        ->toThrow(WorkflowHubException::class, 'MODULE_PACKAGE_KEY_UNKNOWN');

    // Signed by the trusted key, but requires hides a module the package needs.
    $lying = new WorkflowBundle($this->bundle->key, $this->bundle->version, 'iSPA', 'x', null, null, null, ['modules' => [], 'node_types' => $this->bundle->requires['node_types']], $this->bundle->summary, $this->bundle->package, '');
    $lying = new WorkflowBundle($lying->key, $lying->version, 'iSPA', 'x', null, null, null, $lying->requires, $lying->summary, $lying->package, $lying->computeContentSha256());
    expect(fn () => $verifier->verify(($this->sign)($lying)))->toThrow(WorkflowHubException::class, 'requires differs');

    // A changed package whose checksum no longer matches.
    $package = $this->bundle->package;
    $package['workflow']['name'] = 'Other';
    $broken = WorkflowBundle::create('ispa.church-story', 20261202, 'iSPA', 'x', null, null, null, $this->bundle->summary, $package);
    expect(fn () => $verifier->verify(($this->sign)($broken)))->toThrow(WorkflowHubException::class, 'package checksum mismatch');

    // Hub install refuses the tampered bundle and writes nothing.
    ($this->serve)($this->bundle, $tampered);
    Sanctum::actingAs(wlibUser());
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertStatus(422)->assertJsonPath('error_code', 'WORKFLOW_BUNDLE_UNTRUSTED');
    expect(WorkflowProject::query()->count())->toBe(0);
});

it('installs a fresh template inactive, with provenance and the setup screen next', function (): void {
    ($this->serve)($this->bundle);
    Sanctum::actingAs(wlibUser());

    $list = $this->getJson(WLIB_API)->assertOk()->json('data.0');
    expect($list['key'])->toBe('ispa.church-story')
        ->and($list['available'])->toBeTrue()->and($list['missing_modules'])->toBe([])
        ->and($list['installed'])->toBeNull()->and($list['update_available'])->toBeFalse()
        ->and($list['latest_version_label'])->toBe('2026.12.01')
        ->and(array_column($list['summary_lines']['tags'], 'key'))->toBe(['needs_approval'])
        ->and($list['summary_lines']['does'])->toBe(['Pick the churches', 'A person decides', 'Tell the editors']);

    $detail = $this->getJson(WLIB_API . '/ispa.church-story')->assertOk()->json('data');
    expect($detail['signature'])->toBe(['verified' => true, 'key_id' => $this->keyId, 'publisher' => 'iSPA'])
        ->and($detail['install_as_copy'])->toBeFalse()->and($detail['notes'])->toBe('Bản đầu tiên')
        ->and($detail['summary_lines']['asks'])->toHaveCount(2);

    $result = $this->postJson(WLIB_API . '/ispa.church-story/install')->assertCreated()->json('data');
    $project = WorkflowProject::query()->findOrFail($result['project_id']);

    expect($result)->toMatchArray(['slug' => 'church-story', 'name' => 'Church story', 'is_active' => false, 'as_copy' => false, 'copy_reason' => null, 'setup_url' => '/setting/automation/workflows/setup/' . $project->id])
        ->and($project->is_active)->toBeFalse()
        ->and($project->source)->toMatchArray(['kind' => 'hub', 'key' => 'ispa.church-story', 'version' => 20261201, 'checksum' => $this->bundle->contentSha256])
        ->and($project->source['installed_hash'])->toMatch('/^[0-9a-f]{64}$/')
        // Not published: the setup answers and a normal publish (change control included) come first.
        ->and($project->published_version_id)->toBeNull()
        ->and(WorkflowStep::query()->where('project_id', $project->id)->count())->toBe(3);

    $installed = $this->getJson(WLIB_API)->json('data.0.installed');
    expect($installed)->toMatchArray(['project_id' => $project->id, 'version' => 20261201, 'modified' => false, 'is_active' => false]);

    // Answering the setup questions is not a local modification.
    $this->putJson('/api/v2/admin/workflows/' . $project->id . '/setup', ['answers' => ['review.reviewer_permission' => 'workflow_project.viewAny']])->assertOk();
    expect($this->getJson(WLIB_API)->json('data.0.installed.modified'))->toBeFalse();
});

it('installs an update of a modified copy as a new copy and leaves the original untouched', function (): void {
    ($this->serve)($this->bundle);
    Sanctum::actingAs(wlibUser());
    $first = $this->postJson(WLIB_API . '/ispa.church-story/install')->assertCreated()->json('data');
    $original = WorkflowProject::query()->findOrFail($first['project_id']);

    WorkflowStep::query()->where('project_id', $original->id)->where('step_code', 'note')->update(['name' => 'Tell the bishop']);
    $before = WorkflowVersionSnapshot::hash(WorkflowVersionSnapshot::fromDraft($original));

    $newer = WorkflowBundle::create('ispa.church-story', 20261202, 'iSPA', 'Church story', null, 'posts', ['vi' => 'Thêm bước gửi Telegram'], $this->bundle->summary, $this->bundle->package);
    ($this->serve)($newer);

    $item = $this->getJson(WLIB_API)->json('data.0');
    expect($item['update_available'])->toBeTrue()->and($item['installed']['modified'])->toBeTrue();
    expect($this->getJson(WLIB_API . '/ispa.church-story')->json('data'))->toMatchArray(['install_as_copy' => true, 'copy_reason' => 'modified']);

    $copy = $this->postJson(WLIB_API . '/ispa.church-story/install')->assertCreated()->json('data');
    expect($copy)->toMatchArray(['slug' => 'church-story-20261202', 'name' => 'Church story (2026.12.02)', 'as_copy' => true, 'copy_reason' => 'modified', 'is_active' => false]);

    $original->refresh();
    expect($original->slug)->toBe('church-story')
        ->and($original->source['version'])->toBe(20261201)
        ->and(WorkflowVersionSnapshot::hash(WorkflowVersionSnapshot::fromDraft($original)))->toBe($before)
        ->and(WorkflowProject::query()->findOrFail($copy['project_id'])->source['version'])->toBe(20261202);

    // The library now reports the newest install, unmodified and current.
    expect($this->getJson(WLIB_API)->json('data.0'))->toMatchArray(['update_available' => false])
        ->and($this->getJson(WLIB_API)->json('data.0.installed'))->toMatchArray(['project_id' => $copy['project_id'], 'modified' => false, 'copies' => 2]);
});

it('marks templates whose modules are off as unavailable and refuses to install them', function (): void {
    $package = $this->bundle->package;
    $package['manifest']['modules'] = ['Core', 'NoSuchModule'];
    $package['checksum'] = WorkflowPackageFormat::checksum($package);
    $needsModule = WorkflowBundle::create('ispa.church-story', 20261201, 'iSPA', 'Church story', null, null, null, $this->bundle->summary, $package);
    ($this->serve)($needsModule);
    Sanctum::actingAs(wlibUser());

    expect($this->getJson(WLIB_API)->json('data.0'))->toMatchArray(['available' => false, 'missing_modules' => ['NoSuchModule']]);
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertStatus(409)
        ->assertJsonPath('error_code', 'WORKFLOW_TEMPLATE_NOT_AVAILABLE')->assertJsonPath('data.missing_modules', ['NoSuchModule']);
    expect(WorkflowProject::query()->count())->toBe(0);
});

it('needs view rights to browse and the create right to install; an unknown key is 404', function (): void {
    ($this->serve)($this->bundle);

    Sanctum::actingAs(wlibUser([]));
    $this->getJson(WLIB_API)->assertForbidden();
    $this->getJson(WLIB_API . '/ispa.church-story')->assertForbidden();
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertForbidden();

    Sanctum::actingAs(wlibUser(['workflow_project.viewAny']));
    $this->getJson(WLIB_API)->assertOk();
    $this->getJson(WLIB_API . '/ispa.church-story')->assertOk();
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertForbidden();
    expect(WorkflowProject::query()->count())->toBe(0);

    Sanctum::actingAs(wlibUser());
    $this->getJson(WLIB_API . '/ispa.unknown')->assertNotFound()->assertJsonPath('error_code', 'WORKFLOW_HUB_NOT_FOUND');
});

/*
 * Update in place: a newer version of an unmodified install updates its draft,
 * keeping the setup answers; the published version keeps running until a normal publish.
 */

/** A model the v2 template reads; the site check fails once the model allowlist no longer names it. */
class WlibThing extends \Illuminate\Database\Eloquent\Model
{
    protected $table = 'users';
}

/**
 * Build one version of `ispa.church-story` on the "owner" site and forget the source again.
 *
 * @param array<string, array{0: string, 1: string, 2: array<string, mixed>, 3: int, 4: bool}> $steps
 * @param list<array{0: string, 1: string, 2: ?string}>                                         $edges
 */
function wlibBuild(int $version, array $steps, array $edges): WorkflowBundle
{
    $project = WorkflowProject::query()->create(['name' => 'Story', 'slug' => 'church-story', 'trigger_event' => 'manual', 'target_entity_type' => User::class, 'is_active' => false, 'description' => 'Writes a story v' . $version, 'tags' => ['sample']]);
    $ids = [];
    foreach ($steps as $code => [$type, $name, $config, $x, $start]) {
        $ids[$code] = WorkflowStep::query()->create(['project_id' => $project->id, 'step_type' => $type, 'step_code' => $code, 'name' => $name, 'step_config' => $config, 'position_x' => $x, 'position_y' => 50, 'is_start' => $start])->id;
    }
    foreach ($edges as [$from, $to, $handle]) {
        WorkflowConnection::query()->create(['project_id' => $project->id, 'from_step_id' => $ids[$from], 'to_step_id' => $ids[$to],
            'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle]]);
    }
    $bundle = app(WorkflowBundleBuilder::class)->build('church-story', 'ispa.church-story', $version, 'iSPA', 'Church story', null, 'posts', null);
    wlibForget('church-story');

    return $bundle;
}

/** v1: start → review → tell (telegram) → note. */
function wlibV1(): WorkflowBundle
{
    return wlibBuild(20261201, [
        'start' => ['set_variable', 'Pick the churches', ['assignments' => [['key' => 'vars.title', 'valueExpr' => "'Hello'"]]], 0, true],
        'review' => ['approval', 'A person decides', ['mode' => 'human', 'title' => 'Check'], 200, false],
        'tell' => ['telegram_send', 'Send to the group', ['chat' => '', 'text' => 'Done', 'storeAs' => 'vars.telegram'], 400, false],
        'note' => ['log_message', 'Tell the editors', ['message' => 'done', 'level' => 'info'], 600, false],
    ], [['start', 'review', null], ['review', 'tell', 'approved'], ['tell', 'note', null]]);
}

/** v2: review renamed and moved, tell text changed, note dropped, archive added (plus optional extra steps). */
function wlibV2(int $version = 20261202, array $extra = [], array $extraEdges = []): WorkflowBundle
{
    return wlibBuild($version, [
        'start' => ['set_variable', 'Pick the churches', ['assignments' => [['key' => 'vars.title', 'valueExpr' => "'Hello'"]]], 0, true],
        'review' => ['approval', 'The editor decides', ['mode' => 'human', 'title' => 'Check again'], 250, false],
        'tell' => ['telegram_send', 'Send to the group', ['chat' => '', 'text' => 'Done, v2', 'storeAs' => 'vars.telegram'], 450, false],
        'archive' => ['log_message', 'Keep a note', ['message' => 'archived', 'level' => 'info'], 650, false],
    ] + $extra, [['start', 'review', null], ['review', 'tell', 'approved'], ['tell', 'archive', null], ...$extraEdges]);
}

/** Install v1 and answer every setup question. */
function wlibInstallAnswered(object $test, WorkflowBundle $v1, User $user): WorkflowProject
{
    ($test->serve)($v1);
    $id = $test->postJson(WLIB_API . '/ispa.church-story/install')->assertCreated()->json('data.project_id');
    \Cms\Core\Models\Workflows\WorkflowCredential::query()->create(['name' => 'parish-bot', 'type' => 'telegram_bot', 'payload' => ['token' => 'secret-token-123']]);
    $test->putJson("/api/v2/admin/workflows/{$id}/setup", ['answers' => [
        'review.reviewer_permission' => ['permission' => 'workflow_project.viewAny', 'assignees' => [$user->uuid]],
        'tell.credential' => 'parish-bot',
        'tell.chat' => '-1001234',
        'schedule' => ['cron' => '0 6 * * *', 'timezone' => 'Asia/Ho_Chi_Minh'],
    ]])->assertOk();

    return WorkflowProject::query()->findOrFail($id);
}

/** @return array<string, WorkflowStep> */
function wlibSteps(WorkflowProject $project): array
{
    return WorkflowStep::query()->where('project_id', $project->id)->get()->keyBy('step_code')->all();
}

it('updates an unmodified install in place, keeping setup answers, the published version and is_active', function (): void {
    $v1 = wlibV1();
    $v2 = wlibV2();
    $user = wlibUser();
    Sanctum::actingAs($user);
    $project = wlibInstallAnswered($this, $v1, $user);

    $published = app(\Cms\Core\Services\Workflow\Versioning\WorkflowPublishService::class)->publishSystem($project->fresh(), 'v1');
    $project->refresh()->forceFill(['is_active' => true])->save();
    $before = wlibSteps($project);
    $v1Hash = $project->source['installed_hash'];

    ($this->serve)($v2);
    expect($this->getJson(WLIB_API)->json('data.0'))->toMatchArray(['update_available' => true])
        ->and($this->getJson(WLIB_API . '/ispa.church-story')->json('data'))->toMatchArray(['update_in_place' => true, 'install_as_copy' => false, 'copy_reason' => null]);

    $result = $this->postJson(WLIB_API . '/ispa.church-story/install')->assertOk()->json('data');
    expect($result)->toMatchArray(['project_id' => $project->id, 'slug' => 'church-story', 'as_copy' => false, 'copy_reason' => null,
        'updated_in_place' => true, 'is_active' => true, 'version' => 20261202, 'setup_url' => '/setting/automation/workflows/setup/' . $project->id])
        ->and(WorkflowProject::query()->count())->toBe(1);

    $project->refresh();
    $after = wlibSteps($project);
    expect(array_keys($after))->toEqualCanonicalizing(['start', 'review', 'tell', 'archive'])
        // Kept step codes keep their row ids; the dropped step is gone (soft deleted like a designer save).
        ->and($after['start']->id)->toBe($before['start']->id)
        ->and($after['review']->id)->toBe($before['review']->id)
        ->and($after['tell']->id)->toBe($before['tell']->id)
        ->and(WorkflowStep::query()->whereKey($before['note']->id)->exists())->toBeFalse()
        // New definition…
        ->and($after['review']->name)->toBe('The editor decides')
        ->and($after['review']->position_x)->toBe(250)
        ->and($after['review']->step_config['title'])->toBe('Check again')
        ->and($after['tell']->step_config['text'])->toBe('Done, v2')
        // …with the setup answers kept.
        ->and($after['review']->step_config['reviewer_permission'])->toBe('workflow_project.viewAny')
        ->and($after['review']->step_config['assignees'])->toBe([$user->uuid])
        ->and($after['tell']->step_config['credential'])->toBe('$bind:parish-bot')
        ->and($after['tell']->step_config['chat'])->toBe('-1001234')
        ->and($project->schedule_cron)->toBe('0 6 * * *')
        ->and($project->tags)->toBe(['sample'])
        ->and($project->name)->toBe('Church story')
        ->and($project->description)->toBe('Writes a story v20261202')
        ->and($project->is_active)->toBeTrue()
        ->and((int) $project->published_version_id)->toBe((int) $published->id)
        ->and($project->source)->toMatchArray(['kind' => 'hub', 'version' => 20261202, 'checksum' => $v2->contentSha256, 'installed_hashes' => [$v1Hash]])
        ->and($project->source['installed_hash'])->not->toBe($v1Hash);

    $edges = WorkflowVersionSnapshot::fromDraft($project)['connections'];
    expect(array_map(static fn (array $c): string => $c['from'] . '>' . $c['to'], $edges))->toBe(['review>tell', 'start>review', 'tell>archive']);

    // The still-published v1 is a known template version: not modified, no update left.
    expect($this->getJson(WLIB_API)->json('data.0'))->toMatchArray(['update_available' => false])
        ->and($this->getJson(WLIB_API)->json('data.0.installed'))->toMatchArray(['project_id' => $project->id, 'version' => 20261202, 'modified' => false, 'copies' => 1]);

    expect(DB::table('audit_logs')->where('action', 'like', '%workflow.template_updated')->where('target_id', $project->id)->exists())->toBeTrue();

    // The same version again is refused, nothing duplicated.
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertStatus(409)->assertJsonPath('error_code', 'WORKFLOW_TEMPLATE_UP_TO_DATE');
    expect(WorkflowProject::query()->count())->toBe(1);
});

it('installs a newer version as a copy once the draft was edited after install', function (): void {
    $v1 = wlibV1();
    $v2 = wlibV2();
    $user = wlibUser();
    Sanctum::actingAs($user);
    $project = wlibInstallAnswered($this, $v1, $user);
    WorkflowStep::query()->where('project_id', $project->id)->where('step_code', 'note')->update(['name' => 'Tell the bishop']);

    ($this->serve)($v2);
    expect($this->getJson(WLIB_API . '/ispa.church-story')->json('data'))->toMatchArray(['update_in_place' => false, 'install_as_copy' => true, 'copy_reason' => 'modified']);
    $copy = $this->postJson(WLIB_API . '/ispa.church-story/install')->assertCreated()->json('data');
    expect($copy)->toMatchArray(['as_copy' => true, 'copy_reason' => 'modified', 'updated_in_place' => false])
        ->and($copy['project_id'])->not->toBe($project->id)
        ->and($project->fresh()->source['version'])->toBe(20261201);
});

it('refuses the same version of an unmodified install unless a copy is asked', function (): void {
    ($this->serve)($this->bundle);
    Sanctum::actingAs(wlibUser());
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertCreated();

    expect($this->getJson(WLIB_API . '/ispa.church-story')->json('data'))->toMatchArray(['update_in_place' => false, 'install_as_copy' => false, 'copy_reason' => null]);
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertStatus(409)->assertJsonPath('error_code', 'WORKFLOW_TEMPLATE_UP_TO_DATE');
    expect(WorkflowProject::query()->count())->toBe(1);

    $this->postJson(WLIB_API . '/ispa.church-story/install', ['as_copy' => true])->assertCreated()->assertJsonPath('data.copy_reason', 'asked');
    expect(WorkflowProject::query()->count())->toBe(2);
});

it('writes nothing when the newer version fails a site check', function (): void {
    config(['core.workflow_security.models' => [WlibThing::class]]);
    $v1 = wlibV1();
    $v2 = wlibV2(20261202, ['load' => ['retrieve', 'Load a thing', ['model' => WlibThing::class, 'first' => true, 'storeAs' => 'vars.thing'], 800, false]], [['archive', 'load', null]]);
    $user = wlibUser();
    Sanctum::actingAs($user);
    $project = wlibInstallAnswered($this, $v1, $user);
    $before = WorkflowVersionSnapshot::fromDraft($project);
    $source = $project->source;

    // This site no longer allows the model the new version reads.
    config(['core.workflow_security.models' => []]);
    ($this->serve)($v2);
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertStatus(422)->assertJsonPath('error_code', 'WORKFLOW_TEMPLATE_NOT_INSTALLABLE')
        ->assertJsonPath('data.problems.0.code', 'model_not_allowed');

    $project->refresh();
    expect(WorkflowVersionSnapshot::fromDraft($project))->toBe($before)
        ->and($project->source)->toBe($source)
        ->and(WorkflowProject::query()->count())->toBe(1);
});

it('needs the create right to update an install in place', function (): void {
    $v1 = wlibV1();
    $v2 = wlibV2();
    $user = wlibUser();
    Sanctum::actingAs($user);
    $project = wlibInstallAnswered($this, $v1, $user);
    $before = WorkflowVersionSnapshot::fromDraft($project);

    ($this->serve)($v2);
    Sanctum::actingAs(wlibUser(['workflow_project.viewAny', 'workflow_project.update']));
    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertForbidden();
    expect(WorkflowVersionSnapshot::fromDraft($project->fresh()))->toBe($before)
        ->and($project->fresh()->source['version'])->toBe(20261201);
});

it('refuses a first install of a signed bundle older than the version the catalog advertises (review fix 34)', function (): void {
    // The hub answers the detail with an older (still validly signed) bundle than its own catalog lists.
    ($this->serve)($this->bundle);
    $this->hub[WLIB_HUB][1]['workflows'][0]['latest_version'] = $this->bundle->version + 1;
    Cache::flush();
    Sanctum::actingAs(wlibUser());

    $this->postJson(WLIB_API . '/ispa.church-story/install')->assertStatus(422)->assertJsonPath('error_code', 'WORKFLOW_BUNDLE_UNTRUSTED');
    expect(WorkflowProject::query()->where('slug', 'church-story')->exists())->toBeFalse();
});
