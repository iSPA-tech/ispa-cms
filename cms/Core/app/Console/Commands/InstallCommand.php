<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Models\User;
use Cms\Core\Services\Admin\AdminRuntimeConfig;
use Cms\Core\Services\CoreUpgrade\CoreManifest;
use Cms\Core\Services\CoreUpgrade\CoreUpgradeRequests;
use Cms\Core\Services\Platform\HubCredentials;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * First-run installer of a self-installed site: `php artisan cms:install` after `composer install` and a
 * filled-in `.env`.
 *
 * It checks the environment, makes sure the app key exists, runs the migrations and the module seeders (which create
 * the Webmaster role and every permission), creates the first webmaster account, links the public storage folder and
 * prints the hub setup code once. Every step is safe to repeat: a second run changes nothing and prints no password.
 *
 * Run by a person (an interactive terminal), it first asks for the first webmaster's email, username and password; an
 * empty password answer generates one. Run with `--no-interaction` (scripts, CI), it asks nothing: the username is
 * `webmaster` (or `--admin-username`) and the password is generated and printed once. There is no password option:
 * a password on the command line ends up in the shell history and the process list.
 *
 * The site works at once on its own project (`projects/<VITE_TEMPLATE_FOLDER>`, `Default` out of the box). Hub
 * features stay locked until a Webmaster enters the setup code in Modules (sign-in never waits for the hub). Nothing here calls the
 * hub, and the password and setup code are printed to the terminal only, never logged.
 *
 * @see HubSetupCodeCommand Prints a new setup code later.
 */
class InstallCommand extends Command
{
    protected $signature = 'cms:install'
        .' {--admin-email= : Email of the first webmaster (asked when missing)}'
        .' {--admin-username= : Username of the first webmaster (asked when missing, else webmaster)}'
        .' {--admin-name=Webmaster : Display name of the first webmaster}'
        .' {--skip-seed : Skip the module seeders and the admin account (for a seed that runs separately)}';

    /** Printed instead of the runner line when the code belongs to root (same text as the admin card). */
    public const ROOT_OWNER_TEXT = "The website's code belongs to root, so updates from the admin are not possible. The person who manages the server can move the code to a separate account.";

    /** Shortest password accepted for the first webmaster. */
    public const MIN_PASSWORD_LENGTH = 12;

    /** Answers given before the long steps start, at most this many times per question. */
    private const MAX_ATTEMPTS = 3;

    /** Email of the first webmaster, set by askAdmin(). */
    private string $adminEmail = '';

    /** Username of the first webmaster, set by askAdmin(). */
    private string $adminUsername = 'webmaster';

    /** Password typed by the person installing; null when it is generated. */
    private ?string $chosenPassword = null;

    protected $description = 'Install a fresh site: migrate, seed, create the first webmaster and print the hub setup code.';

    /**
     * Run the installer.
     */
    public function handle(HubCredentials $credentials): int
    {
        $this->components->info('iSPA CMS install');

        if (! $this->preflight()) {
            return self::FAILURE;
        }

        $this->ensureAppKey();
        $this->ensureEnvKey('ISPA_ADMIN_AUTH_KEY', 'admin_auth_key', 'an admin key');
        $this->ensureEnvKey('ISPA_API_AUTH_KEY', 'api_auth_key', 'an API key');

        // Ask before the long steps, so nobody waits a minute to be asked.
        if (! $this->option('skip-seed') && ! $this->askAdmin()) {
            return self::FAILURE;
        }

        // Tells the first-boot announcement in CoreServiceProvider to stay quiet; the code is printed at the end.
        app()->instance('cms.installing', true);

        $this->components->task('Migrating the database', fn () => $this->callSilently('migrate', ['--force' => true]) === 0);

        $password = null;
        if (! $this->option('skip-seed')) {
            $result = $this->seed();
            if ($result === null) {
                return self::FAILURE;
            }
            $password = $result;

            // The admin loads its texts from the public language packs (storage/app/public/i18n/<VERSION>), which only
            // the generator writes; deployed releases run it in the deploy hook, a fresh install runs it here.
            $this->components->task('Writing the admin language packs', fn () => $this->callSilently('cms:translation-generate', ['--public-only' => true]) === 0);

            // config/setting.php is generated from the settings table; write it so a fresh site never serves the
            // snapshot the package shipped with. Not run with --skip-seed (tests must never rewrite the tracked file).
            $this->components->task('Writing the site settings', fn () => $this->callSilently('cms:setting-initialize', ['--updateEnv' => 'false']) === 0);
        }

        $this->callSilently('storage:link');
        $this->callSilently('optimize:clear');

        $this->writeAdminConfig();

        $this->printAdmin($password);
        $this->printSetupCode($credentials);
        $this->printRunnerSetup();

        $this->newLine();
        $this->components->info('Done. Open the admin (/iPanel/) on this site and sign in. To connect iSPA Hub later, create the secret code for this website in the customer portal ('.$this->portalUrl().', "Websites & domains"), then enter it with the setup code in Modules ("Connect to iSPA Hub").');

        return self::SUCCESS;
    }

