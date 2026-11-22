<?php

namespace Tests\Feature;

use App\Http\Controllers\System\AdminPanelController;
use App\Http\Controllers\System\ApiRootController;
use App\Http\Controllers\System\HealthCheckController;
use App\Http\Controllers\System\ProjectAssetController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Contract for the routes that had to stop being closures.
 *
 * A closure route cannot be serialized, and a single uncacheable route makes
 * `route:cache` refuse to build for the whole app — which left 748 routes
 * being registered on every request, an 85 ms floor measured on production.
 *
 * The actions are exercised directly rather than over HTTP: they sit in the
 * `web` group, whose middleware resolves the locale from the database, and
 * none of that is what these routes changed.
 *
 * @author iSPA Team
 * @since  8.1.0 (2026-08-03)
 */
class SystemRouteControllersTest extends TestCase
{
    /** The liveness probe answers without touching the database. */
    public function test_health_check_reports_ok(): void
    {
        $response = (new HealthCheckController())();

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame(['status' => 'ok'], $response->getData(true));
    }

    /** A bare GET /api still bounces to the site root. */
    public function test_api_root_redirects_home(): void
    {
        $response = (new ApiRootController())();

        $this->assertSame(302, $response->getStatusCode());
        $this->assertSame(url('/'), $response->getTargetUrl());
    }

    /** Every /iPanel path serves the admin shell. */
    public function test_admin_panel_serves_the_shell(): void
    {
        $this->assertSame('admin', (new AdminPanelController())()->name());
    }

    /** All four routes now resolve to a serializable action. */
    public function test_the_former_closure_routes_point_at_controllers(): void
    {
        $actions = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($route): bool => in_array($route->uri(), ['api', 'health', 'i{p}anel', 'i{p}anel{any}'], true))
            ->mapWithKeys(fn ($route): array => [$route->uri() => $route->getActionName()]);

        $this->assertSame(ApiRootController::class, $actions['api']);
        $this->assertSame(AdminPanelController::class, $actions['i{p}anel']);
        $this->assertSame(AdminPanelController::class, $actions['i{p}anel{any}']);
        // /health is the GameAdmin controller when that module is installed,
        // and the fallback otherwise — either way, never a closure.
        $this->assertStringNotContainsString('Closure', (string) $actions['health']);
    }

    /** A file that exists under the project folder is served. */
    public function test_project_asset_serves_an_existing_file(): void
    {
        // Own folder rather than the configured one: the real project folder
        // is a symlink on some checkouts, and this test has to be able to
        // write into whatever it points at.
        $folder = 'SystemRouteControllersTest';
        config(['ispa-cms.folder_name' => $folder]);

        $root = public_path('projects/' . $folder);
        $target = $root . '/asset.txt';

        File::ensureDirectoryExists($root);
        File::put($target, 'ok');

        try {
            $response = (new ProjectAssetController())(
                Request::create('/projects/' . $folder . '/asset.txt'),
                'asset.txt'
            );

            $this->assertSame(realpath($target), $response->getFile()->getPathname());
        } finally {
            File::deleteDirectory($root);
        }
    }

    /** A missing file is a 404, not a 500. */
    public function test_project_asset_404s_on_a_missing_file(): void
    {
        $this->expectException(HttpException::class);

        (new ProjectAssetController())(
            Request::create('/projects/x/nope.txt'),
            'definitely-not-here.txt'
        );
    }

    /**
     * A traversal path cannot escape the project folder.
     *
     * The closure this replaced concatenated `{path}` straight onto
     * public_path() and only checked file_exists(), so `../../.env` resolved
     * and was served.
     */
    public function test_project_asset_rejects_directory_traversal(): void
    {
        $this->expectException(HttpException::class);

        (new ProjectAssetController())(
            Request::create('/projects/x/../../.env'),
            '../../.env'
        );
    }
}
