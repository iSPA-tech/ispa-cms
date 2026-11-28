<?php

declare(strict_types=1);

namespace Cms\Content\Console\Commands;

use Cms\Content\Models\Post;
use Cms\Core\Models\Email;
use Cms\Core\Models\Workflows\WorkflowConnection;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Models\Workflows\WorkflowStep;
use Cms\Core\Services\Workflow\Hub\WorkflowTemplateTranslations;
use Cms\Core\Services\Workflow\Setup\WorkflowGraphLayout;
use Cms\Core\Workflows\Nodes\NodeRegistry;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Installs the sample workflow "Báo cáo tuần" (`weekly-site-report`, hub template `ispa.weekly-report`, rollout plan
 * 2026-11-27 Task R):
 *
 *   on the schedule the site picks → find the contact messages received in the last 7 days (Core Email, type `inbox`,
 *   written by both contact form writers) → find the posts published in the last 7 days (Content Post, published
 *   status, published_at in the window) → "anything new?" → [yes] one in-app notice to the admins chosen at setup with
 *   both counts and where to read them.
 *
 * Built only from nodes every site already has (trigger_schedule, retrieve, decision, notify, all at config version 1),
 * so a site installs it from the hub without a code deploy. Owner choices (2026-11-27): contacts and posts only (new
 * accounts are left out because no workflow may read `App\Models\User`; the "Tài khoản mới" template announces each
 * account), the admin bell rather than e-mail (a new managed e-mail template would need a deploy), and no notice in a
 * week with nothing new (the run still completes).
 *
 * Each list stops at LIMIT rows (the notice then says "100+"), so a flood of spam never makes a huge run. The run keeps
 * the rows it read (as every `retrieve` does), so the run user must be allowed `viewAny` on contact messages and posts;
 * the notice itself carries counts only, never names or message text.
 *
 * The schedule is a setup question (the trigger has no cron until the site answers), so the person who answers it
 * becomes the user the runs act for. Runs need WORKFLOW_SCHEDULER_ENABLED=true on the site. Internal notice only, so
 * no approval step. Add-only and safe to run again: an existing (or deleted) project is left exactly as it is. Created
 * INACTIVE, without a schedule and with the notice permission empty, so setup asks "when" and "who is told".
 */
final class InstallWeeklyReportWorkflowCommand extends Command
{
    public const PROJECT = 'weekly-site-report';

    /** Rows read per list; the notice shows "100+" when a list is full. */
    public const LIMIT = 100;

    /** Start of the window: 7 days before the run, in the site's time zone. */
    private const SINCE = "formatDate('-7 days', 'Y-m-d H:i:s')";

    /**
     * The notice: the window, both counts, then where to read them. About 160 characters, well under the notify cut.
     */
    public const MESSAGE = "7 ngày qua ({{ formatDate('-7 days', 'd/m') }} – {{ formatDate('now', 'd/m') }}): "
        . "{{ len(vars.contacts) >= 100 ? '100+' : len(vars.contacts) }} tin liên hệ mới, "
        . "{{ len(vars.posts) >= 100 ? '100+' : len(vars.posts) }} bài viết mới đăng. "
        . 'Xem ở mục Liên hệ và Bài viết trong trang quản trị.';

    protected $signature = 'content:workflow-install-weekly-report {--locale= : Language of the names and notice texts, vi or en (default: the site language)} {--suffix= : Added to the workflow slug, e.g. -en to build a second language next to the first}';

    protected $description = 'Install the sample workflow "Báo cáo tuần" (inactive, no schedule).';

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

        [$text, $steps] = WorkflowTemplateTranslations::applyToDefinition(self::TEXT, $locale, [
            'name' => 'Báo cáo tuần',
            'description' => 'Theo lịch trang chọn (ví dụ sáng thứ Hai), đếm tin liên hệ mới và bài viết mới đăng trong 7 ngày qua rồi báo cho quản trị viên trong trang quản trị. Tuần không có gì mới thì không báo.',
        ], self::steps());

