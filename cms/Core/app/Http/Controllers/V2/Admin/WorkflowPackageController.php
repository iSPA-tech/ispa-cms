<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Models\Workflows\WorkflowProject;
use Cms\Core\Services\Workflow\Package\WorkflowPackageException;
use Cms\Core\Services\Workflow\Package\WorkflowPackageExporter;
use Cms\Core\Services\Workflow\Package\WorkflowPackageFormat;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInspector;
use Cms\Core\Services\Workflow\Package\WorkflowPackageInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

/**
 * Workflow packages (`api/v2/admin/workflow/packages`), the backend of the package review page.
 *
 * - `POST preview`: the package (JSON body field `package`, or an uploaded `file`) with optional `bindings`
 *   (name → value), `as_slug` and `sample` → manifest, problems, binding/slug problems, warnings and the dry run.
 *   Nothing is written, nothing is called outside, nothing is spent. Needs `workflow_project.create`.
 * - `POST install`: same input, plus an optional `as_name` for a copy → 201 `{slug, name, is_active: false}`, or 422
 *   with the report. Add-only, inactive, no schedule; needs `workflow_project.create`, and the node permissions of
 *   every step.
 * - `GET export/{slug}`: download the package of a workflow (`<slug>.workflow.json`). Needs `workflow_project.viewAny`.
 *   Workflow projects have no uuid column: the slug is their stable key.
 *
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @since   2026-11-24
 */
class WorkflowPackageController extends Controller
{
    public const MANAGE_ABILITY = 'workflow_project.create';

    public const VIEW_ABILITY = 'workflow_project.viewAny';

    public function preview(Request $request, WorkflowPackageInspector $inspector): JsonResponse
    {
        if (!$request->user()?->can(self::MANAGE_ABILITY)) {
            return $this->forbidden();
        }

        [$package, $error] = $this->package($request);
        if ($package === null) {
            return response()->json(['message' => $error], 422);
        }

        $sample = $request->input('sample');

        return response()->json(['data' => $inspector->inspect(
            $package,
            $request->user(),
            (array) $request->input('bindings', []),
            $request->filled('as_slug') ? (string) $request->input('as_slug') : null,
            is_array($sample) ? $sample : null,
        )]);
    }

    public function install(Request $request, WorkflowPackageInstaller $installer): JsonResponse
    {
        if (!$request->user()?->can(self::MANAGE_ABILITY)) {
            return $this->forbidden();
        }

        [$package, $error] = $this->package($request);
        if ($package === null) {
            return response()->json(['message' => $error], 422);
        }

        try {
            $result = $installer->install($package, $request->user(), (array) $request->input('bindings', []),
                $request->filled('as_slug') ? (string) $request->input('as_slug') : null,
                $request->filled('as_name') ? (string) $request->input('as_name') : null);
        } catch (WorkflowPackageException $e) {
            return response()->json(['message' => $e->getMessage(), 'data' => $e->report], 422);
        }

        return response()->json(['data' => ['slug' => $result['slug'], 'name' => $result['name'], 'is_active' => $result['is_active']]], 201);
    }

    public function export(Request $request, string $slug, WorkflowPackageExporter $exporter, WorkflowPackageInspector $inspector): JsonResponse|Response
    {
        if (!$request->user()?->can(self::VIEW_ABILITY)) {
            return $this->forbidden();
        }
        if (!WorkflowProject::query()->where('slug', $slug)->exists()) {
            return response()->json(['message' => 'Not found'], 404);
        }

        try {
            $package = $exporter->export($slug);
        } catch (WorkflowPackageException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $report = $inspector->inspect($package, null, [], null, null, false);
        if (!$report['valid']) {
            return response()->json(['message' => 'The workflow cannot be shared as it is.', 'data' => ['problems' => $report['problems']]], 422);
        }

        return response((string) json_encode($package, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE), 200, [
            'Content-Type' => 'application/json',
            'Content-Disposition' => 'attachment; filename="' . $slug . '.workflow.json"',
        ]);
    }

    /**
     * The package from the JSON body (`package`) or an uploaded file (`file`).
     *
     * @return array{0: array<string, mixed>|null, 1: string|null}
     */
    private function package(Request $request): array
    {
        $file = $request->file('file');
        if ($file !== null) {
            if (!$file->isValid() || $file->getSize() > WorkflowPackageFormat::MAX_BYTES) {
                return [null, 'The file is missing or larger than ' . WorkflowPackageFormat::MAX_BYTES . ' bytes.'];
            }
            $decoded = json_decode((string) file_get_contents($file->getRealPath()), true);

            return is_array($decoded) ? [$decoded, null] : [null, 'The file is not a JSON workflow package.'];
        }

        $package = $request->input('package');

        return is_array($package) ? [$package, null] : [null, 'Send the workflow package as `package` or upload it as `file`.'];
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
