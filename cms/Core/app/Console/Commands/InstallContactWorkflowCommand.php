<?php

declare(strict_types=1);

namespace Cms\Core\Console\Commands;

use App\Events\ContactMessageReceived;
use Cms\Core\Models\Email;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Mail\ManagedEmailTemplates;
use Cms\Core\Services\Workflow\Hub\WorkflowTemplateTranslations;
use Cms\Core\Services\Workflow\Setup\WorkflowGraphLayout;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Installs the sample workflow "Liên hệ mới" (`contact-new-message`, rollout plan 2026-11-24 §2 row 1):
 *
 *   a visitor sends a contact form (ContactMessageReceived: Website\AjaxController::storeContact or
 *   ContactSubmissionService, once per stored message) → read that message → "use the AI spam label?" (a setup
 *   choice, off by default) → [on] AI labels the message spam / normal → in-app notice to the admins holding the
 *   permission chosen at setup → "labelled spam?" → [no] auto-reply ("đã nhận tin") to the sender.
 *
 * Template version 2026.11.02: starts on the allowlisted app event instead of the Contact model
 * event, so a returning sender (whose e-mail or phone matches an earlier contact, which the form updates instead of
 * creating) is announced too. The event carries the message uuid, sender name, form and time only; the message is
 * read by uuid through `retrieve`, so the run user still needs `email.viewAny`. 2026.11.01 started on
 * `App\Models\Contact` created; a hub install of it updates in place (step codes `load_message`, `use_ai`,
 * `spam_label`, `notify_admins` and their setup answers stay; `contact_created` becomes `message_received`).
 *
 * Template version 2026.11.03: adds `is_spam` (a decision on the AI label; false
 * when the label is off) and `auto_reply`, an `email_send` step in template mode (node version 2) that sends the managed
 * template `contact_auto_reply` (group `workflow`, class `system`, created on install by
 * ManagedEmailTemplates::ensureDefaults and never overwritten) to the sender of the stored message, looked up by the
 * event's `message_uuid` on the server (never from form text). Abuse guard in the node: no mail when the message was
 * removed or marked spam, the contact is banned or the address is blocklisted, and at most one per address per
 * `core.mail_templates.workflow.recipient_hours`. A site on 2026.11.02 updates in place from the hub (new steps added,
 * setup answers kept).
 *
 * The notice is internal and the auto-reply meets policy B, so no approval step.
 * The notice marks the form text as typed by the visitor and not checked. The event is dispatched after the form's
 * transaction commits, so the message row exists.
 *
 * Add-only and safe to run again: an existing (or deleted) project is left exactly as it is. Created INACTIVE and without
 * a schedule; the notify permission is left empty so setup asks "who is told" before it can be switched on.
 */
final class InstallContactWorkflowCommand extends Command
{
    public const PROJECT = 'contact-new-message';

    /** Key (group `workflow`) of the default auto-reply; stable, admins edit the row in "Mẫu email". */
    public const AUTO_REPLY_TEMPLATE = 'contact_auto_reply';

    protected $signature = 'cms:workflow-install-contact {--locale= : Language of the names and notice texts, vi or en (default: the site language)} {--suffix= : Added to the workflow slug, e.g. -en to build a second language next to the first}';

    protected $description = 'Install the sample workflow "Liên hệ mới" (inactive, no schedule).';

