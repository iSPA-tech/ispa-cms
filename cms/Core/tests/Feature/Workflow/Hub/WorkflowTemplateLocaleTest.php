<?php

declare(strict_types=1);

/**
 * Hub workflow templates follow the site language (2026-11-28: an English-only site listed and
 * installed "Liên hệ mới" in Vietnamese). One signed bundle carries the Vietnamese base text and, inside its package,
 * the English text (`workflow.translations`); an English site lists, shows and installs English, a Vietnamese site
 * keeps Vietnamese. Built from the real contact template (`cms:workflow-install-contact` and its `--locale=en` copy);
 * the hub is a catalog stub serving the bundle and the catalog row the hub would send. Nothing leaves the process.
 */

use App\Models\User;
use Cms\Core\Console\Commands\InstallContactWorkflowCommand;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Hub\WorkflowBundle;
use Cms\Core\Services\Workflow\Hub\WorkflowBundleBuilder;
use Cms\Core\Services\Workflow\Hub\WorkflowBundleVerifier;
use Cms\Core\Services\Workflow\Hub\WorkflowHubCatalog;
use Cms\Core\Services\Workflow\Hub\WorkflowHubInstaller;
use Cms\Core\Services\Workflow\Hub\WorkflowTemplateHash;
use Cms\Core\Services\Workflow\Hub\WorkflowTemplateTranslations;
use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInstaller;
use Cms\Core\Services\Workflow\Setup\WorkflowSetupService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

const WTL_SLUG = InstallContactWorkflowCommand::PROJECT;

beforeEach(function (): void {
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    Cache::flush();
    // User id 1 is always a webmaster.
    $this->webmaster = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'name' => 'Webmaster', 'username' => 'wm-' . Str::lower(Str::random(6)),
        'email' => 'wm-' . Str::lower(Str::random(6)) . '@example.com', 'email_verified_at' => now(),
        'password' => Hash::make('secret123'), 'type' => 1, 'level' => 0, 'status' => null,
    ]);
    $this->actingAs($this->webmaster);
    $this->locale = app()->getLocale();
});

afterEach(function (): void {
    app()->setLocale($this->locale);
});

/** Build the contact template bundle on the "owner" site (Vietnamese base, English copy unless told not to), then forget both. */
function wtlBuild(int $version, bool $english = true): WorkflowBundle
{
    test()->artisan('cms:workflow-install-contact', ['--locale' => 'vi'])->assertSuccessful();
    if ($english) {
        test()->artisan('cms:workflow-install-contact', ['--locale' => 'en', '--suffix' => '-en'])->assertSuccessful();
    }
    $bundle = app(WorkflowBundleBuilder::class)->build(WTL_SLUG, 'ispa.contact', $version, 'iSPA', group: 'notifications', translations: $english ? ['en' => WTL_SLUG . '-en'] : []);
    wtlForget(WTL_SLUG);
    wtlForget(WTL_SLUG . '-en');

    return $bundle;
}

function wtlForget(string $slug): void
{
    $id = WorkflowProject::withTrashed()->where('slug', $slug)->value('id');
    if ($id === null) {
        return;
    }
    WorkflowConnection::withTrashed()->where('project_id', $id)->forceDelete();
    WorkflowStep::withTrashed()->where('project_id', $id)->forceDelete();
    WorkflowProject::withTrashed()->whereKey($id)->forceDelete();
}

/** The hub: serves one bundle and the catalog row WorkflowTemplateCatalogService::catalogFor() sends for it. */
function wtlHub(): WorkflowHubCatalog
{
    $hub = new class(app(WorkflowBundleVerifier::class), app(WorkflowTemplateHash::class)) extends WorkflowHubCatalog {
        public ?WorkflowBundle $served = null;

        public function verifiedBundle(string $key): array
        {
            return ['bundle' => $this->served, 'key_id' => 'test'];
        }

        public function assertNotOlderThanAdvertised(WorkflowBundle $bundle): void
        {
        }

        public function missingModules(array $modules): array
        {
            return [];
        }

        protected function fetchCatalog(): array
        {
            $b = $this->served;

            return [static::entry([
                'key' => $b->key, 'publisher' => $b->publisher, 'name' => $b->name, 'description' => $b->description, 'group' => $b->group,
                'latest_version' => $b->version, 'content_sha256' => $b->contentSha256, 'requires' => $b->requires, 'summary' => $b->summary,
                'notes' => $b->notes, 'translations' => WorkflowTemplateTranslations::displayOf($b),
            ])];
        }
    };
    app()->instance(WorkflowHubCatalog::class, $hub);

    return $hub;
}

