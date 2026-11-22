@if (isset($fields, $fields->items))
    @if (isset($review) && $review->get('totalRating') > 0)
        <div class="pt-10 pb-4 px-4 md:px-0 lg:max-w-7xl lg:grid lg:grid-cols-12 lg:gap-x-8">
            <div class="mt-16 lg:mt-0 lg:col-span-6 pb-4">
                <h3 class="font-semibold text-xl mb-4">@lang('web.a.reviews')</h3>
                <p class="text-gray-400 text-sm">
                    @lang('qr.scan.cmt_desc')
                    @lang('web.t.review')
                </p>
                <div class="text-right">
                    <button @click="toggleCommentModal()" type="button"
                            class="inline-flex items-center space-x-2 px-2.5 py-1.5 border border-transparent text-xs font-medium rounded-sm shadow-xs text-white bg-main-500 hover:bg-main-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-main-500">
                        <span><i class="fa fa-edit"></i></span>
                        <span>@lang('web.b.vote')</span>
                    </button>
                </div>
            </div>

            <div class="lg:col-span-6 flex items-center text-xs">
                <div class="flex flex-col items-center">
                    <h3 class="text-center">
                        <span class="text-2xl font-semibold">{{ $review->get('averageRating') }}</span><span>/5</span>
                    </h3>
                    <div>
                        <div class="flex items-center">
                            @for ($i = 1; $i < 6; $i++)
                                <svg
                                    class="shrink-0 h-5 w-5 {{ $review->get('averageRating') >= ($i - 0.5) ? 'text-yellow-400' : 'text-gray-300' }}"
                                    xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                                    aria-hidden="true">
                                    <path
                                        d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                </svg>
                            @endfor
                        </div>
                        <p class="sr-only">{{ $review->get('averageRating') }} out of 5 stars</p>
                    </div>
                    <p class="ml-2 text-xs text-gray-900">{{ $review->get('totalRating') }} @lang('web.a.reviews')</p>
                </div>

                <div class="flex-1 pl-4">
                    <dl class="space-y-1">
                        @for($i = 5; $i > 0; $i--)
                            <div class="flex items-center text-xs">
                                <dt class="flex-1 flex items-center">
                                    <p class="w-3 font-medium text-gray-900">
                                        {{ $i }}
                                        <span class="sr-only"> @lang('web.star') @lang('web.a.reviews')</span>
                                    </p>
                                    <div aria-hidden="true" class="ml-1 flex-1 flex items-center">
                                        <svg class="shrink-0 h-5 w-5 text-yellow-400"
                                             xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor"
                                             aria-hidden="true">
                                            <path
                                                d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                        </svg>

                                        <div class="ml-3 relative flex-1">
                                            <div class="h-3 bg-gray-100 border border-gray-200 rounded-full"></div>
                                            <div
                                                style="width: calc({{ $review->get('total' . $i . 'Star') }} / {{ $review->get('totalRating') }} * 100%)"
                                                class="absolute inset-y-0 bg-yellow-400 border border-yellow-400 rounded-full"></div>
                                        </div>
                                    </div>
                                </dt>
                                <dd class="ml-3 w-14 text-right tabular-nums text-xs text-gray-900 whitespace-nowrap">{{ number_format($review->get('total'. $i . 'Star') * 100 / $review->get('totalRating'), 2) }}
                                    %
                                </dd>
                            </div>
                        @endfor
                    </dl>
                </div>
            </div>
        </div>

        @if (isset($comments) && count($comments) > 0)
            <h3 class="sr-only">Recent reviews</h3>
            <div class="mt-0 pt-0">
                <div class="divide-y divide-gray-200">
                    @foreach($comments as $key => $comment)
                        @if (!empty($comment) && !empty($comment->contact))
                            <div class="py-3">
                                <div class="flex items-center">
              <span class="h-12 w-12 justify-center flex items-center rounded-full text-gray-400 text-2xl">
                <i class="fa fa-user-circle"></i>
              </span>
                                    <div>
                                        <h4 class="text-sm font-bold text-gray-900">
                                            <span>{{ $comment->contact->name ?? $comment->name ?? 'Unknown' }}</span>
                                            <span
                                                class="text-xs font-normal text-gray-400 pl-4">{{ $comment->created_at }}</span>
                                        </h4>
                                        @if (isset($comment->vote) && $comment->vote > 0)
                                            <div class="mt-1 flex items-center">
                                                @for($i = 1; $i < 6; $i++)
                                                    <svg
                                                        class="{{ $i <= $comment->vote ? 'text-yellow-400' : 'text-gray-400' }} h-5 w-5 shrink-0"
                                                        xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20"
                                                        fill="currentColor" aria-hidden="true">
                                                        <path
                                                            d="M9.049 2.927c.3-.921 1.603-.921 1.902 0l1.07 3.292a1 1 0 00.95.69h3.462c.969 0 1.371 1.24.588 1.81l-2.8 2.034a1 1 0 00-.364 1.118l1.07 3.292c.3.921-.755 1.688-1.54 1.118l-2.8-2.034a1 1 0 00-1.175 0l-2.8 2.034c-.784.57-1.838-.197-1.539-1.118l1.07-3.292a1 1 0 00-.364-1.118L2.98 8.72c-.783-.57-.38-1.81.588-1.81h3.461a1 1 0 00.951-.69l1.07-3.292z" />
                                                    </svg>
                                                @endfor
                                            </div>
                                        @endif
                                        <p class="sr-only">{{ $comment->vote }} out of 5 stars</p>
                                    </div>
                                </div>

                                <div class="text-sm italic text-gray-600">{{ $comment->content }}</div>
                                @if ($comment->images->count() > 0)
                                    <div class="py-3">
                                        <img src="{{ $comment->images[0]->url }}" alt=" "
                                             class="p-3 rounded-lg shadow-sm max-w-[200px]">
                                    </div>
                                @endif
                            </div>
                        @endif
                    @endforeach
                </div>
            </div>
        @endif
    @else
        <div class="block bg-gray-50 text-sm font-medium text-gray-500 text-center px-4 py-6 rounded-lg">
            @if (str($type)->contains('Place') || str($type)->contains('Item'))
                <p>@lang('qr.scan.cmt_desc')</p>
            @endif
            <p>@lang('mgs.no.reviews')</p>
            <p>@lang('web.e.no-reviews', ['name' => get_trans_from_model($type)])</p>
            <div class="text-center pt-4">
                <button @click="toggleCommentModal()" type="button"
                        class="inline-flex items-center space-x-2 px-2.5 py-1.5 border border-transparent text-xs font-medium rounded-sm shadow-xs text-white bg-main-500 hover:bg-main-700 focus:outline-hidden focus:ring-2 focus:ring-offset-2 focus:ring-main-500">
                    <span><i class="fa fa-edit"></i></span>
                    <span>@lang('web.b.vote')</span>
                </button>
            </div>
        </div>
    @endif

    @section('modal')
        <div x-show="commentModalState" x-cloak id="comment-modal" x-data="form_comment"
             x-transition:enter="ease-out duration-300"
             x-transition:enter-start="opacity-0"
             x-transition:enter-end="opacity-100"
             x-transition:leave="ease-in duration-200"
             x-transition:leave-start="opacity-100"
             x-transition:leave-end="opacity-0"
             class="fixed z-999 inset-0 overflow-y-auto transition-all" aria-labelledby="modal-title" role="dialog"
             aria-modal="true">
            <div class="flex items-start min-h-screen pt-4 px-4 pb-20 text-center sm:block sm:p-0">
                <div @click="toggleCommentModal()" class="fixed inset-0 bg-black/40 transition-opacity z-[-1]"
                     aria-hidden="true"></div>

                <span class="hidden sm:inline-block sm:align-middle sm:h-screen" aria-hidden="true">&#8203;</span>

                <div x-show="commentModalState"
                     x-transition:enter="ease-out duration-300"
                     x-transition:enter-start="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     x-transition:enter-end="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave="ease-in duration-200"
                     x-transition:leave-start="opacity-100 translate-y-0 sm:scale-100"
                     x-transition:leave-end="opacity-0 translate-y-4 sm:translate-y-0 sm:scale-95"
                     class="inline-block align-top bg-white rounded-lg px-4 pt-5 pb-4 text-left overflow-hidden shadow-xl transform transition-all sm:my-8 sm:max-w-2xl w-full sm:p-6">
                    <div>
                        <div class="flex items-center text-gray-600">
                            <div class="flex-1">
                                @lang('nav.a.comments')
                            </div>

                            <span @click="toggleCommentModal()" role="button">
              <svg xmlns="http://www.w3.org/2000/svg" class="h-8 w-8" viewBox="0 0 20 20" fill="currentColor">
                <path fill-rule="evenodd"
                      d="M10 18a8 8 0 100-16 8 8 0 000 16zM8.707 7.293a1 1 0 00-1.414 1.414L8.586 10l-1.293 1.293a1 1 0 101.414 1.414L10 11.414l1.293 1.293a1 1 0 001.414-1.414L11.414 10l1.293-1.293a1 1 0 00-1.414-1.414L10 8.586 8.707 7.293z"
                      clip-rule="evenodd" />
              </svg>
            </span>
                        </div>
                        <div class="mt-3 sm:mt-5">
                            @if (!empty($fields->items))
                                <form action="#" method="POST" class="mt-4 space-y-4">
                                    <div class="block mt-5">
                                        <x-alert x-id="alert-error" x-show="error" type="warning"
                                                 icon="fa fa-exclamation-triangle"></x-alert>
                                        <x-alert x-id="alert-success" x-show="success" type="success"
                                                 icon="fa fa-check-circle"></x-alert>
                                    </div>

                                    @foreach($fields->items as $key => $item)
                                        <div>
                                            @if ($item->type !== 'textarea')
                                                @if ($item->type === 'radio')
                                                    <fieldset id="rating-star"
                                                              class="flex items-center mx-auto justify-center">
                                                        @for($i = 1; $i < 6; $i++)
                                                            <input x-model="form.{{ $item->field_name }}" class="hidden"
                                                                   type="radio" name="{{ $item->field_name }}"
                                                                   value="{{$i}}" id="rate-{{$i}}">
                                                            <label id="lb-rate-{{ $i }}" @click="checkStar({{ $i }})"
                                                                   for="rate-{{ $i }}"
                                                                   class="cursor-pointer text-gray-400 hover:text-main-500 text-xl mr-2">
                                                                <i class="fa fa-star"></i>
                                                            </label>
                                                        @endfor
                                                    </fieldset>
                                                @elseif($item->type === 'image')
                                                    <div>
                                                        <div class="mb-2 flex items-start">
                                                            <template x-if="imageUrl">
                                                                <img :src="imageUrl"
                                                                     class="object-cover rounded-sm border border-gray-200 w-12 h-12 mr-4"
                                                                >
                                                            </template>

                                                            <input id="{{ $item->field_name }}" type="file"
                                                                   name="{{ $item->field_name }}"
                                                                   accept="image/*"
                                                                   @change="fileChosen"
                                                                   placeholder="@lang('t.select')">

                                                        </div>
                                                    </div>
                                                @else
                                                    <label for="{{ $item->field_name }}"
                                                           class="block font-medium text-gray-700">@lang($item->name_trans_key)</label>
                                                    <div class="mt-1">
                                                        <input type="{{ $item->type }}" name="{{ $item->field_name }}"
                                                               id="{{ $item->field_name }}"
                                                               placeholder="@lang($item->phd_trans_key)"
                                                               autocomplete="given-name"
                                                               x-model="form.{{ $item->field_name }}"
                                                               @blur="activeClass('{{ $item->field_name }}', !validator.isEmpty(form.{{ $item->field_name }}))"
                                                               class="block w-full shadow-xs py-3 px-4 placeholder-gray-500 focus:ring-gray-500 focus:border-gray-500 border-gray-300 rounded-md">
                                                    </div>
                                                @endif
                                            @else
                                                <div class="flex justify-between">
                                                    <label for="{{ $item->field_name }}"
                                                           class="block font-medium text-gray-700">@lang($item->name_trans_key)</label>
                                                    <span id="{{ $item->field_name }}-form"
                                                          class="text-sm text-gray-500">@lang('web.form.max-500')</span>
                                                </div>
                                                <div class="mt-1">
                                                <textarea id="{{ $item->field_name }}" name="{{ $item->field_name }}"
                                                          placeholder="@lang($item->phd_trans_key)"
                                                          aria-describedby="{{ $item->field_name }}-form" rows="4"
                                                          x-model="form.{{ $item->field_name }}"
                                                          @blur="activeClass('{{ $item->field_name }}', !validator.isEmpty(form.{{ $item->field_name }}))"
                                                          class="block w-full shadow-xs py-3 px-4 placeholder-gray-500 focus:ring-gray-500 focus:border-gray-500 border-gray-300 rounded-md"></textarea>
                                                </div>
                                            @endif
                                        </div>
                                    @endforeach

                                    <div class="text-right sm:col-span-2">
                                        <button :disabled="loading" @click="submitForm" type="button"
                                                class="inline-flex space-x-2 justify-center py-2 px-6 border border-transparent shadow-xs font-medium rounded-md text-white bg-main-500 hover:bg-main-700 focus:outline-hidden focus:ring-1 focus:ring-offset-1 focus:ring-blue-500 disabled:opacity-50">
                                            <span><i class="fa fa-paper-plane"></i></span>
                                            <span>@lang('nav.b.submit')</span>
                                        </button>
                                    </div>
                                </form>
                            @endif
                        </div>
                    </div>
                </div>
            </div>
        </div>

            <?php
            $activeClass = [];
            $conditions = [];

            if (isset($fields)) {
                foreach ($fields->items as $key => $item) {
                    if ($item->type !== 'radio') {
                        if ($item->type === 'email') {
                            $validationText = 'validator.isEmail';
                            $conditions[] = "validator.isEmail(f.$item->field_name) && !validator.isEmpty(f.$item->field_name)";
                        } else {
                            $validationText = strpos($item->field_name, 'phone') > -1
                                ? 'validator.isMobilePhone'
                                : '!validator.isEmpty';
                            if (strpos($item->field_name, 'phone') > -1) {
                                $conditions[] = "validator.isMobilePhone(f.$item->field_name) && !validator.isEmpty(f.$item->field_name)";
                            } else if ($item->field_name !== 'images') {
                                $conditions[] = "!validator.isEmpty(f.$item->field_name)";
                            }
                        }
                        if ($item->field_name !== 'images') {
                            $activeClass[] = "this.activeClass('" . $item->field_name . "', " . $validationText . "(f." . $item->field_name . "))";
                        }
                    }
                }
            }
            ?>
        @push('script')
            @if (isset($fields))
                <script
                    src="https://www.google.com/recaptcha/enterprise.js?render=6LdkCH4nAAAAAOqM8nh_nnWyhEJpY0Cqi84KhqbD"></script>

                <script>
                    function onClick(e) {
                        e.preventDefault()
                        grecaptcha.enterprise.ready(async () => {
                            const token = await grecaptcha.enterprise.execute('6LdkCH4nAAAAAOqM8nh_nnWyhEJpY0Cqi84KhqbD', { action: 'LOGIN' })
                        })
                    }
                </script>
                @php
                    $condition = '';
                    $list = '';
                    $stringField = '';
                    foreach($fields->items as $key => $item) {
                        $comma = $key < $fields->items->count() ? ', ' : "";
                        if ($item->type !== 'radio') {
                            $stringField .= "'$item->field_name': ''" . $comma;
                            $list .= "'$item->field_name'" . $comma;
                        }
                    }
                @endphp
                <script>
                    function form_comment() {
                        return {
                            modal: false, error: false, success: false, loading: false,
                            imageUrl: '',
                            form: {
                                {!! $stringField !!} id: '{{ $fields->form_id }}',
                                type: '{{ str_replace('App\Models\\', '', $type) }}',
                                targetId: {{ $id }},
                                star: 0,
                            },
                            message: '@lang('mgs.form.required')',
                            submitForm() {
                                const f = this.form
                                {!! implode(';', $activeClass) !!}

                                if({!! implode(' && ', $conditions) !!})
                                {
                                    const v = this
                                    v.error = false
                                    v.loading = true
                                    const formData = new FormData()
                                    Object.keys(f).forEach(key => formData.append(key, f[key]))

                                    const opts = {
                                        method: 'POST',
                                        body: formData,
                                        credentials: 'same-origin',
                                    }
                                    fetch('{{ route('ajax.comment-request') }}', opts)
                                        .then(function(res) {
                                            return res.json()
                                        })
                                        .then(function(body) {
                                            v.message = body.message || ''
                                            if (body.status === 'success') {
                                                v.error = false
                                                v.resetForm()
                                                v.success = true
                                                v.checkStar(0)
                                                //  Hide alert
                                                setTimeout(() => {
                                                    v.success = false
                                                }, 5000)
                                            } else {
                                                v.error = true
                                            }
                                        })
                                        .catch((err) => {
                                            console.log(err)
                                        })
                                        .finally(() => {
                                            v.loading = false

                                        })
                                }
                            else
                                {
                                    this.error = true
                                    this.message = '@lang('mgs.form.required')'
                                }
                            },
                            activeClass(id = null, status = false) {
                                if (id) {
                                    const element = document.getElementById(id)
                                    if (status === true) {
                                        element.classList.add('border-green-500')
                                        element.classList.remove('border-red-300')
                                    } else {
                                        element.classList.add('border-red-300')
                                        element.classList.remove('border-green-500')
                                    }
                                }
                            },
                            resetForm() {
                                this.form = {
                                    {!!  $stringField !!}
                                    id: '{{ $fields->form_id }}',
                                    type: '{{ str_replace('App\Models\\', '', $type) }}',
                                    targetId: {{ $id }},
                                    star: 0,
                                }
                                this.imageUrl = ''

                                const list = [{!!  $list !!}]
                                list.forEach(id => {
                                    const element = document.getElementById(id)
                                    element.classList.remove('border-green-500')
                                    element.classList.remove('border-red-300')
                                })
                            },
                            fileChosen(event) {
                                this.fileToDataUrl(event, src => this.imageUrl = src)
                            },

                            fileToDataUrl(event, callback) {
                                if (!event.target.files.length) return

                                let file = event.target.files[0],
                                    reader = new FileReader()

                                reader.readAsDataURL(file)
                                reader.onload = e => callback(e.target.result)
                                this.form.images = file
                            },
                        }
                    }
                </script>
            @endif
        @endpush
    @endsection
@endif
