<?php

declare(strict_types=1);

namespace Cms\Core\Listeners;

use App\Models\User;
use Cms\Core\Services\Security\WebMfaSessionService;
use Illuminate\Auth\Events\Login;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

final class StartWebMfaChallenge
{
    private const START_FAILURE_MESSAGE = 'Không thể bắt đầu xác thực đăng nhập. Vui lòng thử lại sau.';

    public function __construct(
        private readonly WebMfaSessionService $mfa,
    ) {}

    public function handle(Login $event): void
    {
        if ($event->guard !== 'web' || ! $event->user instanceof User) {
            return;
        }

        $request = app(Request::class);

        if (! $request->hasSession()) {
            return;
        }

        try {
            $this->mfa->start($event->user, $request, $event->remember);
        } catch (ValidationException $exception) {
            Auth::guard($event->guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw $exception;
        } catch (\Throwable $exception) {
            report($exception);
            Auth::guard($event->guard)->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw ValidationException::withMessages([
                'email' => [self::START_FAILURE_MESSAGE],
                'username' => [self::START_FAILURE_MESSAGE],
            ]);
        }
    }
}
