<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Support;

use Cms\Core\Workflows\Runtime\WorkflowContext;
use Cms\Core\Workflows\Security\WorkflowRunDelegate;
use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;

/**
 * For runner unit tests without a database: every run acts for one in-memory user, and the given models get a policy
 * that allows every ability, so the model steps' delegate check (review 2026-11-24 finding 3) passes.
 */
final class WorkflowTestDelegate
{
    /**
     * @param class-string ...$modelClasses Models the delegate may viewAny / create / update / delete.
     */
    public static function allow(string ...$modelClasses): Authenticatable
    {
        $user = new GenericUser(['id' => 1, 'name' => 'workflow test delegate']);

        app()->instance(WorkflowRunDelegate::class, new class ($user) extends WorkflowRunDelegate {
            public function __construct(private readonly Authenticatable $user)
            {
            }

            public function for(WorkflowContext $context): ?Authenticatable
            {
                return $this->user;
            }
        });

        foreach ($modelClasses as $class) {
            Gate::policy($class, WorkflowTestAllowAllPolicy::class);
        }

        return $user;
    }
}

/**
 * Allows every ability (test only).
 */
final class WorkflowTestAllowAllPolicy
{
    public function __call(string $method, array $arguments): bool
    {
        return true;
    }
}
