<?php

declare(strict_types=1);

use App\Enums\UserStatusEnum;
use App\Events\ContactMessageReceived;
use App\Models\Contact;
use App\Models\User;
use App\Services\SpamEmailBlocklistService;
use Cms\Core\Mail\WorkflowMessageMail;
use Cms\Core\Models\Email;
use Cms\Core\Models\EmailTemplate;
use Cms\Core\Models\Permission;
use Cms\Core\Models\Role;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Mail\MailLayoutAllowlist;
use Cms\Core\Services\Mail\ManagedEmailTemplates;
use Cms\Core\Services\Workflow\Versioning\WorkflowSensitiveFingerprint;
use Cms\Core\Workflows\Nodes\NodeConfigValidator;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Cms\Core\Workflows\Runtime\Runners\Integration\EmailSendRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/*
 * Owner e-mail policy B (2026-11-25): `email_send` template mode sends a managed template (group `workflow`) with
 * escaped variables; `system` mail sends without approval or switch, `business` mail only with the step's switch on;
 * the recipient is the trigger's own subject (resolved on the server) or staff; anything else keeps the approval rule.
 */

uses(Cms\Core\Tests\TestCase::class, RefreshDatabase::class);

beforeEach(function (): void {
    // Same SQLite FK note as ContactWorkflowTemplateTest (contact status rows): checks never run in the rolled-back test.
    if (DB::getDriverName() === 'sqlite') {
        DB::statement('PRAGMA defer_foreign_keys = ON');
    }
    Cache::flush();
    $blocklist = Mockery::mock(SpamEmailBlocklistService::class);
    $blocklist->shouldReceive('isBlocked')->andReturn(false)->byDefault();
    app()->instance(SpamEmailBlocklistService::class, $blocklist);
});

function etmTemplate(string $key, string $class = 'system', ?string $content = null, ?string $subject = null): EmailTemplate
{
    return EmailTemplate::query()->create([
        'name' => $subject ?? 'Hello {{ display_name }} from {{ site_name }}',
        'group' => 'workflow',
        'mail_class' => $class,
        'key_name' => $key,
        'content' => $content ?? '<p>Hi {{ display_name }}, we got it.</p>',
    ]);
}

function etmUser(string $email, array $permissions = [], ?string $status = null): User
{
    $user = User::query()->forceCreate([
        'uuid' => (string) Str::uuid(),
        'name' => 'U ' . Str::random(4),
        'username' => 'u' . Str::lower(Str::random(8)),
        'email' => $email,
        'email_verified_at' => now(),
        'password' => Hash::make('secret123'),
        'type' => 1,
        'level' => 0,
        'status' => $status,
    ]);
    if ($permissions !== []) {
        $role = Role::query()->create(['name' => 'Role ' . Str::random(6), 'custom' => ['level' => 50]]);
        foreach ($permissions as $key) {
            [$group, $ability] = explode('.', $key, 2);
            $role->permissions()->attach(Permission::query()->firstOrCreate(['group' => $group, 'name' => $ability], ['title' => $key]));
        }
        $user->roles()->attach($role);
    }

    return $user->fresh();
}

/** A context as an app-event run builds it (`trigger.meta` = {source, event: {name, payload}}). */
function etmContext(string $event, array $payload, string $source = 'event', array $vars = []): WorkflowContext
{
    $root = new WorkflowProject();
    $root->id = 1;

    return new WorkflowContext($root, ['event' => 'workflow.project.run', 'meta' => [
        'source' => $source, 'event' => ['name' => $event, 'payload' => $payload],
    ]], ['trigger' => ['event' => ['name' => $event, 'payload' => $payload]]] + $vars);
}

function etmRun(array $config, WorkflowContext $context): \Cms\Core\Workflows\Runtime\StepRunResult
{
    return (new EmailSendRunner())->run(new StepDefinition(1, 1, 'email_send', 'mail', 'mail', 'Mail', $config), $context);
}

