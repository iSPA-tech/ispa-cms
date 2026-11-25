<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowProject;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/*
 * Shared helpers of the workflow guardrail tests (lane 2: alerts, audit, credentials, breakers, permissions, trace).
 */

if (!function_exists('wgUser')) {
    /**
     * A staff account; with $level 0 it is a Webmaster, with permissions it gets a role holding them.
     *
     * @param list<string> $permissions
     */
    function wgUser(string $name, array $permissions = [], ?int $level = null): User
    {
        $user = User::query()->forceCreate([
            'uuid' => (string) Str::uuid(),
            'name' => $name,
            'username' => $name . '-' . Str::lower(Str::random(6)),
            'email' => $name . '-' . Str::lower(Str::random(6)) . '@example.com',
            'email_verified_at' => now(),
            'password' => Hash::make('secret123'),
            'type' => 0,
            'level' => 0,
            'status' => null,
        ]);

        if ($permissions !== [] || $level !== null) {
            $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => $level ?? 50]]);
            foreach ($permissions as $key) {
                [$group, $ability] = explode('.', $key, 2);
                $permission = Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]);
                $role->permissions()->attach($permission);
            }
            $user->roles()->attach($role);
        }

        return $user->fresh();
    }

    /** @param array<string, mixed> $attributes */
    function wgProject(array $attributes = []): WorkflowProject
    {
        return WorkflowProject::query()->forceCreate(array_merge([
            'name' => 'Church stories',
            'slug' => 'church-stories-' . Str::lower(Str::random(5)),
            'trigger_event' => 'manual',
            'target_entity_type' => 'none',
            'is_active' => true,
        ], $attributes));
    }
}
