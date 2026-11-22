<?php

declare(strict_types=1);

namespace Cms\Core\Http\Middleware;

use App\Models\User;
use Closure;
use Cms\Core\Services\Security\WebMfaSessionService;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class EnforceWebMfa
{
    private const START_FAILURE_MESSAGE = 'Không thể bắt đầu xác thực đăng nhập. Vui lòng thử lại sau.';

    public function __construct(
        private readonly WebMfaSessionService $mfa,
    ) {}

    public function handle(Request $request, Closure $next): mixed
    {
        $user = $request->user('web');

        if (! $user instanceof User || $this->isExempt($request)) {
            return $next($request);
        }

        try {
            $pending = $this->mfa->start($user, $request);
        } catch (ValidationException $e) {
            $message = collect($e->errors())->flatten()->first() ?: __('mgs.auth.failed');

            return $this->logout($request, (string) $message);
        } catch (\Throwable $e) {
            report($e);

            return $this->logout($request, self::START_FAILURE_MESSAGE);
        }

        if (! $pending) {
            return $next($request);
        }

        $this->mfa->rememberIntendedPath($request);

        if ($request->expectsJson()) {
            return response()->json([
                'message' => 'Cần hoàn tất xác thực đăng nhập.',
            ], Response::HTTP_PRECONDITION_REQUIRED);
        }

        return redirect()->route('core.web-mfa.show');
    }

    private function isExempt(Request $request): bool
    {
        return $request->routeIs('core.web-mfa.*')
            || $request->routeIs('core.passkey.*')
            || $request->is('auth/sign-out')
            || $request->is('auth/logout');
    }

    private function logout(Request $request, ?string $message = null): mixed
    {
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        $errorMessage = $message ?: __('mgs.auth.failed');

        if ($request->expectsJson()) {
            return response()->json([
                'message' => $errorMessage,
            ], Response::HTTP_UNAUTHORIZED);
        }

        return redirect()->to(url('/auth/sign-in'))
            ->withErrors(['email' => $errorMessage]);
    }
}
