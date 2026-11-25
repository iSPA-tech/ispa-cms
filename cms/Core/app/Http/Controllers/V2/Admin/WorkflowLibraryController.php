<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use Cms\Core\Services\Workflow\Hub\WorkflowHubCatalog;
use Cms\Core\Services\Workflow\Hub\WorkflowHubException;
use Cms\Core\Services\Workflow\Hub\WorkflowHubInstaller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Workflow template library from the hub (`api/v2/admin/workflows/library`, roadmap phase C).
 *
 * - `GET /` (`workflow_project.viewAny`; `?refresh=1` skips the 10-minute cache) → `{data: [item]}`, item =
 *   `{key, publisher, name, description, group, latest_version, latest_version_label, requires: {modules, node_types},
 *   summary, summary_lines: {does, asks, may, tags: [{key, label}]}, notes, available, missing_modules,
 *   installed: null|{project_id, slug, name, version, version_label, modified, is_active, setup_url, copies},
 *   update_available}`.
 * - `GET /{key}` (`workflow_project.viewAny`) → the item from the verified signed bundle plus
 *   `signature: {verified, key_id, publisher}`, `update_in_place` (true when the newest install is not modified and
 *   this version is newer), `install_as_copy`, `copy_reason` (`modified` | null; `installed` is no longer answered).
 * - `POST /{key}/install` (`workflow_project.create`; body `as_copy?: bool`) → 201 (200 when updated in place) `{data: {project_id, slug, name,
 *   version, version_label, is_active, as_copy, copy_reason, updated_in_place, setup_url}}`. With
 *   `updated_in_place: true` (`as_copy` false) the existing project was updated: only its draft changed, setup answers
 *   kept, `is_active` and the published version untouched; `project_id`/`setup_url` point at it and a normal publish
 *   makes the new version run. A new or copied project is inactive.
 *
 * Errors: `{message, error_code, data?}` with 404 `WORKFLOW_HUB_NOT_FOUND`, 503 `WORKFLOW_HUB_UNREACHABLE`,
 * 422 `WORKFLOW_BUNDLE_UNTRUSTED`, 409 `WORKFLOW_TEMPLATE_NOT_AVAILABLE` (`data.missing_modules`), 422
 * `WORKFLOW_TEMPLATE_NOT_INSTALLABLE` (`data` = package inspector report; on an update in place `data.problems` may hold
 * the draft checks of a designer save), 409 `WORKFLOW_TEMPLATE_UP_TO_DATE` (the newest install is not modified and
 * already has this version; `as_copy: true` still installs a copy), 409 `WORKFLOW_TEMPLATE_CHANGED` (the install
 * changed while the update waited; ask again). Workflow projects have no uuid: the
 * template key and the project id are the identifiers, as in the builder routes.
 */
class WorkflowLibraryController extends Controller
{
    public const VIEW_ABILITY = 'workflow_project.viewAny';

    public const INSTALL_ABILITY = 'workflow_project.create';

    public function index(Request $request, WorkflowHubCatalog $catalog): JsonResponse
    {
        if (!$request->user()?->can(self::VIEW_ABILITY)) {
            return $this->forbidden();
        }
        try {
            return response()->json(['data' => $catalog->list($request->boolean('refresh'))]);
        } catch (WorkflowHubException $e) {
            return $this->failed($e);
        }
    }

    public function show(Request $request, string $key, WorkflowHubCatalog $catalog): JsonResponse
    {
        if (!$request->user()?->can(self::VIEW_ABILITY)) {
            return $this->forbidden();
        }
        try {
            return response()->json(['data' => $catalog->detail($key)]);
        } catch (WorkflowHubException $e) {
            return $this->failed($e);
        }
    }

    public function install(Request $request, string $key, WorkflowHubInstaller $installer): JsonResponse
    {
        if (!$request->user()?->can(self::INSTALL_ABILITY)) {
            return $this->forbidden();
        }
        try {
            $data = $installer->install($key, $request->user(), $request->boolean('as_copy'));

            return response()->json(['data' => $data], $data['updated_in_place'] ? 200 : 201);
        } catch (WorkflowHubException $e) {
            return $this->failed($e);
        }
    }

    private function failed(WorkflowHubException $e): JsonResponse
    {
        $messages = [
            WorkflowHubException::NOT_FOUND => ['text.workflow.library.error.not_found', 'This template is not in the library.'],
            WorkflowHubException::UNREACHABLE => ['text.workflow.library.error.unreachable', 'The template library cannot be reached right now. Try again later.'],
            WorkflowHubException::UNTRUSTED => ['text.workflow.library.error.untrusted', 'This template failed the iSPA signature check, so it was not installed.'],
            WorkflowHubException::NOT_AVAILABLE => ['text.workflow.library.error.not_available', 'Turn on the modules this template needs first.'],
            WorkflowHubException::NOT_INSTALLABLE => ['text.workflow.library.error.not_installable', 'This template cannot be installed on this website.'],
            WorkflowHubException::UP_TO_DATE => ['text.workflow.library.error.up_to_date', 'This version of the template is already installed.'],
            WorkflowHubException::CHANGED => ['text.workflow.library.error.changed', 'The installed workflow changed while it was being updated. Try again.'],
        ];
        [$key, $fallback] = $messages[$e->errorCode] ?? $messages[WorkflowHubException::NOT_INSTALLABLE];
        $text = __($key);
        $body = ['message' => is_string($text) && $text !== $key ? $text : $fallback, 'error_code' => $e->errorCode];
        if ($e->report !== null) {
            $body['data'] = $e->report;
        }
        if ($e->status >= 500 || $e->errorCode === WorkflowHubException::UNTRUSTED) {
            report($e);
        }

        return response()->json($body, $e->status);
    }

    private function forbidden(): JsonResponse
    {
        return response()->json(['message' => 'Forbidden'], 403);
    }
}
