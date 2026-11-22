<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Models\MetaClass;
use Cms\Core\Policies\MetaClassPolicy;
use Illuminate\Foundation\Auth\User as AuthUser;
use Illuminate\Support\Facades\Gate;

/**
 * Build an in-memory user that only holds the given permission keys.
 *
 * @param  list<string> $permissions Permission keys the user holds.
 * @return AuthUser
 */
function metaClassPolicyTestUser(array $permissions): AuthUser
{
    $user = new class extends AuthUser {
        /** @var list<string> */
        public array $granted = [];

        /**
         * Mirror the permission API consumed by AbstractModelPolicy.
         *
         * @param  string|null $key
         * @return bool
         */
        public function hasRole(?string $key = null): bool
        {
            return $key !== null && in_array($key, $this->granted, true);
        }
    };
    $user->id = 987654;
    $user->granted = $permissions;

    return $user;
}

test('meta class model is mapped to MetaClassPolicy', function () {
    expect(Gate::getPolicyFor(MetaClass::class))->toBeInstanceOf(MetaClassPolicy::class);
});

test('meta class model abilities are decided by the policy permissions', function () {
    $model = new MetaClass();
    $model->exists = true;
    $model->id = 1;

    $allowed = metaClassPolicyTestUser(['meta_class.update', 'meta_class.delete']);
    $denied = metaClassPolicyTestUser([]);

    expect(Gate::forUser($allowed)->allows('update', $model))->toBeTrue()
        ->and(Gate::forUser($allowed)->allows('delete', $model))->toBeTrue()
        ->and(Gate::forUser($denied)->allows('update', $model))->toBeFalse()
        ->and(Gate::forUser($denied)->allows('delete', $model))->toBeFalse();
});
