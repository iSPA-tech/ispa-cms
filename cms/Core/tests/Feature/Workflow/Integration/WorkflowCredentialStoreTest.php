<?php

declare(strict_types=1);

use Cms\Core\Models\Workflows\WorkflowCredential;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialException;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

function wcStore(): WorkflowCredentialStore
{
    return app(WorkflowCredentialStore::class);
}

it('stores the payload encrypted and resolves it by name and type', function (): void {
    wcStore()->create(['name' => 'church_bot', 'type' => 'telegram_bot', 'payload' => ['token' => '123456:SECRET-TOKEN']], null);

    $raw = (string) DB::table('workflow_credentials')->where('name', 'church_bot')->value('payload');
    expect($raw)->not->toContain('SECRET-TOKEN')
        ->and(wcStore()->resolve('church_bot', 'telegram_bot'))->toBe(['token' => '123456:SECRET-TOKEN'])
        ->and(WorkflowCredential::query()->where('name', 'church_bot')->value('last_used_at'))->not->toBeNull();
});

it('refuses a credential of another type and a missing one, without values in the message', function (): void {
    wcStore()->create(['name' => 'church_bot', 'type' => 'telegram_bot', 'payload' => ['token' => '123456:SECRET-TOKEN']], null);

    expect(fn () => wcStore()->resolve('church_bot', 'smtp'))
        ->toThrow(WorkflowCredentialException::class, 'Credential church_bot is not a smtp credential.')
        ->and(fn () => wcStore()->resolve('nope', 'smtp'))->toThrow(WorkflowCredentialException::class)
        ->and(fn () => wcStore()->resolveBinding('123456:SECRET-TOKEN', 'telegram_bot'))->toThrow(WorkflowCredentialException::class);
});

it('parses $bind: values', function (): void {
    expect(WorkflowCredentialStore::bindingName('$bind:church_bot'))->toBe('church_bot')
        ->and(WorkflowCredentialStore::bindingName(' $bind:page.main '))->toBe('page.main')
        ->and(WorkflowCredentialStore::bindingName('church_bot'))->toBeNull()
        ->and(WorkflowCredentialStore::bindingName('$bind:Bad Name'))->toBeNull()
        ->and(WorkflowCredentialStore::bindingName(['$bind:x']))->toBeNull();
});

it('redacts secret values, also url-encoded, but not plain fields', function (): void {
    $payloads = [['token' => '123:abc/def', 'page_id' => '42']];
    $data = ['msg' => 'GET https://x/bot123:abc/def/send', 'enc' => 'q=123%3Aabc%2Fdef', 'nested' => ['page 42']];

    expect(WorkflowCredentialStore::redact($data, $payloads))->toBe([
        'msg' => 'GET https://x/bot[REDACTED]/send',
        'enc' => 'q=[REDACTED]',
        'nested' => ['page 42'],
    ]);
});

it('requires the fields of the type and keeps saved values on an empty update', function (): void {
    expect(fn () => wcStore()->create(['name' => 'page', 'type' => 'facebook_page', 'payload' => ['page_id' => '1']], null))
        ->toThrow(ValidationException::class);

    $credential = wcStore()->create(['name' => 'page', 'type' => 'facebook_page', 'payload' => ['page_id' => '1', 'access_token' => 'tok-1']], null);
    wcStore()->update($credential, ['name' => 'page_main', 'payload' => ['access_token' => '', 'page_id' => '2']], null);

    expect(wcStore()->resolve('page_main', 'facebook_page'))->toBe(['page_id' => '2', 'access_token' => 'tok-1']);
});
