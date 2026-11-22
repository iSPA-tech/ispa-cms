<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace App\Http\Controllers\V2\Admin;

use Exception;
use Throwable;
use App\Models\Contact;
use Cms\Core\Models\Email;
use App\Services\ContactAdminReadService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Cache;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Cms\Core\Traits\Controllers\SearchTrait;
use Cms\Core\Http\Controllers\AdminController;
use Cms\Core\Http\Requests\CoreImportRequest;
use Cms\Core\Services\Import\CoreImportEngine;
use Cms\Core\Services\Import\ImportContext;
use App\Services\Import\ContactImportDefinition;
use App\Repositories\Interfaces\ContactInterface;
use Cms\Core\Http\Resources\Contact\ContactCollection;
use Cms\Core\Http\Requests\Contact\StoreContactRequest;
use Cms\Core\Http\Requests\Contact\UpdateContactRequest;
use Cms\Core\Http\Requests\Contact\MultiDeleteContactRequest;

class ContactController extends AdminController
{
    use SearchTrait;

    /**
     * Controller constructor
     *
     * @param ContactInterface $model
     *
     * @author malayvuong
     *
     * @since 1.0.0
     */
    public function __construct(
        protected ContactInterface $model,
        protected ContactAdminReadService $contactReadService,
    )
    {
        parent::__construct();
    }

    /**
     * Counter contact
     *
     *
     * @return mixed
     *
     * @author malayvuong
     *
     * @since 1.0.0 - 2022-02-6, 16:15
     */
    public function counter(): mixed
    {
        $all = $this->model->model()->where([

        ])->count();

        return ires()->data([
            'contact' => $all,
        ]);
    }

