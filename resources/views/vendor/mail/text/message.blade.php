<x-mail::layout>
    {{-- Header --}}
    <x-slot:header>
        <x-mail::header :url="config('app.url')">
            {{ \App\Support\Mail\MailBrand::name() }}
        </x-mail::header>
    </x-slot:header>

    {{-- Body --}}
    {{ $slot }}

    {{-- Subcopy --}}
    @isset($subcopy)
        <x-slot:subcopy>
            <x-mail::subcopy>
                {{ $subcopy }}
            </x-mail::subcopy>
        </x-slot:subcopy>
    @endisset

    {{-- Footer --}}
    <x-slot:footer>
        <x-mail::footer>
            {{ __('mgs.mail.footer-copyright', ['year' => now()->year, 'brand' => \App\Support\Mail\MailBrand::name()]) }}
        </x-mail::footer>
    </x-slot:footer>
</x-mail::layout>
