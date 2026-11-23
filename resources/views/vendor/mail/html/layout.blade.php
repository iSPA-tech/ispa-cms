{{-- Markdown mail (notifications) inside the shared branded frame; header and footer come from the frame. --}}
<x-email.layout>
{{ Illuminate\Mail\Markdown::parse($slot) }}

{{ $subcopy ?? '' }}
</x-email.layout>
