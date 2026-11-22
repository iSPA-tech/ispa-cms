@if (isset($fields, $fields->id) && $fields->status === \App\Enums\StatusEnum::PUBLISHED->value)
    @php
        $attributes = $attributes ?? new \Illuminate\View\ComponentAttributeBag();
        $initialValues = $initialValues ?? [];
        $existingFileNames = $existingFileNames ?? [];
        $reviewIssues = $reviewIssues ?? [];
        $initialOptions = $initialOptions ?? [];
        $previewOnly = $previewOnly ?? false;
        $isContactForm = $isContactForm ?? false;
        $isCorrection = $form === 'admission-correction';
        $supportedTypes = ['text', 'email', 'tel', 'date', 'select', 'radio', 'checkbox', 'textarea', 'file', 'document', 'image'];
        $layoutColumns = \Cms\Core\Services\Forms\FormLayout::columns((array) $fields->custom);
        $advancedItems = $fields->items->filter(fn ($item) => (bool) $item->status)->map(function ($item) use ($supportedTypes, $fields, $initialValues, $initialOptions, $layoutColumns) {
            $custom = is_array($item->custom) ? $item->custom : (json_decode($item->custom ?: '[]', true) ?: []);
            $presentation = app(\Cms\Core\Services\Forms\FormOptionResolver::class)->presentation($fields, $item, $initialValues, $initialOptions[$item->field_name] ?? []);
            $options = $presentation['options'];
            $type = (string) $item->type;
            $upload = in_array($type, ['file', 'document', 'image'], true)
                ? app(\Cms\Core\Services\Forms\DynamicFormInput::class)->fileSettings($type, $custom) : null;

            return [
                'name' => $item->field_name,
                'label' => (string) $item->name,
                'placeholder' => (string) ($custom['placeholder'] ?? ''),
                'description' => $item->description,
                'type' => $type,
                'layoutStyle' => \Cms\Core\Services\Forms\FormLayout::style(\Cms\Core\Services\Forms\FormLayout::spans((array) $custom, $layoutColumns, $type), 'span'),
                'source' => $presentation['source'],
                'required' => (bool) ($custom['required'] ?? false),
                'visibleWhen' => is_array($custom['visibleWhen'] ?? null) ? $custom['visibleWhen'] : null,
                'section' => is_string($custom['section'] ?? null) ? $custom['section'] : null,
                'upload' => $upload,
                'accept' => $upload ? implode(',', array_map(fn ($ext) => '.' . $ext, $upload['extensions'])) : null,
                'choiceLayout' => $custom['choiceLayout'] ?? 'auto',
                'previewRatio' => preg_match('/^[1-9][0-9]?\/[1-9][0-9]?$/', (string) ($custom['aspectRatio'] ?? ''))
                    ? (string) $custom['aspectRatio'] : null,
                'options' => collect($options)->map(fn ($option) => is_array($option)
                    ? ['value' => (string) ($option['value'] ?? ''), 'label' => (string) ($option['label'] ?? $option['value'] ?? '')]
                    : ['value' => (string) $option, 'label' => (string) $option]
                )->values()->all(),
            ];
        })->values();
        $componentId = 'reviewed-form-' . $fields->id . '-' . preg_replace('/[^A-Za-z0-9_-]/', '-', $form);
    @endphp

    @if ($advancedItems->contains(fn ($item) => !in_array($item['type'], $supportedTypes, true)))
        <p class="rounded-md border border-amber-300 bg-amber-50 p-4 text-amber-900" role="status">Biểu mẫu có trường chưa được hỗ trợ. Vui lòng liên hệ Văn phòng Học viện.</p>
    @else
    @include('components.form-layout-styles')
    <style>
        .reviewed-form{font-size:14px;color:#374151;line-height:1.6}.reviewed-form [x-cloak]{display:none!important}
        .reviewed-form .rf-steps{display:flex;gap:12px;padding:0;margin:0 0 16px;list-style:none;font-size:13px}.reviewed-form .rf-steps li{flex:1;border-bottom:2px solid #e5e7eb;padding:0 0 8px}.reviewed-form .rf-steps [aria-current=step]{color:var(--color-main-700,#126a95);border-color:currentColor;font-weight:600}
        .reviewed-form .rf-notice{background:#f8fafc;border:1px solid #e2e8f0;border-radius:8px;padding:10px 12px;font-size:13px;color:#475569;margin-bottom:20px}
        .reviewed-form .rf-field{border:0;padding:0;margin:0 0 20px;min-width:0}.reviewed-form .rf-field>label,.reviewed-form .rf-field>legend{font-weight:600;margin-bottom:6px;padding:0;line-height:1.5}
        .reviewed-form .form-layout-grid>.rf-field{margin:0}.reviewed-form .form-layout-grid>.rf-section{margin:8px 0 0}
        .reviewed-form .rf-section{font-size:18px;font-weight:600;color:#1f2937;border-bottom:1px solid #e2e8f0;padding-bottom:10px;margin:28px 0 16px}.reviewed-form .rf-section:first-of-type{margin-top:0}
        .reviewed-form input:not([type=radio]):not([type=checkbox]):not([type=file]):not([type=hidden]),.reviewed-form select,.reviewed-form textarea{width:100%;min-height:40px;border:1px solid #cbd5e1;border-radius:8px;padding:8px 12px;font:inherit;background:white;box-shadow:none}.reviewed-form input::placeholder,.reviewed-form textarea::placeholder{color:#64748b}
        .reviewed-form .rf-help{font-size:13px;line-height:1.6;color:#64748b;margin-top:6px}.reviewed-form .rf-choices{display:flex;flex-wrap:wrap;gap:10px}.reviewed-form .rf-choices.rf-stacked{flex-direction:column;align-items:flex-start}.reviewed-form .rf-choice{display:flex;align-items:center;gap:8px;min-height:40px;border:1px solid #cbd5e1;border-radius:8px;padding:6px 12px;cursor:pointer}.reviewed-form .rf-choice:has(input:checked){background:#f0f9ff;border-color:#0284c7}.reviewed-form input[type=radio],.reviewed-form input[type=checkbox]{width:16px;height:16px;margin:0;accent-color:#0284c7}
        .reviewed-form .rf-upload{border:1px dashed #cbd5e1;border-radius:8px;padding:12px;background:#f8fafc}.reviewed-form .rf-file-picker{position:relative;display:inline-flex;align-items:center;gap:8px;min-height:40px;padding:6px 12px;border:1px solid #94a3b8;border-radius:6px;background:white;font-weight:500;cursor:pointer}.reviewed-form .rf-file-picker input[type=file]{position:absolute;width:1px;height:1px;opacity:0;overflow:hidden}.reviewed-form .rf-file-picker:focus-within{outline:2px solid #0284c7;outline-offset:3px}.reviewed-form .rf-file-name{overflow-wrap:anywhere;font-size:13px;margin-top:8px}.reviewed-form .rf-clear{font-size:13px;color:#be123c;margin-top:6px;text-decoration:underline}.reviewed-form .rf-photo{width:96px;max-height:128px;object-fit:contain;background:white;border:1px solid #cbd5e1;border-radius:6px;margin-top:10px}
        .reviewed-form .rf-actions{display:flex;justify-content:flex-end;flex-wrap:wrap;gap:10px;margin-top:24px}.reviewed-form .rf-actions button{min-height:44px;padding:8px 20px}.reviewed-form .rf-summary{border-bottom:1px solid #e2e8f0;padding:10px 0}.reviewed-form .rf-summary dd{overflow-wrap:anywhere;white-space:pre-line;color:#475569;margin-top:4px}
        .reviewed-form :focus-visible{outline:2px solid #0284c7;outline-offset:3px}.reviewed-form [aria-invalid=true]{border-color:#be123c!important}
        @media(max-width:480px){.reviewed-form .rf-steps{gap:8px;font-size:13px}.reviewed-form .rf-actions{flex-direction:column-reverse}.reviewed-form .rf-actions button{width:100%}.reviewed-form .rf-section{font-size:16px}}
        @media(max-width:480px),(pointer:coarse){.reviewed-form input:not([type=radio]):not([type=checkbox]):not([type=file]):not([type=hidden]),.reviewed-form select,.reviewed-form textarea{font-size:16px;min-height:48px}.reviewed-form .rf-choice,.reviewed-form .rf-file-picker,.reviewed-form .rf-actions button{min-height:48px}}
        /* Searchable selects (Select2 over the native select; only when jQuery is present). */
        .reviewed-form .select2-container{width:100%!important;font:inherit}.reviewed-form .select2-container--default .select2-selection--single{min-height:40px;height:auto;border:1px solid #cbd5e1;border-radius:8px;background:white;display:flex;align-items:center;padding:4px 12px}
        .reviewed-form .select2-container--default .select2-selection--single .select2-selection__rendered{padding:0 20px 0 0;line-height:1.5;color:#0f172a}.reviewed-form .select2-container--default .select2-selection--single .select2-selection__placeholder{color:#64748b}.reviewed-form .select2-container--default .select2-selection--single .select2-selection__arrow{height:100%;right:8px}
        .reviewed-form .select2-container--default.select2-container--focus .select2-selection--single,.reviewed-form .select2-container--default.select2-container--open .select2-selection--single{outline:2px solid #0284c7;outline-offset:3px}
        .select2-container--default .select2-dropdown{border-color:#cbd5e1;border-radius:8px;font:inherit}.select2-container--default .select2-search--dropdown .select2-search__field{border-radius:6px;padding:6px 10px;font:inherit}.select2-container--default .select2-results__option--highlighted[aria-selected]{background:#0284c7}
        @media(max-width:480px),(pointer:coarse){.reviewed-form .select2-container--default .select2-selection--single{min-height:48px;font-size:16px}.select2-container--default .select2-search--dropdown .select2-search__field{font-size:16px}}
    </style>
    <form id="{{ $componentId }}" x-data="reviewedForm{{ $fields->id }}" x-ref="form" method="post" action="{{ $actionUrl }}"
          enctype="multipart/form-data" class="reviewed-form py-4 {{ $attributes->get('class') }}" @submit.prevent="review">
        @csrf
        <input type="hidden" name="form_id" value="{{ $fields->form_id }}">
        @if($isContactForm)<input type="hidden" name="id" value="{{ $fields->form_id }}">@endif
        @if ($submissionToken) <input type="hidden" name="submission_token" value="{{ $submissionToken }}"> @endif
        @unless($previewOnly)<div x-show="stage !== 'success'"><x-captcha :form-id="$fields->id" :form-name="$fields->name" /></div>@endunless

        <ol class="rf-steps" aria-label="Các bước gửi thông tin">
            <li :aria-current="stage === 'edit' ? 'step' : null">1. Điền thông tin</li>
            <li :aria-current="stage === 'review' ? 'step' : null">2. Xem lại</li>
            <li :aria-current="stage === 'success' ? 'step' : null">3. Hoàn tất</li>
        </ol>

        <div x-show="stage === 'edit'">
            <p class="rf-notice" role="status"><i class="fa fa-info-circle mr-2" aria-hidden="true"></i>{{ $previewOnly ? 'Đây là bản xem trước. Thông tin điền thử sẽ không được gửi.' : ($isCorrection ? 'Thay đổi của bạn chưa được gửi lại. Hãy xem lại rồi xác nhận gửi.' : 'Thông tin của bạn chưa được gửi. Hãy điền thông tin, xem lại rồi xác nhận gửi.') }} <span class="block">Các mục có dấu <span class="text-red-600">*</span> cần được điền đầy đủ.</span></p>
            <div x-show="message" x-text="message" class="rounded-md p-3" :class="success ? 'bg-green-50 text-green-800' : 'bg-red-50 text-red-800'" aria-live="polite"></div>

            <div class="form-layout-grid" style="{{ \Cms\Core\Services\Forms\FormLayout::style($layoutColumns, 'cols') }}">
            @foreach ($advancedItems as $item)
                @php
                    $name = $item['name'];
                    $inputId = $componentId . '-' . $name;
                    $describedBy = $inputId . '-error';
                @endphp
                @if ($item['section'])
                    <h2 x-show="isVisible(@js($item['visibleWhen']))" class="rf-section form-layout-section">{{ $item['section'] }}</h2>
                @endif
                <fieldset x-show="isVisible(@js($item['visibleWhen']))" :disabled="!isVisible(@js($item['visibleWhen']))" class="rf-field form-layout-item" style="{{ $item['layoutStyle'] }}">
                    @if (in_array($name, $reviewIssues, true))
                        <p class="rounded-md bg-amber-50 px-3 py-2 text-sm font-semibold text-amber-900" role="status">Văn phòng đề nghị kiểm tra lại mục này.</p>
                    @endif

                    @if (in_array($item['type'], ['radio', 'checkbox'], true))
                        <legend class="block font-medium text-gray-700">
                            {{ $item['label'] }} @if ($item['required']) <span class="text-red-600" aria-label="bắt buộc">*</span> @endif
                        </legend>
                        <div class="rf-choices {{ $item['choiceLayout'] === 'stacked' || ($item['choiceLayout'] === 'auto' && count($item['options']) > 3) ? 'rf-stacked' : '' }}">
                            @if ($item['source'])
                            <template x-for="(option, optionIndex) in optionsFor(@js($name))" :key="option.value">
                                <label class="rf-choice" :for="@js($inputId) + '-dynamic-' + optionIndex">
                                    <input :id="@js($inputId) + '-dynamic-' + optionIndex" type="{{ $item['type'] }}" name="{{ $item['type'] === 'checkbox' ? $name . '[]' : $name }}" :value="option.value"
                                           x-model="values[@js($name)]" :disabled="!!sourceStates[@js($name)]?.loading" @if($item['type'] === 'radio') @required($item['required']) @endif aria-describedby="{{ $describedBy }}">
                                    <span x-text="option.label"></span>
                                </label>
                            </template>
                            @else
                            @foreach ($item['options'] as $option)
                                <label class="rf-choice" for="{{ $inputId }}-{{ $loop->index }}">
                                    <input id="{{ $inputId }}-{{ $loop->index }}" type="{{ $item['type'] }}" name="{{ $item['type'] === 'checkbox' ? $name . '[]' : $name }}" value="{{ $option['value'] }}"
                                           x-model="values[@js($name)]" :aria-invalid="errors[@js($name)] ? 'true' : null" @if ($item['type'] === 'radio') @required($item['required']) @endif aria-describedby="{{ $item['description'] ? $inputId . '-help ' : '' }}{{ $describedBy }}">
                                    <span>{{ $option['label'] }}</span>
                                </label>
                            @endforeach
                            @endif
                        </div>
                    @else
                        <label for="{{ $inputId }}" class="block font-medium text-gray-700">
                            {{ $item['label'] }} @if ($item['required']) <span class="text-red-600" aria-label="bắt buộc">*</span> @endif
                        </label>

                        @if ($item['type'] === 'textarea')
                            <textarea id="{{ $inputId }}" name="{{ $name }}" rows="4" placeholder="{{ $item['placeholder'] }}"
                                      x-model="values[@js($name)]" @required($item['required']) aria-describedby="{{ $item['description'] ? $inputId . '-help ' : '' }}{{ $describedBy }}"
                                      class="block w-full"></textarea>
                        @elseif ($item['type'] === 'select')
                            <select id="{{ $inputId }}" name="{{ $name }}" x-model="values[@js($name)]" @required($item['required'])
                                    @if (!$item['source'] && count($item['options']) > 10) data-searchable @endif
                                    :disabled="!!sourceStates[@js($name)]?.loading" aria-describedby="{{ $item['description'] ? $inputId . '-help ' : '' }}{{ $describedBy }}" class="block w-full">
                                <option value="">{{ $item['placeholder'] ?: 'Vui lòng chọn' }}</option>
                                @if($item['source'])
                                <template x-for="option in optionsFor(@js($name))" :key="option.value">
                                    <option :value="option.value" x-text="option.label"></option>
                                </template>
                                @else
                                @foreach ($item['options'] as $option)
                                    <option value="{{ $option['value'] }}">{{ $option['label'] }}</option>
                                @endforeach
                                @endif
                            </select>
                        @elseif ($item['upload'])
                            <div class="rf-upload">
                                <label class="rf-file-picker" for="{{ $inputId }}">
                                    <i class="fa fa-upload" aria-hidden="true"></i><span x-text="files[@js($name)] ? 'Đổi tệp' : 'Chọn tệp'">Chọn tệp</span>
                                    <input id="{{ $inputId }}" name="{{ $name }}" type="file" accept="{{ $item['accept'] }}"
                                           @change="captureFile(@js($name), $event, @js($item['type']))" :aria-invalid="errors[@js($name)] ? 'true' : null"
                                           @required($item['required'] && !isset($existingFileNames[$name])) aria-label="Chọn tệp: {{ $item['label'] }}" aria-describedby="{{ $inputId }}-formats {{ $item['description'] ? $inputId . '-help ' : '' }}{{ $describedBy }}">
                                </label>
                                <p id="{{ $inputId }}-formats" class="rf-help">{{ strtoupper(implode(', ', $item['upload']['extensions'])) }} · Tối đa {{ $item['upload']['maxSizeMb'] }} MB mỗi tệp</p>
                                <p class="rf-file-name" aria-live="polite" x-text="files[@js($name)] ? files[@js($name)] + ' · ' + fileSizes[@js($name)] : 'Chưa chọn tệp mới'"></p>
                                <button type="button" class="rf-clear" x-show="files[@js($name)]" @click="clearFile(@js($name))">Bỏ tệp vừa chọn</button>
                                @if (isset($existingFileNames[$name]))<p class="rf-help">Tệp đã gửi: {{ $existingFileNames[$name] }}. Chỉ chọn tệp mới nếu muốn thay thế.</p>@endif
                                @if ($item['type'] === 'image')<img x-show="previews[@js($name)]" :src="previews[@js($name)]" alt="Ảnh vừa chọn" class="rf-photo">@endif
                            </div>
                        @else
                            <input id="{{ $inputId }}" name="{{ $name }}" type="{{ in_array($item['type'], ['image', 'document'], true) ? 'file' : $item['type'] }}"
                                   @if (in_array($item['type'], ['file', 'document', 'image'], true)) accept="{{ $item['accept'] }}" @endif
                                   @if (!in_array($item['type'], ['file', 'document', 'image'], true)) x-model="values[@js($name)]" placeholder="{{ $item['placeholder'] }}" @else x-on:change="captureFile(@js($name), $event, @js($item['type']))" @endif
                                   @required($item['required'] && !isset($existingFileNames[$name])) aria-describedby="{{ $item['description'] ? $inputId . '-help ' : '' }}{{ $describedBy }}"
                                   class="block w-full">
                            @if (isset($existingFileNames[$name]) && in_array($item['type'], ['file', 'document', 'image'], true))
                                <p class="text-sm text-gray-700">Tệp đã gửi: {{ $existingFileNames[$name] }}. Chọn tệp mới nếu cần thay thế.</p>
                            @endif
                            @if ($item['type'] === 'image')
                                <img x-show="previews[@js($name)]" :src="previews[@js($name)]" alt="Ảnh đã chọn để xem lại"
                                     @if ($item['previewRatio']) style="aspect-ratio: {{ str_replace('/', ' / ', $item['previewRatio']) }}" @endif
                                     class="mt-3 max-h-48 w-36 rounded border object-cover">
                            @endif
                        @endif
                    @endif
                    @if($item['source'])
                        <div class="mt-2" :aria-busy="sourceStates[@js($name)]?.loading ? 'true' : 'false'">
                            <p x-show="sourceStates[@js($name)]?.waiting" class="rf-help" role="status">Vui lòng chọn mục phía trên trước.</p>
                            <p x-show="sourceStates[@js($name)]?.loading" class="rf-help" role="status">Đang tải danh sách…</p>
                            <p x-show="sourceStates[@js($name)]?.error" x-text="sourceStates[@js($name)]?.error" class="rf-help text-red-700" role="alert"></p>
                            <button type="button" x-show="sourceStates[@js($name)]?.error" @click="loadSource(@js($name))" class="rf-clear">Thử lại</button>
                            <label for="{{ $inputId }}-search" class="sr-only">Tìm trong {{ $item['label'] }}</label>
                            <input id="{{ $inputId }}-search" type="search" placeholder="Tìm trong danh sách…" x-model="sourceSearch[@js($name)]"
                                   @input.debounce.300ms="loadSource(@js($name))" x-show="sourceStates[@js($name)]?.hasMore || sourceSearch[@js($name)]" :disabled="!!sourceStates[@js($name)]?.waiting">
                            <button type="button" x-show="sourceStates[@js($name)]?.hasMore" :disabled="!!sourceStates[@js($name)]?.loading" @click="loadSource(@js($name), true)" class="rf-clear">Tải thêm lựa chọn</button>
                            <p x-show="!sourceStates[@js($name)]?.waiting && !sourceStates[@js($name)]?.loading && !sourceStates[@js($name)]?.error && !optionsFor(@js($name)).length" class="rf-help" role="status">Không có lựa chọn phù hợp.</p>
                        </div>
                    @endif
                    @if ($item['description'])
                        <p id="{{ $inputId }}-help" class="rf-help">{{ $item['description'] }}</p>
                    @endif
                    <p id="{{ $describedBy }}" x-show="errors[@js($name)]" x-text="errors[@js($name)]" class="text-sm text-red-700" aria-live="polite"></p>
                </fieldset>
            @endforeach
            </div>

            <div class="rf-actions">
                <button type="{{ $previewOnly ? 'button' : 'submit' }}" @if($previewOnly) @click="review" @endif class="rounded-md bg-main-500 font-medium text-white focus-visible:outline focus-visible:outline-2 focus-visible:outline-offset-2">
                    <i class="fa fa-eye mr-2" aria-hidden="true"></i>
                    Xem lại trước khi gửi
                </button>
            </div>
        </div>

        <section x-show="stage === 'review'" x-cloak aria-labelledby="{{ $componentId }}-review-title" class="space-y-4">
            <h2 id="{{ $componentId }}-review-title" x-ref="reviewTitle" tabindex="-1" class="text-xl font-semibold">Xem lại thông tin trước khi gửi</h2>
            <p class="rf-notice" role="status"><i class="fa fa-info-circle mr-2" aria-hidden="true"></i>{{ $previewOnly ? 'Đây là bản xem trước. Không có hồ sơ nào được gửi.' : ($isCorrection ? 'Hồ sơ chưa được gửi lại. Kiểm tra thông tin bên dưới rồi bấm “Xác nhận gửi lại”.' : 'Hồ sơ chưa được gửi. Kiểm tra thông tin bên dưới rồi bấm “Xác nhận gửi”.') }}</p>
            <div class="space-y-3">
                <template x-for="item in visibleItems()" :key="item.name">
                    <div>
                        <h3 x-show="item.section" x-text="item.section" class="rf-section"></h3>
                        <dl class="rf-summary"><dt class="font-medium" x-text="item.label"></dt><dd x-text="displayValue(item)"></dd></dl>
                        <template x-if="item.type === 'image' && previews[item.name]">
                            <img :src="previews[item.name]" :style="item.previewRatio ? { aspectRatio: item.previewRatio } : {}"
                                 alt="Ảnh chân dung đã chọn" class="mt-2 max-h-48 w-36 rounded border object-cover">
                        </template>
                    </div>
                </template>
            </div>
            <div x-show="message" x-text="message" class="rounded-md bg-red-50 p-3 text-red-800" aria-live="assertive"></div>
            <div class="rf-actions">
                <button type="button" @click="stage = 'edit'" class="rounded-md border"><i class="fa fa-arrow-left mr-2" aria-hidden="true"></i>Chỉnh sửa</button>
                @unless($previewOnly)<button type="button" @click="confirm" :disabled="loading || captchaPending" class="rounded-md bg-main-500 font-medium text-white disabled:opacity-50"><i class="fa fa-paper-plane mr-2" aria-hidden="true"></i><span x-text="loading || captchaPending ? 'Đang gửi…' : @js($isCorrection ? 'Xác nhận gửi lại' : 'Xác nhận gửi')"></span></button>@endunless
            </div>
        </section>

        <section x-show="stage === 'success'" x-cloak class="rounded-lg border border-green-300 bg-green-50 p-6 text-green-900" role="status" aria-live="polite">
            <h2 class="text-xl font-semibold"><i class="fa fa-check-circle mr-2" aria-hidden="true"></i>{{ $isCorrection ? 'Đã gửi lại hồ sơ' : ($isContactForm ? 'Đã gửi thông tin thành công' : 'Đã gửi hồ sơ thành công') }}</h2>
            <p class="mt-2" x-text="message"></p>
            @unless($isContactForm)<p class="mt-2">{{ $isCorrection ? 'Văn phòng Học viện sẽ xét duyệt lại hồ sơ.' : 'Nếu cần chỉnh sửa thông tin, vui lòng liên hệ Văn phòng Học viện và cung cấp mã hồ sơ.' }}</p>@endunless
        </section>
    </form>

    @push('script')
        <script>
            // Loads Select2 once, only when the theme already ships jQuery; native selects stay the fallback.
            window.__reviewedFormSelect2 = window.__reviewedFormSelect2 || function (callback) {
                const $ = window.jQuery;
                if (!$ || !$.fn) return;
                if ($.fn.select2) return callback();
                const pending = window.__reviewedFormSelect2.pending = window.__reviewedFormSelect2.pending || [];
                pending.push(callback);
                if (pending.length > 1) return;
                const base = 'https://cdn.jsdelivr.net/npm/select2@4.0.13/dist/';
                const link = document.createElement('link'); link.rel = 'stylesheet'; link.href = base + 'css/select2.min.css'; document.head.appendChild(link);
                const script = document.createElement('script'); script.src = base + 'js/select2.min.js';
                script.onload = () => pending.splice(0).forEach(fn => fn());
                document.head.appendChild(script);
            };
            function reviewedForm{{ $fields->id }}() {
                return {
                    stage: 'edit', loading: false, success: false, message: '', values: {}, files: {}, fileSizes: {}, previews: {}, errors: {}, captchaWidgetId: null, captchaPending: false,
                    sourceOptions: {}, sourceStates: {}, sourceSearch: {}, sourceFilters: {},
                    previewOnly: @js($previewOnly),
                    initialValues: @js($initialValues), existingFiles: @js($existingFileNames),
                    items: @js($advancedItems),
                    init() {
                        this.items.forEach(item => { this.values[item.name] = this.initialValues[item.name] ?? (item.type === 'checkbox' ? [] : '') });
                        this.items.filter(item => item.source).forEach(item => {
                            this.sourceOptions[item.name] = item.options;
                            this.sourceSearch[item.name] = '';
                            this.sourceStates[item.name] = {loading: false, error: item.source.error, hasMore: item.source.hasMore, page: 1, sequence: 0};
                        });
                        this.syncSources(true);
                        this.$watch('values', () => { this.syncSources(); this.syncSearchable(); });
                        this.$nextTick(() => this.enhanceSelects());
                        const captcha = window.__captcha || { provider: 'off' };
                        if (!this.previewOnly && captcha.provider === 'cloudflare') {
                            if (window.turnstile) this.renderCaptcha();
                            else document.addEventListener('captcha-ready', () => this.renderCaptcha(), { once: true });
                        }
                    },
                    renderCaptcha() {
                        if (this.captchaWidgetId !== null) return;
                        const captcha = window.__captcha;
                        this.captchaWidgetId = turnstile.render('#captcha-widget-{{ $fields->id }}', {
                            sitekey: captcha.siteKey, action: @js($form), size: 'flexible',
                            execution: captcha.verificationProvider === 'hcaptcha' ? undefined : 'execute',
                            callback: (token) => {
                                this.$refs.captchaToken.value = token;
                                if (this.captchaPending && this.stage === 'review') {
                                    this.captchaPending = false;
                                    this.post();
                                }
                            },
                            'expired-callback': () => { this.captchaPending = false; this.$refs.captchaToken.value = ''; },
                            'error-callback': () => { this.captchaPending = false; this.message = 'Không thể xác minh biểu mẫu. Vui lòng thử lại.'; },
                        });
                    },
                    isVisible(condition) {
                        return !condition || String(this.values[condition.field] ?? '') === String(condition.value ?? '')
                    },
                    optionsFor(name) {
                        return this.sourceOptions[name] ?? this.items.find(item => item.name === name)?.options ?? [];
                    },
                    filtersFor(item) {
                        const filters = {...item.source.filters};
                        for (const [key, field] of Object.entries(item.source.dependsOn)) {
                            if (!String(this.values[field] ?? '').trim()) return null;
                            filters[key] = this.values[field];
                        }
                        return filters;
                    },
                    enhanceSelects() {
                        if (!this.$refs.form || !window.__reviewedFormSelect2) return;
                        window.__reviewedFormSelect2(() => {
                            const $ = window.jQuery;
                            this.$refs.form.querySelectorAll('select[data-searchable]:not(.select2-hidden-accessible)').forEach(select => {
                                $(select).select2({
                                    width: '100%', minimumResultsForSearch: 8,
                                    placeholder: select.options[0] ? select.options[0].text : '',
                                    language: { noResults: () => 'Không có kết quả phù hợp.', searching: () => 'Đang tìm…' },
                                }).on('select2:select', () => {
                                    // Select2 only fires jQuery events; Alpine's x-model needs the native one.
                                    select.dataset.searchableValue = select.value;
                                    select.dispatchEvent(new Event('change', { bubbles: true }));
                                }).on('select2:open', () => {
                                    // Select2 4.0 does not focus its search box under jQuery 3.6+; do it explicitly.
                                    window.setTimeout(() => document.querySelector('.select2-container--open .select2-search__field')?.focus(), 0);
                                });
                                select.dataset.searchableValue = select.value;
                            });
                        });
                    },
                    syncSearchable() {
                        const $ = window.jQuery;
                        if (!$ || !$.fn || !$.fn.select2 || !this.$refs.form) return;
                        this.$nextTick(() => this.$refs.form.querySelectorAll('select[data-searchable].select2-hidden-accessible').forEach(select => {
                            if (select.dataset.searchableValue === select.value) return;
                            select.dataset.searchableValue = select.value;
                            $(select).trigger('change.select2');
                        }));
                    },
                    syncSources(initial = false) {
                        this.items.filter(item => item.source).forEach(item => {
                            const fingerprint = JSON.stringify(this.filtersFor(item));
                            if (this.sourceFilters[item.name] === fingerprint) return;
                            this.sourceFilters[item.name] = fingerprint;
                            if (!initial) {
                                this.values[item.name] = item.type === 'checkbox' ? [] : '';
                                this.sourceSearch[item.name] = '';
                            }
                            this.loadSource(item.name);
                        });
                    },
                    async loadSource(name, more = false) {
                        const item = this.items.find(item => item.name === name);
                        const state = this.sourceStates[name];
                        const sequence = ++state.sequence;
                        const filters = this.filtersFor(item);
                        state.waiting = filters === null; state.error = ''; state.invalidValues = [];
                        if (state.waiting) { state.loading = false; this.sourceOptions[name] = []; return; }
                        state.loading = true;
                        const selected = Array.isArray(this.values[name]) ? this.values[name] : (this.values[name] ? [this.values[name]] : []);
                        const query = new URLSearchParams({q: this.sourceSearch[name] || '', page: more ? state.page + 1 : 1});
                        Object.entries(filters).forEach(([key, value]) => query.set(`filters[${key}]`, value));
                        selected.forEach(value => query.append('selected[]', value));
                        try {
                            const response = await fetch(item.source.url + '?' + query, {credentials: 'omit', headers: {Accept: 'application/json'}});
                            const body = await response.json();
                            if (!response.ok || body.status !== 'success') throw new Error('Không thể tải danh sách. Vui lòng thử lại.');
                            if (sequence !== state.sequence) return;
                            const data = body.data;
                            const options = [...(more ? this.sourceOptions[name] : []), ...data.options, ...data.selected];
                            item.options.filter(option => selected.includes(option.value)).forEach(option => options.push(option));
                            this.sourceOptions[name] = [...new Map(options.map(option => [option.value, option])).values()];
                            state.hasMore = data.hasMore; state.page = data.page;
                            state.invalidValues = selected.filter(value => !data.selected.some(option => option.value === value));
                            this.$nextTick(() => {
                                const select = this.$refs.form.querySelector(`select[name="${CSS.escape(name)}"]`);
                                if (select) select.value = String(this.values[name] ?? '');
                            });
                        } catch (error) {
                            if (sequence === state.sequence) state.error = error.message;
                        } finally { if (sequence === state.sequence) state.loading = false; }
                    },
                    captureFile(name, event, type) {
                        const file = event.target.files[0];
                        this.clearFile(name, false);
                        if (file) {
                            const rules = this.items.find(item => item.name === name).upload;
                            const extension = file.name.split('.').pop().toLowerCase();
                            if (file.size > rules.maxSizeMb * 1024 * 1024 || !rules.extensions.includes(extension)) {
                                event.target.value = '';
                                this.errors[name] = `Vui lòng chọn tệp ${rules.extensions.join(', ').toUpperCase()} không quá ${rules.maxSizeMb} MB.`;
                                return;
                            }
                        }
                        this.files[name] = file?.name || '';
                        this.fileSizes[name] = file ? (file.size < 1024 * 1024 ? Math.max(1, Math.ceil(file.size / 1024)) + ' KB' : (file.size / 1024 / 1024).toFixed(1) + ' MB') : '';
                        this.previews[name] = file && type === 'image' ? URL.createObjectURL(file) : '';
                    },
                    clearFile(name, resetInput = true) {
                        if (this.previews[name]) URL.revokeObjectURL(this.previews[name]);
                        this.files[name] = ''; this.fileSizes[name] = ''; this.previews[name] = ''; delete this.errors[name];
                        if (resetInput) this.$refs.form.querySelector(`input[type="file"][name="${CSS.escape(name)}"]`).value = '';
                    },
                    visibleItems() { return this.items.filter(item => this.isVisible(item.visibleWhen)) },
                    review() {
                        this.errors = {}; this.message = ''; this.success = false;
                        for (const item of this.visibleItems().filter(item => item.source)) {
                            const state = this.sourceStates[item.name];
                            if (state.loading || state.error) { this.message = state.error || 'Vui lòng chờ danh sách tải xong.'; return; }
                            const chosen = Array.isArray(this.values[item.name]) ? this.values[item.name] : [this.values[item.name]];
                            if (chosen.some(value => (state.invalidValues || []).includes(value))) this.errors[item.name] = 'Lựa chọn trước đây không còn phù hợp. Vui lòng chọn lại.';
                        }
                        if (Object.keys(this.errors).length) return;
                        if (!this.$refs.form.reportValidity()) return;
                        this.visibleItems().forEach(item => {
                            if (item.required && !this.files[item.name] && !this.existingFiles[item.name] && (Array.isArray(this.values[item.name]) ? !this.values[item.name].length : !String(this.values[item.name] ?? '').trim())) this.errors[item.name] = 'Vui lòng điền mục này.';
                        });
                        if (Object.keys(this.errors).length) return;
                        this.stage = 'review';
                        this.$nextTick(() => this.$refs.reviewTitle.focus());
                    },
                    displayValue(item) {
                        if (['file', 'document', 'image'].includes(item.type)) return this.files[item.name] || this.existingFiles[item.name] || 'Chưa chọn tệp';
                        const options = this.optionsFor(item.name);
                        if (item.type === 'checkbox') return options.filter(option => (this.values[item.name] || []).includes(option.value)).map(option => option.label).join(', ') || '—';
                        const option = options.find(option => String(option.value) === String(this.values[item.name] ?? ''));
                        return option ? option.label : this.values[item.name] || '—';
                    },
                    confirm() {
                        if (this.previewOnly || this.loading || this.captchaPending || this.stage !== 'review') return;
                        this.message = ''; this.success = false;
                        const captcha = window.__captcha || { provider: 'off' };
                        if (captcha.provider === 'cloudflare') {
                            if (this.captchaWidgetId === null) { this.message = 'Không thể xác minh biểu mẫu.'; return; }
                            this.captchaPending = true;
                            this.$refs.captchaToken.value = '';
                            if (captcha.verificationProvider === 'hcaptcha') {
                                const token = turnstile.getResponse(this.captchaWidgetId);
                                if (token) {
                                    this.$refs.captchaToken.value = token;
                                    this.captchaPending = false;
                                    this.post();
                                }
                            } else {
                                turnstile.reset(this.captchaWidgetId);
                                turnstile.execute(this.captchaWidgetId);
                            }
                            return;
                        }
                        if (captcha.provider === 'google') {
                            grecaptcha.execute(captcha.siteKey, { action: @js($form) }).then(token => { this.$refs.captchaToken.value = token; this.post(); })
                                .catch(() => { this.message = 'Không thể xác minh biểu mẫu.'; });
                            return;
                        }
                        this.post();
                    },
                    async post() {
                        if (this.previewOnly) return;
                        this.loading = true; this.message = '';
                        try {
                            const response = await fetch(@js($actionUrl), {
                                method: 'POST', credentials: 'same-origin',
                                headers: {'Accept': 'application/json', 'X-CSRF-TOKEN': @js(csrf_token())},
                                body: new FormData(this.$refs.form),
                            });
                            const body = await response.json();
                            if (!response.ok || body?.status !== 'success') {
                                this.errors = Object.fromEntries(Object.entries(body?.errors || {}).map(([key, value]) => [key, value?.[0] || value]));
                                if (Object.keys(this.errors).length) this.stage = 'edit';
                                throw new Error(body?.message || 'Vui lòng kiểm tra lại thông tin.');
                            }
                            this.success = true;
                            this.message = body?.data?.applicationId ? `${body.message} (${body.data.applicationId})` : body.message;
                            Object.values(this.previews).forEach(url => { if (url) URL.revokeObjectURL(url) });
                            this.$refs.form.reset(); this.items.forEach(item => this.values[item.name] = item.type === 'checkbox' ? [] : ''); this.files = {}; this.previews = {}; this.stage = 'success';
                        } catch (error) {
                            this.message = error.message || 'Không thể gửi biểu mẫu.';
                            if ((window.__captcha || {}).provider === 'cloudflare' && this.captchaWidgetId !== null) {
                                turnstile.reset(this.captchaWidgetId);
                            }
                        } finally { this.loading = false; }
                    },
                }
            }
        </script>
    @endpush
    @endif
@else
    <p class="rounded-md border border-amber-300 bg-amber-50 p-4 text-amber-900" role="status">Biểu mẫu hiện chưa sẵn sàng. Vui lòng liên hệ Văn phòng Học viện.</p>
@endif