    /**
     * Write public/iPanel/config.js last, so the admin reads this site's values. A failure only warns: the admin falls
     * back to the browser location and `php artisan cms:admin-config` can be run again later.
     */
    private function writeAdminConfig(): void
    {
        try {
            $path = app(AdminRuntimeConfig::class)->write();
            $this->line("Admin config written to {$path}");
        } catch (\Throwable $e) {
            $this->components->warn('Could not write the admin config ('.Str::limit(strtok($e->getMessage(), "\n") ?: 'unknown error', 160).'). Run php artisan cms:admin-config once public/iPanel is writable.');
        }
    }

    /**
     * Check what the later steps need and print every problem found.
     */
    private function preflight(): bool
    {
        $problems = [];

        if (! is_file(app()->environmentFilePath())) {
            $problems[] = 'There is no .env file. Copy .env.example to .env, set the database (DB_*) and run this command again.';
        }

        $project = (string) config('ispa-cms.folder_name', 'Default');
        if (! is_dir(base_path('projects/'.$project))) {
            $problems[] = "The project folder projects/{$project} does not exist (VITE_TEMPLATE_FOLDER in .env).";
        }

        foreach ([storage_path(), base_path('bootstrap/cache')] as $path) {
            if (! is_dir($path) || ! is_writable($path)) {
                $problems[] = "{$path} must exist and be writable by the user running PHP.";
            }
        }

        $connection = (string) config('database.default');
        $driver = (string) config("database.connections.{$connection}.driver", '');
        $extension = 'pdo_'.($driver === 'mariadb' ? 'mysql' : $driver);
        if ($driver !== '' && ! extension_loaded($extension)) {
            $problems[] = "The PHP extension {$extension} is not loaded, but DB_CONNECTION={$connection} needs it.";
        } else {
            try {
                DB::connection()->getPdo();
            } catch (\Throwable $e) {
                $problems[] = 'The database is not reachable ('.Str::limit(strtok($e->getMessage(), "\n") ?: 'connection failed', 160).'). Check DB_* in .env.';
            }
        }

        foreach ($problems as $problem) {
            $this->components->error($problem);
        }

        return $problems === [];
    }

    /**
     * Generate APP_KEY into .env when it is empty (never replaces an existing key: that would break stored secrets).
     */
    private function ensureAppKey(): void
    {
        if ((string) config('app.key', '') !== '') {
            return;
        }

        $this->components->task('Generating the app key', fn () => $this->callSilently('key:generate', ['--force' => true]) === 0);
    }

