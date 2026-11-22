<?php

namespace Tests\Traits;

trait WithApiVerifyHeader
{
    /**
     * Add ISPA-AUTH-KEY header for api.verify middleware.
     * Value is read from config/env ISPA_API_AUTH_KEY (e.g. set in phpunit.xml).
     */
    protected function withApiVerify(): array
    {
        $key = env('ISPA_API_AUTH_KEY', 'test-api-verify-key');

        return [
            'ISPA-AUTH-KEY' => $key,
        ];
    }
}