function etmAccountEvent(): string
{
    // The Account module declares AccountRegistered; declare a stand-in here so Core tests do not need it.
    config()->set('core.workflow_security.trigger_events.Tests\\EtmAccountEvent', ['schema' => [], 'subject' => ['kind' => 'account', 'field' => 'user_uuid']]);

    return 'Tests\\EtmAccountEvent';
}

function etmMessage(string $email, string $name = 'Lan', ?string $contactStatus = null): Email
{
    $contact = Contact::query()->forceCreate([
        'name' => $name, 'email' => $email, 'source' => 'contact', 'type' => 'visitor', 'status' => $contactStatus ?? UserStatusEnum::USER_ACTIVE->value,
    ]);

    return Email::query()->forceCreate([
        'uuid' => (string) Str::uuid(), 'type' => 'inbox', 'subject' => 'Hỏi', 'content' => 'x', 'from_name' => $name,
        'from_email' => $email, 'reply_to' => $email, 'contact_id' => $contact->id,
    ]);
}

$managed = static fn (string $key, array $extra = []): array => $extra + [
    'mode' => 'template', 'template_key' => $key, 'recipient' => 'trigger_subject',
    'variables' => ['display_name' => '{{ vars.trigger.event.payload.display_name }}'], 'storeAs' => 'vars.mail',
];

it('renders a managed template with escaped variables inside the branded frame', function () use ($managed): void {
    Mail::fake();
    etmTemplate('welcome', 'system', '<p>Hi <b>{{ display_name }}</b> {!! display_name !!} {{ unknown }}</p>');
    $user = etmUser('lan@example.test');

    $result = etmRun($managed('welcome'), etmContext(etmAccountEvent(), ['user_uuid' => $user->uuid, 'display_name' => '<script>x</script>Lan']));

    expect($result->status)->toBe('success')
        ->and($result->resultData)->toMatchArray(['sent' => true, 'recipients' => 1, 'skipped' => false, 'mail_class' => 'system']);
    Mail::assertSent(WorkflowMessageMail::class, function (WorkflowMessageMail $mail): bool {
        $html = $mail->render();
        expect($mail->mailSubject)->toBe('Hello <script>x</script>Lan from ' . app(ManagedEmailTemplates::class)->builtins()['site_name'])
            ->and($mail->hasTo('lan@example.test'))->toBeTrue()
            ->and($html)->toContain('Hi <b>&lt;script&gt;x&lt;/script&gt;Lan</b> &lt;script&gt;x&lt;/script&gt;Lan')
            ->and($html)->not->toContain('<script>x</script>')
            ->and($html)->toContain('<!DOCTYPE html>'); // the <x-email.layout> frame

        return true;
    });
});

it('sends a system mail with no switch and skips a business mail while its switch is off', function () use ($managed): void {
    Mail::fake();
    etmTemplate('notice', 'system');
    etmTemplate('promo', 'business');
    $user = etmUser('lan@example.test');
    $context = fn () => etmContext(etmAccountEvent(), ['user_uuid' => $user->uuid, 'display_name' => 'Lan']);

    $system = etmRun($managed('notice'), $context());
    $off = etmRun($managed('promo'), $ctx = $context());
    $on = etmRun($managed('promo', ['business_enabled' => true]), $context());

    expect($system->resultData['sent'])->toBeTrue()
        ->and($off->status)->toBe('success')
        ->and($off->resultData)->toBe(['sent' => false, 'recipients' => 0, 'skipped' => true, 'skip_reason' => 'business_mail_off', 'mail_class' => 'business'])
        ->and($ctx->getVar('mail')['skip_reason'])->toBe('business_mail_off')
        ->and(collect($off->logs)->pluck('message')->all())->toContain('email_send skipped: business mail off')
        ->and($on->resultData['sent'])->toBeTrue();
    Mail::assertSentCount(2);
});

