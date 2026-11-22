<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Http\Controllers\V2\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Cms\Core\Models\RecordNote;
use Cms\Core\Records\RecordNoteService;
use Cms\Core\Records\RecordRegistry;
use Cms\Core\Records\RecordType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * Internal notes on one registered record, and the people who may be @mentioned in them.
 *
 * Every action follows the record history rules: 404 for an unknown type, 403 when the user lacks the type's view
 * ability, 404 when the record does not exist or the type's resolver hides it. Responses carry uuids and names only,
 * never numeric ids, e-mail addresses or request metadata.
 *
 * @see RecordNoteService
 * @package Cms\Core\Http\Controllers\V2\Admin
 * @author  iSPA Team
 */
class RecordNoteController extends Controller
{
    /** Notes per page. */
    public const PER_PAGE = 20;

    /** Most users returned by the mention picker. */
    public const MENTIONABLE_LIMIT = 10;

    /** Most matching users inspected per mention search before giving up. */
    private const MENTIONABLE_SCAN = 200;

    /**
     * Create the controller.
     *
     * @param  RecordNoteService  $notes  Note writer
     */
    public function __construct(private readonly RecordNoteService $notes)
    {
    }

    /**
     * List a record's notes, newest first.
     *
     * `before` is the uuid of the last note of the previous page; `meta.next_before` gives the next one or null.
     *
     * @param  Request  $request  The incoming HTTP request (`before`)
     * @param  string   $type     Record type key
     * @param  string   $uuid     Record UUID
     * @return JsonResponse  `{data: items, meta: {next_before}}` or an error response
     */
    public function index(Request $request, string $type, string $uuid): JsonResponse
    {
        $context = $this->context($request, $type);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$recordType, $user] = $context;

        $validated = $request->validate(['before' => ['nullable', 'uuid']]);

        $record = $recordType->resolve($uuid, $user);
        if ($record === null) {
            return $this->notFound();
        }

        $query = $this->notesOf($recordType, $record);
        if (isset($validated['before'])) {
            $beforeId = $this->notesOf($recordType, $record)->where('uuid', $validated['before'])->value('id');
            if ($beforeId === null) {
                return response()->json(['message' => 'The given data was invalid.', 'errors' => ['before' => ['invalid']]], 422);
            }
            $query->where('id', '<', $beforeId);
        }

        $rows = $query->with('author:id,uuid,name')->orderByDesc('id')->limit(self::PER_PAGE + 1)->get();
        $hasMore = $rows->count() > self::PER_PAGE;
        $rows = $rows->take(self::PER_PAGE)->values();

        $mentionNames = $this->mentionNames($rows);
        $canManage = Gate::forUser($user)->allows($recordType->manageAbility);