    /**
     * Provide dashboard metrics for contacts.
     */
    public function dashboard(Request $request): mixed
    {
        if (!$this->canViewDashboard()) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $activityDays = max(7, min((int)$request->query('activity_days', 14), 90));
        $recentLimit = max(3, min((int)$request->query('recent_limit', 8), 30));
        $sourceLimit = max(3, min((int)$request->query('source_limit', 6), 20));
        $typeLimit = max(3, min((int)$request->query('type_limit', 6), 20));

        $filterHash = md5(json_encode($request->only([
            'keywords',
            'search',
            'status',
            'type',
            'source',
            'source_prefix',
            'sourcePrefix',
            'showroom_uid',
            'showroomUid',
        ])));
        $cacheVersion = (string) Cache::get(ContactAdminReadService::DASHBOARD_CACHE_VERSION_KEY, '0');

        $cacheKey = sprintf(
            'contact.dashboard:%s:%d:%d:%d:%d:%s:%s',
            (string)(auth()->id() ?? 'guest'),
            $activityDays,
            $recentLimit,
            $sourceLimit,
            $typeLimit,
            $filterHash,
            $cacheVersion,
        );

        if ($request->boolean('reload')) {
            Cache::forget($cacheKey);
        }

        $filters = $request->only([
            'keywords',
            'search',
            'status',
            'type',
            'source',
            'source_prefix',
            'sourcePrefix',
            'showroom_uid',
            'showroomUid',
        ]);

        $payload = Cache::remember($cacheKey, now()->addMinutes(5), function () use (
            $activityDays,
            $recentLimit,
            $sourceLimit,
            $typeLimit,
            $filters
        ) {
            $now = now();
            $startOfToday = (clone $now)->startOfDay();
            $weekAgo = (clone $now)->subDays(7)->startOfDay();
            $monthAgo = (clone $now)->subDays(30)->startOfDay();
            $activityStart = (clone $now)->subDays($activityDays - 1)->startOfDay();

            $filteredContacts = $this->contactReadService->applyFilters(Contact::query(), $filters);
            $blockedContacts = (clone $filteredContacts)
                ->where('status', ContactAdminReadService::BLOCKED_STATUS)
                ->count();
            $baseQuery = ($filters['status'] ?? null) === ContactAdminReadService::BLOCKED_STATUS
                ? clone $filteredContacts
                : $this->contactReadService->excludeBlockedContacts(clone $filteredContacts);

            $totalContacts = (clone $baseQuery)->count();
            $todayContacts = (clone $baseQuery)->where('created_at', '>=', $startOfToday)->count();
            $weeklyContacts = (clone $baseQuery)->where('created_at', '>=', $weekAgo)->count();
            $monthlyContacts = (clone $baseQuery)->where('created_at', '>=', $monthAgo)->count();
            $subscribedContacts = (clone $baseQuery)->where('is_subscribe', true)->count();
            $assignedContacts = (clone $baseQuery)->whereNotNull('user_id')->count();

            $repliedContactIds = Email::query()
                ->whereNotNull('contact_id')
                ->whereIn('contact_id', (clone $baseQuery)->select('id'))
                ->where(static function ($query) {
                    $query->where('type', 'outbound')
                        ->orWhereNotNull('reply_id');
                })
                ->distinct()
                ->pluck('contact_id')
                ->filter()
                ->values();

            $repliedLookup = array_fill_keys($repliedContactIds->all(), true);
            $repliedCount = $repliedContactIds->count();

            $pendingReplyQuery = clone $baseQuery;
            if ($repliedContactIds->isNotEmpty()) {
                $pendingReplyQuery->whereNotIn('id', $repliedContactIds->all());
            }
            $pendingReplies = $pendingReplyQuery->count();

            $unreadEmails = Email::query()->whereNull('last_read')->count();

            $unknownLabel = __('t.contact_dashboard.unknown');

            $sourceStats = (clone $baseQuery)
                ->selectRaw(
                    'COALESCE(NULLIF(source, \'\'), ?) as source_key, COUNT(*) as total',
                    [$unknownLabel]
                )
                ->groupBy('source_key')
                ->orderByDesc('total')
                ->limit($sourceLimit)
                ->get()
                ->map(fn($row) => [
                    'label' => $row->source_key === $unknownLabel
                        ? $unknownLabel
                        : ($this->contactReadService->formatSourceLabel($row->source_key) ?? $row->source_key),
                    'total' => (int)$row->total,
                ])
                ->values();

            $typeStats = (clone $baseQuery)
                ->selectRaw(
                    'COALESCE(NULLIF(type, \'\'), ?) as label, COUNT(*) as total',
                    [$unknownLabel]
                )
                ->groupBy('label')
                ->orderByDesc('total')
                ->limit($typeLimit)
                ->get()
                ->map(static fn($row) => [
                    'label' => $row->label,
                    'total' => (int)$row->total,
                ])
                ->values();

            $recentContactsQuery = (clone $baseQuery)
                ->select([
                    'id',
                    'name',
                    'email',
                    'mobile',
                    'address',
                    'organization',
                    'url',
                    'source',
                    'type',
                    'status',
                    'user_id',
                    'is_subscribe',
                    'created_at',
                    'updated_at',
                ])
                ->orderByDesc('created_at')
                ->limit($recentLimit)
                ->get();

            $recentContactIds = $recentContactsQuery->pluck('id')->filter()->values();
            $latestInboxByContact = $this->contactReadService->getLatestInboxByContactIds($recentContactIds);

            $recentContacts = $recentContactsQuery
                ->map(fn(Contact $contact): array => $this->contactReadService->mapContactRecord(
                    $contact,
                    $latestInboxByContact->get($contact->id),
                    $repliedLookup
                ))
                ->toArray();

            $activity = (clone $baseQuery)
                ->selectRaw('DATE(created_at) as date, COUNT(*) as total')
                ->where('created_at', '>=', $activityStart)
                ->groupBy('date')
                ->orderBy('date')
                ->get()
                ->map(static fn($row) => [
                    'date' => $row->date,
                    'total' => (int)$row->total,
                ])
                ->toArray();

            return [
                'refreshedAt' => $now->toIso8601String(),
                'totals' => [
                    'total' => $totalContacts,
                    'today' => $todayContacts,
                    'week' => $weeklyContacts,
                    'month' => $monthlyContacts,
                    'subscribed' => $subscribedContacts,
                    'assigned' => $assignedContacts,
                ],
                'status' => [
                    'blocked' => $blockedContacts,
                    'replied' => $repliedCount,
                    'pending' => $pendingReplies,
                    'unreadEmails' => $unreadEmails,
                ],
                'sources' => $sourceStats,
                'types' => $typeStats,
                'recentContacts' => $recentContacts,
                'activity' => $activity,
                'scope' => [
                    'status' => $filters['status'] ?? null,
                    'type' => $filters['type'] ?? null,
                    'sourcePrefix' => $filters['source_prefix'] ?? $filters['sourcePrefix'] ?? null,
                    'showroomUid' => $filters['showroom_uid'] ?? $filters['showroomUid'] ?? null,
                ],
            ];
        });

        return ires()->data($payload);
    }

