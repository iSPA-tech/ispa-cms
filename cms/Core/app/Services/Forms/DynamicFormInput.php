<?php

namespace Cms\Core\Services\Forms;

use Cms\Core\Models\Field;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use LogicException;

/** Validates a published form definition on the server; browser rules are only UX. */
final class DynamicFormInput
{
    public function __construct(private FormOptionResolver $optionSources) {}
    /**
     * @return array{answers: array<string, mixed>, files: array<string, UploadedFile>, snapshot: array<string, mixed>}
     */
    public function validate(Request $request, Field $form, array $existingAttachments = []): array
    {
        $rules = [];
        $snapshot = [];
        $fileFields = [];
        $messages = [];
        $labels = [];

        foreach ($form->items as $item) {
            if (!$item->status) {
                continue;
            }

            $name = (string) $item->field_name;
            if (!preg_match('/^[A-Za-z][A-Za-z0-9_]{0,49}$/', $name) || isset($rules[$name])) {
                throw new LogicException('Invalid or duplicate dynamic form field name.');
            }

            $custom = (array) ($item->custom ?? []);
            $condition = (array) ($custom['visibleWhen'] ?? []);
            $conditionField = (string) ($condition['field'] ?? '');
            $conditionValue = (string) ($condition['value'] ?? '');
            $active = $conditionField === '' || (string) $request->input($conditionField) === $conditionValue;
            $type = (string) $item->type;
            $labels[$name] = (string) $item->name;
            $upload = in_array($type, ['file', 'document', 'image'], true) ? $this->fileSettings($type, $custom) : null;
            $required = (bool) ($custom['required'] ?? false);
            $options = collect((array) ($item->options ?? []))
                ->map(static fn ($option): array => (array) $option)
                ->filter(static fn (array $option): bool => isset($option['value']) && is_scalar($option['value']))
                ->map(static fn (array $option): array => [
                    'value' => (string) $option['value'],
                    'label' => (string) ($option['label'] ?? $option['value']),
                ])->values()->all();

            if (!$active) {
                $snapshot[] = $this->snapshotItem($item, $this->optionSources->binding($item) ? [] : $options);
                $rules[$name] = ['exclude'];
                continue;
            }

            if ($this->optionSources->binding($item)) {
                $options = $this->optionSources->selections($form, $item, $request->all(), $request->input($name));
            }
            $snapshot[] = $this->snapshotItem($item, $options);

            $presence = $required && !isset($existingAttachments[$name]) ? 'required' : 'nullable';
            $ratioRule = $type === 'image' ? $this->imageRatioRule($custom) : null;
            $rules[$name] = match ($type) {
                'text', 'tel' => [$presence, 'string', 'max:255'],
                'email' => [$presence, 'email', 'max:255'],
                'textarea' => [$presence, 'string', 'max:4000'],
                'date' => [$presence, 'date_format:Y-m-d'],
                'select', 'radio' => [$presence, 'string', Rule::in(array_column($options, 'value'))],
                'checkbox' => [$presence, 'array', $required ? 'min:1' : 'max:50'],
                'file', 'document' => [$presence, 'file', 'max:' . ($upload['maxSizeMb'] * 1024), 'mimes:' . implode(',', $upload['extensions'])],
                'image' => array_values(array_filter([
                    $presence, 'file', 'max:' . ($upload['maxSizeMb'] * 1024), 'mimes:' . implode(',', $upload['extensions']),
                    $ratioRule,
                ])),
                default => throw \Illuminate\Validation\ValidationException::withMessages(['form' => 'Biểu mẫu có trường chưa được hỗ trợ.']),
            };

            if (in_array($type, ['file', 'document', 'image'], true)) {
                $fileFields[] = $name;
                $messages[$name . '.max'] = 'Tệp của mục “' . $item->name . '” không được lớn hơn ' . $upload['maxSizeMb'] . ' MB.';
                $messages[$name . '.mimes'] = 'Vui lòng chọn tệp ' . implode(', ', array_map('strtoupper', $upload['extensions'])) . ' cho mục “' . $item->name . '”.';
            }
            if ($ratioRule !== null) {
                $messages[$name . '.dimensions'] = $item->name . ' phải đúng tỷ lệ ' . $custom['aspectRatio'] . '.';
            }
            if ($type === 'checkbox') {
                $rules[$name . '.*'] = ['string', Rule::in(array_column($options, 'value'))];
            }
        }

        $validated = Validator::make($request->all(), $rules, $messages, $labels)->validate();
        $files = [];
        foreach ($fileFields as $name) {
            if (($validated[$name] ?? null) instanceof UploadedFile) {
                $files[$name] = $validated[$name];
                unset($validated[$name]);
            }
        }

        return [
            'answers' => $validated,
            'files' => $files,
            'snapshot' => ['form_id' => $form->form_id, 'items' => $snapshot],
        ];
    }

