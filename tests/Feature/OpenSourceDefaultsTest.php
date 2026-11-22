<?php

declare(strict_types=1);

use App\Helpers\AppHelpers;
use App\Models\User;
use Illuminate\Support\Facades\Gate;
use Stevebauman\Location\Facades\Location;

uses(Tests\TestCase::class);

// Defaults a public install gets: no vendor access, no vendor address, nothing tied to one operator's servers.

it('opens Telescope only to the configured addresses, with no built-in vendor address', function (): void {
    app()['env'] = 'production';
    $vendor = User::factory()->make(['email' => 'team@ispa.io']);
    $listed = User::factory()->make(['email' => 'ops@example.test']);

    config(['telescope.allowed_emails' => '']);
    expect(Gate::forUser($vendor)->allows('viewTelescope'))->toBeFalse();

    // Read from config, not env(): a cached config makes env() return null on live sites.
    config(['telescope.allowed_emails' => ' ops@example.test , other@example.test']);
    expect(Gate::forUser($listed)->allows('viewTelescope'))->toBeTrue()
        ->and(Gate::forUser($vendor)->allows('viewTelescope'))->toBeFalse();
});

it('looks up the visitor IP, or the configured fallback outside production', function (): void {
    app()['env'] = 'testing';
    $this->app['request']->server->set('REMOTE_ADDR', '203.0.113.7');

    config(['ispa-cms.location_fallback_ip' => '']);
    Location::shouldReceive('get')->once()->with('203.0.113.7')->andReturn(false);
    AppHelpers::getLocation();

    config(['ispa-cms.location_fallback_ip' => '198.51.100.9']);
    Location::shouldReceive('get')->once()->with('198.51.100.9')->andReturn(false);
    AppHelpers::getLocation();
});
