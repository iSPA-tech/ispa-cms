<?php

namespace Cms\Core\Workflows\Runtime\Runners\Util;

use Cms\Core\Workflows\Runtime\Contracts\StepRunner;
use Cms\Core\Workflows\Runtime\StepDefinition;
use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Str;
use Normalizer;

/**
 * Text operations on `inputExpr`, stored at `storeAs`.
 *
 * Ops: slugify, truncate (`length`, `suffix`), strip_html, regex_replace (`pattern`, `replacement`),
 * word_count, markdown_to_html (raw HTML in the source is stripped, unsafe links dropped), html_to_text,
 * normalize_whitespace, normalize_unicode (NFC), lower, upper, trim.
 */
class TextTransformRunner implements StepRunner
{
    use NodeHelpers;

    public const OPS = [
        'slugify', 'truncate', 'strip_html', 'regex_replace', 'word_count', 'markdown_to_html', 'html_to_text',
        'normalize_whitespace', 'normalize_unicode', 'lower', 'upper', 'trim',
    ];

    protected const MAX_INPUT_BYTES = 1048576;

    public function run(StepDefinition $step, WorkflowContext $context): StepRunResult
    {
        $config = $step->stepConfig;
        $op = (string) ($config['op'] ?? '');
        $path = $this->storePath($config, 'vars.text');

        if (!in_array($op, self::OPS, true)) {
            return StepRunResult::failed('text_transform: unknown op');
        }
        if ($path === null) {
            return StepRunResult::failed('text_transform: storeAs must start with vars.');
        }

        try {
            $input = $this->evalField($config, 'inputExpr', $context, '');
        } catch (\Throwable $e) {
            return StepRunResult::failed('text_transform: ' . $e->getMessage());
        }
        if (!is_scalar($input) && $input !== null) {
            return StepRunResult::failed('text_transform: input must be text');
        }
        $text = (string) $input;
        if (strlen($text) > self::MAX_INPUT_BYTES) {
            return StepRunResult::failed('text_transform: input is larger than 1 MB');
        }

        try {
            $value = $this->apply($op, $text, $config);
        } catch (\Throwable $e) {
            return StepRunResult::failed('text_transform: ' . $e->getMessage());
        }

        $context->setVarPath($path, $value);

        return StepRunResult::success(['op' => $op]);
    }

    protected function apply(string $op, string $text, array $config): string|int
    {
        return match ($op) {
            'slugify' => Str::slug($text, (string) ($config['separator'] ?? '-')),
            'truncate' => Str::limit($text, max(1, (int) ($config['length'] ?? 100)), (string) ($config['suffix'] ?? '...')),
            'strip_html', 'html_to_text' => $this->htmlToText($text),
            'regex_replace' => $this->regexReplace($text, $config),
            'word_count' => $this->htmlToText($text) === '' ? 0 : count(preg_split('/\s+/u', $this->htmlToText($text)) ?: []),
            'markdown_to_html' => (string) Str::markdown($text, ['html_input' => 'strip', 'allow_unsafe_links' => false]),
            'normalize_whitespace' => trim((string) preg_replace('/\s+/u', ' ', $text)),
            'normalize_unicode' => class_exists(Normalizer::class) ? (string) Normalizer::normalize($text, Normalizer::FORM_C) : $text,
            'lower' => mb_strtolower($text),
            'upper' => mb_strtoupper($text),
            'trim' => trim($text),
        };
    }

    protected function htmlToText(string $html): string
    {
        $html = (string) preg_replace('#<(script|style)\b[^>]*>.*?</\1>#is', ' ', $html);
        $html = (string) preg_replace('#<(br|/p|/div|/li|/h[1-6])\b[^>]*>#i', "$0\n", $html);
        $text = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');

        return trim((string) preg_replace('/[ \t]+/u', ' ', $text));
    }

    /**
     * Author-supplied pattern; bounded length and backtracking so a bad pattern fails instead of hanging.
     */
    protected function regexReplace(string $text, array $config): string
    {
        $pattern = (string) ($config['pattern'] ?? '');
        if ($pattern === '' || strlen($pattern) > 500) {
            throw new \InvalidArgumentException('pattern is required (max 500 characters)');
        }
        if (@preg_match($pattern, '') === false) {
            throw new \InvalidArgumentException('pattern is not a valid regex');
        }

        $previous = ini_get('pcre.backtrack_limit');
        ini_set('pcre.backtrack_limit', '100000');
        try {
            $result = @preg_replace($pattern, (string) ($config['replacement'] ?? ''), $text);
        } finally {
            ini_set('pcre.backtrack_limit', (string) $previous);
        }

        if ($result === null) {
            throw new \InvalidArgumentException('pattern is invalid or too expensive');
        }

        return $result;
    }
}