    /**
     * Determine if the authenticated user can view the contact dashboard.
     */
    protected function canViewDashboard(): bool
    {
        $user = auth()->user();

        if (!$user) {
            return false;
        }

        if ($user->can('contact.viewAny')) {
            return true;
        }

        try {
            return $user->can('viewAny', $this->model->model());
        } catch (Exception) {
            return false;
        }
    }

    /**
     * Display a listing of the resource.
     *
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function index(Request $request): mixed
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $perPage = max(10, min((int) $request->query('pageSize', $request->query('limit', 10)), 100));
        $model = $this->contactReadService
            ->applyFilters(Contact::query(), $request->all())
            ->select([
                'id',
                'name',
                'email',
                'mobile',
                'address',
                'organization',
                'url',
                'source',
                'type',
                'status',
                'user_id',
                'is_subscribe',
                'created_at',
                'updated_at',
            ])
            ->orderByDesc('created_at')
            ->paginate($perPage);

        $contactIds = $model->getCollection()->pluck('id')->filter()->values();
        $latestInboxByContact = $this->contactReadService->getLatestInboxByContactIds($contactIds);
        $repliedLookup = $this->contactReadService->getRepliedLookup($contactIds);

        $model->setCollection(
            $model->getCollection()->map(fn(Contact $contact): array => $this->contactReadService->mapContactRecord(
                $contact,
                $latestInboxByContact->get($contact->id),
                $repliedLookup
            ))
        );

        return ires()->data(new ContactCollection($model));
    }

    /**
     * Show the form for creating a new resource.
     *
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function create()
    {
        return $res;
    }

    /**
     * Store a newly created resource in storage.
     *
     * @param StoreContactRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function store(StoreContactRequest $request): mixed
    {
        $model = $this->fill();

        if (!user()?->can('create', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        DB::beginTransaction();

        try {
            //  Process to save
            $model->save();
        } catch (Exception $e) {
            Log::debug($e->getMessage());
            DB::rollBack();

            return ires()->error()->message($e->getMessage());
        }

        DB::commit();

        return ires()->message(__('mgs.ok.create', ['name' => __('nav.a.contacts')]));
    }

    /**
     * Fill table
     *
     * @param null $model
     *
     * @return mixed $model
     *
     **@author malayvuong
     */
    protected function fill($model = null)
    {
        if (!$model) {
            $model = $this->model->model();
        }

        $model->fill(request()->only($model->getFillable()));

        return $model;
    }

    /**
     * Display the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function show(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('viewAny', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $contactIds = collect([$model->id]);
        $latestInboxByContact = $this->contactReadService->getLatestInboxByContactIds($contactIds);
        $repliedLookup = $this->contactReadService->getRepliedLookup($contactIds);

        return ires()->data($this->contactReadService->mapContactRecord(
            $model,
            $latestInboxByContact->get($model->id),
            $repliedLookup
        ));
    }

    /**
     * Show the form for editing the specified resource.
     *
     * @param int $id
     *
     * @return mixed $res
     *
     * @author malayvuong
     */
    public function edit(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->data($model);
    }

