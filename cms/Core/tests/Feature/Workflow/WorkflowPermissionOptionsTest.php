<?php

declare(strict_types=1);

/*
 * GET api/v2/admin/workflows/permission-options: the permission picker of the workflow step form (approval
 * `reviewer_permission`, notify `permission`). Open to workflow editors (create or update); one flat list ordered by
 * module, with labels in the user's language.
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
    Permission::query()->create(['group' => 'post', 'name' => 'update', 'title' => 'Update posts']);
    Permission::query()->create(['group' => 'post', 'name' => 'create', 'title' => null]);
});

it('lists every permission as {key, label, module, module_label}, ordered by module', function (): void {
    app()->setLocale('en');
    Sanctum::actingAs(permissionOptionsUser(['workflow_project.update']));

    $rows = $this->getJson('/api/v2/admin/workflows/permission-options')->assertOk()->json('data');

    expect($rows)->toHaveCount(3)
        ->and(array_keys($rows[0]))->toBe(['key', 'label', 'module', 'module_label'])
        ->and(array_column($rows, 'module'))->toBe(['article-module', 'post', 'post'])
        ->and($rows[0])->toMatchArray(['key' => 'article-module.stories.review', 'label' => 'Review AI stories'])
        ->and(collect($rows)->firstWhere('key', 'post.update')['label'])->toBe('Update posts')
        ->and(collect($rows)->firstWhere('key', 'post.create')['label'])->toBe('post.create')
        ->and($rows[0]['module_label'])->toBeString()->not->toBe('');
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
