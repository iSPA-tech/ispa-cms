<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Web;

use Cms\Core\Http\Requests\PasskeyLoginRequest;
use Cms\Core\Services\Security\WebsitePasskeySessionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Auth;

final class PasskeyLoginController
{
    public function options(WebsitePasskeySessionService $passkeys): JsonResponse
    {
        if (Auth::check()) {
            return $this->alreadyAuthenticated();
        }

        return $this->noStore([
            'data' => $passkeys->loginOptions(),
        ]);
    }

    public function login(PasskeyLoginRequest $request, WebsitePasskeySessionService $passkeys): JsonResponse
    {
        if (Auth::check()) {
            return $this->alreadyAuthenticated();
        }

        $passkeys->login(
            $request->validated('challenge_id'),
            $request->publicKeyCredential(),
            $request->boolean('remember'),
            $request->session(),
        );

        return $this->noStore([
            'data' => [
                'redirect_url' => redirect()->intended(url('/'))->getTargetUrl(),
            ],
        ]);
    }

    private function alreadyAuthenticated(): JsonResponse
    {
        return $this->noStore([
            'message' => 'Phiên đăng nhập hiện tại không thể dùng luồng passkey này.',
        ], 409);
    }

    /**
     * @param array<string, mixed> $data
     */
    private function noStore(array $data, int $status = 200): JsonResponse
    {
        return response()
            ->json($data, $status)
            ->header('Cache-Control', 'no-store, private');
    }
}
