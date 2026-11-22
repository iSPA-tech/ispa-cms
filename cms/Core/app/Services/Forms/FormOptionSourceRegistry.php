<?php

namespace Cms\Core\Services\Forms;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Nwidart\Modules\Facades\Module;

/** Trusted module/project definitions, never database names supplied by a browser. */
final class FormOptionSourceRegistry
{
    private array $registered = [];

    public function register(string $key, array $definition): void
    {
        $this->registered[$key] = $definition;
    }

    private function definitions(): array
    {
        $sources = [];
        foreach (config()->all() as $settings) {
            if (is_array($settings)) $sources = array_replace($sources, $settings['form_options']['sources'] ?? []);
        }
        return array_replace($sources, $this->registered);
    }

    public function definition(string $key): array
    {
        $definition = $this->definitions()[$key] ?? null;
        if (!is_array($definition) || !$this->available($definition)) {
            throw ValidationException::withMessages(['optionsSource' => 'Nguồn danh sách không còn sẵn sàng. Vui lòng chọn nguồn khác.']);
        }
        return $definition;
    }

    private function available(array $definition): bool
    {
        $class = $definition['model'] ?? '';
        if (($definition['public'] ?? false) !== true || !is_string($class) || !is_subclass_of($class, Model::class)) return false;
        $parts = explode('\\', $class);
        $module = $definition['module'] ?? (($parts[0] ?? '') === 'Cms' ? ($parts[1] ?? '') : 'Core');
        if (!$module || !Module::find($module)?->isEnabled()) return false;
        if (isset($definition['project']) && $definition['project'] !== config('ispa-cms.folder_name')) return false;
        $model = new $class();
        return Schema::connection($model->getConnectionName())->hasTable($model->getTable());
    }

    public function catalogue(): array
    {
        $result = [];
        foreach ($this->definitions() as $key => $definition) {
            if (!$this->available($definition)) continue;
            $filters = [];
            foreach ($definition['filters'] ?? [] as $name => $filter) {
                if (($filter['type'] ?? '') === 'reference') {
                    try { $this->definition($filter['source'] ?? ''); } catch (ValidationException) { continue; }
                }
                $filters[] = [
                    'key' => $name, 'label' => $this->text($filter['title'] ?? $name),
                    'type' => $filter['type'] ?? 'text', 'source' => $filter['source'] ?? null,
                    'default' => $filter['default'] ?? null,
                    'options' => array_map(fn (array $option): array => [
                        'value' => (string) $option['value'], 'label' => $this->text($option['label'] ?? $option['value']),
                    ], $filter['options'] ?? []),
                ];
            }
            $result[] = ['key' => $key, 'label' => $this->text($definition['title']),
                'group' => $this->text($definition['group'] ?? 'Dữ liệu hệ thống'), 'filters' => $filters];
        }
        return $result;
    }

    public function text(mixed $text): string
    {
        return is_array($text) ? (string) ($text[app()->getLocale()] ?? $text['vi'] ?? reset($text)) : (string) $text;
    }

    public function validateFilters(string $key, array $filters): void
    {
        $definition = $this->definition($key);
        foreach ($filters as $name => $value) {
            $filter = $definition['filters'][$name] ?? null;
            if (!$filter || (!is_scalar($value) && $value !== null) || strlen((string) $value) > 255) {
                throw ValidationException::withMessages(['optionsSource' => 'Điều kiện lọc danh sách không hợp lệ.']);
            }
            if ($value !== null && $value !== '' && ($filter['type'] ?? '') === 'choice') {
                $allowed = array_map('strval', array_column($filter['options'] ?? [], 'value'));
                if (!in_array((string) $value, $allowed, true)) throw ValidationException::withMessages(['optionsSource' => 'Lựa chọn lọc danh sách không hợp lệ.']);
            }
        }
    }

    public function query(string $key, array $filters = [], int $depth = 0): Builder
    {
        if ($depth > 4) throw ValidationException::withMessages(['optionsSource' => 'Điều kiện danh sách phụ thuộc quá nhiều cấp.']);
        $source = $this->definition($key);
        $this->validateFilters($key, $filters);
        $class = $source['model'];
        $query = isset($source['query']) ? call_user_func($source['query']) : $class::query();
        if (!$query instanceof Builder) throw new \LogicException('A form option source must return an Eloquent builder.');
        foreach ($source['where'] ?? [] as $column => $value) {
            is_array($value) ? $query->whereIn($column, $value) : $query->where($column, $value);
        }
        foreach ($source['filters'] ?? [] as $name => $filter) {
            $value = $filters[$name] ?? $filter['default'] ?? null;
            if ($value === null || $value === '') continue;
            if (($filter['type'] ?? '') === 'reference') {
                $reference = $this->definition($filter['source']);
                $referenceColumn = $filter['reference_column'] ?? 'id';
                $record = $this->query($filter['source'], [], $depth + 1)->where($reference['value'], (string) $value)
                    ->toBase()->first([$reference['value'], $referenceColumn]);
                if (!$record) throw ValidationException::withMessages(['optionsSource' => 'Lựa chọn liên quan không còn sẵn sàng.']);
                $value = $record->{$referenceColumn};
            } elseif (isset($filter['mapping'][(string) $value])) {
                $value = $filter['mapping'][(string) $value];
            }
            is_array($value) ? $query->whereIn($filter['column'], $value) : $query->where($filter['column'], $value);
        }
        return $query;
    }

    /** Selected values use the complete scoped query, not only a visible search page. */
    public function selected(string $key, array $filters, array $values): array
    {
        if (collect($values)->contains(fn ($value) => !is_scalar($value))) {
            throw ValidationException::withMessages(['optionsSource' => 'Lựa chọn danh sách không hợp lệ.']);
        }
        $values = array_values(array_unique(array_map('strval', $values)));
        if (count($values) > 50 || collect($values)->contains(fn ($value) => strlen($value) > 255)) {
            throw ValidationException::withMessages(['optionsSource' => 'Có quá nhiều lựa chọn hoặc lựa chọn quá dài.']);
        }
        if (!$values) return [];
        $source = $this->definition($key);
        return $this->project($this->query($key, $filters)->whereIn($source['value'], $values)->toBase()->get([$source['value'], $source['label']]), $source);
    }

    public function lookup(string $key, array $filters = [], string $search = '', int $page = 1, array $selected = []): array
    {
        if ($page < 1 || $page > 1000 || strlen($search) > 100) throw ValidationException::withMessages(['optionsSource' => 'Yêu cầu tìm danh sách không hợp lệ.']);
        $source = $this->definition($key);
        $query = $this->query($key, $filters);
        if (trim($search) !== '') $query->where($source['label'], 'like', '%' . trim($search) . '%');
        $rows = $query->orderBy($source['label'])->orderBy($source['value'])->skip(($page - 1) * 50)->take(51)->toBase()->get([$source['value'], $source['label']]);
        return ['options' => $this->project($rows->take(50), $source), 'hasMore' => $rows->count() > 50,
            'page' => $page, 'selected' => $this->selected($key, $filters, $selected)];
    }

    private function project($rows, array $source): array
    {
        return $rows->map(fn ($row): array => [
            'value' => (string) $row->{$source['value']},
            'label' => $this->text($row->{$source['label']}),
        ])->unique('value')->values()->all();
    }
}