function wtlSteps(int $projectId): Illuminate\Support\Collection
{
    return WorkflowStep::query()->where('project_id', $projectId)->get()->keyBy('step_code');
}

test('an English site lists, shows and installs the contact template in English', function () {
    $bundle = wtlBuild(20261201);
    // The signed bundle keeps the Vietnamese base text; the English text rides inside its package.
    expect($bundle->name)->toBe('Liên hệ mới')
        ->and(WorkflowTemplateTranslations::displayOf($bundle)['en']['name'])->toBe('New contact');

    $hub = wtlHub();
    $hub->served = $bundle;
    app()->setLocale('en');

    $item = $hub->list(true)[0];
    expect($item['name'])->toBe('New contact')
        ->and($item['description'])->toStartWith('When someone sends a contact form')
        ->and($item['summary_lines']['does'])->toContain('Tell the admins')
        ->and($item['summary_lines']['does'])->not->toContain('Báo quản trị viên')
        ->and(implode(' | ', $item['summary_lines']['asks']))->toContain('Tell the admins');
    expect($hub->detail('ispa.contact'))->toMatchArray(['name' => 'New contact'])
        ->and($hub->detail('ispa.contact')['summary_lines']['does'])->toContain('AI flags spam');

    $result = app(WorkflowHubInstaller::class)->install('ispa.contact', $this->webmaster);
    $project = WorkflowProject::query()->findOrFail($result['project_id']);
    $steps = wtlSteps($project->id);
    expect($result['name'])->toBe('New contact')
        ->and($project->slug)->toBe(WTL_SLUG)
        ->and($project->description)->toStartWith('When someone sends a contact form')
        ->and($steps['notify_admins']->name)->toBe('Tell the admins')
        ->and($steps['notify_admins']->step_config['message'])->toBe(InstallContactWorkflowCommand::MESSAGE_EN)
        ->and($steps['spam_label']->step_config['labels'][0]['description'])->toStartWith('Advertising')
        ->and($steps['auto_reply']->step_config['template_key'])->toBe(InstallContactWorkflowCommand::AUTO_REPLY_TEMPLATE)
        ->and($steps->pluck('name')->implode(' '))->not->toMatch('/[àáạảãâầấậẩẫăằắặẳẵèéẹẻẽêềếệểễìíịỉĩòóọỏõôồốộổỗơờớợởỡùúụủũưừứựửữỳýỵỷỹđ]/u');
    // Installed as the template, not as a local change.
    expect($hub->list(true)[0]['installed'])->toMatchArray(['name' => 'New contact', 'modified' => false]);
});

test('a Vietnamese site lists and installs the same bundle in Vietnamese', function () {
    $hub = wtlHub();
    $hub->served = wtlBuild(20261201);
    app()->setLocale('vi');

    $item = $hub->list(true)[0];
    expect($item['name'])->toBe('Liên hệ mới')
        ->and($item['description'])->toStartWith('Khi có người gửi biểu mẫu liên hệ')
        ->and($item['summary_lines']['does'])->toContain('Báo quản trị viên');

    $result = app(WorkflowHubInstaller::class)->install('ispa.contact', $this->webmaster);
    $project = WorkflowProject::query()->findOrFail($result['project_id']);
    $steps = wtlSteps($project->id);
    expect($result['name'])->toBe('Liên hệ mới')
        ->and($project->description)->toStartWith('Khi có người gửi biểu mẫu liên hệ')
        ->and($steps['notify_admins']->name)->toBe('Báo quản trị viên')
        ->and($steps['notify_admins']->step_config['message'])->toBe(InstallContactWorkflowCommand::MESSAGE)
        ->and($steps['spam_label']->step_config['labels'][0]['description'])->toStartWith('Quảng cáo');
});

