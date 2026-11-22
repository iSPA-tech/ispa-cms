<?php

uses(\Cms\Core\Tests\TestCase::class, \Illuminate\Foundation\Testing\RefreshDatabase::class);

use Cms\Core\Providers\CoreServiceProvider;
use Cms\Core\Services\Platform\HubCredentials;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * the one-time hub setup code is printed only to an interactive terminal, never to a CI, cron or piped log
 * (the deploy log of release 119 held one). `cms:hub-setup-code --quiet` replaces a leaked code without printing.
 */
const HUB_CODE_PATTERN = '/([A-Z2-7]{4}-){7}[A-Z2-7]{4}/';

beforeEach(function () {
    config(['ispa-cms.cms_domain' => 'cms.ispa.io', 'ispa-cms.cms_domain_secret' => '']);
});

function hubSetupCodeRow(): ?object
{
    return DB::table(HubCredentials::TABLE)->where('name', 'setup_code')->first();
}

it('does not issue or print a code after migrate when the output is not a terminal', function () {
    app()->instance('cms.console.terminal', false);
    $output = new BufferedOutput();

    CoreServiceProvider::announceHubSetupCode($output);

    $text = $output->fetch();
    expect($text)->not->toMatch(HUB_CODE_PATTERN)
        ->and($text)->toContain('php artisan cms:hub-setup-code')
        ->and(hubSetupCodeRow())->toBeNull();

    // A later run in a terminal still gets the first code.
    app()->instance('cms.console.terminal', true);
    CoreServiceProvider::announceHubSetupCode($terminal = new BufferedOutput());
    expect($terminal->fetch())->toMatch(HUB_CODE_PATTERN);
});

it('prints the code once after migrate in a terminal', function () {
    app()->instance('cms.console.terminal', true);

    CoreServiceProvider::announceHubSetupCode($first = new BufferedOutput());
    CoreServiceProvider::announceHubSetupCode($second = new BufferedOutput());

    expect($first->fetch())->toMatch(HUB_CODE_PATTERN)
        ->and(hubSetupCodeRow())->not->toBeNull()
        ->and($second->fetch())->toBe('');
});

it('says nothing after migrate when the site is connected or a code was issued', function () {
    app()->instance('cms.console.terminal', false);
    app(HubCredentials::class)->issueSetupCode();

    CoreServiceProvider::announceHubSetupCode($output = new BufferedOutput());
    expect($output->fetch())->toBe('');

    DB::table(HubCredentials::TABLE)->delete();
    config(['ispa-cms.cms_domain' => 'env-site.example.com', 'ispa-cms.cms_domain_secret' => 'env-secret-value']);
    CoreServiceProvider::announceHubSetupCode($output = new BufferedOutput());
    expect($output->fetch())->toBe('');
});

it('replaces a leaked code without printing anything under --quiet', function () {
    $credentials = app(HubCredentials::class);
    $leaked = $credentials->issueSetupCode();

    $this->artisan('cms:hub-setup-code', ['--quiet' => true])
        ->doesntExpectOutputToContain('-')
        ->assertSuccessful();

    expect($credentials->checkSetupCode($leaked))->toBeFalse()
        ->and(hubSetupCodeRow()->value)->not->toBe(hash('sha256', str_replace('-', '', $leaked)));
});

it('keeps printing the code from cms:hub-setup-code without --quiet', function () {
    $this->artisan('cms:hub-setup-code')
        ->expectsOutputToContain('iSPA hub setup code')
        ->assertSuccessful();
});