it('resolves the trigger\'s own account on the server and never uses an address from the payload', function () use ($managed): void {
    Mail::fake();
    etmTemplate('welcome');
    $user = etmUser('real@example.test');
    $event = etmAccountEvent();

    // The payload carries an attacker address and a `to`: ignored, the account's own e-mail is used.
    $sent = etmRun($managed('welcome'), etmContext($event, ['user_uuid' => $user->uuid, 'email' => 'attacker@evil.test', 'to' => 'attacker@evil.test']));
    // The same payload in a run nobody started through the event (manual / sub-workflow / webhook): no subject.
    $manual = etmRun($managed('welcome'), etmContext($event, ['user_uuid' => $user->uuid], 'manual'));
    $webhook = etmRun($managed('welcome'), etmContext($event, ['user_uuid' => $user->uuid], 'webhook'));
    // An event that declares no subject has none either.
    config()->set('core.workflow_security.trigger_events.Tests\\EtmNoSubject', ['schema' => []]);
    $undeclared = etmRun($managed('welcome'), etmContext('Tests\\EtmNoSubject', ['user_uuid' => $user->uuid]));
    $unknown = etmRun($managed('welcome'), etmContext($event, ['user_uuid' => (string) Str::uuid()]));

    expect($sent->resultData['sent'])->toBeTrue()
        ->and($manual->resultData['skip_reason'])->toBe('no_recipient')
        ->and($webhook->resultData['skip_reason'])->toBe('no_recipient')
        ->and($undeclared->resultData['skip_reason'])->toBe('no_recipient')
        ->and($unknown->resultData['skip_reason'])->toBe('no_recipient')
        ->and(json_encode([$sent->resultData, $sent->logs]))->not->toContain('real@example.test');
    Mail::assertSentCount(1);
    Mail::assertSent(WorkflowMessageMail::class, fn (WorkflowMessageMail $m): bool => $m->hasTo('real@example.test') && !$m->hasTo('attacker@evil.test'));
});

it('sends to staff by permission and fails a missing template closed', function () use ($managed): void {
    Mail::fake();
    etmTemplate('staff_notice');
    etmUser('staff@example.test', ['contacts.viewAny']);
    etmUser('other@example.test', ['posts.viewAny']);

    $staff = etmRun($managed('staff_notice', ['recipient' => 'staff', 'recipient_permission' => 'contacts.viewAny']), etmContext('X', []));
    $missing = etmRun($managed('nope'), etmContext(etmAccountEvent(), []));

    expect($staff->resultData['recipients'])->toBe(1)
        ->and($missing->status)->toBe('failed');
    Mail::assertSent(WorkflowMessageMail::class, fn (WorkflowMessageMail $m): bool => $m->hasTo('staff@example.test') && !$m->hasTo('other@example.test'));
});

it('picks the asked language, then the site default, then any row of the key', function (): void {
    $vi = DB::table('languages')->insertGetId(['name' => 'Tiếng Việt', 'code' => 'vi', 'default_web' => true, 'created_at' => now(), 'updated_at' => now()]);
    $en = DB::table('languages')->insertGetId(['name' => 'English', 'code' => 'en', 'default_web' => false, 'created_at' => now(), 'updated_at' => now()]);
    $rowVi = EmailTemplate::query()->create(['name' => 'VI', 'group' => 'workflow', 'key_name' => 'k', 'content' => 'x', 'language_id' => $vi]);
    $rowEn = EmailTemplate::query()->create(['name' => 'EN', 'group' => 'workflow', 'key_name' => 'k', 'content' => 'x', 'language_id' => $en]);
    $templates = app(ManagedEmailTemplates::class);

    expect($templates->find('workflow', 'k', 'en')->id)->toBe($rowEn->id)
        ->and($templates->find('workflow', 'k', 'en-US')->id)->toBe($rowEn->id)
        ->and($templates->find('workflow', 'k', 'fr')->id)->toBe($rowVi->id)
        ->and($templates->find('workflow', 'k')->id)->toBe($rowVi->id)
        ->and($templates->find('workflow', 'missing'))->toBeNull();
});