    /**
     * Generate a shared key into .env when it is empty, so a fresh site never runs on a shared default. A set value
     * (including an old private site's) is never rotated. The runtime config is updated too, so the admin config.js
     * written later in this run carries the key. The key itself is never printed.
     */
    private function ensureEnvKey(string $env, string $configKey, string $label): void
    {
        if ((string) config('ispa-cms.'.$configKey, '') !== '') {
            return;
        }

        $path = app()->environmentFilePath();
        $contents = (string) @file_get_contents($path);
        if (preg_match('/^'.$env.'=[ \t]*(?![\'"]{2}[ \t]*$)\S/m', $contents) === 1) {
            return;
        }

        $key = bin2hex(random_bytes(20));
        if (preg_match('/^'.$env.'=.*$/m', $contents) === 1) {
            $contents = (string) preg_replace('/^'.$env.'=.*$/m', $env.'='.$key, $contents, 1);
        } else {
            $contents = rtrim($contents, "\n")."\n".$env.'='.$key."\n";
        }

        if (@file_put_contents($path, $contents) === false) {
            $this->components->warn('Could not write '.$env.' into .env; set it by hand.');

            return;
        }

        config(['ispa-cms.'.$configKey => $key]);
        $this->line('Generated '.$label.' into .env ('.$env.').');
    }

    /**
     * Run the module seeders with the first webmaster's details passed through the environment the Core user seeder
     * reads. Returns the new account's password, '' when the account already existed, or null on failure.
     */
    private function seed(): ?string
    {
        $email = $this->adminEmail;
        $existed = User::query()->where('email', $email)->exists();
        $password = $existed ? '' : ($this->chosenPassword ?? Str::password(20, symbols: false));

        foreach ([
            'MASTER_EMAIL' => $email,
            'MASTER_NAME' => (string) $this->option('admin-name'),
            'MASTER_USERNAME' => $this->adminUsername,
            'MASTER_PASSWORD' => $password !== '' ? $password : Str::random(32),
        ] as $key => $value) {
            putenv("{$key}={$value}");
            $_ENV[$key] = $_SERVER[$key] = $value;
        }

        try {
            $this->components->info('Seeding modules, permissions and translations (about a minute)...');
            $exit = $this->call('cms:modules-sync');
        } finally {
            foreach (['MASTER_EMAIL', 'MASTER_NAME', 'MASTER_USERNAME', 'MASTER_PASSWORD'] as $key) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            }
        }

        if ($exit !== 0) {
            $this->components->error('The module seed reported errors (see above). Fix them and run cms:install again; it is safe to repeat.');

            return null;
        }

        if (! User::query()->where('email', $email)->exists()) {
            $this->components->error("The webmaster account {$email} was not created.");

            return null;
        }

