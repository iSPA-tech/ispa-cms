@php
    $currentMethod = (string) ($challenge['current_method'] ?? '');
    $isRecovery = request()->input('method') === 'recovery_code';
    $cancelKey = $isRecovery
        ? 'text.mfa.cancel-recovery-access'
        : match ($currentMethod) {
            \Cms\Core\Services\Security\AdminMfaService::METHOD_EMAIL_CODE => 'text.mfa.cancel-email-access',
            \Cms\Core\Services\Security\AdminMfaService::METHOD_TOTP => 'text.mfa.cancel-authenticator-access',
            default => 'text.mfa.back-to-sign-in',
        };
@endphp

<!doctype html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ __('Xác thực đăng nhập') }}</title>
    <style>
        body { align-items: center; background: #f8fafc; color: #172033; display: flex; font-family: system-ui, sans-serif; justify-content: center; margin: 0; min-height: 100vh; }
        main { background: #fff; border: 1px solid #e2e8f0; border-radius: 16px; box-shadow: 0 12px 32px rgb(15 23 42 / 8%); max-width: 430px; padding: 32px; width: calc(100% - 48px); }
        h1 { font-size: 1.5rem; margin: 0 0 8px; } p { color: #64748b; line-height: 1.55; } label { display: block; font-weight: 600; margin: 24px 0 8px; }
        input { border: 1px solid #94a3b8; border-radius: 8px; box-sizing: border-box; font-size: 1.1rem; padding: 12px; width: 100%; }
        button { background: #1677ff; border: 0; border-radius: 8px; color: #fff; cursor: pointer; font-size: 1rem; font-weight: 700; margin-top: 16px; padding: 12px 16px; width: 100%; }
        .muted, .error, .success { font-size: .9rem; margin-top: 14px; } .error { color: #b91c1c; } .success { color: #047857; }
        .secondary { background: transparent; color: #1665c1; font-weight: 600; margin-top: 8px; padding: 4px; } .secondary-form { margin: 0; }
        .cancel-section { border-top: 1px solid #e2e8f0; margin-top: 24px; padding-top: 18px; }
        .cancel-note { color: #64748b; font-size: .85rem; margin: 0; text-align: center; }
        .cancel { background: transparent; border: 1px solid #cbd5e1; color: #475569; font-weight: 600; margin-top: 10px; }
        .cancel:hover { background: #f8fafc; border-color: #94a3b8; color: #0f172a; }
    </style>
</head>
<body>
<main>
    <h1>Xác thực đăng nhập</h1>
    @if ($isRecovery)
        <p>Nhập một mã khôi phục chưa sử dụng từ khi bạn thiết lập ứng dụng xác thực.</p>
    @elseif ($currentMethod === \Cms\Core\Services\Security\AdminMfaService::METHOD_TOTP)
        <p>Mở ứng dụng xác thực và nhập mã gồm 6 chữ số.</p>
    @else
        <p>Mã xác thực đã được gửi đến <strong>{{ $challenge['email'] }}</strong>.</p>
    @endif

    @if ($errors->any())
        <p class="error">{{ $errors->first() }}</p>
    @endif
    @if (session('success'))
        <p class="success">{{ session('success') }}</p>
    @endif

    <form method="post" action="{{ route('core.web-mfa.verify') }}">
        @csrf
        @if ($isRecovery)
            <input type="hidden" name="method" value="recovery_code">
        @endif
        <label for="code">{{ $isRecovery ? 'Mã khôi phục' : 'Mã xác thực' }}</label>
        <input id="code" name="code" autocomplete="one-time-code" autofocus required>
        <button type="submit">Xác nhận</button>
    </form>

    @if (! $isRecovery && ! empty($challenge['can_resend']))
        <form class="secondary-form" method="post" action="{{ route('core.web-mfa.resend') }}">
            @csrf
            <button class="secondary" type="submit">Gửi lại mã</button>
        </form>
    @endif
    @if (! $isRecovery && ! empty($challenge['can_use_recovery_code']))
        <form class="secondary-form" method="get" action="{{ route('core.web-mfa.show') }}">
            <input type="hidden" name="method" value="recovery_code">
            <button class="secondary" type="submit">Dùng mã khôi phục</button>
        </form>
    @endif

    <section class="cancel-section" aria-label="{{ __('text.mfa.cancel-sign-in-note') }}">
        <p class="cancel-note">{{ __('text.mfa.cancel-sign-in-note') }}</p>
        <form method="post" action="{{ route('core.web-mfa.cancel') }}">
            @csrf
            <button class="cancel" type="submit">{{ __($cancelKey) }}</button>
        </form>
    </section>
</main>
</body>
</html>
