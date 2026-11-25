<?php

declare(strict_types=1);

use Cms\Core\Mail\WorkflowMessageMail;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowPublishReceipt;
use Cms\Core\Services\Workflow\Credentials\SmtpMailerFactory;
use Cms\Core\Services\Workflow\Credentials\WorkflowCredentialStore;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\Runners\Integration\EmailSendRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\FacebookPagePostRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\TelegramSendRunner;
use Cms\Core\Workflows\Runtime\Runners\Integration\TextToSpeechRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunnerRegistry;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Contracts\Mail\Mailer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Mail\MailManager;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    Http::preventStrayRequests();
});

function msgContext(array $vars = []): WorkflowContext
{
    $root = new WorkflowProject();
    $root->id = 1;

    return new WorkflowContext($root, [], $vars);
}

function msgStep(string $type, array $config): StepDefinition
{
    return new StepDefinition(1, 1, $type, 's1', 's1', 'Step', $config);
}

function msgCredential(string $name, string $type, array $payload): void
{
    app(WorkflowCredentialStore::class)->create(['name' => $name, 'type' => $type, 'payload' => $payload], null);
}

it('registers the messaging node set with its safety and approval defaults', function (): void {
    $registry = new NodeRegistry();

    expect(NodeRegistry::runnerMap())->toHaveKey('telegram_send', TelegramSendRunner::class)
        ->and($registry->get('facebook_page_post')?->safety)->toBe(['external_call', 'publish'])
        ->and($registry->get('facebook_page_post')?->approvalDefault)->toBe('required')
        ->and($registry->get('text_to_speech')?->safety)->toBe(['ai_spend'])
        ->and($registry->get('email_send')?->bindings)->toBe(['smtp'])
        ->and(app(StepRunnerRegistry::class)->has('telegram_send'))->toBeTrue();
});

it('sends a telegram message and stores the result without the token', function (): void {
    msgCredential('bot', 'telegram_bot', ['token' => '999:SUPER-SECRET']);
    Http::fake(['https://api.telegram.org/bot999:SUPER-SECRET/sendMessage' => Http::response(['ok' => true, 'result' => ['message_id' => 77]])]);
    $context = msgContext(['title' => 'Mass at 7']);

    $result = (new TelegramSendRunner())->run(msgStep('telegram_send', [
        'credential' => '$bind:bot', 'chat' => '@parish_news', 'text' => 'New: {{ vars.title }}', 'storeAs' => 'vars.tg',
    ]), $context);

    expect($result->status)->toBe('success')
        ->and($result->resultData['message_id'])->toBe(77)
        ->and($context->getVar('tg')['message_id'])->toBe(77)
        ->and(json_encode([$result->resultData, $result->logs]))->not->toContain('SUPER-SECRET');
    Http::assertSent(fn (Request $r): bool => $r['text'] === 'New: Mass at 7' && $r['chat_id'] === '@parish_news');
});

it('redacts the token from a failed telegram call', function (): void {
    msgCredential('bot', 'telegram_bot', ['token' => '999:SUPER-SECRET']);
    Http::fake(fn () => throw new ConnectionException('cURL error 28 for https://api.telegram.org/bot999:SUPER-SECRET/sendMessage'));

    $result = (new TelegramSendRunner())->run(msgStep('telegram_send', [
        'credential' => '$bind:bot', 'chat' => '-100123', 'text' => 'hi',
    ]), msgContext());

    expect($result->status)->toBe('failed')
        ->and($result->error['message'])->toContain('[REDACTED]')
        ->and(json_encode($result->error))->not->toContain('SUPER-SECRET');
});

it('fails cleanly with a wrong credential type or a literal token', function (): void {
    msgCredential('mail', 'smtp', ['host' => 'smtp.example.test', 'port' => '587', 'from_address' => 'a@example.test']);

    $wrong = (new TelegramSendRunner())->run(msgStep('telegram_send', ['credential' => '$bind:mail', 'chat' => '1', 'text' => 'x']), msgContext());
    $literal = (new TelegramSendRunner())->run(msgStep('telegram_send', ['credential' => '999:RAW', 'chat' => '1', 'text' => 'x']), msgContext());

    expect($wrong->status)->toBe('failed')
        ->and($wrong->error['message'])->toContain('not a telegram_bot credential')
        ->and($literal->status)->toBe('failed')
        ->and($literal->error['message'])->not->toContain('999:RAW');
    Http::assertNothingSent();
});

