<?php

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Models\Field;
use Cms\Core\Services\Forms\FormOptionSourceRegistry;
use Illuminate\Http\Request;

final class FormOptionSourceController extends Controller
{
    public function catalogue(FormOptionSourceRegistry $sources): mixed
    {
        $this->authorizeEditor();
        return ires()->data($sources->catalogue());
    }

    public function options(string $source, Request $request, FormOptionSourceRegistry $sources): mixed
    {
        $this->authorizeEditor();
        return $this->lookup($source, $request, $sources);
    }

    public function publicOptions(string $source, Request $request, FormOptionSourceRegistry $sources): mixed
    {
        return $this->lookup($source, $request, $sources);
    }

    private function authorizeEditor(): void
    {
        abort_unless(user()?->can('viewAny', new Field()) || user()?->can('create', new Field()) || user()?->can('update', new Field()), 403);
    }

    private function lookup(string $source, Request $request, FormOptionSourceRegistry $sources): mixed
    {
        $data = $request->validate(['q' => 'nullable|string|max:100', 'page' => 'nullable|integer|between:1,1000',
            'filters' => 'nullable|array|max:20', 'selected' => 'nullable|array|max:50', 'selected.*' => 'string|max:255']);
        return ires()->data($sources->lookup($source, $data['filters'] ?? [], $data['q'] ?? '', (int) ($data['page'] ?? 1), $data['selected'] ?? []));
    }
}