    /**
     * Update the specified resource in storage.
     *
     * @param int $id
     * @param UpdateContactRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function update(int $id, UpdateContactRequest $request): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('update', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $model = $this->fill($model);

        if (!$model->save()) {
            return ires()->error()->message(__('mgs.er.update', ['name' => __('nav.a.contacts')]));
        }

        return ires()->message(__('mgs.ok.update', ['name' => __('nav.a.contacts')]));
    }

    /**
     * Remove the specified resource from storage.
     *
     * @param int $id
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function destroy(int $id): mixed
    {
        if ($id < 1) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        $model = $this->model->find($id);

        if (!$model) {
            return ires()->error()->message(__('mgs.model.404'));
        }

        if (!user()?->can('delete', $model)) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        if (!$model->delete()) {
            return ires()->error()->message(__('mgs.er.delete', ['name' => __('nav.a.contacts')]));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.contacts')]));
    }

    /**
     * Remove the multiple specified resource from storage.
     *
     * @param MultiDeleteContactRequest $request
     *
     * @return mixed $res
     * @author malayvuong
     *
     */
    public function massDestroy(MultiDeleteContactRequest $request): mixed
    {
        if (!user()?->can('delete', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        return ires()->message(__('mgs.ok.delete', ['name' => __('nav.a.contacts')]));
    }

    /**
     * Import contacts from CSV/Excel file.
     *
     * @param CoreImportRequest $request
     * @param CoreImportEngine $engine
     *
     * @return mixed
     */
    public function import(CoreImportRequest $request, CoreImportEngine $engine): mixed
    {
        if (!user()?->can('create', $this->model->model())) {
            return ires()->error()->message(__('mgs.no.permission'));
        }

        $options = $request->toImportOptions();
        $def = new ContactImportDefinition();
        $ctx = new ImportContext($def, $options, user()?->getAuthIdentifier() ?? 0);

        try {
            $report = $engine->run($def, $request->file('file'), $options, $ctx);

            return ires()->data($report);
        } catch (Throwable $e) {
            Log::error('Contact import failed: ' . $e->getMessage());

            return ires()->error()->message($e->getMessage());
        }
    }

    /**
     * Export contacts to Excel file.
     *
     * @param Request $request
     *
     * @return StreamedResponse
     */
    public function export(Request $request): StreamedResponse
    {
        if (!user()?->can('viewAny', $this->model->model())) {
            abort(403, __('mgs.no.permission'));
        }

        $query = $this->contactReadService
            ->applyFilters(Contact::query(), $request->all())
            ->select([
                'name', 'email', 'mobile', 'address', 'organization',
                'url', 'source', 'type', 'birthday', 'gender', 'is_subscribe',
                'created_at',
            ])
            ->orderByDesc('created_at');

        $contacts = $query->get();

        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('Contacts');

        $headers = [
            'Name', 'Email', 'Mobile', 'Address', 'Organization',
            'URL', 'Source', 'Type', 'Birthday', 'Gender', 'Subscribed',
            'Created At',
        ];

        foreach ($headers as $col => $header) {
            $sheet->setCellValue([$col + 1, 1], $header);
        }

        $sheet->getStyle('1:1')->getFont()->setBold(true);

        $genderMap = [0 => 'Unknown', 1 => 'Male', 2 => 'Female'];

        foreach ($contacts as $row => $contact) {
            $rowNum = $row + 2;
            $sheet->setCellValue([1, $rowNum], $contact->name);
            $sheet->setCellValue([2, $rowNum], $contact->email);
            $sheet->setCellValue([3, $rowNum], $contact->mobile);
            $sheet->setCellValue([4, $rowNum], $contact->address);
            $sheet->setCellValue([5, $rowNum], $contact->organization);
            $sheet->setCellValue([6, $rowNum], $contact->url);
            $sheet->setCellValue([7, $rowNum], $contact->source);
            $sheet->setCellValue([8, $rowNum], $contact->type);
            $sheet->setCellValue([9, $rowNum], $contact->birthday?->format('Y-m-d'));
            $sheet->setCellValue([10, $rowNum], $genderMap[$contact->gender] ?? 'Unknown');
            $sheet->setCellValue([11, $rowNum], $contact->is_subscribe ? 'Yes' : 'No');
            $sheet->setCellValue([12, $rowNum], $contact->created_at?->format('Y-m-d H:i'));
        }

        $filename = 'contacts-' . now()->format('Y-m-d') . '.xlsx';

        return new StreamedResponse(function () use ($spreadsheet) {
            $writer = new Xlsx($spreadsheet);
            $writer->save('php://output');
        }, 200, [
            'Content-Type'        => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Cache-Control'       => 'max-age=0',
        ]);
    }
}
