<?php

declare(strict_types=1);

namespace Cms\Core\Workflows\Runtime\Runners\Integration;

use Cms\Core\Workflows\Runtime\StepRunResult;
use Cms\Core\Workflows\Runtime\WorkflowContext;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * `text_to_speech`: turn text into an audio file with the OpenAI speech API and save it on the configured disk under
 * `workflow/tts/`. Spends money (ai_spend): the step reports usage (characters as tokens_in, cost from the per-model
 * price per million characters in the settings) through StepRunResult::withUsage(), which the executor sums into
 * the run and checks against the project budget.
 *
 * Config: `credential` ($bind:<tts>), `text` (template), `voice`, `model`, `format` (mp3|opus|aac|wav), `storeAs`.
 * Result: {path, url, disk, bytes, format, characters}.
 *
 * @package Cms\Core\Workflows\Runtime\Runners\Integration
 * @since   2026-11-24
 */
class TextToSpeechRunner extends IntegrationRunner
{
    private const FORMATS = ['mp3', 'opus', 'aac', 'wav'];

    private const VOICE_PATTERN = '/^[a-z]{2,20}$/';

    protected function type(): string
    {
        return 'text_to_speech';
    }

    protected function execute(array $config, WorkflowContext $context): StepRunResult
    {
        $key = $this->bind($config['credential'] ?? null, 'tts');
        $text = $this->renderText($config['text'] ?? '', $context);
        $settings = (array) config('core.workflow_nodes.messaging.settings.tts', []);
        $maxChars = max(1, (int) ($settings['max_characters'] ?? 4096));
        $models = (array) ($settings['models'] ?? []);

        $model = (string) ($config['model'] ?? ($settings['default_model'] ?? 'gpt-4o-mini-tts'));
        $voice = (string) ($config['voice'] ?? 'alloy');
        $format = (string) ($config['format'] ?? 'mp3');

        if ($text === '') {
            throw new IntegrationFailure('text is empty.');
        }
        if (mb_strlen($text) > $maxChars) {
            throw new IntegrationFailure("text is longer than {$maxChars} characters.");
        }
        if (!array_key_exists($model, $models)) {
            throw new IntegrationFailure('model is not one of: ' . implode(', ', array_keys($models)) . '.');
        }
        if (!preg_match(self::VOICE_PATTERN, $voice)) {
            throw new IntegrationFailure('voice is not valid.');
        }
        if (!in_array($format, self::FORMATS, true)) {
            throw new IntegrationFailure('format must be one of: ' . implode(', ', self::FORMATS) . '.');
        }

        $response = $this->http()
            ->withToken((string) ($key['api_key'] ?? ''))
            ->accept('*/*')
            ->asJson()
            ->post(IntegrationHosts::OPENAI . '/v1/audio/speech', [
                'model' => $model, 'voice' => $voice, 'input' => $text, 'response_format' => $format,
            ]);
        $this->ensureOk($response, 'Text to speech');

        $audio = $response->body();
        if ($audio === '') {
            throw new IntegrationFailure('the provider returned no audio.');
        }

        $disk = (string) ($settings['disk'] ?? 'public');
        $path = 'workflow/tts/' . now()->format('Y/m') . '/' . Str::uuid() . '.' . $format;
        Storage::disk($disk)->put($path, $audio);

        $characters = mb_strlen($text);
        $pricePerMillion = $models[$model];
        $result = [
            'path' => $path,
            'url' => $this->publicUrl($disk, $path),
            'disk' => $disk,
            'bytes' => strlen($audio),
            'format' => $format,
            'characters' => $characters,
        ];
        $this->store($config, $context, $result);

        return StepRunResult::success($result, [[
            'action' => 'text_to_speech',
            'model' => $model,
            'characters' => $characters,
            'bytes' => strlen($audio),
        ]])->withUsage([
            'task_key' => 'workflow.text_to_speech',
            'provider' => 'openai',
            'model' => $model,
            'tokens_in' => $characters,
            'tokens_out' => 0,
            'cost_usd' => is_numeric($pricePerMillion) ? round($characters * (float) $pricePerMillion / 1_000_000, 6) : null,
        ]);
    }

    private function publicUrl(string $disk, string $path): ?string
    {
        try {
            return Storage::disk($disk)->url($path);
        } catch (\Throwable) {
            return null;
        }
    }
}