    public function snapshot(Field $form): array
    {
        $items = [];
        foreach ($form->items as $item) {
            if (!$item->status) {
                continue;
            }
            $options = collect($this->optionSources->binding($item) ? [] : (array) ($item->options ?? []))
                ->map(static fn ($option): array => (array) $option)
                ->filter(static fn (array $option): bool => isset($option['value']) && is_scalar($option['value']))
                ->map(static fn (array $option): array => [
                    'value' => (string) $option['value'],
                    'label' => (string) ($option['label'] ?? $option['value']),
                ])->values()->all();
            $items[] = $this->snapshotItem($item, $options);
        }

        return ['form_id' => $form->form_id, 'items' => $items];
    }

    private function snapshotItem($item, array $options): array
    {
        $custom = (array) ($item->custom ?? []);
        $condition = (array) ($custom['visibleWhen'] ?? []);
        $conditionField = (string) ($condition['field'] ?? '');

        return [
            'field_name' => (string) $item->field_name,
            'name' => (string) $item->name,
            'type' => (string) $item->type,
            'required' => (bool) ($custom['required'] ?? false),
            'options' => $options,
            'optionsSource' => $this->optionSources->binding($item),
            'visibleWhen' => $conditionField === '' ? null : [
                'field' => $conditionField,
                'value' => (string) ($condition['value'] ?? ''),
            ],
            'section' => (string) ($custom['section'] ?? ''),
            'upload' => in_array($item->type, ['file', 'document', 'image'], true) ? $this->fileSettings($item->type, $custom) : null,
        ];
    }

    /** Shared by validation and the visible upload guidance. */
    public function fileSettings(string $type, array $custom): array
    {
        $limit = filter_var($custom['maxFileSizeMb'] ?? 10, FILTER_VALIDATE_INT);
        if ($limit === false || $limit < 1 || $limit > 10) {
            throw new LogicException('File size setting must be between 1 and 10 MB.');
        }

        return [
            'extensions' => explode(',', $this->allowedExtensions($type, $custom)),
            'maxSizeMb' => $limit,
            'aspectRatio' => $type === 'image' ? ($custom['aspectRatio'] ?? null) : null,
        ];
    }

    /** Only allow file formats the admission storage and download path can safely serve. */
    private function allowedExtensions(string $type, array $custom): string
    {
        $supported = $type === 'image' ? ['jpg', 'jpeg', 'png'] : ['jpg', 'jpeg', 'png', 'pdf'];
        $configured = trim((string) ($custom['allowFileType'] ?? ''));
        if ($configured === '') {
            return implode(',', $supported);
        }

        $requested = array_map(
            static fn (string $value): string => ltrim(strtolower(trim($value)), '.'),
            explode(',', $configured),
        );
        $allowed = array_values(array_intersect($requested, $supported));
        if ($allowed === []) {
            throw new LogicException('Dynamic form file types have no supported extension.');
        }

        return implode(',', $allowed);
    }

    private function imageRatioRule(array $custom): ?string
    {
        $ratio = trim((string) ($custom['aspectRatio'] ?? ''));
        if ($ratio === '') {
            return null;
        }
        if (!preg_match('/^[1-9][0-9]?\/[1-9][0-9]?$/', $ratio)) {
            throw new LogicException('Invalid dynamic form image ratio.');
        }

        return 'dimensions:ratio=' . $ratio;
    }
}