test('a Vietnamese install on an English site turns English at the next template version and keeps its setup answers', function () {
    // Built before anything is installed: the builder and the site share this database.
    $v1 = wtlBuild(20261201, english: false);
    $v2 = wtlBuild(20261202);
    $v3 = wtlBuild(20261203);
    $hub = wtlHub();
    app()->setLocale('en');

    // Before this change the template had only the Vietnamese text: that is what the site got.
    Permission::query()->firstOrCreate(['group' => 'contacts', 'name' => 'viewAny'], ['title' => 'contacts.viewAny']);
    $hub->served = $v1;
    $first = app(WorkflowHubInstaller::class)->install('ispa.contact', $this->webmaster);
    $project = WorkflowProject::query()->findOrFail($first['project_id']);
    expect($first['name'])->toBe('Liên hệ mới')
        ->and(app(WorkflowSetupService::class)->apply($project, ['notify_admins.permission' => 'contacts.viewAny'], $this->webmaster))->toBe([]);

    $hub->served = $v2;
    $result = app(WorkflowHubInstaller::class)->install('ispa.contact', $this->webmaster);
    $project->refresh();
    $steps = wtlSteps($project->id);
    expect($result)->toMatchArray(['project_id' => $project->id, 'updated_in_place' => true, 'version' => 20261202, 'name' => 'New contact'])
        ->and($project->description)->toStartWith('When someone sends a contact form')
        ->and($steps['notify_admins']->name)->toBe('Tell the admins')
        ->and($steps['notify_admins']->step_config['message'])->toBe(InstallContactWorkflowCommand::MESSAGE_EN)
        ->and($steps['notify_admins']->step_config['permission'])->toBe('contacts.viewAny');

    // A name changed on the site stays at the next update.
    $project->forceFill(['name' => 'Contact desk'])->save();
    $hub->served = $v3;
    expect(app(WorkflowHubInstaller::class)->install('ispa.contact', $this->webmaster))->toMatchArray(['updated_in_place' => true, 'name' => 'Contact desk']);
});

test('a site from before per-language text installs the same bundle unchanged, in the base text', function () {
    $bundle = wtlBuild(20261201);
    app()->setLocale('en');

    // What WorkflowHubInstaller did before this change: the package as signed, `workflow.translations` included.
    expect(array_key_exists(WorkflowTemplateTranslations::KEY, $bundle->package['workflow']))->toBeTrue();
    $result = app(WorkflowPackageInstaller::class)->install($bundle->package, $this->webmaster, [], WTL_SLUG, $bundle->name);
    $project = WorkflowProject::query()->where('slug', $result['slug'])->sole();
    expect($project->name)->toBe('Liên hệ mới')
        ->and(wtlSteps($project->id)['notify_admins']->step_config['message'])->toBe(InstallContactWorkflowCommand::MESSAGE);
});

test('a translation whose graph differs from the template is refused at build time', function () {
    $this->artisan('cms:workflow-install-contact', ['--locale' => 'vi'])->assertSuccessful();
    $this->artisan('cms:workflow-install-contact', ['--locale' => 'en', '--suffix' => '-en'])->assertSuccessful();
    $en = WorkflowProject::query()->where('slug', WTL_SLUG . '-en')->sole();
    WorkflowStep::query()->where('project_id', $en->id)->where('step_code', 'notify_admins')->sole()
        ->forceFill(['step_type' => 'log_message'])->save();

    expect(fn () => app(WorkflowBundleBuilder::class)->build(WTL_SLUG, 'ispa.contact', 20261201, 'iSPA', translations: ['en' => WTL_SLUG . '-en']))
        ->toThrow(WorkflowPackageException::class, 'same type');
    $this->artisan('cms:workflow-install-contact', ['--locale' => 'fr'])->assertFailed();
});
