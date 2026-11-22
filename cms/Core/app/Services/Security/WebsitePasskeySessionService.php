<?php

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Events\AuditLogEvent;
use Illuminate\Session\Store;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;
use Webauthn\PublicKeyCredential;

final class WebsitePasskeySessionService
{
    private const CONTEXT = 'website';

    public function __construct(private readonly PasskeyService $passkeys)
    {
    }

    /**
     * @return array{challenge_id: string, options: array<string, mixed>}
     */
    public function loginOptions(): array
    {
        return $this->passkeys->loginOptions(self::CONTEXT);
    }

    public function login(
        string $challengeId,
        PublicKeyCredential $credential,
        bool $remember,
        Store $session,
    ): User {
        $user = $this->passkeys->verifyLogin($challengeId, self::CONTEXT, $credential);

        if (! in_array((string) $user->status, [UserStatusEnum::USER_ACTIVE->value, 'active'], true)
            || $user->email_verified_at === null) {
            throw ValidationException::withMessages([
                'credential' => ['Tài khoản chưa đủ điều kiện đăng nhập bằng passkey.'],
            ]);
        }

        Auth::login($user, $remember);
        $session->regenerate();

        event(new AuditLogEvent(
            'sign-in',
            [],
            User::class,
            (int) $user->getKey(),
            'fa fa-sign-in-alt',
            'text-green-600',
        ));

        return $user;
    }
}
