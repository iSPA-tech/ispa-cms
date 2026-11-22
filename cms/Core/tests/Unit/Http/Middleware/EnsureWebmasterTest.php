<?php

declare(strict_types=1);

use App\Models\User;
use Cms\Core\Http\Middleware\EnsureWebmaster;
use Cms\Core\Models\Role;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/*
 * EnsureWebmaster (alias `admin.webmaster`): only a Webmaster passes, in every environment including `local`.
 */

uses(Cms\Core\Tests\TestCase::class);

/**
 * Unsaved user with the given id and one role of the given level (no database needed).
 */
function ewUser(int $id, int $roleLevel): User
{
    $user = new User();
    $user->id = $id;
    $user->setRelation('roles', collect([new Role(['name' => 'Role ' . $roleLevel, 'custom' => ['level' => $roleLevel]])]));

    return $user;
}

/**
 * Run the middleware for a request signed in as $user (or nobody) and return the response.
 */
function ewHandle(?User $user): Response
{
    $request = Request::create('/api/v2/admin/config-packages/sections', 'GET');
    $request->setUserResolver(static fn () => $user);

    return (new EnsureWebmaster())->handle($request, static fn (): Response => response()->json(['passed' => true]));
}

test('a non-Webmaster admin gets 403 WEBMASTER_ONLY, also in the local environment', function (): void {
    foreach (['testing', 'local'] as $environment) {
        $this->app['env'] = $environment;
        expect(app()->environment($environment))->toBeTrue();

        $response = ewHandle(ewUser(42, 50));

        expect($response->getStatusCode())->toBe(403)
            ->and(json_decode((string) $response->getContent(), true)['error_code'] ?? null)->toBe('WEBMASTER_ONLY');
    }
});

test('a request without a user gets 403', function (): void {
    $response = ewHandle(null);

    expect($response->getStatusCode())->toBe(403)
        ->and(json_decode((string) $response->getContent(), true)['error_code'] ?? null)->toBe('WEBMASTER_ONLY');
});

test('a Webmaster passes through, by role level 0 or as user id 1', function (): void {
    foreach ([ewUser(42, 0), ewUser(1, 50)] as $user) {
        $response = ewHandle($user);

        expect($response->getStatusCode())->toBe(200)
            ->and(json_decode((string) $response->getContent(), true))->toBe(['passed' => true]);
    }
});
