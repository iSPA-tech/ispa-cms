<?php

declare(strict_types=1);

use Cms\Core\Contracts\WebhookHandler;
use Cms\Core\Jobs\ProcessWebhookReceiptJob;
use Cms\Core\Models\WebhookReceipt;
use Cms\Core\Services\Webhooks\WebhookInbox;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

uses(Tests\TestCase::class);

/** Test handler that records payloads and fails when the payload asks for it. */
final class FakeInboxWebhookHandler implements WebhookHandler
{
    /** @var array<int, array<string, mixed>> */
    public static array $seen = [];

    public function handle(array $payload): void
    {
        self::$seen[] = $payload;

        if (($payload['fail'] ?? false) === true) {
            throw new RuntimeException('boom for jane@example.com');
        }
    }
}

beforeEach(function (): void {
    $this->artisan('migrate', ['--path' => 'cms/Core/database/migrations'])->run();
    DB::table('core_webhook_receipts')->delete();
    FakeInboxWebhookHandler::$seen = [];
    config()->set('core.webhooks.mode', 'inline');
    $this->inbox = app(WebhookInbox::class);
    $this->inbox->registerHandler('fake', FakeInboxWebhookHandler::class);
});

it('stores an event once per provider and event id', function (): void {
    expect($this->inbox->receive('fake', 'e1', ['a' => 1]))->toBeInstanceOf(WebhookReceipt::class)
        ->and($this->inbox->receive('fake', 'e1', ['a' => 1]))->toBeNull()
        ->and($this->inbox->receive('other', 'e1', ['a' => 1]))->not->toBeNull()
        ->and(WebhookReceipt::query()->count())->toBe(2);
});

it('processes inline and marks the receipt processed', function (): void {
    $receipt = $this->inbox->receive('fake', 'e1', ['a' => 1]);
    $this->inbox->dispatch($receipt);

    $receipt->refresh();
    expect($receipt->status)->toBe('processed')
        ->and($receipt->attempts)->toBe(1)
        ->and($receipt->processed_at)->not->toBeNull()
        ->and(FakeInboxWebhookHandler::$seen)->toBe([['a' => 1]]);
});

it('marks failed with a redacted error and rethrows', function (): void {
    $receipt = $this->inbox->receive('fake', 'e1', ['fail' => true]);

    expect(fn () => $this->inbox->dispatch($receipt))->toThrow(RuntimeException::class);

    $receipt->refresh();
    expect($receipt->status)->toBe('failed')
        ->and($receipt->attempts)->toBe(1)
        ->and($receipt->last_error)->toStartWith('RuntimeException: boom')
        ->and($receipt->last_error)->not->toContain('jane@example.com')
        ->and(strlen($receipt->last_error))->toBeLessThanOrEqual(1000);
});

it('queues the job in queue mode and the job retries with backoff', function (): void {
    Queue::fake();
    config()->set('core.webhooks.mode', 'queue');
    $receipt = $this->inbox->receive('fake', 'e1', ['a' => 1]);
    $this->inbox->dispatch($receipt);

    Queue::assertPushed(ProcessWebhookReceiptJob::class, fn ($job) => $job->receiptId === $receipt->id);
    expect($receipt->refresh()->status)->toBe('received');

    $job = new ProcessWebhookReceiptJob($receipt->id);
    expect($job->tries)->toBe(5)->and($job->backoff())->toBe([10, 60, 300, 900]);
    $job->handle($this->inbox);
    expect($receipt->refresh()->status)->toBe('processed');
    $job->handle($this->inbox);
    expect(FakeInboxWebhookHandler::$seen)->toHaveCount(1);
});

it('replays failed receipts, respects filters and refuses without a filter', function (): void {
    $failed = $this->inbox->receive('fake', 'f1', ['fail' => true]);
    $done = $this->inbox->receive('fake', 'd1', ['a' => 1]);
    $this->inbox->process($done->id);
    try {
        $this->inbox->process($failed->id);
    } catch (RuntimeException) {
    }
    FakeInboxWebhookHandler::$seen = [];

    $this->artisan('cms:webhook-replay')->assertExitCode(2);
    $this->artisan('cms:webhook-replay', ['provider' => 'fake'])->assertExitCode(2);
    expect(FakeInboxWebhookHandler::$seen)->toBe([]);

    $this->artisan('cms:webhook-replay', ['provider' => 'other', '--failed' => true])->assertExitCode(0);
    expect(FakeInboxWebhookHandler::$seen)->toBe([]);

    $this->artisan('cms:webhook-replay', ['--failed' => true])->assertExitCode(1);
    expect(FakeInboxWebhookHandler::$seen)->toHaveCount(1)
        ->and($failed->refresh()->attempts)->toBe(2)
        ->and($done->refresh()->attempts)->toBe(1);

    $this->artisan('cms:webhook-replay', ['--id' => [$done->id]])->assertExitCode(0);
    expect($done->refresh()->attempts)->toBe(2);
});

it('prunes only old processed receipts', function (): void {
    $old = now()->subDays(40);
    foreach ([['p-old', 'processed', $old], ['p-new', 'processed', now()], ['f-old', 'failed', $old]] as [$id, $status, $at]) {
        DB::table('core_webhook_receipts')->insert([
            'provider' => 'fake', 'event_id' => $id, 'status' => $status, 'payload' => '{}',
            'received_at' => $at, 'created_at' => $at, 'updated_at' => $at,
        ]);
    }

    $this->artisan('cms:webhook-prune')->assertExitCode(0);

    expect(WebhookReceipt::query()->orderBy('event_id')->pluck('event_id')->all())->toBe(['f-old', 'p-new']);
});

it('re-claims failed and stale receipts but not processed or fresh ones', function (): void {
    $receipt = $this->inbox->receive('fake', 'e1', ['fail' => true]);
    expect($this->inbox->receive('fake', 'e1', []))->toBeNull(); // fresh received

    try {
        $this->inbox->process($receipt->id);
    } catch (RuntimeException) {
    }

    // Concurrent redelivery of a failed receipt: only one claim wins.
    expect($this->inbox->receive('fake', 'e1', []))->not->toBeNull()
        ->and($this->inbox->receive('fake', 'e1', []))->toBeNull();

    // Never finished for 10+ minutes: claimable again.
    DB::table('core_webhook_receipts')->where('id', $receipt->id)->update(['status' => 'processing', 'updated_at' => now()->subMinutes(11)]);
    expect($this->inbox->receive('fake', 'e1', []))->not->toBeNull();

    DB::table('core_webhook_receipts')->where('id', $receipt->id)->update(['status' => 'processed']);
    expect($this->inbox->receive('fake', 'e1', []))->toBeNull();
});

it('re-dispatches the job when a failed receipt is redelivered in queue mode', function (): void {
    Queue::fake();
    config()->set('core.webhooks.mode', 'queue');
    $receipt = $this->inbox->receive('fake', 'e1', ['a' => 1]);
    $this->inbox->dispatch($receipt);
    DB::table('core_webhook_receipts')->where('id', $receipt->id)->update(['status' => 'failed']);

    $again = $this->inbox->receive('fake', 'e1', ['a' => 1]);
    $this->inbox->dispatch($again);
    expect($this->inbox->receive('fake', 'e1', ['a' => 1]))->toBeNull(); // job pending: duplicate

    Queue::assertPushed(ProcessWebhookReceiptJob::class, 2);
});
