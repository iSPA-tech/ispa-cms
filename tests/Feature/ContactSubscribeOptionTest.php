<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Events\PusherContactCreate;
use App\Events\SendConfirmToContactor;
use App\Models\Contact;
use App\Services\ContactSubmissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

/**
 * The optional `is_subscribe` input of ContactSubmissionService decides only for new contacts; callers that do not
 * pass it keep the old behaviour (always subscribed).
 */
final class ContactSubscribeOptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Event::fake([PusherContactCreate::class, SendConfirmToContactor::class]);
    }

    /**
     * Submit one contact request (one per test: the contact repository shares one model instance per process).
     *
     * @param array<string, mixed> $extra
     */
    private function submit(array $extra = []): void
    {
        app(ContactSubmissionService::class)->submit($extra + [
            'name' => 'Lan Nguyen',
            'email' => 'lan@example.test',
            'message' => 'Hello',
        ]);
    }

    /** Callers that pass nothing still subscribe new contacts. */
    public function test_callers_that_pass_nothing_still_subscribe(): void
    {
        $this->submit();

        $this->assertTrue((bool) Contact::query()->where('email', 'lan@example.test')->value('is_subscribe'));
    }

    /** A new contact can be stored without subscribing. */
    public function test_a_new_contact_can_be_stored_without_subscribing(): void
    {
        $this->submit(['is_subscribe' => false]);

        $this->assertFalse((bool) Contact::query()->where('email', 'lan@example.test')->value('is_subscribe'));
    }

    /** An existing subscribed contact stays subscribed. */
    public function test_an_existing_contact_keeps_its_subscription(): void
    {
        Contact::query()->forceCreate(['name' => 'Lan', 'email' => 'lan@example.test', 'is_subscribe' => true]);

        $this->submit(['is_subscribe' => false]);

        $this->assertSame(1, Contact::query()->where('email', 'lan@example.test')->count());
        $this->assertTrue((bool) Contact::query()->where('email', 'lan@example.test')->value('is_subscribe'));
    }
}
