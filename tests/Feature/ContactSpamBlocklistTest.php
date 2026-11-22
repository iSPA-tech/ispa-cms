<?php

use App\Constants\Tables;
use App\Enums\UserStatusEnum;
use App\Events\PusherContactCreate;
use App\Events\SendConfirmToContactor;
use App\Models\Contact;
use App\Services\ContactAdminReadService;
use App\Services\ContactSubmissionService;
use App\Services\SpamEmailBlocklistService;
use App\Repositories\Interfaces\ContactInterface;
use Cms\Core\Constants\CoreTableConstant;
use Cms\Core\Interfaces\EmailInterface;
use Cms\Core\Models\Email;
use Illuminate\Auth\GenericUser;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

uses(Tests\TestCase::class, RefreshDatabase::class);

function ensureContactStatusEnums(): void
{
    foreach ([
        [
            'enum_key' => UserStatusEnum::USER_ACTIVE->value,
            'name' => 'Active',
            'name_alias' => 'Active',
        ],
        [
            'enum_key' => UserStatusEnum::USER_BANNED->value,
            'name' => 'Blocked',
            'name_alias' => 'Blocked',
        ],
    ] as $status) {
        DB::table(CoreTableConstant::ENUMERATES)->updateOrInsert(
            ['enum_key' => $status['enum_key']],
            [
                ...$status,
                'type' => 'user_status',
                'type_id' => 5,
                'input_type' => 'radio',
                'is_filter' => true,
                'status' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ],
        );
    }
}

test('spam blocklist stores only a normalized email hash', function (): void {
    $blocklist = app(SpamEmailBlocklistService::class);

    expect($blocklist->block([' Spam@block-domain.test '], true))->toBeTrue()
        ->and($blocklist->isBlocked('spam@block-domain.test'))->toBeTrue()
        ->and($blocklist->isBlocked('other@block-domain.test'))->toBeTrue()
        ->and($blocklist->block(['single@gmail.com'], true))->toBeTrue()
        ->and($blocklist->isBlocked('other@gmail.com'))->toBeFalse()
        ->and($blocklist->block(['single@yahoo.com'], true))->toBeTrue()
        ->and($blocklist->isBlocked('other@yahoo.co.uk'))->toBeFalse();

    $block = DB::table(Tables::SPAM_EMAIL_BLOCKS)->first();

    expect($block->email_hash)->not->toBe('spam@block-domain.test')
        ->and($block->email_hash)->toHaveLength(64);

    expect(DB::table(Tables::SPAM_EMAIL_DOMAIN_BLOCKS)->value('domain_hash'))->toHaveLength(64);
});

test('blocked public contact submission creates no contact, email, or event', function (): void {
    Event::fake();

    $blocklist = app(SpamEmailBlocklistService::class);
    $blocklist->block(['seed@blocked-contact.test'], true);

    $contacts = $this->createMock(ContactInterface::class);
    $contacts->expects($this->never())->method('model');
    $emails = $this->createMock(EmailInterface::class);
    $emails->expects($this->never())->method('model');

    $service = new ContactSubmissionService(
        $contacts,
        $emails,
        app(SpamEmailBlocklistService::class),
    );

    expect($service->submit([
        'name' => 'Spam sender',
        'email' => 'other@blocked-contact.test',
        'message' => 'Blocked message',
    ]))->toBe(['blocked' => true])
        ->and(DB::table(Tables::CONTACTS)->count())->toBe(0)
        ->and(DB::table(CoreTableConstant::EMAILS)->count())->toBe(0);

    Event::assertNotDispatched(PusherContactCreate::class);
    Event::assertNotDispatched(SendConfirmToContactor::class);
});

test('admin marking inbox email as spam blocks its sender and soft deletes every matching inbox email', function (): void {
    Event::fake();
    ensureContactStatusEnums();

    $contact = Contact::query()->create([
        'name' => 'Spam sender',
        'email' => 'spam@sender-spam.test',
        'is_subscribe' => true,
    ]);
    $contact->status = UserStatusEnum::USER_ACTIVE->value;
    $contact->save();
    $matchingContact = Contact::query()->create([
        'name' => 'Duplicate spam sender',
        'email' => 'SPAM@sender-spam.test',
        'is_subscribe' => true,
    ]);
    $mail = Email::query()->create([
        'uuid' => 'edb16f72-4392-4c6e-84eb-f1921d2224b3',
        'subject' => 'Spam mail',
        'content' => 'Spam content',
        'from_email' => 'SPAM@sender-spam.test',
        'contact_id' => $contact->id,
        'type' => 'inbox',
    ]);
    $matchingMail = Email::query()->create([
        'uuid' => '90d95b5e-bf1c-4d3b-86c3-18b49e1e10b6',
        'subject' => 'Earlier spam mail',
        'content' => 'Earlier spam content',
        'from_email' => 'spam@sender-spam.test',
        'type' => 'inbox',
    ]);
    $otherMail = Email::query()->create([
        'uuid' => 'dc52c2d1-c0a3-4f2e-857f-b142fba5f689',
        'subject' => 'Keep this inbox mail',
        'content' => 'Keep this content',
        'from_email' => 'other@sender-spam.test',
        'type' => 'inbox',
    ]);
    $sentMail = Email::query()->create([
        'uuid' => '1f76eeb5-24b8-470f-937d-981e1c269e0d',
        'subject' => 'Keep sent mail',
        'content' => 'Keep the outbound record',
        'email' => 'spam@sender-spam.test',
        'type' => 'sent',
    ]);
    $admin = new class(['id' => 99]) extends GenericUser {
        public function can($abilities, $arguments = []): bool
        {
            return true;
        }
    };

    $this->withoutMiddleware()
        ->actingAs($admin)
        ->putJson('/api/v2/admin/email/spam', [
            'uuids' => [$mail->uuid],
            'block_domain' => true,
        ])
        ->assertOk()
        ->assertJsonPath('status', 'success');

    expect(app(SpamEmailBlocklistService::class)->isBlocked('other@sender-spam.test'))->toBeTrue()
        ->and(Contact::find($contact->id)?->status)->toBe(UserStatusEnum::USER_BANNED->value)
        ->and(Contact::find($contact->id)?->is_subscribe)->toBeFalse()
        ->and(Contact::find($matchingContact->id)?->status)->toBe(UserStatusEnum::USER_BANNED->value)
        ->and(Contact::find($matchingContact->id)?->is_subscribe)->toBeFalse()
        ->and(Email::withTrashed()->find($mail->id)?->trashed())->toBeTrue()
        ->and(Email::withTrashed()->find($matchingMail->id)?->trashed())->toBeTrue()
        ->and(Email::find($otherMail->id)?->trashed())->toBeFalse()
        ->and(Email::find($sentMail->id)?->trashed())->toBeFalse();
});