it('posts to a facebook page once per receipt key', function (): void {
    msgCredential('page', 'facebook_page', ['page_id' => '4242', 'access_token' => 'PAGE-TOKEN-XYZ']);
    Http::fake(['https://graph.facebook.com/*/4242/feed' => Http::response(['id' => '4242_1'])]);
    $step = msgStep('facebook_page_post', [
        'credential' => '$bind:page', 'kind' => 'text', 'message' => 'Hello', 'receipt_key' => 'story-{{ vars.id }}', 'storeAs' => 'vars.fb',
    ]);

    $first = (new FacebookPagePostRunner())->run($step, msgContext(['id' => 5]));
    $second = (new FacebookPagePostRunner())->run($step, msgContext(['id' => 5]));

    expect($first->status)->toBe('success')
        ->and($first->resultData)->toMatchArray(['post_id' => '4242_1', 'reused' => false])
        ->and($second->resultData)->toMatchArray(['post_id' => '4242_1', 'reused' => true])
        ->and(WorkflowPublishReceipt::query()->where('receipt_key', 'story-5')->exists())->toBeTrue()
        ->and(json_encode([$first->resultData, $first->logs]))->not->toContain('PAGE-TOKEN-XYZ');
    Http::assertSentCount(1);
});

it('sends a branded mail through the site mailer', function (): void {
    Mail::fake();

    $result = (new EmailSendRunner())->run(msgStep('email_send', [
        'to' => 'a@example.test, bad-address, b@example.test', 'subject' => 'Hi {{ vars.name }}', 'body' => "Line one\n\nLine two",
    ]), msgContext(['name' => 'An']));

    expect($result->status)->toBe('success')->and($result->resultData['recipients'])->toBe(2);
    Mail::assertSent(WorkflowMessageMail::class, fn (WorkflowMessageMail $m): bool => $m->mailSubject === 'Hi An' && $m->hasTo('b@example.test'));
});

it('sends through an smtp credential with its from address', function (): void {
    msgCredential('mail', 'smtp', ['host' => 'smtp.example.test', 'port' => '587', 'password' => 'SMTP-PASS-1', 'from_address' => 'parish@example.test']);
    $mailer = app(MailManager::class)->build(['transport' => 'array']);
    app()->instance(SmtpMailerFactory::class, new class($mailer) extends SmtpMailerFactory {
        public array $seen = [];

        public function __construct(private readonly Mailer $mailer)
        {
        }

        public function make(array $smtp): Mailer
        {
            $this->seen[] = $smtp['host'];

            return $this->mailer;
        }
    });

    $result = (new EmailSendRunner())->run(msgStep('email_send', [
        'credential' => '$bind:mail', 'to' => 'a@example.test', 'subject' => 'S', 'body' => 'B',
    ]), msgContext());

    $sent = $mailer->getSymfonyTransport()->messages();
    expect($result->status)->toBe('success')
        ->and($sent)->toHaveCount(1)
        ->and($sent->first()->getOriginalMessage()->getFrom()[0]->getAddress())->toBe('parish@example.test')
        ->and(app(SmtpMailerFactory::class)->seen)->toBe(['smtp.example.test']);
});

it('turns text into speech, saves the file and reports usage', function (): void {
    Storage::fake('public');
    msgCredential('voice', 'tts', ['api_key' => 'sk-TTS-SECRET']);
    Http::fake(['https://api.openai.com/v1/audio/speech' => Http::response('AUDIO-BYTES', 200, ['Content-Type' => 'audio/mpeg'])]);

    $result = (new TextToSpeechRunner())->run(msgStep('text_to_speech', [
        'credential' => '$bind:voice', 'text' => 'Peace be with you', 'model' => 'tts-1', 'voice' => 'alloy', 'storeAs' => 'vars.audio',
    ]), msgContext());

    expect($result->status)->toBe('success')
        ->and(Storage::disk('public')->get($result->resultData['path']))->toBe('AUDIO-BYTES')
        ->and($result->usage())->toMatchArray(['provider' => 'openai', 'model' => 'tts-1', 'tokens_in' => 17, 'cost_usd' => 0.000255]);
    Http::assertSent(fn (Request $r): bool => $r->hasHeader('Authorization', 'Bearer sk-TTS-SECRET') && $r['input'] === 'Peace be with you');
});