        return $password;
    }

    /**
     * Settle who the first webmaster is: email, username and, for a person at the terminal, the password. Nothing is
     * asked about an account that already exists. False when a given option is not valid.
     */
    private function askAdmin(): bool
    {
        $this->adminEmail = $this->resolveAdminEmail();
        if (Schema::hasTable('users') && User::query()->where('email', $this->adminEmail)->exists()) {
            return true;
        }

        $username = $this->resolveAdminUsername();
        if ($username === null) {
            return false;
        }
        $this->adminUsername = $username;
        $this->chosenPassword = $this->askPassword();

        return true;
    }

    /**
     * The first webmaster's username: the option, else asked (default webmaster), else webmaster. Null when the option
     * is not a valid username.
     */
    private function resolveAdminUsername(): ?string
    {
        $given = strtolower(trim((string) $this->option('admin-username')));
        if ($given !== '') {
            if (! self::validUsername($given)) {
                $this->components->error('--admin-username: use 3 to 50 letters, digits, dots, dashes or underscores, starting with a letter or digit.');

                return null;
            }

            return $given;
        }

        if (! $this->input->isInteractive()) {
            return 'webmaster';
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $answer = strtolower(trim((string) $this->ask('Username of the first webmaster', 'webmaster')));
            if (self::validUsername($answer)) {
                return $answer;
            }
            $this->components->error('Use 3 to 50 letters, digits, dots, dashes or underscores, starting with a letter or digit.');
        }

        $this->line('Using the username webmaster.');

        return 'webmaster';
    }

    /**
     * Ask a person for the first webmaster's password, typed twice and hidden. Null (generate one) when nobody is at
     * the terminal, when the answer is empty, or after too many failed tries.
     */
    private function askPassword(): ?string
    {
        if (! $this->input->isInteractive()) {
            return null;
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            $password = (string) $this->secret('Password of the first webmaster (at least '.self::MIN_PASSWORD_LENGTH.' characters; leave empty to generate one)');
            if ($password === '') {
                return null;
            }
            if (mb_strlen($password) < self::MIN_PASSWORD_LENGTH || mb_strlen($password) > 255) {
                $this->components->error('The password needs '.self::MIN_PASSWORD_LENGTH.' to 255 characters.');

                continue;
            }
            if ((string) $this->secret('Type the password again') !== $password) {
                $this->components->error('The two passwords are not the same.');

                continue;
            }

            return $password;
        }

        $this->line('A password will be generated and printed at the end.');

        return null;
    }

    private static function validUsername(string $username): bool
    {
        return preg_match('/^[a-z0-9][a-z0-9._-]{2,49}$/', $username) === 1;
    }

    /**
     * The first webmaster's email: the option, else asked, else a placeholder that cannot receive mail.
     */
    private function resolveAdminEmail(): string
    {
        $email = trim((string) $this->option('admin-email'));
        if ($email === '' && $this->input->isInteractive()) {
            $email = trim((string) $this->ask('Email of the first webmaster', 'webmaster@example.test'));
        }

        return filter_var($email, FILTER_VALIDATE_EMAIL) ? strtolower($email) : 'webmaster@example.test';
    }

    /**
     * Print the account details; the password only when this run created the account.
     */
    private function printAdmin(?string $password): void
    {
        if ($password === null) {
            return;
        }

        $this->newLine();
        if ($password === '') {
            $this->line('The webmaster account already existed; its password is unchanged.');

            return;
        }

        if ($this->chosenPassword !== null) {
            $this->line('First webmaster:');
            $this->line('  email:    '.$this->adminEmail);
            $this->line('  username: '.$this->adminUsername);
            $this->line('  password: the one you typed');

            return;
        }

        $this->line('First webmaster (shown once, change it after signing in):');
        $this->line('  email:    '.$this->adminEmail);
        $this->line('  username: '.$this->adminUsername);
        $this->line('  password: '.$password);
    }

    /**
     * Print the one-time hub setup code, or say why none is printed.
     */
    private function printSetupCode(HubCredentials $credentials): void
    {
        $this->newLine();

        if ($credentials->isConfigured()) {
            $this->line($credentials->source() === HubCredentials::SOURCE_ENV
                ? 'This site already has hub credentials in .env; no setup code is needed.'
                : 'This site is already connected to the hub.');

            return;
        }

        $code = $credentials->announceSetupCodeOnce();
        if ($code === null) {
            $this->line('A hub setup code was already issued. Run php artisan cms:hub-setup-code to print a new one.');

            return;
        }

        $this->line('Hub setup code (works once, valid '.HubCredentials::SETUP_CODE_TTL_DAYS.' days):');
        $this->line('  '.$code);
        $this->line('Enter it on the connect screen of the admin with the secret code from '.$this->portalUrl().', or print a new one later: php artisan cms:hub-setup-code.');
    }

    /**
     * On a site installed from a core package (it has core-manifest.json), print the upgrade runner's crontab line,
     * so the admin's one-click core update works without a later setup step, or, when the code belongs to root, why
     * that is not possible. Nothing otherwise.
     */
    private function printRunnerSetup(): void
    {
        $requests = app(CoreUpgradeRequests::class);
        if (! is_file($requests->base().'/'.CoreManifest::FILE)) {
            return;
        }
        $setup = $requests->runnerSetup();

        $this->newLine();
        $this->line('One-click core updates (Settings > Modules):');
        if ($setup['root_owner']) {
            // The runner never runs as root, so there is no line to add.
            $this->line(self::ROOT_OWNER_TEXT);

            return;
        }
        $this->line('Add this line once to the crontab of '.$setup['user'].', the user that owns the code:');
        $this->line('  '.$setup['line']);
    }

    /**
     * The customer portal of the configured hub, where a website gets its secret code ("Websites & domains").
     */
    private function portalUrl(): string
    {
        return rtrim((string) config('ispa-cms.platform_hub_url', 'https://ispa.tech'), '/').'/portal';
    }
}
