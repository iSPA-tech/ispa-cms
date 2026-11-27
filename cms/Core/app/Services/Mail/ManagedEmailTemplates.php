<?php

declare(strict_types=1);

namespace Cms\Core\Services\Mail;

use App\Support\Mail\MailBrand;
use Cms\Core\Models\EmailTemplate;
use Cms\Core\Models\Language;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Managed e-mail templates (`email_templates`, edited in the admin "Mẫu email" screen) as the workflow `email_send`
 * node and the admin preview use them.
 *
 * - find(): a template by group + key in a language: the asked language (code or id), else the site's default web
 *   language, else the row without a language, else any row of the key (lowest id).
 * - render(): subject (`name`, plain text, one line, 200 characters) and HTML body (`content`) filled through
 *   SafeTemplateRenderer: every value escaped and treated as untrusted, unknown placeholders empty, nothing compiled.
 *   The built-in values `site_name` and `site_url` are added unless the caller gives them.
 * - variables(): the declared `variables` of a row plus every placeholder its subject and body use, with sample values.
 * - ensureDefaults(): the default rows of group `workflow` declared in `core.mail_templates.workflow.defaults`, created
 *   while the key has no row at all (a deleted row counts), so an edited or removed default is never overwritten. A key
 *   that already has rows per language gets the rows of the site's other languages it lacks (never one that was
 *   deleted); a key kept as one row without a language is left alone.
 * - A language is matched by its `code`, else its `path`, ignoring case (sites store `VN`/`vi`, `EN`/`en`).
 * - keysUsedBy(): template keys of the `email_send` steps of a graph in template mode.
 *
 * @package Cms\Core\Services\Mail
 * @since   2026-11-25
 */
class ManagedEmailTemplates
{
    public const SUBJECT_MAX = 200;

    /** Placeholder names in a stored subject or body (`{{ key }}`, `{{ $key }}`, `{!! key !!}`, `{key}`). */
    private const PLACEHOLDER = '/\{\{\s*\$?([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*)\s*\}\}|\{!!\s*\$?([A-Za-z_][A-Za-z0-9_]*(?:\.[A-Za-z0-9_]+)*)\s*!!\}|(?<![{@])\{([A-Za-z_][A-Za-z0-9_.]*)\}(?!\})/';

    public function __construct(protected SafeTemplateRenderer $renderer = new SafeTemplateRenderer())
    {
    }

    /**
     * @param string|int|null $language Language code (`vi`), id, or null for the site default.
     */
    public function find(string $group, string $key, string|int|null $language = null): ?EmailTemplate
    {
        $query = static fn () => EmailTemplate::query()->where('group', $group)->where('key_name', $key);

        $languageId = $this->languageId($language);
        if ($languageId !== null && ($row = $query()->where('language_id', $languageId)->first()) !== null) {
            return $row;
        }

        $defaultId = $this->defaultLanguageId();
        if ($defaultId !== null && ($row = $query()->where('language_id', $defaultId)->first()) !== null) {
            return $row;
        }

        return $query()->whereNull('language_id')->first() ?? $query()->orderBy('id')->first();
    }

    /** Whether a workflow template key can be used here: a row exists or a default is declared for it. */
    public function knows(string $key): bool
    {
        $key = trim($key);

        return $key !== '' && (EmailTemplate::query()->where('group', EmailTemplate::GROUP_WORKFLOW)->where('key_name', $key)->exists()
            || is_array(config('core.mail_templates.workflow.defaults.' . $key)));
    }

    /**
     * Filled subject and body of a template.
     *
     * @param array<string, mixed> $data Values (untrusted; all escaped in the body).
     * @return array{subject: string, html: string}
     */
    public function render(EmailTemplate $template, array $data): array
    {
        $data += $this->builtins();
        $subject = $this->renderer->renderText((string) $template->name, $data);
        $subject = trim((string) preg_replace('/\s+/u', ' ', $subject));

        return [
            'subject' => mb_substr($subject, 0, self::SUBJECT_MAX),
            'html' => $this->renderer->render((string) $template->content, $data),
        ];
    }

    /**
     * Values every template may use.
     *
     * @return array{site_name: string, site_url: string}
     */
    public function builtins(): array
    {
        try {
            $name = MailBrand::name();
        } catch (Throwable) {
            $name = (string) config('app.name');
        }

        return ['site_name' => $name, 'site_url' => rtrim((string) config('app.url'), '/')];
    }

    /**
     * Variables of a template: declared ones first, then placeholders found in the subject and body.
     *
     * @return list<array{key: string, description: string|null, sample: string|null, declared: bool, builtin: bool}>
     */
    public function variables(EmailTemplate $template): array
    {
        $declared = [];
        foreach ((array) ($template->variables ?? []) as $k => $v) {
            if (is_string($k) && $k !== '') {
                $declared[$k] = is_scalar($v) ? (string) $v : null;
            } elseif (is_string($v) && $v !== '') {
                $declared[$v] = null;
            } elseif (is_array($v) && is_string($v['key'] ?? null)) {
                $declared[$v['key']] = is_string($v['description'] ?? null) ? $v['description'] : null;
            }
        }

        $used = $this->placeholders((string) $template->name . "\n" . (string) $template->content);
        $samples = is_array($template->sample_data) ? $template->sample_data : [];
        $builtins = $this->builtins();

        $out = [];
        foreach (array_unique([...array_keys($declared), ...$used]) as $key) {
            $sample = data_get($samples, $key, $builtins[$key] ?? null);
            $out[] = [
                'key' => (string) $key,
                'description' => $declared[$key] ?? null,
                'sample' => is_scalar($sample) ? (string) $sample : null,
                'declared' => array_key_exists($key, $declared),
                'builtin' => array_key_exists($key, $builtins),
            ];
        }

        return $out;
    }