    public function handle(): int
    {
        $locale = WorkflowTemplateTranslations::commandLocale($this->option('locale'));
        $suffix = (string) $this->option('suffix');
        if ($locale === null || preg_match('/\A[a-z0-9_-]{0,20}\z/', $suffix) !== 1) {
            $this->error('--locale must be vi or en; --suffix may only use a-z, 0-9, - and _.');

            return self::FAILURE;
        }
        $slug = self::PROJECT . $suffix;
        $existing = WorkflowProject::withTrashed()->where('slug', $slug)->first();
        if ($existing !== null) {
            $this->line('Workflow ' . $slug . ($existing->trashed() ? ' was deleted; left deleted.' : ' exists; left unchanged.'));

            return self::SUCCESS;
        }

        // The auto-reply the template sends; created once, an edited or removed row is left as it is.
        app(ManagedEmailTemplates::class)->ensureDefaults([self::AUTO_REPLY_TEMPLATE]);

        [$text, $steps] = WorkflowTemplateTranslations::applyToDefinition(self::TEXT, $locale, [
            'name' => 'Liên hệ mới',
            'description' => 'Khi có người gửi biểu mẫu liên hệ, báo ngay cho quản trị viên kèm tên, tiêu đề và biểu mẫu, rồi gửi thư tự động "đã nhận tin" cho người gửi (mẫu "contact_auto_reply" trong Mẫu email; mỗi địa chỉ tối đa một thư mỗi ngày, không gửi cho thư rác). Có thể bật AI đánh dấu thư rác.',
        ], self::steps());

        DB::transaction(static function () use ($slug, $text, $steps): void {
            $project = WorkflowProject::query()->create([
                'name' => $text['name'],
                'slug' => $slug,
                'target_entity_type' => 'none',
                'is_active' => false,
                'tags' => ['contact', 'notice', 'sample'],
                'description' => $text['description'],
            ]);

            $made = [];
            // Laid out top to bottom as the designer's "Tidy up" does.
            $positions = WorkflowGraphLayout::tidy(array_keys($steps), self::edges());
            foreach ($steps as $code => [$type, $name, $config]) {
                [$x, $y] = $positions[$code];
                $made[$code] = WorkflowStep::query()->create([
                    'project_id' => $project->id,
                    'step_code' => $code,
                    'name' => $name,
                    'step_type' => $type,
                    'is_start' => $code === 'message_received',
                    'position_x' => $x,
                    'position_y' => $y,
                    // Stamped with the node's current config version (trigger_event is 2) so the template declares it.
                    'step_config' => \Cms\Core\Workflows\Nodes\NodeRegistry::current()->get($type)?->stamped($config) ?? $config,
                ]);
            }

            foreach (self::edges() as [$from, $to, $handle]) {
                WorkflowConnection::query()->create([
                    'project_id' => $project->id,
                    'from_step_id' => $made[$from]->id,
                    'to_step_id' => $made[$to]->id,
                    'condition' => $handle === null ? null : ['source_handle' => $handle, 'condition_type' => 'equals', 'condition_value' => $handle],
                ]);
            }
        });

        $this->info('Created workflow ' . $slug . ' (inactive, no schedule).');

        return self::SUCCESS;
    }

    /**
     * The template in other languages ({@see WorkflowTemplateTranslations}); the Vietnamese text above is the base.
     * `--locale=en` writes it; a hub bundle carries it when built with `--translation=en:<slug of that install>`.
     */
    public const TEXT = [
        'en' => [
            'name' => 'New contact',
            'description' => 'When someone sends a contact form, tells the admins right away with the name, subject and form, then sends the sender an automatic "we got your message" e-mail (template "contact_auto_reply" in Email Templates; at most one e-mail per address per day, none to spam). AI spam flagging can be switched on.',
            'steps' => [
                'message_received' => ['name' => 'New contact message'],
                'load_message' => ['name' => 'Read the message just sent'],
                'use_ai' => ['name' => 'Use AI to flag spam?'],
                'spam_label' => ['name' => 'AI flags spam', 'config' => ['labels' => [
                    ['key' => 'spam', 'description' => 'Advertising, scams, strange links or automatic mail unrelated to the site'],
                    ['key' => 'normal', 'description' => 'A real person asking, commenting or asking for help'],
                ]]],
                'notify_admins' => ['name' => 'Tell the admins', 'config' => ['message' => self::MESSAGE_EN]],
                'is_spam' => ['name' => 'Did the AI flag it as spam?'],
                'auto_reply' => ['name' => 'Automatic reply to the sender'],
            ],
        ],
    ];

