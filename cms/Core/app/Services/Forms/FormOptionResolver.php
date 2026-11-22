<?php

namespace Cms\Core\Services\Forms;

use Cms\Core\Models\Field;
use Illuminate\Validation\ValidationException;

final class FormOptionResolver
{
    public function __construct(private FormOptionSourceRegistry $sources) {}

    public function binding($item): ?array
    {
        $binding = $item->custom['optionsSource'] ?? null;
        if (!$binding || empty($binding['source'])) return null;
        return ['source' => (string) $binding['source'],
            'filters' => array_filter((array) ($binding['filters'] ?? []), fn ($value) => $value !== null && $value !== ''),
            'dependsOn' => array_filter((array) ($binding['dependsOn'] ?? []), fn ($value) => $value !== null && $value !== '')];
    }

    public function hasSources(Field $form): bool
    {
        return $form->items->contains(fn ($item) => $this->binding($item) !== null);
    }

    public function validateSettings(Field $form): void
    {
        foreach ($form->items as $index => $item) {
            if (is_array($item->custom['optionsSource'] ?? null) && empty($item->custom['optionsSource']['source'])) {
                throw ValidationException::withMessages(["items.$index.custom.optionsSource" => 'Vui lòng chọn nguồn dữ liệu cho mục này.']);
            }
            foreach ((array) ($item->custom['optionsSource']['dependsOn'] ?? []) as $field) {
                if ($field === null || $field === '') {
                    throw ValidationException::withMessages(["items.$index.custom.optionsSource" => 'Vui lòng chọn mục liên kết hoặc chuyển sang giá trị cố định.']);
                }
            }
        }
        if (!$this->hasSources($form)) return;
        $this->assertProject($form);
        $preceding = [];
        foreach ($form->items as $index => $item) {
            $binding = $this->binding($item);
            if ($binding) {
                try {
                    if (!in_array($item->type, ['select', 'radio', 'checkbox'], true)) {
                        throw ValidationException::withMessages(['optionsSource' => 'Nguồn danh sách chỉ dùng cho các mục lựa chọn.']);
                    }
                    $definition = $this->sources->definition($binding['source']);
                    $this->sources->validateFilters($binding['source'], $binding['filters']);
                    foreach ($binding['dependsOn'] as $filter => $field) {
                        $parent = $preceding[$field] ?? null;
                        if (!isset($definition['filters'][$filter]) || isset($binding['filters'][$filter]) || !$parent
                            || !$parent->status || !in_array($parent->type, ['select', 'radio'], true)) {
                            throw ValidationException::withMessages(['optionsSource' => 'Mục liên kết phải là một lựa chọn đứng trước và không trùng điều kiện cố định.']);
                        }
                        $parentSource = $this->binding($parent)['source'] ?? null;
                        $reference = $definition['filters'][$filter]['source'] ?? null;
                        if ($reference && $parentSource && $parentSource !== $reference) {
                            throw ValidationException::withMessages(['optionsSource' => 'Mục liên kết cần lấy từ cùng nguồn dữ liệu liên quan.']);
                        }
                    }
                    // Validate fixed reference values under the public/project scope as well.
                    $this->sources->query($binding['source'], $binding['filters']);
                } catch (ValidationException $error) {
                    throw ValidationException::withMessages(["items.$index.custom.optionsSource" => collect($error->errors())->flatten()->all()]);
                }
            }
            $preceding[$item->field_name] = $item;
        }
    }

    private function assertProject(Field $form): void
    {
        if (isset($form->custom['project']) && $form->custom['project'] !== config('ispa-cms.folder_name')) {
            throw ValidationException::withMessages(['optionsSource' => 'Biểu mẫu không thuộc website hiện tại.']);
        }
    }

    public function filters(Field $form, $item, array $values): ?array
    {
        $this->assertProject($form);
        $binding = $this->binding($item);
        $filters = $binding['filters'];
        foreach ($binding['dependsOn'] as $key => $field) {
            $value = $values[$field] ?? null;
            if ($value === null || $value === '') return null;
            $filters[$key] = $value;
        }
        return $filters;
    }

    public function selections(Field $form, $item, array $values, mixed $selected): array
    {
        $selection = is_array($selected) ? $selected : (($selected === null || $selected === '') ? [] : [$selected]);
        if (!$selection) return [];
        $binding = $this->binding($item);
        $filters = $this->filters($form, $item, $values);
        $options = $filters === null ? [] : $this->sources->selected($binding['source'], $filters, $selection);
        if (count($options) !== count(array_unique(array_map('strval', $selection)))) {
            throw ValidationException::withMessages([$item->field_name => 'Lựa chọn ở mục “' . $item->name . '” không còn phù hợp. Vui lòng chọn lại.']);
        }
        return $options;
    }

    public function presentation(Field $form, $item, array $values = [], array $historical = []): array
    {
        $binding = $this->binding($item);
        if (!$binding) return ['options' => (array) ($item->options ?? []), 'source' => null];
        $options = [];
        $error = null;
        try {
            $filters = $this->filters($form, $item, $values);
            $selected = $values[$item->field_name] ?? [];
            $selected = is_array($selected) ? $selected : ($selected === '' ? [] : [$selected]);
            $page = $filters === null ? ['options' => [], 'selected' => [], 'hasMore' => false] : $this->sources->lookup($binding['source'], $filters, '', 1, $selected);
            $historicSelection = array_filter($historical, fn ($option) => in_array($option['value'], $selected, true));
            $options = collect(array_merge($historicSelection, $page['selected'], $page['options']))->unique('value')->values()->all();
        } catch (ValidationException) {
            $error = 'Không thể tải danh sách. Vui lòng liên hệ nơi tiếp nhận hồ sơ.';
        }
        return ['options' => $options, 'source' => array_merge($binding, [
            'url' => route('api.v2.form-option-sources.options', ['source' => $binding['source']]),
            'error' => $error, 'hasMore' => $page['hasMore'] ?? false,
        ])];
    }

    public function preserveLabels(array $snapshot, array $previous, array $answers, array $original): array
    {
        $oldItems = collect($previous['items'] ?? [])->keyBy('field_name');
        foreach ($snapshot['items'] as &$item) {
            $name = $item['field_name'];
            if (empty($item['optionsSource']) || ($answers[$name] ?? null) !== ($original[$name] ?? null)) continue;
            $old = $oldItems->get($name);
            if (!$old || ($old['optionsSource'] ?? null) != $item['optionsSource']) continue;
            $labels = collect($old['options'] ?? [])->keyBy('value');
            $item['options'] = array_map(fn ($option) => $labels->get($option['value']) ?? $option, $item['options']);
        }
        return $snapshot;
    }
}
