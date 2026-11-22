@if ($actionUrl)
    @include('components.form-generate-reviewed', ['actionUrl' => $actionUrl, 'fields' => $fields, 'form' => $form, 'submissionToken' => $submissionToken, 'isContactForm' => $isContactForm ?? false])
@elseif (isset($fields, $fields->id))
    @php $layoutColumns = \Cms\Core\Services\Forms\FormLayout::columns((array) $fields->custom); @endphp
    @include('components.form-layout-styles')
    <form id="form{{ str($form)->ucfirst() }}" x-data="form{{ str($form)->ucfirst() }}" @submit.prevent="submitForm"
          class="py-4 space-y-4 {{ $class ?? '' }}">
        <x-captcha :form-id="$fields->id" :form-name="$fields->name" />

        <div class="block mt-5">
            <x-alert x-id="alert-error" x-show="error" type="warning" icon="fa fa-exclamation-triangle"></x-alert>
            <x-alert x-id="alert-success" x-show="success" type="success" icon="fa fa-check-circle"></x-alert>
        </div>

        <div class="form-layout-grid form-layout-grid--legacy" style="{{ \Cms\Core\Services\Forms\FormLayout::style($layoutColumns, 'cols') }}">
        @foreach($fields->items as $key => $item)
            @php $itemCustom = is_string($item->custom) ? json_decode($item->custom, true) : (is_array($item->custom) ? $item->custom : []); @endphp
            <div class="form-layout-item" style="{{ \Cms\Core\Services\Forms\FormLayout::style(\Cms\Core\Services\Forms\FormLayout::spans((array) $itemCustom, $layoutColumns, (string) $item->type), 'span') }}">
                @if ($item->type !== 'textarea')
                    <label for="{{ $item->field_name }}" class="block font-medium text-gray-700">
                        @lang($item->name_trans_key)
                        @if (($itemCustom['required'] ?? false) === true)
                            <span class="text-red-500 font-semibold pl-2">*</span>
                        @endif
                    </label>
                    <div class="mt-1">
                        <input
                            {{ (($itemCustom['required'] ?? false) === true) ? 'required' : '' }} {!! $item->type === 'tel' ? '@input.prevent="setTheCharacters(\''.$item->field_name.'\')"' : '' !!} type="{{ $item->type }}"
                            name="{{ $item->field_name }}" id="{{ $item->field_name }}"
                            placeholder="@lang($item->phd_trans_key)" autocomplete="given-name"
                            x-model="form.{{ $item->field_name }}" @blur="{!! print_field_validate($item) !!}"
                            class="block w-full shadow-xs py-3 px-4 placeholder-gray-500 focus:ring-gray-500 focus:border-gray-500 border-gray-300 rounded-md">
                        <p class="text-xs pt-0.5 text-red-500" x-text="messages.{{ $item->field_name }}"></p>
                    </div>
                @else
                    <div class="flex justify-between">
                        <label for="{{ $item->field_name }}" class="block font-medium text-gray-700">
                            @lang($item->name_trans_key)
                            @if (($itemCustom['required'] ?? false) === true)
                                <span class="text-red-500 font-semibold pl-2">*</span>
                            @endif
                        </label>
                        <span id="{{ $item->field_name }}-form"
                              class="text-sm text-gray-500">@lang('web.form.max-500')</span>
                    </div>
                    <div class="mt-1">
                        <textarea
                            {{ (($itemCustom['required'] ?? false) === true) ? 'required' : '' }} id="{{ $item->field_name }}"
                            name="{{ $item->field_name }}" placeholder="@lang($item->phd_trans_key)"
                            aria-describedby="{{ $item->field_name }}-form" rows="4"
                            x-model="form.{{ $item->field_name }}" @blur="{!! print_field_validate($item) !!}"
                            class="block w-full shadow-xs py-3 px-4 placeholder-gray-500 focus:ring-gray-500 focus:border-gray-500 border-gray-300 rounded-md"></textarea>
                        <p class="text-xs pt-0.5 text-red-500" x-text="messages.{{ $item->field_name }}"></p>
                    </div>
                @endif
            </div>
        @endforeach
        </div>

        <div class="text-right sm:col-span-2">
            <button type="submit"
                    :disabled="loading || ((window.__captcha && window.__captcha.provider !== 'off' && !window.__captcha.siteKey) ? true : false)"
                    class="inline-flex space-x-2 justify-center py-3 px-12 border border-transparent shadow-xs font-medium rounded-md text-white bg-main-500 hover:bg-main-700 focus:outline-hidden focus:ring-1 focus:ring-offset-1 focus:ring-blue-500 disabled:opacity-50">
                <span><i class="fa fa-paper-plane"></i></span>
                <span>@lang('nav.b.submit')</span>
            </button>
        </div>
    </form>


    @push('script')
        <script>
                <?php
                $condition = []; $fieldName = []; $list = ''; $stringField = '';
                foreach ($fields->items as $key => $item) {
                    $custom = is_string($item->custom) ? json_decode($item->custom, true) : (is_array($item->custom) ? $item->custom : []);
                    $stringField .= "'$item->field_name': ''" . ($key < $fields->items->count() ? ', ' : "");
                    $list .= "'$item->field_name'" . ($key < $fields->items->count() ? ', ' : "");
                    $fieldName[$item->field_name] = '';
                }
                ?>
            function form{{ str($form)->ucfirst() }}() {
                return {
                    modal: false, error: false, success: false, loading: false,
                    captchaWidgetId: null, captchaToken: null, pendingSubmit: null,
                    form: {
                        {!! $stringField !!} id: '{{ $fields->form_id }}',
                    },
                    message: '@lang('mgs.form.required')',
                    messages: {!! json_encode($fieldName, JSON_THROW_ON_ERROR) !!},
                    init() {
                        const captcha = window.__captcha || { provider: 'off' }
                        if (captcha.provider === 'cloudflare') {
                            if (window.turnstile) {
                                this.renderCloudflare()
                            } else {
                                document.addEventListener('captcha-ready', () => this.renderCloudflare(), { once: true })
                            }
                        }
                    },
                    renderCloudflare() {
                        if (this.captchaWidgetId !== null) return
                        const captcha = window.__captcha
                        this.captchaWidgetId = turnstile.render('#captcha-widget-{{ $fields->id }}', {
                            sitekey: captcha.siteKey,
                            action: '{{ $form }}',
                            size: 'flexible',
                            callback: (token) => {
                                this.captchaToken = token
                                document.getElementById('recaptcha-token-{{ $fields->id }}').value = token
                                if (typeof this.pendingSubmit === 'function') {
                                    const cb = this.pendingSubmit
                                    this.pendingSubmit = null
                                    cb()
                                }
                            },
                        })
                    },
                    executeCaptcha() {
                        const captcha = window.__captcha || { provider: 'off' }
                        if (captcha.provider === 'cloudflare') {
                            if (this.captchaWidgetId === null) {
                                this.error = true
                                this.loading = false
                                return
                            }
                            this.captchaToken = null
                            this.pendingSubmit = () => this.processSubmission()
                            turnstile.reset(this.captchaWidgetId)
                        } else if (captcha.provider === 'google') {
                            grecaptcha.execute(captcha.siteKey, { action: '{{ $form }}' }).then((token) => {
                                this.captchaToken = token
                                document.getElementById('recaptcha-token-{{ $fields->id }}').value = token
                                this.processSubmission()
                            })
                        } else {
                            this.processSubmission()
                        }
                    },
                    submitForm() {
                        this.error = false
                        this.loading = true
                        this.executeCaptcha()
                    },
                    processSubmission() {
                        const f = this.form
                        const v = this
                            <?php
                            $condition = [];
                            foreach ($fields->items as $key => $item) {
                                $custom = is_string($item->custom) ? json_decode($item->custom, true) : (is_array($item->custom) ? $item->custom : []);
                                if (($custom['required'] ?? false) === true) {
                                    echo "
v.activeClass('$item->field_name', !validator.isEmpty(f.$item->field_name), '" . __('validation.required', ['attribute' => __($item->name_trans_key)]) . "');";
                                    $condition[] = "!validator.isEmpty(f.$item->field_name)";
                                }
                                if ($item->type === 'email') {
                                    echo "
v.activeClass('$item->field_name', validator.isEmail(f.$item->field_name), '" . __('validation.email', ['attribute' => __($item->name_trans_key)]) . "');";
                                    $condition[] = "validator.isEmail(f.$item->field_name)";
                                } else if (str_contains('phone', $item->field_name) || str_contains('mobile', $item->field_name)) {
                                    echo "

if (f.$item->field_name.length " . (($custom['required'] ?? false) === true ? '>=' : '>') . " 0) {
v.activeClass('$item->field_name', validator.isMobilePhone(f.$item->field_name), '" . __('validation.regex', ['attribute' => __($item->name_trans_key)]) . "');
} else {
v.activeClass('$item->field_name', validator.isEmpty(f.$item->field_name))
}";
                                    $condition[] = "((f.$item->field_name.length " . (($custom['required'] ?? false) === true ? '>=' : '>') . " 0 && validator.isMobilePhone(f.$item->field_name)) || f.$item->field_name.length === 0)";
                                }
                            }
                            ?>

                        if ({!!  implode(' && ', $condition) !!}) {
                            v.error = false
                            v.loading = true
                            const opts = {
                                method: 'POST',
                                headers: {
                                    'Content-Type': 'application/json',
                                    'X-CSRF-Token': "{{ csrf_token() }}",
                                },
                                body: JSON.stringify({ ...f, 'captcha-response': v.captchaToken }),
                                credentials: 'same-origin',
                            }

                            fetch('{{ route('ajax.contact-request') }}', opts).then(function(res) {
                                return res.json()
                            }).then(function(body) {
                                v.message = body.message
                                if (body?.status === 'success') {
                                    v.error = false
                                    v.resetForm()
                                    v.success = true

                                    setTimeout(() => {
                                        v.success = false
                                    }, 5000)
                                } else {
                                    v.error = true
                                }
                                v.loading = false
                            }).catch(() => {
                                v.loading = false
                            })
                        } else {
                            v.error = true
                            v.message = "{{ __('mgs.form.required') }}"
                            v.loading = false
                        }
                    },
                    activeClass(id = null, status = false, message = null) {
                        if (id) {
                            {{ 'const element = document.getElementById(id);' }}
                            if(status === true)
                            {
                                {!! 'element.classList.add("border-green-500"); element.classList.remove("border-red-300"); this.messages[id] = "";' !!}
                            }
                        else
                            {
                                {!! 'element.classList.add("border-red-300");element.classList.remove("border-green-500");' !!}
                                if(message && this.messages[id] === '')
                                {
                                    this.messages[id] = message @php echo ';'; @endphp
                                }
                            }
                        }
                    },
                    setTheCharacters(name) {
                        this.form[name] = this.form[name].replace(/[^0-9+.]/g, '').replace(/(\..*)\./g, '$1') @php echo ';'; @endphp
                    },
                    resetForm() {
                        this.form = {
                            {!!  $stringField !!} id: '{{ $fields->form_id }}',
                        }
                        {!! ';const list = ['.$list.'];' !!}
                        list.forEach(id => {
                            {{ 'const element = document.getElementById(id);' }}
                            element.classList.remove('border-green-500')
                            @php echo ';'; @endphp
                            element.classList.remove('border-red-300')@php echo ';'; @endphp
                        })
                    },
                }
            }
        </script>
    @endpush
@endif