it('guards the contact auto-reply: one per address per day, nothing for spam, removed messages or banned senders', function () use ($managed): void {
    Mail::fake();
    etmTemplate('auto');
    $reply = $managed('auto');
    $send = fn (Email $message) => etmRun($reply, etmContext(ContactMessageReceived::class, ['message_uuid' => $message->uuid, 'display_name' => 'Lan']));

    $first = $send(etmMessage('lan@example.test'));
    $again = $send(etmMessage('LAN@example.test'));
    $other = $send(etmMessage('binh@example.test'));

    $removed = etmMessage('gone@example.test');
    $removed->delete(); // marking spam deletes the message
    $banned = etmMessage('banned@example.test', 'B', UserStatusEnum::USER_BANNED->value);
    $blocklist = Mockery::mock(SpamEmailBlocklistService::class);
    $blocklist->shouldReceive('isBlocked')->andReturnUsing(fn (?string $e): bool => $e === 'blocked@example.test');
    app()->instance(SpamEmailBlocklistService::class, $blocklist);

    expect($first->resultData['sent'])->toBeTrue()
        ->and($again->resultData)->toMatchArray(['sent' => false, 'skipped' => true, 'skip_reason' => 'rate_limited'])
        ->and($other->resultData['sent'])->toBeTrue()
        ->and($send($removed)->resultData['skip_reason'])->toBe('spam')
        ->and($send($banned)->resultData['skip_reason'])->toBe('spam')
        ->and($send(etmMessage('blocked@example.test'))->resultData['skip_reason'])->toBe('spam');
    Mail::assertSentCount(2);

    // Configurable: 0 hours turns the window off.
    config()->set('core.mail_templates.workflow.recipient_hours', 0);
    expect($send(etmMessage('lan@example.test'))->resultData['sent'])->toBeTrue();
});

/**
 * Taint lint codes of a graph: code => [type, config], edges [from, to].
 *
 * @return list<string>
 */
function etmLint(array $steps, array $edges): array
{
    $rows = [];
    foreach ($steps as $code => [$type, $config]) {
        $rows[] = ['step_code' => $code, 'step_type' => $type, 'step_config' => $config];
    }

    return array_map(
        static fn (array $p): string => $p['step_code'] . ':' . $p['code'],
        (new NodeConfigValidator(NodeRegistry::current()))->validate($rows, array_map(static fn (array $e): array => ['from' => $e[0], 'to' => $e[1]], $edges), null),
    );
}

it('needs no approval for a B-compliant step but keeps it for AI text, an address from data or free text', function () use ($managed): void {
    etmTemplate('auto');
    $start = ['trigger_event', ['event' => ContactMessageReceived::class, 'storeAs' => 'vars.trigger']];
    $ai = ['ai_classify', ['text' => '{{ vars.trigger.event.payload.display_name }}', 'labels' => ['spam', 'normal'], 'storeAs' => 'vars.spam']];
    $chain = static fn (array $mail): array => etmLint(['go' => $start, 'ai' => $ai, 'mail' => ['email_send', $mail]], [['go', 'ai'], ['ai', 'mail']]);

    expect($chain($managed('auto')))->toBe([])
        // Staff by permission is internal: also no approval.
        ->and($chain($managed('auto', ['recipient' => 'staff', 'recipient_permission' => 'contacts.viewAny'])))->toBe([])
        // AI output in the mail.
        ->and($chain($managed('auto', ['variables' => ['label' => '{{ vars.spam.label }}']])))->toBe(['mail:taint_needs_approval'])
        // An address taken from the trigger data instead of the server-resolved subject.
        ->and($chain($managed('auto', ['recipient' => 'address', 'to' => '{{ vars.trigger.event.payload.display_name }}'])))->toBe(['mail:taint_needs_approval'])
        // Free text typed in the step (text mode, or leftovers in template mode).
        ->and($chain(['to' => 'x@example.test', 'subject' => 'Hi', 'body' => '{{ vars.trigger.event.payload.display_name }}']))->toBe(['mail:taint_needs_approval'])
        ->and($chain($managed('auto', ['body' => '{{ vars.trigger.event.payload.display_name }}'])))->toBe(['mail:taint_needs_approval'])
        // An approval in front still clears them.
        ->and(etmLint(
            ['go' => $start, 'ai' => $ai, 'ok' => ['approval', ['mode' => 'human']], 'mail' => ['email_send', $managed('auto', ['variables' => ['label' => '{{ vars.spam.label }}']])]],
            [['go', 'ai'], ['ai', 'ok'], ['ok', 'mail']],
        ))->toBe([]);
});

