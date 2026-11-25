<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Human;

use Cms\Core\Workflows\Expression\TemplateRenderer;
use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\Runners\Util\NodeHelpers;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Throwable;

/**
 * `notify` node: an in-app admin notice (bell) to every user holding `permission` and/or the users listed in `users`
 * (uuids, expression). `message` is a template (`{{ vars.x }}`), cut to 300 characters; `approval_id` (expression,
 * optional) makes the notice open that approval. Internal recipients only; nothing leaves the site.
 *
 * Result in `vars.<storeAs>` (default `vars.notified`): `count`, `user_ids`.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Human
 * @since   2026-11-24
 */
class NotifyRunner implements StepRunner
{
    use NodeHelpers;

    public function __construct(protected HumanNotifier $notifier)
    {
    }

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $path = $this->storePath($config, 'vars.notified');
        if ($path === null) {
            return StepRunResult::failed('notify: storeAs must start with vars.');
        }

        try {
            $message = trim((new TemplateRenderer())->renderString((string) ($config['message'] ?? ''), $context));
            $users = $this->evalField($config, 'users', $context, []);
            $approvalId = $this->evalField($config, 'approval_id', $context);
        } catch (Throwable $e) {
            return StepRunResult::failed('notify: ' . $e->getMessage());
        }

        if ($message === '') {
            return StepRunResult::failed('notify: message is empty');
        }
        $users = is_string($users) ? [$users] : (is_array($users) ? array_values(array_filter($users, 'is_string')) : []);
        $permission = is_string($config['permission'] ?? null) && $config['permission'] !== '' ? $config['permission'] : null;
        if ($permission === null && $users === []) {
            return StepRunResult::failed('notify: set a permission or users');
        }

        $payload = ['message' => mb_substr($message, 0, 300)];
        if (is_numeric($approvalId)) {
            $payload['approval_id'] = (int) $approvalId;
        }
        $instanceId = $this->instanceId($context);
        if ($instanceId !== null) {
            $payload['workflow_instance_id'] = $instanceId;
        }

        $sent = $this->notifier->send('workflow.notice', $payload, $permission, $users);
        $context->setVarPath($path, ['count' => count($sent), 'user_ids' => $sent]);

        return StepRunResult::success(['count' => count($sent)], [
            ['level' => 'info', 'message' => 'notify: ' . count($sent) . ' user(s)'],
        ]);
    }
}