    /**
     * @return list<string>
     */
    public function placeholders(string $text): array
    {
        preg_match_all(self::PLACEHOLDER, $text, $m, PREG_SET_ORDER);
        $keys = [];
        foreach ($m as $match) {
            $key = ($match[1] ?? '') !== '' ? $match[1] : (($match[2] ?? '') !== '' ? $match[2] : ($match[3] ?? ''));
            if ($key !== '') {
                $keys[$key] = true;
            }
        }

        return array_keys($keys);
    }

    /**
     * Create the missing default rows of these workflow template keys.
     *
     * @param list<string> $keys
     * @return list<string> keys whose rows were created now
     */
    public function ensureDefaults(array $keys): array
    {
        $created = [];
        foreach (array_unique(array_filter($keys, 'is_string')) as $key) {
            $default = config('core.mail_templates.workflow.defaults.' . $key);
            if (!is_array($default) || !is_array($default['translations'] ?? null) || $default['translations'] === []) {
                continue;
            }

            $made = DB::transaction(function () use ($key, $default): bool {
                // Every row of the key, deleted ones too: language id => true ('' for a row without a language).
                $existing = EmailTemplate::withTrashed()->where('group', EmailTemplate::GROUP_WORKFLOW)
                    ->where('key_name', $key)->lockForUpdate()->pluck('language_id')
                    ->mapWithKeys(static fn ($id): array => [($id === null ? '' : (string) $id) => true])->all();
                if (isset($existing[''])) {
                    // Kept as one row without a language: that row serves every language; nothing is added.
                    return false;
                }

                $class = in_array($default['mail_class'] ?? null, EmailTemplate::MAIL_CLASSES, true) ? $default['mail_class'] : EmailTemplate::CLASS_BUSINESS;
                $rows = [];
                foreach ($default['translations'] as $code => $text) {
                    $languageId = $this->languageId((string) $code);
                    if ($languageId !== null && is_array($text) && !isset($existing[(string) $languageId])) {
                        $rows[$languageId] = $text;
                    }
                }
                if ($existing !== []) {
                    // The key exists: only the languages it lacks (and never one whose row was deleted).
                    if ($rows === []) {
                        return false;
                    }
                } elseif ($rows === []) {
                    // A site without those languages: one row with no language, in the first translation.
                    $rows[''] = reset($default['translations']);
                }

                foreach ($rows as $languageId => $text) {
                    $template = new EmailTemplate([
                        'name' => (string) ($text['name'] ?? ''),
                        'group' => EmailTemplate::GROUP_WORKFLOW,
                        'mail_class' => $class,
                        'key_name' => $key,
                        'content' => (string) ($text['content'] ?? ''),
                        'language_id' => $languageId === '' ? null : (int) $languageId,
                        'blade_layout' => null,
                        'variables' => is_array($default['variables'] ?? null) ? $default['variables'] : null,
                        'sample_data' => is_array($default['sample_data'] ?? null) ? $default['sample_data'] : null,
                    ]);
                    $plain = trim(strip_tags($template->content));
                    $template->description = mb_strlen($plain) > 200 ? mb_substr($plain, 0, 197) . '...' : $plain;
                    $template->save();
                }

                return true;
            });

            if ($made) {
                $created[] = $key;
            }
        }

        return $created;
    }

    /**
     * Template keys of the `email_send` steps in template mode.
     *
     * @param iterable<array<string, mixed>> $steps rows with `step_type` and `step_config`
     * @return list<string>
     */
    public function keysUsedBy(iterable $steps): array
    {
        $keys = [];
        foreach ($steps as $step) {
            $config = is_array($step['step_config'] ?? null) ? $step['step_config'] : [];
            if (($step['step_type'] ?? null) === 'email_send' && ($config['mode'] ?? null) === 'template'
                && is_string($config['template_key'] ?? null) && trim($config['template_key']) !== '') {
                $keys[trim($config['template_key'])] = true;
            }
        }

        return array_keys($keys);
    }

    protected function languageId(string|int|null $language): ?int
    {
        if (is_int($language) || (is_string($language) && ctype_digit($language))) {
            return (int) $language > 0 ? (int) $language : null;
        }
        $code = strtolower(trim((string) $language));
        if ($code === '') {
            return null;
        }
        $code = explode('-', str_replace('_', '-', $code))[0];

        try {
            $id = Language::query()->whereRaw('LOWER(code) = ?', [$code])->orderBy('id')->value('id')
                ?? Language::query()->whereRaw('LOWER(path) = ?', [$code])->orderBy('id')->value('id');
        } catch (Throwable) {
            return null;
        }

        return $id === null ? null : (int) $id;
    }

    protected function defaultLanguageId(): ?int
    {
        try {
            $id = Language::query()->where('default_web', true)->orderBy('id')->value('id');
        } catch (Throwable) {
            return null;
        }

        return $id === null ? null : (int) $id;
    }
}
