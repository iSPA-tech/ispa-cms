<?php

declare(strict_types=1);

namespace Cms\Core\Services\Security;

use App\Enums\UserStatusEnum;
use App\Models\User;
use Cms\Core\Models\AuthChallenge;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use RuntimeException;

final class WebMfaSessionService
{
    private const PENDING_CHALLENGE_KEY = 'core.web_mfa.pending_challenge';
    private const VERIFIED_USER_ID_KEY = 'core.web_mfa.verified_user_id';
    private const INTENDED_PATH_KEY = 'core.web_mfa.intended_path';
    private const CONTEXT_SCOPE = 'website';

    public function __construct(
        private readonly AdminMfaService $mfa,
    ) {}

    public function start(User $user, Request $request, bool $remember = false): bool
    {
        // The guard may carry a model reused by a settings update in this request.
        // MFA must always resolve the latest factor and mode state.
        $user->unsetRelation('settings');
        $user->unsetRelation('mfaFactors');

        $this->assertEligible($user);

        if ($this->isVerified($request, $user)) {
            return false;
        }

        if ($this->pendingChallenge($request, $user)) {
            return true;
        }

        $request->session()->forget([
            self::PENDING_CHALLENGE_KEY,
            self::VERIFIED_USER_ID_KEY,
        ]);

        $requiredMethods = $this->mfa->resolveRequiredMethods($user);

        $challenge = $this->mfa->createChallenge(
            $user,
            (string) $request->ip(),
            $request->userAgent(),
            $remember,
            [
                'scope' => self::CONTEXT_SCOPE,
                'enforced_for_website' => true,
            ],
            $requiredMethods,
        );

        if (! $challenge) {
            $this->markVerified($request, $user);

            return false;
        }

        $request->session()->put(self::PENDING_CHALLENGE_KEY, [
            'challenge_id' => $challenge->uuid,
            'user_id' => (int) $user->getKey(),
        ]);

        return true;
    }

    public function assertEligible(User $user): void
    {
        if (! in_array((string) $user->status, [UserStatusEnum::USER_ACTIVE->value, 'active'], true)) {
            throw ValidationException::withMessages([
                'username' => ['Tài khoản của bạn đã bị khóa hoặc chưa được kích hoạt.'],
                'email' => ['Tài khoản của bạn đã bị khóa hoặc chưa được kích hoạt.'],
            ]);
        }

        if ($user->email_verified_at === null) {
            throw ValidationException::withMessages([
                'username' => [__('mgs.auth.email-not-verified')],
                'email' => [__('mgs.auth.email-not-verified')],
            ]);
        }
    }

    public function isVerified(Request $request, User $user): bool
    {
        return (int) $request->session()->get(self::VERIFIED_USER_ID_KEY) === (int) $user->getKey();
    }

    public function pendingChallenge(Request $request, ?User $user = null): ?AuthChallenge
    {
        $pending = $request->session()->get(self::PENDING_CHALLENGE_KEY);

        if (! is_array($pending)
            || ! is_string($pending['challenge_id'] ?? null)
            || ! is_numeric($pending['user_id'] ?? null)) {
            return null;
        }

        if ($user && (int) $pending['user_id'] !== (int) $user->getKey()) {
            $this->clearPendingChallenge($request);

            return null;
        }

        $challenge = $this->mfa->findPendingChallengeByUuid($pending['challenge_id']);

        if (! $challenge
            || $challenge->status !== 'pending'
            || $challenge->expires_at?->isPast()
            || (int) $challenge->user_id !== (int) $pending['user_id']
            || data_get($challenge->context, 'scope') !== self::CONTEXT_SCOPE) {
            $this->clearPendingChallenge($request);

            return null;
        }

        return $challenge;
    }

    /**
     * @return array{type: string, payload: array<string, mixed>}
     */
    public function verify(Request $request, string $code, ?string $method = null): array
    {
        $user = $request->user('web');
        $challenge = $user instanceof User
            ? $this->pendingChallenge($request, $user)
            : null;

        if (! $challenge) {
            throw new RuntimeException(__('mgs.auth.challenge_not_found'));
        }

        $result = $this->mfa->verifyChallenge(
            $challenge,
            $code,
            $method,
            static fn (User $user): array => ['user_id' => (int) $user->getKey()],
        );

        if ($result['type'] === 'auth') {
            $user = $challenge->user;

            if (! $user instanceof User) {
                throw new RuntimeException(__('mgs.auth.challenge_not_found'));
            }

            $this->clearPendingChallenge($request);
            $this->markVerified($request, $user);
            $request->session()->regenerate();
        }

        return $result;
    }

    /**
     * @return array<string, mixed>
     */
    public function resend(Request $request): array
    {
        $user = $request->user('web');
        $challenge = $user instanceof User
            ? $this->pendingChallenge($request, $user)
            : null;

        if (! $challenge) {
            throw new RuntimeException(__('mgs.auth.challenge_not_found'));
        }

        return $this->mfa->resendEmailCode($challenge);
    }

    public function cancel(Request $request): void
    {
        $user = $request->user('web');
        $challenge = $user instanceof User
            ? $this->pendingChallenge($request, $user)
            : null;

        if ($challenge) {
            AuthChallenge::query()
                ->whereKey($challenge->id)
                ->where('status', 'pending')
                ->update(['status' => 'cancelled']);
        }

        $request->session()->forget([
            self::PENDING_CHALLENGE_KEY,
            self::VERIFIED_USER_ID_KEY,
            self::INTENDED_PATH_KEY,
        ]);
    }

    public function rememberIntendedPath(Request $request): void
    {
        if (! $request->isMethod('GET')) {
            return;
        }

        $path = $request->getRequestUri();

        if (! str_starts_with($path, '/') || preg_match('#^[/\\\\]{2}#', $path)) {
            return;
        }

        $request->session()->put(self::INTENDED_PATH_KEY, $path);
    }

    public function consumeIntendedPath(Request $request): string
    {
        $path = (string) $request->session()->pull(self::INTENDED_PATH_KEY, '/');

        return str_starts_with($path, '/') && ! preg_match('#^[/\\\\]{2}#', $path)
            ? $path
            : '/';
    }

    private function markVerified(Request $request, User $user): void
    {
        $request->session()->put(self::VERIFIED_USER_ID_KEY, (int) $user->getKey());
    }

    private function clearPendingChallenge(Request $request): void
    {
        $request->session()->forget(self::PENDING_CHALLENGE_KEY);
    }
}
