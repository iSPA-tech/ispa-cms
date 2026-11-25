<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration;

use Cms\Core\Mail\WorkflowMessageMail;
use Cms\Core\Models\EmailTemplate;
use Cms\Core\Services\Mail\ManagedEmailTemplates;
use Cms\Core\Services\Workflow\Credentials\SmtpMailerFactory;
use Cms\Core\Services\Workflow\Mail\TriggerSubjectResolver;
use Cms\Core\Workflows\Runtime\Runners\Human\HumanNotifier;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

/**
 * `email_send`: a branded mail (`mail.branded`, the shared `<x-email.layout>` frame) to one or more addresses.
 *
 * Config: `to` (address, list or comma-separated; template), `subject` (template), `body` (plain text template; lines
 * become paragraphs, HTML is escaped), `reply_to` (optional), `credential` (optional $bind:<smtp>; without it the
 * site's own mailer sends), `storeAs`. At most `max_recipients` (setting, default 20) addresses.
 * Result: {sent, recipients}.
 *
 * Template mode: the mail is the managed template
 * `template_key` of group `workflow` (ManagedEmailTemplates::find, language from `language` with fallback to the site
 * default, then any), filled through SafeTemplateRenderer with `variables` (name => `{{ expression }}` template, each
 * value escaped and treated as untrusted) plus `recipient_name`, `site_name` and `site_url`, inside the same branded
 * frame. Subject and body typed in the step, `to` and `reply_to` are not used unless `recipient` is `address`.
 * - Class `business` and `business_enabled` off: nothing is sent; result `skipped: true, skip_reason: business_mail_off`.
 * - `recipient: trigger_subject`: the trigger's own account / sender, looked up on the server (TriggerSubjectResolver;
 *   never an address from the payload). Abuse guard (contact auto-reply, sign-up welcome): skipped as `spam` when the
 *   message was removed or marked spam, the sender or account is banned or the address is blocklisted, and as
 *   `rate_limited` after `core.mail_templates.workflow.recipient_max` mails per address and template within
 *   `recipient_hours`.
 * - `recipient: staff`: the users holding `recipient_permission` (HumanNotifier::recipients).
 * Result: {sent, recipients, skipped, skip_reason?, mail_class}. Addresses are never stored or logged.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration
 * @since   2026-11-24
 */