        DB::transaction(static function () use ($slug, $text, $steps): void {
            $project = WorkflowProject::query()->create([
                'name' => $text['name'],
                'slug' => $slug,
                'target_entity_type' => 'none',
                'is_active' => false,
                'tags' => ['report', 'contact', 'posts', 'notice', 'sample'],
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
                    'is_start' => $code === 'schedule',
                    'position_x' => $x,
                    'position_y' => $y,
                    'step_config' => NodeRegistry::current()->get($type)?->stamped($config) ?? $config,
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
            'name' => 'Weekly report',
            'description' => 'On the schedule the site picks (for example Monday morning), counts the new contact messages and the posts published in the last 7 days and tells the admins in the admin area. A week with nothing new sends nothing.',
            'steps' => [
                'schedule' => ['name' => 'Every week'],
                'contacts' => ['name' => 'Find contact messages of the last 7 days'],
                'posts' => ['name' => 'Find posts published in the last 7 days'],
                'anything_new' => ['name' => 'Anything new?'],
                'notify_admins' => ['name' => 'Tell the admins', 'config' => ['message' => self::MESSAGE_EN]],
            ],
        ],
    ];

    /** {@see self::MESSAGE} in English. */
    public const MESSAGE_EN = "Last 7 days ({{ formatDate('-7 days', 'j M') }} – {{ formatDate('now', 'j M') }}): "
        . "{{ len(vars.contacts) >= 100 ? '100+' : len(vars.contacts) }} new contact messages, "
        . "{{ len(vars.posts) >= 100 ? '100+' : len(vars.posts) }} new posts. "
        . 'See Contacts and Posts in the admin area.';

    /**
     * Steps in graph order: code => [type, name, config].
     *
     * @return array<string, array{0: string, 1: string, 2: array<string, mixed>}>
     */
    public static function steps(): array
    {
        return [
            'schedule' => ['trigger_schedule', 'Mỗi tuần', ['cron' => '', 'timezone' => null, 'storeAs' => 'vars.trigger']],
            'contacts' => ['retrieve', 'Tìm tin liên hệ 7 ngày qua', [
                'model' => Email::class,
                'where' => [
                    ['field' => 'type', 'op' => '==', 'valueExpr' => "'inbox'"],
                    ['field' => 'created_at', 'op' => '>=', 'valueExpr' => self::SINCE],
                ],
                'orderBy' => [['field' => 'created_at', 'dir' => 'desc']],
                'first' => false, 'limit' => self::LIMIT, 'includeTrashed' => false, 'storeAs' => 'vars.contacts',
            ]],
            'posts' => ['retrieve', 'Tìm bài viết mới đăng 7 ngày qua', [
                'model' => Post::class,
                'where' => [
                    // `published` is the key of sites that have not run the 2026-11-26 status key migration yet.
                    ['field' => 'status', 'op' => 'in', 'valueExpr' => "['status_published', 'published']"],
                    ['field' => 'published_at', 'op' => '>=', 'valueExpr' => self::SINCE],
                    ['field' => 'published_at', 'op' => '<=', 'valueExpr' => "formatDate('now', 'Y-m-d H:i:s')"],
                ],
                'orderBy' => [['field' => 'published_at', 'dir' => 'desc']],
                'first' => false, 'limit' => self::LIMIT, 'includeTrashed' => false, 'storeAs' => 'vars.posts',
            ]],
            'anything_new' => ['decision', 'Có gì mới?', [
                'expression' => 'len(vars.contacts) + len(vars.posts) > 0',
            ]],
            'notify_admins' => ['notify', 'Báo quản trị viên', [
                'permission' => null,
                'message' => self::MESSAGE,
                'storeAs' => 'vars.notified',
            ]],
        ];
    }

    /**
     * Edges [from, to, output handle].
     *
     * @return list<array{0: string, 1: string, 2: string|null}>
     */
    public static function edges(): array
    {
        return [
            ['schedule', 'contacts', null],
            ['contacts', 'posts', null],
            ['posts', 'anything_new', null],
            ['anything_new', 'notify_admins', 'true'],
        ];
    }
}
