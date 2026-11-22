<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Web;

use Cms\Core\Events\AuditLogEvent;
use Cms\Core\Services\Security\AdminMfaService;
use Cms\Core\Services\Security\WebMfaSessionService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\View;
use RuntimeException;

final class WebMfaChallengeController
{
    public function show(
        Request $request,
        WebMfaSessionService $mfa,
        AdminMfaService $adminMfa,
    ): View|RedirectResponse
    {
        $user = $request->user('web');
        $challenge = $user instanceof \App\Models\User
            ? $mfa->pendingChallenge($request, $user)
            : null;

        if (! $challenge) {
            return $this->missingChallenge();
        }

        return view('core::auth.mfa', [
            'challenge' => $adminMfa->buildChallengePayload($challenge),
        ]);
    }

    public function verify(Request $request, WebMfaSessionService $mfa): RedirectResponse
    {
        $data = $request->validate([
            'code' => ['required', 'string', 'max:32'],
            'method' => ['nullable', 'in:recovery_code'],
        ]);

        try {
            $result = $mfa->verify(
                $request,
                $data['code'],
                $data['method'] ?? null,
            );
        } catch (RuntimeException $exception) {
            return back()->withErrors([$exception->getMessage()]);
        }

        if ($result['type'] === 'auth') {
            event(new AuditLogEvent(
                'sign-in-mfa-completed',
                [],
                \App\Models\User::class,
                auth()->id(),
                'fa fa-shield-alt',
                'text-green-600',
            ));

            return redirect()->to($mfa->consumeIntendedPath($request));
        }

        return redirect()->route('core.web-mfa.show');
    }

    public function resend(Request $request, WebMfaSessionService $mfa): RedirectResponse
    {
        try {
            $mfa->resend($request);
        } catch (RuntimeException $exception) {
            return back()->withErrors([$exception->getMessage()]);
        }

        return back()->with('success', 'Mã xác thực mới đã được gửi.');
    }

    public function cancel(Request $request, WebMfaSessionService $mfa): RedirectResponse
    {
        $mfa->cancel($request);
        Auth::guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->to('/');
    }

    private function missingChallenge(): RedirectResponse
    {
        return redirect()->to(url('/auth/sign-in'))
            ->withErrors([__('mgs.auth.challenge_not_found')]);
    }
}
