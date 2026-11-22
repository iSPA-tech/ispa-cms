<?php

declare(strict_types=1);

namespace Cms\Core\Http\Controllers\Web;

use App\Models\User;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

final class EmailVerificationController
{
    public function verify(Request $request, string $id, string $hash): RedirectResponse
    {
        $user = User::query()->findOrFail($id);

        abort_unless(hash_equals(sha1($user->getEmailForVerification()), $hash), 403);

        if (! $user->hasVerifiedEmail()) {
            $user->markEmailAsVerified();
            event(new Verified($user));
        }

        return redirect()->to(url('/auth/sign-in'))
            ->with('success', 'Địa chỉ email đã được xác minh. Bạn có thể đăng nhập.');
    }
}
