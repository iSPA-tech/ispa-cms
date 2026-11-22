@if (strlen(iSeo('contribute_contact')) > 0)
    @if (str_contains(iSeo('contribute_contact'), ','))
        @php
            $break = explode(',', iSeo('contribute_contact'));
        @endphp
        @if (is_array($break) && count($break) > 1)
            @foreach($break as $key => $email)
                <a href="mailto:{{ str($email)->trim() }}" title="Send an email to us" class="mr-2">{{ $email }}</a>
                @if ($key < count($break) - 1)
                    <span class="mr-2">|</span>
                @endif
            @endforeach
        @else
            <a href="mailto:{{ iSeo('contribute_contact') }}"
               title="Send an email to us">{{ iSeo('contribute_contact') }}</a>
        @endif
    @else
        <a href="mailto:{{ iSeo('contribute_contact') }}"
           title="Send an email to us">{{ iSeo('contribute_contact') }}</a>
    @endif
@endif