test('spam-blocked contacts leave the operational dashboard immediately', function (): void {
    Cache::flush();
    Event::fake();
    ensureContactStatusEnums();

    $contact = Contact::query()->create([
        'name' => 'Spam sender',
        'email' => 'spam@dashboard-spam.test',
        'is_subscribe' => true,
        'source' => 'website',
        'type' => 'visitor',
    ]);
    $contact->status = UserStatusEnum::USER_ACTIVE->value;
    $contact->save();
    $mail = Email::query()->create([
        'uuid' => 'd3a9f0a7-0e98-48a2-95c1-70daddf35efc',
        'subject' => 'Dashboard spam',
        'content' => 'Remove from dashboard',
        'from_email' => 'spam@dashboard-spam.test',
        'contact_id' => $contact->id,
        'type' => 'inbox',
    ]);
    $admin = new class(['id' => 99]) extends GenericUser {
        public function can($abilities, $arguments = []): bool
        {
            return true;
        }
    };

    $this->withoutMiddleware()
        ->actingAs($admin)
        ->getJson('/api/v2/admin/contact/dashboard')
        ->assertOk()
        ->assertJsonPath('data.totals.total', 1)
        ->assertJsonPath('data.status.blocked', 0)
        ->assertJsonPath('data.recentContacts.0.status', 'new');

    $this->putJson('/api/v2/admin/email/spam', [
        'uuids' => [$mail->uuid],
    ])->assertOk();

    expect(app(ContactAdminReadService::class)->resolveContactStatus($contact->fresh()))->toBe('blocked');

    $this->getJson('/api/v2/admin/contact/dashboard')
        ->assertOk()
        ->assertJsonPath('data.totals.total', 0)
        ->assertJsonPath('data.status.blocked', 1)
        ->assertJsonCount(0, 'data.recentContacts');

    $this->getJson('/api/v2/admin/contact/dashboard?status=user_banned')
        ->assertOk()
        ->assertJsonPath('data.totals.total', 1)
        ->assertJsonPath('data.recentContacts.0.status', 'blocked');
});

test('marking spam requires contact update permission before changing a matching contact', function (): void {
    Event::fake();
    ensureContactStatusEnums();

    $contact = Contact::query()->create([
        'name' => 'Protected contact',
        'email' => 'protected@sender-spam.test',
    ]);
    $mail = Email::query()->create([
        'uuid' => '1d1fb3dc-0b37-4a5a-8e96-eea345688bb4',
        'subject' => 'Protected spam mail',
        'content' => 'Do not mutate without contact permission',
        'from_email' => 'protected@sender-spam.test',
        'contact_id' => $contact->id,
        'type' => 'inbox',
    ]);
    $admin = new class(['id' => 99]) extends GenericUser {
        public function can($abilities, $arguments = []): bool
        {
            return $abilities === 'delete';
        }
    };

    $this->withoutMiddleware()
        ->actingAs($admin)
        ->putJson('/api/v2/admin/email/spam', ['uuids' => [$mail->uuid]])
        ->assertJsonPath('status', 'error');

    expect(app(SpamEmailBlocklistService::class)->isBlocked('protected@sender-spam.test'))->toBeFalse()
        ->and(Contact::find($contact->id)?->status)->toBeNull()
        ->and(Email::find($mail->id)?->trashed())->toBeFalse();
});

test('admin email list searches sender fields and defaults to twenty rows per page', function (): void {
    $mail = Email::query()->create([
        'uuid' => '23805744-cefe-49ae-af77-4d3d784ded33',
        'subject' => 'Mailbox filter',
        'content' => 'Searchable content',
        'from_email' => 'find-me@sender-filter.test',
        'type' => 'inbox',
    ]);
    $admin = new class(['id' => 99]) extends GenericUser {
        public function can($abilities, $arguments = []): bool
        {
            return true;
        }
    };

    $this->withoutMiddleware()
        ->actingAs($admin)
        ->getJson('/api/v2/admin/email?type=inbox&query=find-me%40sender-filter.test')
        ->assertOk()
        ->assertJsonPath('data.per_page', 20)
        ->assertJsonPath('data.total', 1)
        ->assertJsonPath('data.data.0.uuid', $mail->uuid);
});
