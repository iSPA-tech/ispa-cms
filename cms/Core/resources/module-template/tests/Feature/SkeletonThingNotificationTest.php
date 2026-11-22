<?php

declare(strict_types=1);

namespace Cms\Skeleton\Tests\Feature;

use Cms\Skeleton\Jobs\SendSkeletonThingNotification;
use Cms\Skeleton\Mail\SkeletonThingSavedMail;
use Cms\Skeleton\Services\SkeletonThingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** Saving a thing queues a mail only when skeleton.notify_email is set. */
final class SkeletonThingNotificationTest extends TestCase
{
    use RefreshDatabase;

    public function test_no_address_queues_no_mail(): void
    {
        Bus::fake();
        config(['skeleton.notify_email' => null]);

        app(SkeletonThingService::class)->create(['name' => 'Quiet']);

        Bus::assertNotDispatched(SendSkeletonThingNotification::class);
    }

    public function test_the_address_gets_a_mail(): void
    {
        Bus::fake();
        config(['skeleton.notify_email' => 'editor@example.test']);

        $skeletonThing = app(SkeletonThingService::class)->create(['name' => 'Loud']);
        Bus::assertDispatched(SendSkeletonThingNotification::class);

        Mail::fake();
        (new SendSkeletonThingNotification($skeletonThing->id))->handle();
        Mail::assertSent(SkeletonThingSavedMail::class, static fn (SkeletonThingSavedMail $mail): bool => $mail->hasTo('editor@example.test'));
    }
}
