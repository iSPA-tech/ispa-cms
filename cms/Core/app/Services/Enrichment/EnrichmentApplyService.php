<?php

namespace Cms\Core\Services\Enrichment;

use Illuminate\Database\Eloquent\Model;

final class EnrichmentApplyService
{
    /**
     * Apply confident enrichment fields to a target model without overwriting confirmed data.
     *
     * @param array<string, mixed> $fields
     * @param array{
     *     field_map?: array<string, string>,
     *     thresholds?: array<string, float|int|string>,
     *     default_min_confidence?: float|int|string|null,
     *     confirmed_fields?: array<int, string>,
     *     protect_confirmed_target?: bool,
     *     confirmed_attribute?: string,
     *     save?: bool
     * } $options
     * @return array{applied: array<int, array<string, mixed>>, skipped: array<int, array<string, mixed>>, changed: bool}
     */
    public function apply(Model $target, array $fields, array $options = []): array
    {
        $fieldMap = (array) ($options['field_map'] ?? []);
        $thresholds = (array) ($options['thresholds'] ?? []);
        $defaultMinConfidence = (float) ($options['default_min_confidence'] ?? 0);
        $confirmedFields = array_flip(array_filter(array_map('strval', (array) ($options['confirmed_fields'] ?? []))));
        $protectConfirmedTarget = (bool) ($options['protect_confirmed_target'] ?? false);
        $confirmedAttribute = (string) ($options['confirmed_attribute'] ?? 'is_confirmed');
        $save = (bool) ($options['save'] ?? true);

        $applied = [];
        $skipped = [];

        foreach ($fields as $field => $candidate) {
            $field = (string) $field;
            $targetPath = $fieldMap[$field] ?? null;

            if (! is_string($targetPath) || $targetPath === '') {
                $skipped[] = $this->skip($field, null, 'field_not_mapped');

                continue;
            }

            [$value, $confidence] = $this->candidateValueAndConfidence($candidate);

            if (! $this->hasValue($value)) {
                $skipped[] = $this->skip($field, $targetPath, 'empty_value', $confidence);

                continue;
            }

            $minConfidence = $this->minConfidence($field, $targetPath, $thresholds, $defaultMinConfidence);

            if ($confidence === null && $minConfidence > 0) {
                $skipped[] = $this->skip($field, $targetPath, 'missing_confidence', null, $minConfidence);

                continue;
            }

            if ($confidence !== null && $confidence < $minConfidence) {
                $skipped[] = $this->skip($field, $targetPath, 'confidence_below_threshold', $confidence, $minConfidence);

                continue;
            }

            $currentValue = $this->getTargetValue($target, $targetPath);

            if ($this->isConfirmedField($field, $targetPath, $confirmedFields) && $this->hasValue($currentValue)) {
                $skipped[] = $this->skip($field, $targetPath, 'confirmed_field', $confidence, $minConfidence);

                continue;
            }

            if (
                $protectConfirmedTarget
                && (bool) $target->getAttribute($confirmedAttribute)
                && $this->hasValue($currentValue)
            ) {
                $skipped[] = $this->skip($field, $targetPath, 'confirmed_target_value', $confidence, $minConfidence);

                continue;
            }

            if ($this->sameValue($currentValue, $value)) {
                $skipped[] = $this->skip($field, $targetPath, 'unchanged', $confidence, $minConfidence);

                continue;
            }

            $this->setTargetValue($target, $targetPath, $value);

            $applied[] = [
                'field' => $field,
                'target_path' => $targetPath,
                'confidence' => $confidence,
                'min_confidence' => $minConfidence,
            ];
        }

        if ($applied !== [] && $save) {
            $target->save();
        }

        return [
            'applied' => $applied,
            'skipped' => $skipped,
            'changed' => $applied !== [],
        ];
    }

    /**
     * @param mixed $candidate
     * @return array{0: mixed, 1: float|null}
     */
    private function candidateValueAndConfidence(mixed $candidate): array
    {
        if (is_array($candidate) && array_key_exists('value', $candidate)) {
            return [
                $candidate['value'],
                $this->nullableFloat($candidate['confidence'] ?? null),
            ];
        }

        return [$candidate, null];
    }

    /**
     * @param array<string, float|int|string> $thresholds
     */
    private function minConfidence(string $field, string $targetPath, array $thresholds, float $default): float
    {
        return (float) ($thresholds[$field] ?? $thresholds[$targetPath] ?? $default);
    }

    /**
     * @param array<string, int> $confirmedFields
     */
    private function isConfirmedField(string $field, string $targetPath, array $confirmedFields): bool
    {
        return isset($confirmedFields[$field]) || isset($confirmedFields[$targetPath]);
    }

    private function getTargetValue(Model $target, string $path): mixed
    {
        [$root, $nestedPath] = $this->splitPath($path);
        $value = $target->getAttribute($root);

        if ($nestedPath === null) {
            return $value;
        }

        return data_get($this->arrayPayload($value), $nestedPath);
    }

    private function setTargetValue(Model $target, string $path, mixed $value): void
    {
        [$root, $nestedPath] = $this->splitPath($path);

        if ($nestedPath === null) {
            $target->setAttribute($root, $value);

            return;
        }

        $payload = $this->arrayPayload($target->getAttribute($root));
        data_set($payload, $nestedPath, $value);
        $target->setAttribute($root, $payload);
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function splitPath(string $path): array
    {
        $segments = explode('.', $path, 2);

        return [$segments[0], $segments[1] ?? null];
    }

    /**
     * @return array<string, mixed>
     */
    private function arrayPayload(mixed $value): array
    {
        if (is_array($value)) {
            return $value;
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    private function hasValue(mixed $value): bool
    {
        if ($value === null) {
            return false;
        }

        if (is_string($value)) {
            return trim($value) !== '';
        }

        if (is_array($value)) {
            return $value !== [];
        }

        return true;
    }

    private function sameValue(mixed $current, mixed $incoming): bool
    {
        return $current === $incoming
            || json_encode($current, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
                === json_encode($incoming, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    private function nullableFloat(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    /**
     * @return array<string, mixed>
     */
    private function skip(
        string $field,
        ?string $targetPath,
        string $reason,
        ?float $confidence = null,
        ?float $minConfidence = null,
    ): array {
        return array_filter([
            'field' => $field,
            'target_path' => $targetPath,
            'reason' => $reason,
            'confidence' => $confidence,
            'min_confidence' => $minConfidence,
        ], static fn ($value): bool => $value !== null);
    }
}
