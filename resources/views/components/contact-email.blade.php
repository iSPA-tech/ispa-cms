@if (strlen(iSeo('email_contact')) > 0)
    @if (str_contains(iSeo('email_contact'), ','))
        @php
            $break = explode(',', iSeo('email_contact'));
        @endphp
        @if (is_array($break) && count($break) > 1)
            @foreach($break as $key => $email)
                <a href="mailto:{{ str($email)->trim() }}" title="Send an email to us" class="mr-2">{{ $email }}</a>
                @if ($key < count($break) - 1)
                    <span class="mr-2">|</span>
                @endif
            @endforeach
        @else
            <a href="mailto:{{ iSeo('email_contact') }}" title="Send an email to us">{{ iSeo('email_contact') }}</a>
        @endif
    @else
        <a href="mailto:{{ iSeo('email_contact') }}" title="Send an email to us">{{ iSeo('email_contact') }}</a>
    @endif
@endif
