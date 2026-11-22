<?php
/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

namespace Cms\Core\Records;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Models\RecordNote;
use Cms\Core\Notifications\RecordMentionedNotification;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/**
 * Write internal notes on registered records and notify the people they @mention.
 *
 * A mention only counts when the mentioned user exists, is active, holds the type's view ability and can resolve
 * the record through the type's resolver. Other mentions are dropped and only counted (`ignored`), never named, so
 * the author learns nothing about other accounts. Mention notifications are sent after the database commit and
 * carry the record type, record uuid and note uuid only, never the note text.
 *
 * Write methods return `array{note: RecordNote, ignored: int}`.
 *
 * @package Cms\Core\Records
 * @author  iSPA Team
 */
class RecordNoteService
{
    /** Longest note body, in characters, after cleaning. */
    public const MAX_BODY_LENGTH = 4000;

    /** Most distinct users one note may mention. */
    public const MAX_MENTIONS = 10;

    /**
     * Add a note to a record.
     *
     * @param  RecordType          $type          Registered type of the record
     * @param  Model               $record        Record the note is attached to
     * @param  User                $author        Note author
     * @param  string              $body          Raw note text
     * @param  array<int, mixed>   $mentionUuids  UUIDs of the users mentioned
     * @return array{note: RecordNote, ignored: int}  The stored note and the number of mentions dropped
     *
     * @throws ValidationException When the body is empty, too long, or more than ten users are mentioned
     */
    public function add(RecordType $type, Model $record, User $author, string $body, array $mentionUuids): array
    {
        $clean = self::cleanBody($body);
        [$valid, $ignored] = $this->validMentions($type, $record, $author, $mentionUuids);

        $note = DB::transaction(function () use ($type, $record, $author, $clean, $valid): RecordNote {
            $note = RecordNote::query()->create([
                'target_type' => $type->key,
                'target_id' => (int) $record->getKey(),
                'body' => $clean,
                'mentions' => $valid->pluck('uuid')->values()->all(),
                'user_id' => (int) $author->getKey(),
            ]);

            event(new AuditLogEvent(
                'note_added',
                ['note_uuid' => $note->uuid, 'mentions' => $valid->count()],
                $record::class,
                (int) $record->getKey(),
                null,
                null,
                (int) $author->getKey(),
            ));

            return $note;
        });

        $this->notifyAfterCommit($type, $record, $note, $valid);

        return ['note' => $note, 'ignored' => $ignored];
    }

    /**
     * Change the text and mentions of a note. Only users newly mentioned by this edit are notified.
     *
     * @param  RecordType          $type          Registered type of the record
     * @param  Model               $record        Record the note is attached to
     * @param  RecordNote          $note          Note to change
     * @param  User                $author        User making the change; must be the note author
     * @param  string              $body          Raw note text
     * @param  array<int, mixed>   $mentionUuids  UUIDs of the users mentioned
     * @return array{note: RecordNote, ignored: int}  The changed note and the number of mentions dropped
     *
     * @throws AuthorizationException When the user is not the note author
     * @throws ValidationException When the body is empty, too long, or more than ten users are mentioned
     */
    public function update(RecordType $type, Model $record, RecordNote $note, User $author, string $body, array $mentionUuids): array
    {
        if ((int) $note->user_id !== (int) $author->getKey()) {
            throw new AuthorizationException();
        }

        $clean = self::cleanBody($body);
        [$valid, $ignored] = $this->validMentions($type, $record, $author, $mentionUuids);

        $previous = array_values(array_filter((array) ($note->mentions ?? []), 'is_string'));
        $note->forceFill([
            'body' => $clean,
            'mentions' => $valid->pluck('uuid')->values()->all(),
            'edited_at' => now(),
        ])->save();

        $added = $valid->reject(static fn (User $user): bool => in_array($user->uuid, $previous, true));
        $this->notifyAfterCommit($type, $record, $note, $added);

        return ['note' => $note, 'ignored' => $ignored];
    }

    /**
     * Soft-delete a note. Authorization is the caller's job.
     *
     * @param  RecordNote  $note  Note to delete
     * @return void
     */
    public function delete(RecordNote $note): void
    {
        $note->delete();
    }