        return response()->json([
            'data' => $rows->map(fn (RecordNote $note): array => $this->item($note, $user, $canManage, $mentionNames))->all(),
            'meta' => ['next_before' => $hasMore ? $rows->last()?->uuid : null],
        ]);
    }

    /**
     * Add a note to a record.
     *
     * @param  Request  $request  The incoming HTTP request (`body`, `mentions[]` user uuids)
     * @param  string   $type     Record type key
     * @param  string   $uuid     Record UUID
     * @return JsonResponse  201 `{data: item, meta: {ignored}}` or an error response
     */
    public function store(Request $request, string $type, string $uuid): JsonResponse
    {
        $context = $this->context($request, $type);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$recordType, $user] = $context;

        $validated = $this->validateWrite($request);

        $record = $recordType->resolve($uuid, $user);
        if ($record === null) {
            return $this->notFound();
        }

        $result = $this->notes->add($recordType, $record, $user, $validated['body'], $validated['mentions'] ?? []);

        return $this->written($result, $recordType, $user, 201);
    }

    /**
     * Change a note. Only its author may do so.
     *
     * @param  Request  $request  The incoming HTTP request (`body`, `mentions[]` user uuids)
     * @param  string   $type     Record type key
     * @param  string   $uuid     Record UUID
     * @param  string   $note     Note UUID
     * @return JsonResponse  `{data: item, meta: {ignored}}` or an error response
     */
    public function update(Request $request, string $type, string $uuid, string $note): JsonResponse
    {
        $context = $this->context($request, $type);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$recordType, $user] = $context;

        $validated = $this->validateWrite($request);

        $record = $recordType->resolve($uuid, $user);
        $model = $record === null ? null : $this->notesOf($recordType, $record)->where('uuid', $note)->first();
        if ($model === null) {
            return $this->notFound();
        }

        if ((int) $model->user_id !== (int) $user->getKey()) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $result = $this->notes->update($recordType, $record, $model, $user, $validated['body'], $validated['mentions'] ?? []);

        return $this->written($result, $recordType, $user, 200);
    }

    /**
     * Delete a note. Its author and holders of the type's manage ability may do so.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  string   $type     Record type key
     * @param  string   $uuid     Record UUID
     * @param  string   $note     Note UUID
     * @return JsonResponse|Response  204 or an error response
     */
    public function destroy(Request $request, string $type, string $uuid, string $note): JsonResponse|Response
    {
        $context = $this->context($request, $type);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$recordType, $user] = $context;

        $record = $recordType->resolve($uuid, $user);
        $model = $record === null ? null : $this->notesOf($recordType, $record)->where('uuid', $note)->first();
        if ($model === null) {
            return $this->notFound();
        }

        $isAuthor = (int) $model->user_id === (int) $user->getKey();
        if (!$isAuthor && !Gate::forUser($user)->allows($recordType->manageAbility)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        $this->notes->delete($model);

        return response()->noContent();
    }

    /**
     * Active users who may see the record and match the search, for the mention picker.
     *
     * @param  Request  $request  The incoming HTTP request (`q`, at least two characters, matched on name or e-mail)
     * @param  string   $type     Record type key
     * @param  string   $uuid     Record UUID
     * @return JsonResponse  `{data: [{uuid, name}]}` with at most ten users, or an error response
     */
    public function mentionable(Request $request, string $type, string $uuid): JsonResponse
    {
        $context = $this->context($request, $type);
        if ($context instanceof JsonResponse) {
            return $context;
        }
        [$recordType, $user] = $context;

        $validated = $request->validate(['q' => ['required', 'string', 'min:2', 'max:100']]);

        $record = $recordType->resolve($uuid, $user);
        if ($record === null) {
            return $this->notFound();
        }

        $term = '%' . addcslashes(trim($validated['q']), '\\%_') . '%';
        $candidates = User::query()
            ->select(['id', 'uuid', 'name', 'status'])
            ->whereKeyNot($user->getKey())
            ->where(static fn ($query) => $query->where('name', 'like', $term)->orWhere('email', 'like', $term))
            ->orderBy('name')
            ->orderBy('id')
            ->limit(self::MENTIONABLE_SCAN)
            ->get();

        $found = [];
        foreach ($candidates as $candidate) {
            if (RecordNoteService::maySee($recordType, $uuid, $candidate)) {
                $found[] = ['uuid' => $candidate->uuid, 'name' => $candidate->name];
                if (count($found) >= self::MENTIONABLE_LIMIT) {
                    break;
                }
            }
        }

        return response()->json(['data' => $found]);
    }

    /**
     * Resolve the record type and check the type-level view ability.
     *
     * @param  Request  $request  The incoming HTTP request
     * @param  string   $type     Record type key
     * @return array{0: RecordType, 1: User}|JsonResponse  Type and user, or a 404/403 response
     */
    private function context(Request $request, string $type): array|JsonResponse
    {
        $recordType = app(RecordRegistry::class)->get($type);
        if ($recordType === null) {
            return $this->notFound();
        }

        $user = $request->user();
        if (!$user instanceof User || !Gate::forUser($user)->allows($recordType->viewAbility)) {
            return response()->json(['message' => 'Forbidden'], 403);
        }

        return [$recordType, $user];
    }

    /**
     * Validate the note body and mention list of a write request.
     *
     * Length and mention-count rules are enforced by {@see RecordNoteService} after cleaning and de-duplication.
     *
     * @param  Request  $request  The incoming HTTP request
     * @return array{body: string, mentions?: array<int, mixed>}
     */
    private function validateWrite(Request $request): array
    {
        return $request->validate([
            'body' => ['required', 'string', 'max:20000'],
            'mentions' => ['nullable', 'array', 'max:100'],
            'mentions.*' => ['nullable', 'string', 'max:64'],
        ]);
    }

    /**
     * Query of the live notes attached to one record.
     *
     * @param  RecordType  $type    Registered type of the record
     * @param  Model       $record  Record
     * @return \Illuminate\Database\Eloquent\Builder<RecordNote>
     */
    private function notesOf(RecordType $type, Model $record): \Illuminate\Database\Eloquent\Builder
    {
        return RecordNote::query()
            ->where('target_type', $type->key)
            ->where('target_id', $record->getKey());
    }

    /**
     * Names of every user mentioned on the page, keyed by uuid.
     *
     * @param  Collection<int, RecordNote>  $rows  Notes of the page
     * @return array<string, string>
     */
    private function mentionNames(Collection $rows): array
    {
        $uuids = $rows->flatMap(static fn (RecordNote $note): array => array_filter((array) ($note->mentions ?? []), 'is_string'))
            ->unique()->values()->all();

        if ($uuids === []) {
            return [];
        }

        return User::query()->whereIn('uuid', $uuids)->pluck('name', 'uuid')->map(static fn ($name): string => (string) $name)->all();
    }

    /**
     * Response item of one note.
     *
     * @param  RecordNote             $note          Note
     * @param  User                   $user          Current user
     * @param  bool                   $canManage     Whether the user holds the type's manage ability
     * @param  array<string, string>  $mentionNames  Names of mentioned users keyed by uuid
     * @return array<string, mixed>
     */
    private function item(RecordNote $note, User $user, bool $canManage, array $mentionNames): array
    {
        $isAuthor = (int) $note->user_id === (int) $user->getKey();
        $author = $note->author;
        $mentions = [];
        foreach ((array) ($note->mentions ?? []) as $mentionUuid) {
            if (is_string($mentionUuid) && isset($mentionNames[$mentionUuid])) {
                $mentions[] = ['uuid' => $mentionUuid, 'name' => $mentionNames[$mentionUuid]];
            }
        }

        return [
            'uuid' => $note->uuid,
            'body' => $note->body,
            'author' => $author === null ? null : ['uuid' => $author->uuid, 'name' => $author->name],
            'mentions' => $mentions,
            'created_at' => $note->created_at?->toIso8601String(),
            'edited_at' => $note->edited_at?->toIso8601String(),
            'can_edit' => $isAuthor,
            'can_delete' => $isAuthor || $canManage,
        ];
    }

    /**
     * Response of a successful add or change.
     *
     * @param  array{note: RecordNote, ignored: int}  $result      Service result
     * @param  RecordType                             $recordType  Registered type of the record
     * @param  User                                   $user        Current user
     * @param  int                                    $status      HTTP status
     * @return JsonResponse
     */
    private function written(array $result, RecordType $recordType, User $user, int $status): JsonResponse
    {
        $note = $result['note']->load('author:id,uuid,name');
        $item = $this->item($note, $user, Gate::forUser($user)->allows($recordType->manageAbility), $this->mentionNames(collect([$note])));

        return response()->json(['data' => $item, 'meta' => ['ignored' => $result['ignored']]], $status);
    }

    /**
     * Plain 404 response.
     *
     * @return JsonResponse
     */
    private function notFound(): JsonResponse
    {
        return response()->json(['message' => 'Not Found'], 404);
    }
}