it('refuses an unknown template key and a trigger-subject recipient without an event that declares one', function () use ($managed): void {
    $webhook = ['trigger_webhook', ['storeAs' => 'vars.trigger']];
    $contact = ['trigger_event', ['event' => ContactMessageReceived::class, 'storeAs' => 'vars.trigger']];
    etmTemplate('auto');

    expect(etmLint(['go' => $contact, 'mail' => ['email_send', $managed('missing_key')]], [['go', 'mail']]))->toBe(['mail:email_template_unavailable'])
        // A declared default counts as available (created at install).
        ->and(etmLint(['go' => $contact, 'mail' => ['email_send', $managed('contact_auto_reply')]], [['go', 'mail']]))->toBe([])
        // Not a plain key: refused, and (no longer a managed mail) the trigger data in it needs an approval again.
        ->and(etmLint(['go' => $contact, 'mail' => ['email_send', $managed('bad key!')]], [['go', 'mail']]))->toBe(['mail:config_invalid', 'mail:taint_needs_approval'])
        // Webhook data never names the trigger's own person.
        ->and(etmLint(['go' => $webhook, 'mail' => ['email_send', $managed('auto', ['variables' => []])]], [['go', 'mail']]))->toContain('mail:email_recipient_unavailable');
});

it('makes the mode, template, recipient and business switch of an e-mail step sensitive', function () use ($managed): void {
    $graph = static fn (array $mail): array => [
        'steps' => [
            ['step_code' => 'go', 'step_type' => 'trigger_event', 'step_config' => ['event' => ContactMessageReceived::class], 'is_start' => true],
            ['step_code' => 'mail', 'step_type' => 'email_send', 'step_config' => $mail, 'is_start' => false],
        ],
        'connections' => [['from' => 'go', 'to' => 'mail', 'condition' => null]],
        'settings' => [],
    ];
    $changes = static fn (array $a, array $b): array => WorkflowSensitiveFingerprint::changes(
        WorkflowSensitiveFingerprint::of($graph($a))['summary'],
        WorkflowSensitiveFingerprint::of($graph($b))['summary'],
    );
    $off = $managed('promo', ['business_enabled' => false]);

    expect($changes($off, $managed('promo', ['business_enabled' => true])))->toBe([['kind' => 'host_added', 'step_code' => 'mail', 'detail' => 'email_send business_enabled']])
        ->and($changes($managed('promo'), $managed('promo', ['business_enabled' => true])))->toBe([['kind' => 'host_added', 'step_code' => 'mail', 'detail' => 'email_send business_enabled']])
        ->and($changes($off, $managed('other', ['business_enabled' => false])))->toBe([['kind' => 'host_added', 'step_code' => 'mail', 'detail' => 'email_send template_key']])
        ->and(array_column($changes($off, $managed('promo', ['business_enabled' => false, 'recipient' => 'staff', 'recipient_permission' => 'contacts.viewAny'])), 'detail'))
        ->toBe(['email_send recipient', 'email_send recipient_permission'])
        // Wording of the variables is not sensitive.
        ->and($changes($off, $managed('promo', ['business_enabled' => false, 'variables' => ['display_name' => '{{ vars.x }}']])))->toBe([]);
});

it('stamps email_send config version 2 and keeps a version 1 config valid', function (): void {
    $definition = NodeRegistry::current()->get('email_send');
    $problems = (new NodeConfigValidator())->validate([['step_code' => 's', 'step_type' => 'email_send', 'step_config' => ['to' => 'a@example.test', 'subject' => 'S', 'body' => 'B']]], [], null);

    expect($definition->version)->toBe(2)
        ->and($definition->stamped(['mode' => 'template'])['_v'])->toBe(2)
        ->and($problems)->toBe([]);
});

