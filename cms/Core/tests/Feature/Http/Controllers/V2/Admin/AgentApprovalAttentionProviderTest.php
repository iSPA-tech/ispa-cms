<?php

namespace Cms\Core\Tests\Feature\Http\Controllers\V2\Admin;

use App\Models\User;
use App\Services\Dashboard\AttentionRegistry;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Tests\TestCase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Pending agent approvals appear in "my tasks" only for users who may view agentic resources.
 */
class AgentApprovalAttentionProviderTest extends TestCase
{
    /**
     * Create the minimal tables.
     */
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('users', function (Blueprint $t) {
            $t->id();
            $t->uuid('uuid')->nullable();
            $t->string('name')->nullable();
            $t->string('email')->nullable();
            $t->string('password')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create(CoreTableConstant::PERMISSIONS, function (Blueprint $t) {
            $t->id();
            $t->string('group')->nullable();
            $t->string('name')->nullable();
            $t->string('title')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create(CoreTableConstant::ROLES, function (Blueprint $t) {
            $t->id();
            $t->string('name')->nullable();
            $t->text('custom')->nullable();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create(CoreTableConstant::PERMISSION_ROLE, function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('permission_id');
        });
        Schema::create(CoreTableConstant::ROLE_USER, function (Blueprint $t) {
            $t->unsignedBigInteger('role_id');
            $t->unsignedBigInteger('user_id');
        });
        Schema::create(CoreTableConstant::AGENT_ARTIFACT, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('run_id')->nullable();
            $t->string('artifact_type')->nullable();
            $t->json('payload')->nullable();
            $t->timestamps();
        });
        Schema::create(CoreTableConstant::AGENT_APPROVAL, function (Blueprint $t) {
            $t->id();
            $t->unsignedBigInteger('run_id')->nullable();
            $t->unsignedBigInteger('artifact_id')->nullable();
            $t->string('status')->default('pending');
            $t->timestamps();
        });
        DB::table('users')->insert(['id' => 1, 'name' => 'Webmaster', 'created_at' => now(), 'updated_at' => now()]);
    }

    /**
     * Create a plain user, optionally holding permissions through one role.
     *
     * @param string|array<int, string>|null $permissions Permission names as "group.name".
     */
    private function user(string|array|null $permissions): User
    {
        $id = DB::table('users')->insertGetId(['name' => 'U', 'created_at' => now(), 'updated_at' => now()]);
        if ($permissions !== null && $permissions !== []) {
            $roleId = DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'R', 'created_at' => now(), 'updated_at' => now()]);
            foreach ((array) $permissions as $permission) {
                [$group, $name] = explode('.', $permission);
                $permissionId = DB::table(CoreTableConstant::PERMISSIONS)->insertGetId(['group' => $group, 'name' => $name, 'created_at' => now(), 'updated_at' => now()]);
                DB::table(CoreTableConstant::PERMISSION_ROLE)->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
            }
            DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $id]);
        }

        return User::query()->findOrFail($id);
    }

    /**
     * Seed one pending and one approved approval.
     */
    private function seedApprovals(): int
    {
        $artifact = DB::table(CoreTableConstant::AGENT_ARTIFACT)->insertGetId([
            'artifact_type' => 'content_patch', 'payload' => json_encode(['summary' => 'Refresh the roadmap post']),
            'created_at' => now(), 'updated_at' => now(),
        ]);
        $pending = DB::table(CoreTableConstant::AGENT_APPROVAL)->insertGetId(['artifact_id' => $artifact, 'status' => 'pending', 'created_at' => now(), 'updated_at' => now()]);
        DB::table(CoreTableConstant::AGENT_APPROVAL)->insert(['artifact_id' => $artifact, 'status' => 'approved', 'created_at' => now(), 'updated_at' => now()]);

        return $pending;
    }

    /**
     * A view-only user sees the pending approval with its detail link, titled by artifact type and never by payload data.
     */
    public function test_viewer_sees_pending_approval(): void
    {
        $id = $this->seedApprovals();

        $entries = app(AttentionRegistry::class)->forUser($this->user('agentic.viewAny'), true);

        $entry = collect($entries)->firstWhere('key', 'agentic_approvals_pending');
        self::assertNotNull($entry);
        self::assertSame(1, $entry['count']);
        self::assertSame('setting-agentic-approvals', $entry['route']);
        // No raw code as the title: the page translates the type key and falls back to the generic key.
        self::assertSame('', $entry['items'][0]['title']);
        self::assertSame('text.agentic.artifact.content_patch', $entry['items'][0]['title_key']);
        self::assertSame('text.agentic.artifact.unknown', $entry['items'][0]['title_fallback_key']);
        self::assertSame('text.my_tasks.at.created', $entry['items'][0]['at_label']);
        self::assertStringNotContainsString('Refresh the roadmap post', (string) json_encode($entry));
        self::assertSame('setting-agentic-approval-detail', $entry['items'][0]['route']);
        self::assertSame($id, $entry['items'][0]['params']['id']);
    }

    /**
     * An approver sees the artifact summary as the item title.
     */
    public function test_approver_sees_artifact_summary(): void
    {
        $id = $this->seedApprovals();

        $entries = app(AttentionRegistry::class)->forUser($this->user(['agentic.viewAny', 'agentic.approve']), true);

        $entry = collect($entries)->firstWhere('key', 'agentic_approvals_pending');
        self::assertNotNull($entry);
        self::assertSame(1, $entry['count']);
        self::assertSame('Refresh the roadmap post', $entry['items'][0]['title']);
        self::assertArrayNotHasKey('title_key', $entry['items'][0]);
        self::assertSame($id, $entry['items'][0]['params']['id']);
    }

    /**
     * Users who cannot view approvals get no entry.
     */
    public function test_user_without_permission_gets_none(): void
    {
        $this->seedApprovals();

        $entries = app(AttentionRegistry::class)->forUser($this->user(null), true);

        self::assertNotContains('agentic_approvals_pending', array_column($entries, 'key'));
    }
}