    /**
     * Whether the user is active: not banned or inactive. A user without a status is treated as active, the same
     * rule the admin sign-in applies.
     *
     * @param  User  $user  User to check
     * @return bool
     */
    public static function isActive(User $user): bool
    {
        $status = $user->getAttribute('status');

        return $status === null || $status === '' || $status === UserStatusEnum::USER_ACTIVE->value;
    }

    /**
     * Whether the user may see the record: active, holding the type's view ability, and able to resolve it.
     *
     * @param  RecordType  $type    Registered type of the record
     * @param  string      $uuid    Record UUID
     * @param  User        $user    User to check
     * @return bool
     */
    public static function maySee(RecordType $type, string $uuid, User $user): bool
    {
        return self::isActive($user)
            && Gate::forUser($user)->allows($type->viewAbility)
            && $type->resolve($uuid, $user) !== null;
    }

    /**
     * Trim the body, normalize line breaks and strip control characters other than newline and tab.
     *
     * @param  string  $body  Raw note text
     * @return string  Clean text of 1-4000 characters
     *
     * @throws ValidationException When the cleaned text is empty, too long, or not valid UTF-8
     */
    public static function cleanBody(string $body): string
    {
        $normalized = str_replace(["\r\n", "\r"], "\n", $body);
        $stripped = preg_replace('/[\x{0000}-\x{0008}\x{000B}-\x{001F}\x{007F}-\x{009F}]/u', '', $normalized);
        $clean = is_string($stripped) ? trim($stripped) : '';
        $length = mb_strlen($clean);

        if ($length < 1 || $length > self::MAX_BODY_LENGTH) {
            throw ValidationException::withMessages([
                'body' => [__('validation.between.string', ['attribute' => 'body', 'min' => 1, 'max' => self::MAX_BODY_LENGTH])],
            ]);
        }

        return $clean;
    }

    /**
     * Split the requested mentions into users who may be notified and a count of dropped ones.
     *
     * Duplicates collapse; the author is removed without being counted; non-string, unknown, inactive or
     * unauthorized entries are counted as ignored.
     *
     * @param  RecordType         $type          Registered type of the record
     * @param  Model              $record        Record the note is attached to
     * @param  User               $author        Note author
     * @param  array<int, mixed>  $mentionUuids  Requested mention uuids
     * @return array{0: Collection<int, User>, 1: int}
     *
     * @throws ValidationException When more than ten distinct users are mentioned
     */
    private function validMentions(RecordType $type, Model $record, User $author, array $mentionUuids): array
    {
        $strings = array_values(array_unique(array_filter($mentionUuids, static fn ($value): bool => is_string($value) && $value !== '')));
        $ignored = count(array_unique(array_filter($mentionUuids, static fn ($value): bool => !is_string($value) || $value === ''), SORT_REGULAR));

        if (count($strings) > self::MAX_MENTIONS) {
            throw ValidationException::withMessages([
                'mentions' => [__('validation.max.array', ['attribute' => 'mentions', 'max' => self::MAX_MENTIONS])],
            ]);
        }

        $strings = array_values(array_filter($strings, static fn (string $uuid): bool => $uuid !== (string) $author->uuid));
        if ($strings === []) {
            return [collect(), $ignored];
        }

        $recordUuid = (string) $record->getAttribute('uuid');
        $valid = User::query()
            ->whereIn('uuid', $strings)
            ->where('id', '!=', $author->getKey())
            ->get()
            ->filter(static fn (User $user): bool => self::maySee($type, $recordUuid, $user))
            ->values();

        return [$valid, $ignored + count($strings) - $valid->count()];
    }

    /**
     * Send the mention notification to each user once the surrounding transaction (if any) commits.
     *
     * @param  RecordType               $type    Registered type of the record
     * @param  Model                    $record  Record the note is attached to
     * @param  RecordNote               $note    Note holding the mentions
     * @param  Collection<int, User>    $users   Users to notify
     * @return void
     */
    private function notifyAfterCommit(RecordType $type, Model $record, RecordNote $note, Collection $users): void
    {
        if ($users->isEmpty()) {
            return;
        }

        $notification = new RecordMentionedNotification($type->key, (string) $record->getAttribute('uuid'), (string) $note->uuid);
        DB::afterCommit(static fn () => Notification::send($users, $notification));
    }
}
