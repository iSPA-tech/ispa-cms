<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;

/**
 * Renders `template` with `{{ expression }}` placeholders into `storeAs`.
 *
 * `format`: `text` and `markdown` insert values as they are; `html` escapes every inserted value, so data
 * (often untrusted: AI or fetched text) never becomes markup. The template itself is author text.
 */
class RenderTemplateRunner implements StepRunner
{
    use NodeHelpers;

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $template = $config['template'] ?? null;
        $format = (string) ($config['format'] ?? 'text');
        $path = $this->storePath($config, 'vars.rendered');

        if (!is_string($template)) {
            return StepRunResult::failed('render_template: template is required');
        }
        if (!in_array($format, ['text', 'markdown', 'html'], true)) {
            return StepRunResult::failed('render_template: format must be text, markdown or html');
        }
        if ($path === null) {
            return StepRunResult::failed('render_template: storeAs must start with vars.');
        }

        try {
            $output = preg_replace_callback('/\{\{\s*(.+?)\s*\}\}/s', function (array $m) use ($context, $format): string {
                $value = $this->engine()->evaluate($m[1], $context, false);
                $text = match (true) {
                    $value === null => '',
                    is_bool($value) => $value ? 'true' : 'false',
                    is_array($value) || is_object($value) => (string) json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
                    default => (string) $value,
                };

                return $format === 'html' ? htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') : $text;
            }, $template);
        } catch (\Throwable $e) {
            return StepRunResult::failed('render_template: ' . $e->getMessage());
        }

        $context->setVarPath($path, $output);

        return StepRunResult::success(['length' => mb_strlen((string) $output), 'format' => $format]);
    }
}
