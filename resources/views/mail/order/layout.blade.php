{{-- Body layout of DB email templates (order mails, Journal). Only the content: the sender puts it inside the shared branded frame (mail.branded / <x-email.layout>), so the mail stays one document. --}}
@if (isset($content))
{!! $content !!}
@else
@yield('content')
@endif
