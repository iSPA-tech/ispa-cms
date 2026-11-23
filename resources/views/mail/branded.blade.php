{{-- A ready HTML fragment ($body) inside the shared branded frame; for mail built as an HTML string. --}}
<x-email.layout :title="$title ?? null">
{!! $body !!}
</x-email.layout>
