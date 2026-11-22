<?php

uses(\Cms\Core\Tests\TestCase::class);

use Cms\Core\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Model;

test('UserRepository findByUuid forwards to findBy with settings', function () {
    $repo = new class extends UserRepository {
        public array $lastArgs = [];

        public function __construct() {}

        public function findBy(array $where = [], array $select = ['*'], array $with = []): ?object
        {
            $this->lastArgs = [$where, $select, $with];

            return new class extends Model {
                protected $table = 'users';
            };
        }
    };

    $repo->findByUuid('uuid-123', ['id']);

    expect($repo->lastArgs[0])->toBe(['uuid' => 'uuid-123']);
    expect($repo->lastArgs[1])->toBe(['id']);
    expect($repo->lastArgs[2])->toBe(['settings']);
});

test('UserRepository getByEmailOrUsername looks an email-shaped input up by email, without a DNS check', function () {
    $repo = new class extends UserRepository {
        public array $lastArgs = [];

        public function __construct() {}

        public function findBy(array $where = [], array $select = ['*'], array $with = []): ?object
        {
            $this->lastArgs = [$where, $select, $with];

            return new class extends Model {
                protected $table = 'users';
            };
        }
    };

    $repo->getByEmailOrUsername('user@example.com', ['roles']);

    expect($repo->lastArgs[0])->toBe(['email' => 'user@example.com']);
    expect($repo->lastArgs[1])->toBe(['*']);
    expect($repo->lastArgs[2])->toBe(['roles']);
});