it('allows only listed mail layouts: refused on save, skipped at render, existing layouts keep working', function (): void {
    expect(MailLayoutAllowlist::allows('mail.order.layout'))->toBeTrue()
        ->and(MailLayoutAllowlist::allows('journal::mail.layout'))->toBeTrue()
        ->and(MailLayoutAllowlist::allows(null))->toBeTrue()
        ->and(MailLayoutAllowlist::allows('auth.login'))->toBeFalse()
        ->and(MailLayoutAllowlist::wrap('auth.login', '<p>body</p>'))->toBe('<p>body</p>')
        ->and(MailLayoutAllowlist::wrap('mail.order.layout', '<p>body</p>'))->toContain('<p>body</p>')
        ->and(MailLayoutAllowlist::wrap('mail.order.layout', '<p>body</p>'))->not->toBe('<p>body</p>');

    $rules = (new \Cms\Core\Http\Requests\EmailTemplate\StoreEmailTemplateRequest())->rules();
    $valid = static fn (array $data): bool => !validator($data, $rules)->fails();
    $base = ['name' => 'S', 'group' => 'workflow', 'key_name' => 'k', 'content' => 'x'];
    expect($valid($base + ['blade_layout' => 'mail.order.layout', 'mail_class' => 'system']))->toBeTrue()
        ->and($valid($base + ['blade_layout' => 'auth.login']))->toBeFalse()
        ->and($valid($base + ['mail_class' => 'marketing']))->toBeFalse();

    $update = (new \Cms\Core\Http\Requests\EmailTemplate\UpdateEmailTemplateRequest())->rules();
    expect(validator(['blade_layout' => 'welcome'], $update)->fails())->toBeTrue()
        ->and(validator(['mail_class' => 'business'], $update)->fails())->toBeFalse();
});

it('creates a default row once and never overwrites or recreates it', function (): void {
    $templates = app(ManagedEmailTemplates::class);

    expect($templates->ensureDefaults(['contact_auto_reply', 'not_declared']))->toBe(['contact_auto_reply']);
    $row = EmailTemplate::query()->where('group', 'workflow')->where('key_name', 'contact_auto_reply')->sole();
    expect($row->mail_class)->toBe('system')
        ->and($row->language_id)->toBeNull()
        ->and($row->name)->toContain('{{ site_name }}');

    $row->forceFill(['name' => 'Sửa tay', 'mail_class' => 'business'])->save();
    expect($templates->ensureDefaults(['contact_auto_reply']))->toBe([])
        ->and($row->fresh()->name)->toBe('Sửa tay')
        ->and($row->fresh()->mail_class)->toBe('business');

    $row->delete();
    expect($templates->ensureDefaults(['contact_auto_reply']))->toBe([])
        ->and(EmailTemplate::query()->where('key_name', 'contact_auto_reply')->count())->toBe(0);
});

// 2026-11-27 a production site: languages stored as code `VN`/`EN` with path `vi`/`en` got only the English default
// rows, so a Vietnamese site sent English mail. A language is matched by code or path; missing language rows are added.
it('matches a language by code or path and sends the site language default', function (): void {
    DB::table('languages')->insert([
        ['name' => 'Tiếng Việt', 'code' => 'VN', 'path' => 'vi', 'default_web' => true, 'created_at' => now(), 'updated_at' => now()],
        ['name' => 'English', 'code' => 'EN', 'path' => 'en', 'default_web' => false, 'created_at' => now(), 'updated_at' => now()],
    ]);
    $templates = app(ManagedEmailTemplates::class);

    expect($templates->ensureDefaults(['contact_auto_reply']))->toBe(['contact_auto_reply'])
        ->and(EmailTemplate::query()->where('key_name', 'contact_auto_reply')->count())->toBe(2)
        ->and($templates->find('workflow', 'contact_auto_reply')->name)->toBe(config('core.mail_templates.workflow.defaults.contact_auto_reply.translations.vi.name'))
        ->and($templates->find('workflow', 'contact_auto_reply', 'en')->name)->toBe(config('core.mail_templates.workflow.defaults.contact_auto_reply.translations.en.name'));
});