class EmailSendRunner extends IntegrationRunner
{
    protected function type(): string
    {
        return 'email_send';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        if (EmailSendPolicy::mode($config) === EmailSendPolicy::MODE_TEMPLATE) {
            return $this->sendTemplate($config, $context);
        }

        $smtp = ($config['credential'] ?? '') !== '' && $config['credential'] !== null
            ? $this->bind($config['credential'], 'smtp')
            : null;

        $recipients = $this->recipients($this->render($config['to'] ?? '', $context));
        $max = max(1, (int) config('core.workflow_nodes.messaging.settings.email_max_recipients', 20));
        if ($recipients === []) {
            throw new IntegrationFailure('to has no valid email address.');
        }
        if (count($recipients) > $max) {
            throw new IntegrationFailure("too many recipients (max {$max}).");
        }

        $subject = mb_substr($this->renderText($config['subject'] ?? '', $context), 0, 200);
        $body = $this->renderText($config['body'] ?? '', $context);
        if ($subject === '' || $body === '') {
            throw new IntegrationFailure('subject and body are required.');
        }

        $replyTo = $this->renderText($config['reply_to'] ?? '', $context);
        $mail = new WorkflowMessageMail($subject, $body, filter_var($replyTo, FILTER_VALIDATE_EMAIL) ? $replyTo : null);

        if ($smtp !== null) {
            $mail->from((string) $smtp['from_address'], ($smtp['from_name'] ?? '') !== '' ? (string) $smtp['from_name'] : null);
            app(SmtpMailerFactory::class)->make($smtp)->to($recipients)->send($mail);
        } else {
            Mail::to($recipients)->send($mail);
        }

        $result = ['sent' => true, 'recipients' => count($recipients)];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'email_send',
            'recipients' => count($recipients),
            'own_server' => $smtp !== null,
            'body_length' => mb_strlen($body),
        ]]);
    }

    /**
     * Template mode (see the class comment).
     *
     * @param array<string, mixed> $config
     */
    private function sendTemplate(array $config, WorkflowContext $context): StepRunResult
    {
        $key = EmailSendPolicy::templateKey($config);
        if ($key === null) {
            throw new IntegrationFailure('template_key must name an e-mail template.');
        }

        $templates = app(ManagedEmailTemplates::class);
        $language = $this->renderText($config['language'] ?? '', $context);
        $template = $templates->find(EmailTemplate::GROUP_WORKFLOW, $key, $language !== '' ? $language : null);
        if ($template === null) {
            throw new IntegrationFailure("e-mail template {$key} not found.");
        }

        $class = in_array($template->mail_class, EmailTemplate::MAIL_CLASSES, true) ? (string) $template->mail_class : EmailTemplate::CLASS_BUSINESS;
        if ($class === EmailTemplate::CLASS_BUSINESS && !EmailSendPolicy::businessEnabled($config)) {
            return $this->skipped($config, $context, 'business_mail_off', $class, $key);
        }

        $kind = EmailSendPolicy::recipient($config);
        $data = [];
        $recipients = [];
        if ($kind === EmailSendPolicy::RECIPIENT_TRIGGER_SUBJECT) {
            $subject = app(TriggerSubjectResolver::class)->resolve($context);
            if (isset($subject['skip'])) {
                return $this->skipped($config, $context, $subject['skip'], $class, $key);
            }
            if ($this->rateLimited($key, $subject['email'])) {
                return $this->skipped($config, $context, 'rate_limited', $class, $key);
            }
            $recipients = [$subject['email']];
            $data['recipient_name'] = $subject['name'] ?? '';
        } elseif ($kind === EmailSendPolicy::RECIPIENT_STAFF) {
            $permission = EmailSendPolicy::staffPermission($config);
            if ($permission === null) {
                throw new IntegrationFailure('recipient_permission must name a permission.');
            }
            $max = max(1, (int) config('core.workflow_nodes.messaging.settings.email_max_recipients', 20));
            foreach (app(HumanNotifier::class)->recipients($permission, [], $max) as $user) {
                $email = strtolower(trim((string) $user->email));
                if ($email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    $recipients[$email] = $email;
                }
            }
            $recipients = array_values($recipients);
            if ($recipients === []) {
                return $this->skipped($config, $context, 'no_recipient', $class, $key);
            }
        } else {
            $recipients = $this->recipients($this->render($config['to'] ?? '', $context));
            $max = max(1, (int) config('core.workflow_nodes.messaging.settings.email_max_recipients', 20));
            if ($recipients === []) {
                throw new IntegrationFailure('to has no valid email address.');
            }
            if (count($recipients) > $max) {
                throw new IntegrationFailure("too many recipients (max {$max}).");
            }
        }

        foreach ((array) ($config['variables'] ?? []) as $name => $expression) {
            if (is_string($name) && preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $name) === 1 && is_scalar($expression)) {
                $data[$name] = mb_substr($this->renderText((string) $expression, $context), 0, 2000);
            }
        }

        ['subject' => $subject, 'html' => $html] = $templates->render($template, $data);
        if ($subject === '' || trim(strip_tags($html)) === '') {
            throw new IntegrationFailure("e-mail template {$key} has an empty subject or body.");
        }

        $mail = WorkflowMessageMail::fromTemplate($subject, $html);
        $smtp = ($config['credential'] ?? '') !== '' && $config['credential'] !== null
            ? $this->bind($config['credential'], 'smtp')
            : null;
        if ($smtp !== null) {
            $mail->from((string) $smtp['from_address'], ($smtp['from_name'] ?? '') !== '' ? (string) $smtp['from_name'] : null);
            app(SmtpMailerFactory::class)->make($smtp)->to($recipients)->send($mail);
        } else {
            Mail::to($recipients)->send($mail);
        }

        $result = ['sent' => true, 'recipients' => count($recipients), 'skipped' => false, 'mail_class' => $class];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'email_send',
            'mode' => 'template',
            'template_key' => $key,
            'mail_class' => $class,
            'recipient' => $kind,
            'recipients' => count($recipients),
            'own_server' => $smtp !== null,
        ]]);
    }

    /**
     * A step that sends nothing on purpose (policy B): a clear result, not a failure.
     *
     * @param array<string, mixed> $config
     */
    private function skipped(array $config, WorkflowContext $context, string $reason, string $class, string $key): StepRunResult
    {
        $result = ['sent' => false, 'recipients' => 0, 'skipped' => true, 'skip_reason' => $reason, 'mail_class' => $class];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'level' => 'info',
            'message' => 'email_send skipped: ' . str_replace('_', ' ', $reason),
            'action' => 'email_send',
            'template_key' => $key,
        ]]);
    }

    /**
     * Claim one send to this address with this template; true when the window's limit is used up.
     */
    private function rateLimited(string $key, string $email): bool
    {
        $hours = (int) config('core.mail_templates.workflow.recipient_hours', 24);
        if ($hours <= 0) {
            return false;
        }
        $max = max(1, (int) config('core.mail_templates.workflow.recipient_max', 1));
        $limiter = 'workflow-mail:' . hash('sha256', $key . '|' . strtolower($email));
        if (RateLimiter::tooManyAttempts($limiter, $max)) {
            return true;
        }
        RateLimiter::hit($limiter, $hours * 3600);

        return false;
    }

    /**
     * @return list<string>
     */
    private function recipients(mixed $value): array
    {
        $items = is_array($value) ? $value : preg_split('/[,;\s]+/', (string) $value);
        $valid = [];
        foreach ((array) $items as $item) {
            $item = is_scalar($item) ? trim((string) $item) : '';
            if ($item !== '' && filter_var($item, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($item)] = $item;
            }
        }

        return array_values($valid);
    }
}
