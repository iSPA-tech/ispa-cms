<?php

declare(strict_types=1);

use App\Clients\CmsClient;
use App\Jobs\CallApiToTextToSpeechJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

uses(TestCase::class, RefreshDatabase::class);

/**
 * the job used an undefined `$this->client`; it now builds the CMS client and ends cleanly
 * when text-to-speech is not configured or the service returns nothing.
 */
test('the job ends without an error when text-to-speech is not configured', function (): void {
    config(['ispa-cms.cms_domain_secret' => '']);
    $client = Mockery::mock(CmsClient::class);
    $client->shouldNotReceive('textToSpeech');
    app()->instance(CmsClient::class, $client);

    (new CallApiToTextToSpeechJob(999999, '1'))->handle($client);

    expect(true)->toBeTrue();
});

test('the job ends without an error for an unknown content record', function (): void {
    config(['ispa-cms.cms_domain_secret' => 'secret']);
    $client = Mockery::mock(CmsClient::class);
    $client->shouldNotReceive('textToSpeech');
    app()->instance(CmsClient::class, $client);

    // Unknown content id: nothing to convert, the client is never called.
    (new CallApiToTextToSpeechJob(999999, '1'))->handle($client);

    expect(true)->toBeTrue();
});
