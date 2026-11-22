{{--
    The link is an arrow and nothing else, so its name has to come from
    aria-label — a screen reader announced it as just "link". The svg is
    hidden from the accessibility tree for the same reason.

    The label goes through Lang::has rather than __() alone: a missing key
    makes __() return the key itself, and a live site once shipped a release whose
    lang pack lacked it, so the control announced itself as
    "web.btn.back_to_top". A raw key is worse than no translation.
--}}
@php
    $backToTopLabel = \Illuminate\Support\Facades\Lang::has('web.btn.back_to_top')
        ? __('web.btn.back_to_top')
        : 'Back to top';
@endphp
<div {{ $attributes->class('rounded-sm w-10 h-10 lg:w-12 lg:h-12 bg-white/80 border fixed bottom-24 right-6') }}>
    <a href="#" class="w-full h-full flex items-center place-content-center"
       aria-label="{{ $backToTopLabel }}" title="{{ $backToTopLabel }}">
        <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor"
             class="size-6" aria-hidden="true" focusable="false">
            <path stroke-linecap="round" stroke-linejoin="round" d="M4.5 10.5 12 3m0 0 7.5 7.5M12 3v18" />
        </svg>
    </a>
</div>
