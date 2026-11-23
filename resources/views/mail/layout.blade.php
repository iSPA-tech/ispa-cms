{{-- Legacy app mail views (@extends('mail.layout')) inside the shared branded frame. Child views yield table rows. --}}
<x-email.layout :title="$subject ?? null" :brand="$brand ?? null" :live-url="(isset($uuid) && ! isset($created_at) && \Illuminate\Support\Facades\Route::has('mail-live')) ? route('mail-live', ['uid' => $uuid]) : null">
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0" style="width:100%;">
        @yield('content')
    </table>
    <x-slot:footer>{{ __('mgs.mail.footer-sent-at', ['time' => isset($created_at) ? $created_at : now()->setTimezone(\App\Support\Mail\MailBrand::timezone())->format('Y-m-d H:i:s')]) }}</x-slot:footer>
</x-email.layout>
