<?php

declare(strict_types=1);

namespace Cms\Core\Tests\Feature\UpdateBatch;

use App\Constants\UserType;
use App\Models\User;
use App\Support\Modules\RuntimeModules;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Services\Modules\Install\InstallPreflight;
use Cms\Core\Services\Modules\Install\ModuleCatalog;
use Cms\Core\Services\Platform\PlatformSignature;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Http;

/**
 * Shared set-up of the upgrade-all tests: a site with temporary release and runtime module folders and a signed
 * hub catalog (same shape as ScheduledInstallRunTest), plus a Webmaster.
 */
final class UpdateBatchFixtures
{
    /**
     * Release modules Core 2026.11.07; runtime Account 2026.11.04, SocialAuth 2026.11.01, Webmail 2026.11.02,
     * Forum 2026.11.01. Hub: Account 2026.11.05, SocialAuth 2026.11.06 (needs Account >= 2026.11.05), Webmail
     * 2026.11.03 (irreversible), Forum 2026.12.01 (needs Core >= 2026.12.01).
     *
     * @return string the temporary folder (remove it with {@see self::tearDown()})
     */
    public static function site(object $test, array $hubModules = []): string
    {
        config(['cache.default' => 'array']);
        Cache::flush();

        $tmp = sys_get_temp_dir() . '/upgrade-all-' . bin2hex(random_bytes(6));
        mkdir($tmp . '/release', 0755, true);
        mkdir($tmp . '/runtime', 0755, true);
        RuntimeModules::setPath($tmp . '/runtime');
        self::module($tmp . '/release/Core', '2026.11.07');
        self::module($tmp . '/runtime/Account', '2026.11.04');
        self::module($tmp . '/runtime/SocialAuth', '2026.11.01', ['Account' => '>=2026.11.01']);
        self::module($tmp . '/runtime/Webmail', '2026.11.02');
        self::module($tmp . '/runtime/Forum', '2026.11.01');

        $secret = 'test-secret-' . bin2hex(random_bytes(8));
        config([
            'ispa-cms.cms_domain' => 'site.example.test',
            'ispa-cms.cms_domain_secret' => $secret,
            'ispa-cms.platform_hub_url' => 'https://hub.test',
            'core.module_packages.install_enabled' => true,
            'core.module_packages.trusted_keys' => ['0123456789abcdef' => base64_encode(str_repeat('k', 32))],
            'core.module_packages.revoked_key_ids' => [],
            'core.module_packages.job_timeout' => 2700,
            'core.scheduler.heartbeat_path' => $tmp . '/heartbeat',
            'modules.activator' => 'database',
        ]);

        $catalog = new ModuleCatalog(null, $tmp . '/release');
        app()->instance(ModuleCatalog::class, $catalog);
        app()->instance(InstallPreflight::class, new InstallPreflight(
            $catalog,
            '8.3.0',
            fn (string $e): bool => true,
            fn (): array => [],
            fn (): array => [],
            fn (): array => [],
        ));

        $modules = $hubModules !== [] ? $hubModules : self::hubModules();
        $body = json_encode(['modules' => $modules]);
        Http::fake(function (ClientRequest $sent) use ($secret, $body) {
            $nonce = $sent->header(PlatformSignature::HEADER_NONCE)[0];

            return Http::response($body, 200, [PlatformSignature::HEADER_RESPONSE_SIGNATURE => PlatformSignature::response($secret, 200, $nonce, $body)]);
        });

        return $tmp;
    }

    /**
     * The default hub catalog of {@see self::site()}.
     *
     * @return list<array<string, mixed>>
     */
    public static function hubModules(): array
    {
        $version = static fn (string $number, array $requires = [], mixed $irreversible = false): array => [
            'version' => $number, 'revoked' => false, 'requires' => $requires, 'irreversible' => $irreversible,
            'custom_code' => false, 'php_min' => '8.1.0',
        ];

        return [
            ['name' => 'Account', 'latest_version' => '2026.11.05', 'versions' => [$version('2026.11.04'), $version('2026.11.05')]],
            ['name' => 'SocialAuth', 'latest_version' => '2026.11.06', 'versions' => [
                $version('2026.11.01', ['Account' => '>=2026.11.01']),
                $version('2026.11.06', ['Account' => '>=2026.11.05']),
            ]],
            ['name' => 'Webmail', 'latest_version' => '2026.11.03', 'versions' => [$version('2026.11.02'), $version('2026.11.03', [], true)]],
            ['name' => 'Forum', 'latest_version' => '2026.12.01', 'versions' => [$version('2026.11.01'), $version('2026.12.01', ['Core' => '>=2026.12.01'])]],
        ];
    }

    public static function tearDown(string $tmp): void
    {
        RuntimeModules::resetPath();
        self::removeTree($tmp);
    }

    /**
     * A Webmaster holding the given permission keys (a Webmaster does not bypass these: only `permission.*`/`role.*`).
     *
     * @param list<string> $permissions
     */
    public static function webmaster(array $permissions = ['widget.hub', 'workflow_project.create'], int $level = 0): User
    {
        $user = User::query()->create(['name' => 'Upgrade Admin', 'email' => 'ua-' . uniqid() . '@test.local', 'password' => Hash::make('password')]);
        $user->forceFill(['type' => UserType::ADMIN])->save();
        $roleId = (int) DB::table(CoreTableConstant::ROLES)->insertGetId(['name' => 'Role ' . uniqid(), 'custom' => json_encode(['level' => $level])]);
        DB::table(CoreTableConstant::ROLE_USER)->insert(['role_id' => $roleId, 'user_id' => $user->id]);
        foreach ($permissions as $key) {
            $dot = (int) strrpos($key, '.');
            $permissionId = DB::table(CoreTableConstant::PERMISSIONS)->insertGetId(['group' => substr($key, 0, $dot), 'name' => substr($key, $dot + 1), 'title' => $key]);
            DB::table(CoreTableConstant::PERMISSION_ROLE)->insert(['role_id' => $roleId, 'permission_id' => $permissionId]);
        }

        return $user->fresh();
    }

    /**
     * @param array<string, string> $requires
     */
    public static function module(string $dir, string $version, array $requires = []): void
    {
        @mkdir($dir, 0755, true);
        file_put_contents($dir . '/module.json', json_encode(['name' => basename($dir), 'version' => $version, 'requires' => (object) $requires]));
    }

    private static function removeTree(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            @unlink($path);

            return;
        }
        foreach (@scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }
        @rmdir($path);
    }
}
