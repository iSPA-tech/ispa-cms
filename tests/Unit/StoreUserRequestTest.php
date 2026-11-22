<?php

namespace Tests\Unit;

use Cms\Core\Http\Requests\User\StoreUserRequest;
use PHPUnit\Framework\TestCase;

class StoreUserRequestTest extends TestCase
{
    public function test_store_user_roles_must_be_existing_integer_ids(): void
    {
        $rules = (new StoreUserRequest())->rules();

        $this->assertSame('array|required|min:1', $rules['roles']);
        $this->assertSame('integer|exists:roles,id', $rules['roles.*']);
    }
}
