<?php

use App\Models\User;
use Cms\Core\Records\RecordHistory;
use Cms\Core\Records\RecordRegistry;
use Cms\Core\Records\RecordType;

/** Build a fixture record type. */
function recordHistoryFixtureType(string $key = 'core.users', array $labels = []): RecordType
{
    return new RecordType($key, User::class, 'core.users.view', 'core.users.manage', null, ['name'], $labels);
}

it('registers, gets and lists types', function () {
    $registry = new RecordRegistry();
    $registry->register(recordHistoryFixtureType('core.users'));
    $registry->register(recordHistoryFixtureType('helpdesk.tickets'));

    expect($registry->keys())->toBe(['core.users', 'helpdesk.tickets'])
        ->and($registry->get('core.users')?->key)->toBe('core.users')
        ->and($registry->get('missing'))->toBeNull();
});

it('rejects a duplicate key', function () {
    $registry = new RecordRegistry();
    $registry->register(recordHistoryFixtureType());
    $registry->register(recordHistoryFixtureType());
})->throws(LogicException::class);

it('rejects a malformed key', function (string $key) {
    recordHistoryFixtureType($key);
})->throws(InvalidArgumentException::class)->with(['Upper', 'with space', 'slash/key', '']);

it('rejects a model that is not an Eloquent model', function () {
    new RecordType('core.bad', stdClass::class, 'v', 'm');
})->throws(InvalidArgumentException::class);

it('clamps per_page into 1-50', function (int $given, int $expected) {
    expect(RecordHistory::clampPerPage($given))->toBe($expected);
})->with([[-5, 1], [0, 1], [1, 1], [20, 20], [50, 50], [51, 50], [1000, 50]]);

it('maps declared actions to labels and unknown actions to the unknown key', function () {
    $type = recordHistoryFixtureType('core.users', ['action.update' => 'text.history.action.update']);

    expect($type->labelFor('action.update'))->toBe('text.history.action.update')
        ->and($type->labelFor('action.delete'))->toBe('text.history.action.unknown')
        ->and($type->labelFor(''))->toBe('text.history.action.unknown');
});

it('shows only whitelisted fields', function () {
    $type = recordHistoryFixtureType();

    expect($type->showsField('name'))->toBeTrue()
        ->and($type->showsField('password'))->toBeFalse();
});