it('adds the missing language rows of a key without touching an edited row or recreating a deleted one', function (): void {
    $vi = DB::table('languages')->insertGetId(['name' => 'Tiếng Việt', 'code' => 'VN', 'path' => 'vi', 'default_web' => true, 'created_at' => now(), 'updated_at' => now()]);
    $en = DB::table('languages')->insertGetId(['name' => 'English', 'code' => 'EN', 'path' => 'en', 'default_web' => false, 'created_at' => now(), 'updated_at' => now()]);
    $edited = EmailTemplate::query()->create(['name' => 'Edited EN', 'group' => 'workflow', 'key_name' => 'contact_auto_reply', 'content' => 'x', 'language_id' => $en]);
    $templates = app(ManagedEmailTemplates::class);

    expect($templates->ensureDefaults(['contact_auto_reply']))->toBe(['contact_auto_reply'])
        ->and($edited->fresh()->name)->toBe('Edited EN')
        ->and(EmailTemplate::query()->where('key_name', 'contact_auto_reply')->where('language_id', $vi)->count())->toBe(1);

    EmailTemplate::query()->where('key_name', 'contact_auto_reply')->where('language_id', $vi)->first()->delete();
    expect($templates->ensureDefaults(['contact_auto_reply']))->toBe([])
        ->and(EmailTemplate::query()->where('key_name', 'contact_auto_reply')->where('language_id', $vi)->count())->toBe(0);
});

it('serves the admin catalog, variables and a preview filled through the safe renderer', function (): void {
    $this->withoutMiddleware();
    $admin = etmUser('admin@example.test', ['email_template.viewAny', 'email_template.view', 'email_template.create', 'email_template.update']);
    $this->actingAs($admin);
    $row = etmTemplate('auto', 'system', '<p>Hi {{ display_name }} @php echo 1; @endphp</p>');
    $row->forceFill(['sample_data' => ['display_name' => '<i>Lan</i>'], 'variables' => ['display_name' => 'Sender name']])->save();
    etmTemplate('promo', 'business');

    $catalog = $this->getJson('/api/v2/admin/email-template/catalog?group=workflow')->json('data');
    expect(collect($catalog)->pluck('mail_class', 'key_name')->all())->toBe(['auto' => 'system', 'promo' => 'business']);

    $variables = $this->getJson("/api/v2/admin/email-template/{$row->id}/variables")->json('data');
    expect(collect($variables)->pluck('key')->all())->toBe(['display_name', 'site_name'])
        ->and($variables[0])->toMatchArray(['description' => 'Sender name', 'sample' => '<i>Lan</i>', 'declared' => true]);

    $preview = $this->postJson("/api/v2/admin/email-template/{$row->id}/preview", ['content' => '<p>Edited {{ display_name }}</p>'])->json('data');
    expect($preview['body'])->toBe('<p>Edited &lt;i&gt;Lan&lt;/i&gt;</p>')
        ->and($preview['html'])->toContain('<!DOCTYPE html>')
        ->and($row->fresh()->content)->toContain('Hi {{ display_name }}'); // preview never saves

    $show = $this->getJson("/api/v2/admin/email-template/{$row->id}")->json('data');
    expect($show['mail_class'])->toBe('system')->and(collect($show['variable_list'])->pluck('key')->all())->toContain('display_name');

    // The controller backs the table up to repo files after a save; never in a test.
    Artisan::shouldReceive('call')->andReturn(0);
    $this->putJson("/api/v2/admin/email-template/{$row->id}", ['mail_class' => 'business'])->assertOk();
    expect($row->fresh()->mail_class)->toBe('business');
    $this->putJson("/api/v2/admin/email-template/{$row->id}", ['blade_layout' => 'auth.login']);
    expect($row->fresh()->blade_layout)->toBeNull();
});
