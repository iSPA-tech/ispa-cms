<?php

uses(Tests\TestCase::class);

test('website sign in post route is owned once by the shared app controller', function () {
    $sharedRoutes = (string) file_get_contents(base_path('routes/web.php'));

    expect($sharedRoutes)
        ->toContain("Route::post('auth/sign-in', [WebsiteAuthController::class, 'postSignIn'])")
        ->toContain("->name('auth.postSignIn')")
        ->toContain("->middleware(['recaptcha', 'throttle:web-login'])");

    foreach (glob(base_path('projects/*/routes/index.php')) as $routeFile) {
        expect((string) file_get_contents($routeFile))
            ->not->toContain("Route::post('sign-in', 'postSignIn')");
    }

    foreach (glob(base_path('projects/*/Controllers/AuthController.php')) as $controllerFile) {
        expect((string) file_get_contents($controllerFile))
            ->not->toContain('public function postSignIn(');
    }
});
