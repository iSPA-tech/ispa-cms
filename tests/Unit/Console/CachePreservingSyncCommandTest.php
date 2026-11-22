<?php

uses(\Tests\TestCase::class);

use Illuminate\Support\Facades\Artisan;

test('deployment sync commands expose cache preservation option', function (string $command): void {
    $definition = Artisan::all()[$command]->getDefinition();

    expect($definition->hasOption('skip-cache-clear'))->toBeTrue();
})->with([
    'cms:sync-permissions',
    'cms:sync-email-template',
]);
