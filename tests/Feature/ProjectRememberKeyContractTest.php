<?php

namespace Tests\Feature;

use Tests\TestCase;

class ProjectRememberKeyContractTest extends TestCase
{
    public function test_project_login_forms_and_controllers_use_the_remember_key(): void
    {
        $legacyFormKey = 'remember-me';

        foreach (glob(base_path('projects/*/views/pages/auth/sign-in.blade.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);

            self::assertStringNotContainsString($legacyFormKey, $source, $path);
        }

        foreach (glob(base_path('projects/*/Controllers/AuthController.php')) ?: [] as $path) {
            $source = (string) file_get_contents($path);

            self::assertStringNotContainsString("input('{$legacyFormKey}'", $source, $path);
        }

        $passkeySignIn = (string) file_get_contents(
            base_path('resources/views/components/auth/passkey-sign-in.blade.php')
        );
        self::assertStringNotContainsString($legacyFormKey, $passkeySignIn);
        self::assertStringContainsString('input[name="remember"]', $passkeySignIn);
    }
}