    /** {@see self::MESSAGE} in English. */
    public const MESSAGE_EN = "{{ vars.spam.label == 'spam' ? '[AI: may be spam] ' : '' }}"
        . 'New contact from {{ vars.trigger.event.payload.display_name }} (form “{{ vars.trigger.event.payload.form }}”). '
        . 'Typed by the visitor, not checked: {{ coalesce(vars.message.attributes.raw_data.subject, vars.message.attributes.content) }}';

    /**
     * Steps in graph order: code => [type, name, config].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function steps(): array
    {
        return [
            'message_received' => ['trigger_event', 'Có liên hệ mới', [
                'event' => ContactMessageReceived::class,
                'schema' => ContactMessageReceived::payloadSchema(),
                'storeAs' => 'vars.trigger',
            ]],
            'load_message' => ['retrieve', 'Đọc tin nhắn vừa gửi', [
                'model' => Email::class,
                'where' => [
                    ['field' => 'uuid', 'op' => '==', 'valueExpr' => 'vars.trigger.event.payload.message_uuid'],
                    ['field' => 'type', 'op' => '==', 'valueExpr' => "'inbox'"],
                ],
                'orderBy' => [['field' => 'id', 'dir' => 'desc']],
                'first' => true, 'limit' => 1, 'includeTrashed' => false, 'storeAs' => 'vars.message',
            ]],
            // Setup choice (`ask_site`): off until the site switches it on; the answer becomes 'true' / 'false'.
            'use_ai' => ['decision', 'Dùng AI đánh dấu thư rác?', [
                'expression' => 'false', 'ask_site' => true,
            ]],
            'spam_label' => ['ai_classify', 'AI đánh dấu thư rác', [
                'text' => "{{ vars.message.attributes.subject }}\n{{ vars.message.attributes.content }}",
                'labels' => [
                    ['key' => 'spam', 'description' => 'Quảng cáo, lừa đảo, liên kết lạ hoặc thư tự động không liên quan đến trang'],
                    ['key' => 'normal', 'description' => 'Một người thật hỏi, góp ý hoặc nhờ giúp'],
                ],
                'multi' => false, 'fallback' => 'normal', 'storeAs' => 'vars.spam',
            ]],
            'notify_admins' => ['notify', 'Báo quản trị viên', [
                'permission' => null,
                'message' => self::MESSAGE,
                'storeAs' => 'vars.notified',
            ]],
            // No auto-reply to a message the AI labelled spam (the label is empty while the AI step is off).
            'is_spam' => ['decision', 'AI cho là thư rác?', [
                'expression' => "vars.spam != null and vars.spam.label == 'spam'",
            ]],
            // Policy B: managed template, the stored sender of the message, filled values escaped.
            'auto_reply' => ['email_send', 'Trả lời tự động cho người gửi', [
                'mode' => 'template',
                'template_key' => self::AUTO_REPLY_TEMPLATE,
                'recipient' => 'trigger_subject',
                'variables' => ['display_name' => '{{ vars.trigger.event.payload.display_name }}'],
                'storeAs' => 'vars.auto_reply',
            ]],
        ];
    }

    /**
     * The notice (cut to 300 characters by the notify node): the AI label first when it ran, then who wrote in through
     * which form, then the subject (or the start of the message) marked as visitor text that nobody checked.
     */
    public const MESSAGE = "{{ vars.spam.label == 'spam' ? '[AI: có thể là thư rác] ' : '' }}"
        . 'Liên hệ mới từ {{ vars.trigger.event.payload.display_name }} (biểu mẫu “{{ vars.trigger.event.payload.form }}”). '
        . 'Khách tự nhập, chưa kiểm tra: {{ coalesce(vars.message.attributes.raw_data.subject, vars.message.attributes.content) }}';

    /**
     * Edges [from, to, output handle].
     *
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    public static function edges(): array
    {
        return [
            ['message_received', 'load_message', null],
            ['load_message', 'use_ai', null],
            ['use_ai', 'spam_label', 'true'],
            ['use_ai', 'notify_admins', 'false'],
            ['spam_label', 'notify_admins', null],
            ['notify_admins', 'is_spam', null],
            ['is_spam', 'auto_reply', 'false'],
        ];
    }
}
