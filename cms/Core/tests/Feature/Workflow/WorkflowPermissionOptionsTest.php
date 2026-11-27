<?php

declare(strict_types=1);

/*
 * GET api/v2/admin/workflows/permission-options: the permission picker of the workflow step form (approval
 * `reviewer_permission`, notify `permission`). Open to workflow editors (create or update); one flat list ordered by
 * module, with labels in the user's language. `resource_label` names what a module permission is about (the
 * `text.permission.resource.<group>` translation, else the readable resource) and is empty for a one-part group.
 */

use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;

uses(TestCase::class, RefreshDatabase::class);

/** @param list<string> $keys */
function permissionOptionsUser(array $keys): User
{
    $user = new class extends User {
        /** @var list<string> */
        public array $keys = [];

        public function permissionKeys(): array
        {
            return $this->keys;
        }

        public function isWebmaster(): bool
        {
            return false;
        }
    };
    $user->keys = $keys;
    $user->forceFill(['id' => random_int(1, 99999), 'name' => 'Picker', 'email' => uniqid('perm_', true) . '@example.com', 'password' => 'x']);
    $user->exists = true;

    return $user;
}

beforeEach(function (): void {
    $this->withoutMiddleware();
    Permission::query()->forceDelete();
    Permission::query()->create(['group' => 'article-module.stories', 'name' => 'review', 'title' => 'Review AI stories | Duyệt nội dung AI']);
    Permission::query()->create(['group' => 'education.edu_research_result', 'name' => 'viewAny', 'title' => 'View research results']);
    Permission::query()->create(['group' => 'post', 'name' => 'update', 'title' => 'Update posts']);
    Permission::query()->create(['group' => 'post', 'name' => 'create', 'title' => null]);
});

it('lists every permission as {key, label, module, module_label, resource_label}, ordered by module', function (): void {
    app()->setLocale('en');
    app('translator')->addLines(['text.permission.resource.article-module.stories' => 'AI stories'], 'en');
    Sanctum::actingAs(permissionOptionsUser(['workflow_project.update']));

    $rows = $this->getJson('/api/v2/admin/workflows/permission-options')->assertOk()->json('data');
    $row = static fn (string $key): array => collect($rows)->firstWhere('key', $key);

    expect($rows)->toHaveCount(4)
        ->and(array_keys($rows[0]))->toBe(['key', 'label', 'module', 'module_label', 'resource_label'])
        ->and(array_column($rows, 'module'))->toBe(['article-module', 'education', 'post', 'post'])
        ->and($rows[0])->toMatchArray(['key' => 'article-module.stories.review', 'label' => 'Review AI stories'])
        ->and($row('post.update')['label'])->toBe('Update posts')
        ->and($row('post.create')['label'])->toBe('post.create')
        ->and($rows[0]['module_label'])->toBeString()->not->toBe('')
        // Two-part group: its translation, else the resource without the edu_/qb_ prefix made readable.
        ->and($rows[0]['resource_label'])->toBe('AI stories')
        ->and($row('education.edu_research_result.viewAny')['resource_label'])->toBe('Research Result')
        // One-part group: the module label already names it.
        ->and($row('post.update')['resource_label'])->toBe('')
        ->and($row('post.create')['resource_label'])->toBe('');
});

it('shows the Vietnamese half of a bilingual title to a Vietnamese user', function (): void {
    app()->setLocale('vi');
    Sanctum::actingAs(permissionOptionsUser(['workflow_project.create']));

    $rows = $this->getJson('/api/v2/admin/workflows/permission-options')->assertOk()->json('data');

    expect(collect($rows)->firstWhere('key', 'article-module.stories.review')['label'])->toBe('Duyệt nội dung AI');
});

it('refuses a user who may not create or edit workflows', function (): void {
    Sanctum::actingAs(permissionOptionsUser(['workflow_project.viewAny', 'workflow_project.view']));

    $this->getJson('/api/v2/admin/workflows/permission-options')->assertStatus(403);
});
