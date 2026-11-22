<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex-1 flex h-full flex-col p-3 md:p-6">
        <breadcrumbs
            :items="[
                {
                    icon: 'calendar-multiple',
                    title: $t('nav.a.calendars'),
                    text: $t('nav.a.calendars'),
                },
            ]"
        >
            <i-button variant="primary" @click="createEvent">
                <template #icon>
                    <i-icon class="size-3.5" icon="plus-thick" />
                </template>
                <span>{{ $t('nav.b.create') }} {{ $t('nav.a.calendars') }}</span>
            </i-button>
        </breadcrumbs>
        <header
            class="relative z-20 flex items-center justify-between border-b border-gray-200 py-4 lg:flex-none"
        >
            <h2 class="text-lg font-semibold text-gray-900">
                <time datetime="2022-01">{{ dayList.month }}</time>
            </h2>
            <div class="flex items-center">
                <div class="flex items-center rounded-md shadow-xs md:items-stretch">
                    <button
                        class="flex items-center justify-center rounded-l-md border border-r-0 border-gray-300 bg-white py-2 pl-3 pr-4 text-gray-400 hover:text-gray-500 focus:relative md:w-9 md:px-2 md:hover:bg-gray-50"
                        type="button"
                        @click="changeMonth(-1)"
                    >
                        <span class="sr-only">Previous month</span>
                        <svg
                            aria-hidden="true"
                            class="h-5 w-5"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                clip-rule="evenodd"
                                d="M12.707 5.293a1 1 0 010 1.414L9.414 10l3.293 3.293a1 1 0 01-1.414 1.414l-4-4a1 1 0 010-1.414l4-4a1 1 0 011.414 0z"
                                fill-rule="evenodd"
                            />
                        </svg>
                    </button>
                    <button
                        class="flex items-center justify-hidden border-t border-b border-gray-300 bg-white py-2 px-3.5 text-sm font-medium text-gray-700 hover:bg-gray-50 hover:text-gray-900 focus:relative md:block"
                        type="button"
                        @click="changeMonth(0)"
                    >
                        Today
                    </button>
                    <span class="relative -mx-px h-5 w-px bg-gray-300 md:hidden"></span>
                    <button
                        class="flex items-center justify-center rounded-r-md border border-l-0 border-gray-300 bg-white py-2 pl-4 pr-3 text-gray-400 hover:text-gray-500 focus:relative md:w-9 md:px-2 md:hover:bg-gray-50"
                        type="button"
                        @click="changeMonth(1)"
                    >
                        <span class="sr-only">Next month</span>
                        <svg
                            aria-hidden="true"
                            class="h-5 w-5"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                clip-rule="evenodd"
                                d="M7.293 14.707a1 1 0 010-1.414L10.586 10 7.293 6.707a1 1 0 011.414-1.414l4 4a1 1 0 010 1.414l-4 4a1 1 0 01-1.414 0z"
                                fill-rule="evenodd"
                            />
                        </svg>
                    </button>
                </div>
                <div class="hidden md:flex md:items-center">
                    <div class="relative">
                        <!-- <button type="button" class="flex items-center rounded-md border border-gray-300 bg-white py-2 pl-3 pr-2 text-sm font-medium text-gray-700 shadow-xs hover:bg-gray-50" id="menu-button" aria-expanded="false" aria-haspopup="true">
							Month view
							<svg class="ml-2 h-5 w-5 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor" aria-hidden="true">
								<path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
							</svg>
						</button> -->

                        <!--
							Dropdown menu, show/hide based on menu state.

							Entering: "transition ease-out duration-100"
								From: "transform opacity-0 scale-95"
								To: "transform opacity-100 scale-100"
							Leaving: "transition ease-in duration-75"
								From: "transform opacity-100 scale-100"
								To: "transform opacity-0 scale-95"
						-->
                        <div
                            aria-labelledby="menu-button"
                            aria-orientation="vertical"
                            class="hidden focus:outline-hidden absolute right-0 mt-3 w-36 origin-top-right overflow-hidden rounded-md bg-white shadow-lg ring-1 ring-black/5"
                            role="menu"
                            tabindex="-1"
                        >
                            <div class="py-1" role="none">
                                <!-- Active: "bg-gray-100 text-gray-900", Not Active: "text-gray-700" -->
                                <a
                                    id="menu-item-0"
                                    class="text-gray-700 block px-4 py-2 text-sm"
                                    href="#"
                                    role="menuitem"
                                    tabindex="-1"
                                    >Day view</a
                                >
                                <a
                                    id="menu-item-1"
                                    class="text-gray-700 block px-4 py-2 text-sm"
                                    href="#"
                                    role="menuitem"
                                    tabindex="-1"
                                    >Week view</a
                                >
                                <a
                                    id="menu-item-2"
                                    class="text-gray-700 block px-4 py-2 text-sm"
                                    href="#"
                                    role="menuitem"
                                    tabindex="-1"
                                    >Month view</a
                                >
                                <a
                                    id="menu-item-3"
                                    class="text-gray-700 block px-4 py-2 text-sm"
                                    href="#"
                                    role="menuitem"
                                    tabindex="-1"
                                    >Year view</a
                                >
                            </div>
                        </div>
                    </div>
                </div>
                <div class="relative ml-6 md:hidden">
                    <button
                        id="menu-0-button"
                        aria-expanded="false"
                        aria-haspopup="true"
                        class="-mx-2 flex items-center rounded-full border border-transparent p-2 text-gray-400 hover:text-gray-500"
                        type="button"
                    >
                        <span class="sr-only">Open menu</span>
                        <svg
                            aria-hidden="true"
                            class="h-5 w-5"
                            fill="currentColor"
                            viewBox="0 0 20 20"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M6 10a2 2 0 11-4 0 2 2 0 014 0zM12 10a2 2 0 11-4 0 2 2 0 014 0zM16 12a2 2 0 100-4 2 2 0 000 4z"
                            />
                        </svg>
                    </button>

                    <!--
						Dropdown menu, show/hide based on menu state.

						Entering: "transition ease-out duration-100"
							From: "transform opacity-0 scale-95"
							To: "transform opacity-100 scale-100"
						Leaving: "transition ease-in duration-75"
							From: "transform opacity-100 scale-100"
							To: "transform opacity-0 scale-95"
					-->
                    <div
                        aria-labelledby="menu-0-button"
                        aria-orientation="vertical"
                        class="hidden focus:outline-hidden absolute right-0 mt-3 w-36 origin-top-right divide-y divide-gray-100 overflow-hidden rounded-md bg-white shadow-lg ring-1 ring-black/5"
                        role="menu"
                        tabindex="-1"
                    >
                        <div class="py-1" role="none">
                            <!-- Active: "bg-gray-100 text-gray-900", Not Active: "text-gray-700" -->
                            <a
                                id="menu-0-item-0"
                                class="text-gray-700 block px-4 py-2 text-sm"
                                href="#"
                                role="menuitem"
                                tabindex="-1"
                                >Create event</a
                            >
                        </div>
                        <div class="py-1" role="none">
                            <a
                                id="menu-0-item-1"
                                class="text-gray-700 block px-4 py-2 text-sm"
                                href="#"
                                role="menuitem"
                                tabindex="-1"
                                >Go to today</a
                            >
                        </div>
                        <div class="py-1" role="none">
                            <a
                                id="menu-0-item-2"
                                class="text-gray-700 block px-4 py-2 text-sm"
                                href="#"
                                role="menuitem"
                                tabindex="-1"
                                >Day view</a
                            >
                            <a
                                id="menu-0-item-3"
                                class="text-gray-700 block px-4 py-2 text-sm"
                                href="#"
                                role="menuitem"
                                tabindex="-1"
                                >Week view</a
                            >
                            <a
                                id="menu-0-item-4"
                                class="text-gray-700 block px-4 py-2 text-sm"
                                href="#"
                                role="menuitem"
                                tabindex="-1"
                                >Month view</a
                            >
                            <a
                                id="menu-0-item-5"
                                class="text-gray-700 block px-4 py-2 text-sm"
                                href="#"
                                role="menuitem"
                                tabindex="-1"
                                >Year view</a
                            >
                        </div>
                    </div>
                </div>
            </div>
        </header>

        <div class="shadow-xs ring-1 ring-black/5 lg:flex lg:flex-auto lg:flex-col">
            <div
                class="grid grid-cols-7 gap-px border-b border-gray-300 bg-gray-200 text-center text-xs font-semibold leading-6 text-gray-700 lg:flex-none"
            >
                <div class="bg-white py-2">M<span class="sr-only sm:not-sr-only">on</span></div>
                <div class="bg-white py-2">T<span class="sr-only sm:not-sr-only">ue</span></div>
                <div class="bg-white py-2">W<span class="sr-only sm:not-sr-only">ed</span></div>
                <div class="bg-white py-2">T<span class="sr-only sm:not-sr-only">hu</span></div>
                <div class="bg-white py-2">F<span class="sr-only sm:not-sr-only">ri</span></div>
                <div class="bg-white py-2">S<span class="sr-only sm:not-sr-only">at</span></div>
                <div class="bg-white py-2">S<span class="sr-only sm:not-sr-only">un</span></div>
            </div>
            <div class="flex bg-gray-200 text-xs leading-6 text-gray-700 lg:flex-auto">
                <div
                    :class="{
                        'lg:grid-rows-6': dayList.grid === 6,
                        'lg:grid-rows-5': dayList.grid === 5,
                        'lg:grid-rows-4': dayList.grid === 4,
                    }"
                    class="hidden w-full lg:grid lg:grid-cols-7 lg:gap-px"
                >
                    <div
                        v-for="day in dayList.list"
                        :key="day.date"
                        :class="[
                            day.current_month
                                ? 'bg-white text-slate-600'
                                : 'bg-gray-50 text-gray-400',
                        ]"
                        class="relative py-2 px-3"
                    >
                        <time
                            :class="[
                                day.now
                                    ? 'flex h-6 w-6 items-center justify-center rounded-full bg-indigo-600 font-semibold text-white'
                                    : '',
                            ]"
                            :datetime="day.date"
                        >
                            {{ day.day }}
                        </time>
                        <ol v-if="day.events && day.events.length > 0" class="mt-2">
                            <li v-for="(evt, ind) in day.events" :key="ind">
                                <a class="group flex" href="#" @click="editCalendar(evt.id)">
                                    <p
                                        class="flex-auto truncate font-medium text-gray-900 group-hover:text-indigo-600"
                                    >
                                        <strong v-if="evt.language" class="uppercase">
                                            [{{ evt.language.path }}]
                                        </strong>
                                        {{ evt.name }}
                                    </p>
                                    <time
                                        class="ml-3 hidden flex-none text-gray-500 group-hover:text-indigo-600 xl:block"
                                        datetime="2022-01-03T10:00"
                                    >
                                        10AM
                                    </time>
                                </a>
                            </li>
                        </ol>
                    </div>
                </div>

                <div
                    v-if="dayList.list.length > 0"
                    :class="{
                        'grid-rows-6': dayList.grid === 6,
                        'grid-rows-5': dayList.grid === 5,
                        'grid-rows-4': dayList.grid === 4,
                    }"
                    class="isolate grid w-full grid-cols-7 gap-px lg:hidden"
                >
                    <template v-for="day in dayList.list">
                        <button
                            v-if="day && day.date"
                            :key="day.date"
                            :class="[
                                day.current_month ? 'bg-white' : 'bg-gray-50 text-gray-400',
                                day.now
                                    ? 'font-semibold text-indigo-600'
                                    : day.date === currentSelectedDay.date
                                      ? ''
                                      : 'text-slate-600',
                                day.date === currentSelectedDay.date
                                    ? 'bg-indigo-600 text-white'
                                    : (day.current_month
                                          ? 'bg-white'
                                          : 'bg-gray-50 text-gray-400') + ' hover:bg-gray-100',
                            ]"
                            class="flex h-14 flex-col py-2 px-3 focus:z-10"
                            type="button"
                            @click="selectDate(day)"
                        >
                            <time
                                :class="[
                                    day.date === currentSelectedDay.date
                                        ? 'flex h-6 w-6 items-center justify-center rounded-full ' +
                                          (day.now && day.date === currentSelectedDay.date
                                              ? 'bg-indigo-600'
                                              : 'bg-gray-900')
                                        : '',
                                ]"
                                :datetime="day.date"
                                class="ml-auto"
                            >
                                {{ day.day }}
                            </time>
                            <span class="sr-only">{{ day.events.length }} events</span>
                            <span
                                v-if="day.events && day.events.length > 0"
                                class="-mx-0.5 mt-auto flex flex-wrap-reverse"
                            >
                                <span
                                    v-for="i in day.events.length"
                                    :key="i"
                                    class="mx-0.5 mb-1 h-1.5 w-1.5 rounded-full bg-gray-400"
                                />
                            </span>
                        </button>
                    </template>
                </div>
            </div>
        </div>

        <div class="py-10 px-4 sm:px-6 lg:hidden">
            <ol
                v-if="currentSelectedDay.events && currentSelectedDay.events.length > 0"
                class="divide-y divide-gray-100 overflow-hidden rounded-lg bg-white text-sm shadow-xs ring-1 ring-black/5"
            >
                <li
                    v-for="(evt, ind) in currentSelectedDay.events"
                    :key="ind"
                    class="group flex p-4 pr-6 focus-within:bg-gray-50 hover:bg-gray-50"
                >
                    <div class="flex-auto">
                        <p class="font-semibold text-gray-900">{{ evt.name }}</p>
                        <time
                            class="mt-2 flex items-center text-gray-700"
                            datetime="2022-01-15T09:00"
                        >
                            <svg
                                aria-hidden="true"
                                class="mr-2 h-5 w-5 text-gray-400"
                                fill="currentColor"
                                viewBox="0 0 20 20"
                                xmlns="http://www.w3.org/2000/svg"
                            >
                                <path
                                    clip-rule="evenodd"
                                    d="M10 18a8 8 0 100-16 8 8 0 000 16zm1-12a1 1 0 10-2 0v4a1 1 0 00.293.707l2.828 2.829a1 1 0 101.415-1.415L11 9.586V6z"
                                    fill-rule="evenodd"
                                />
                            </svg>
                            <span v-if="evt.isAllDay">All day</span>
                            <span v-else>{{ evt.dateFrom }} - {{ evt.dateTo }}</span>
                        </time>
                    </div>
                    <a
                        class="ml-6 flex-none self-center rounded-md border border-gray-300 bg-white py-2 px-3 font-semibold text-gray-700 shadow-xs hover:bg-gray-50"
                        href="#"
                        @click="editCalendar(evt.id)"
                    >
                        {{ $t('nav.b.edit') }}
                    </a>
                </li>
            </ol>
        </div>
    </div>

    <a-drawer
        v-model:open="modalState"
        :title="`${$t(form.id > 0 ? 'nav.b.update' : 'nav.b.create')} ${$t('nav.a.calendars')}`"
        :width="$isMobile() ? '100%' : '400px'"
    >
        <a-form ref="formRef" :model="form" :rules="formRule" layout="vertical">
            <a-form-item :label="$t('validation.attributes.event_name')" name="name">
                <a-input
                    v-model:value="form.name"
                    :placeholder="$t('validation.attributes.event_name')"
                ></a-input>
            </a-form-item>

            <a-form-item :label="$t('validation.attributes.event_url')" name="url">
                <a-input
                    v-model:value="form.url"
                    :placeholder="$t('validation.attributes.event_url')"
                ></a-input>
            </a-form-item>

            <div class="pb-3">
                <i-switch
                    v-model="form.is_repeat"
                    :text-on="$t('validation.attributes.event_repeat')"
                    color-off="secondary"
                    show-icon
                    size="small"
                />
            </div>

            <a-form-item
                v-if="form.is_repeat"
                :label="$t('validation.attributes.event_repeat_type')"
                name="repeat_type"
            >
                <a-select
                    v-model:value="form.repeat_type"
                    :placeholder="$t('text.select')"
                    class="w-full"
                    :options="[
                        { label: $t('text.event.repeat_yearly'), value: '0' },
                        { label: $t('text.event.repeat_monthly'), value: '1' },
                    ]"
                />
            </a-form-item>

            <div class="pb-3">
                <i-switch
                    v-model="form.is_all_day"
                    :text-on="$t('validation.attributes.event_allday')"
                    color-off="secondary"
                    show-icon
                    size="small"
                />
            </div>

            <a-form-item :label="$t('validation.attributes.date_from')" name="date_from">
                <a-date-picker
                    v-model:value="form.date_from"
                    :placeholder="$t('validation.attributes.date_from')"
                    class="w-full"
                    :format="form.is_all_day ? 'YYYY-MM-DD' : 'YYYY-MM-DD HH:mm:ss'"
                    :show-time="{ defaultValue: dayjs('00:00:00', 'HH:mm:ss') }"
                />
            </a-form-item>

            <a-form-item
                v-if="!form.is_all_day"
                :label="$t('validation.attributes.date_to')"
                name="date_to"
            >
                <a-date-picker
                    v-model:value="form.date_to"
                    :placeholder="$t('validation.attributes.date_to')"
                    :format="form.is_all_day ? 'YYYY-MM-DD' : 'YYYY-MM-DD HH:mm:ss'"
                    :show-time="{ defaultValue: dayjs('00:00:00', 'HH:mm:ss') }"
                    class="w-full"
                />
            </a-form-item>

            <select-input-language
                v-if="modalState"
                v-model="form.language_id"
                :label="$t('validation.attributes.language_id')"
                name="language_id"
                required
            />

            <a-form-item :label="$t('validation.attributes.status')" name="status">
                <a-select
                    v-model:value="form.status"
                    :placeholder="$t('text.select')"
                    :options="statusSelectOptions"
                    class="w-full"
                />
            </a-form-item>
        </a-form>

        <template #footer>
            <div class="flex flex-nowrap items-center justify-between space-x-3">
                <div class="flex-1 text-left">
                    <a-button
                        v-if="form.id > 0"
                        :loading="isSubmitting"
                        danger
                        type="primary"
                        @click="deleteCalendar(form.id)"
                    >
                        <span><i-icon class="size-4" icon="delete" /></span>
                        <span>{{ $t('nav.b.delete') }}</span>
                    </a-button>
                </div>
                <a-button @click="modalState = false">
                    <span><i-icon icon="close" /></span>
                    <span>{{ $t('nav.b.cancel') }}</span>
                </a-button>
                <a-button :loading="isSubmitting" danger type="primary" @click="formSubmit">
                    <span><i-icon icon="content-save" class="size-4" /></span>
                    <span>{{ $t('nav.b.save') }}</span>
                </a-button>
            </div>
        </template>
    </a-drawer>
</template>
<script setup>
import { computed, defineComponent, ref, onMounted } from 'vue'
import ajax from '@/ajax'
import { iAlert } from '@/mixins'
import { useI18n } from 'vue-i18n'
import dayjs from 'dayjs'
import { useEnumerates } from '@/hooks'
import SelectInputLanguage from '@/components/select-input-language.vue'

defineComponent({
    name: 'Calendar',
})

const monthOffset = ref(0)
const dayList = ref({ list: [] })
const modalState = ref(false)
const isSubmitting = ref(false)
const { t } = useI18n()
const { data: enumerates } = useEnumerates()
const statusOptions = computed(() =>
    (enumerates.value || []).filter((item) => item.type === 'status')
)
const statusSelectOptions = computed(() =>
    statusOptions.value.map((status) => ({
        label: `${status.name} (${status.name_alias || status.enum_key})`,
        value: status.enum_key,
    }))
)
const currentSelectedDay = ref({
    date: dayjs().format('YYYY-MM-DD'),
    day: '',
    month: '',
    name: '',
    now: true,
    current_month: true,
    events: [],
})

/** Form section */
const formDefault = () => {
    return {
        id: 0,
        name: null,
        url: null,
        date_from: dayjs(),
        date_to: dayjs(),
        language_id: null,
        is_repeat: false,
        repeat_type: '0',
        status: 'status_published',
        is_all_day: false,
    }
}
const form = ref({})
const formRef = ref(null)
const formRule = ref({
    name: [
        {
            required: true,
            trigger: 'blur',
            message: t('validation.required', { a: t('validation.attributes.name') }),
        },
    ],
    language_id: [
        {
            required: true,
            trigger: 'blur',
            message: t('validation.required', { a: t('validation.attributes.language_id') }),
        },
    ],
    date_from: [
        {
            required: true,
            trigger: 'blur',
            message: t('validation.required', { a: t('validation.attributes.date_from') }),
        },
    ],
})

const formatCalendarDate = (value) => {
    if (!value) {
        return null
    }

    const parsed = dayjs(value)

    return parsed.isValid() ? parsed.format('YYYY-MM-DD HH:mm:ss') : value
}

const buildCalendarPayload = () => {
    const payload = { ...form.value }

    payload.date_from = formatCalendarDate(payload.date_from)
    payload.date_to = formatCalendarDate(payload.is_all_day ? payload.date_from : payload.date_to)
    payload.is_all_day = Boolean(payload.is_all_day)
    payload.is_repeat = Boolean(payload.is_repeat)
    payload.repeat_type = Number(payload.repeat_type || 0)
    payload.status = payload.status || 'status_published'
    payload.language_id = payload.language_id || null

    return payload
}

const isFormValidationError = (err) => {
    return Boolean(err?.errorFields || err?.values)
}

const formSubmit = async () => {
    try {
        await formRef.value?.validate?.()
    } catch (err) {
        if (isFormValidationError(err)) {
            return
        }

        iAlert(err, true)
        return
    }

    if (isSubmitting.value) {
        return
    }
    isSubmitting.value = true

    const payload = buildCalendarPayload()
    try {
        const res =
            payload.id > 0
                ? await ajax.put(`calendar/${payload.id}`, payload)
                : await ajax.post(`calendar`, payload)

        if (res.data.status === 'success') {
            await getDayList()
            //	Reset form
            if (form.value.id < 1) resetForm()
        }
        if (res.data.message) {
            iAlert(res.data)
        }
    } catch (err) {
        iAlert(err, true)
    } finally {
        isSubmitting.value = false
    }
}

const selectDate = (day) => {
    currentSelectedDay.value = day
}

const getDayList = async () => {
    //	Get the list of calendar
    await ajax
        .get(`utilities/list-days?offset=${monthOffset.value}`)
        .then((res) => {
            if (res.data.status === 'success') {
                dayList.value = res.data.data
                //	Set currentSelectedDay = today
                let today = dayList.value.list.find((e) => e.now === true)
                if (!today) {
                    today = dayList.value.list.find((e) => e.day === '01')
                }
                selectDate(today)
            }
            if (res.data.message) {
                iAlert(res.data)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}

const changeMonth = (val = 0) => {
    if (val !== 0) {
        monthOffset.value += val
    } else {
        monthOffset.value = 0
    }
    getDayList()
}

const resetForm = () => {
    if (formRef.value) {
        formRef.value.resetFields()
    }
    form.value = formDefault()
}

const setForm = (val) => {
    resetForm()
    const newValue = Object.assign(formDefault(), val)
    newValue.date_from = dayjs(newValue.date_from)
    newValue.date_to = dayjs(newValue.date_to)

    form.value = newValue

    if (form.value.repeat_type >= 0) {
        form.value.repeat_type = form.value.repeat_type.toString()
    }
}

const createEvent = () => {
    resetForm()
    form.value.date_from = dayjs()
    form.value.date_to = dayjs(form.value.date_from).add(1, 'hours')
    modalState.value = true
}

const editCalendar = (id) => {
    ajax.get(`calendar/${id}/edit`)
        .then((res) => {
            if (res.data.status === 'success') {
                setForm(res.data.data)
                //	Open modal
                modalState.value = true
            }
            if (res.data.message) {
                iAlert(res.data)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
}

const deleteCalendar = async (id) => {
    if (isSubmitting.value) {
        return
    }
    isSubmitting.value = true

    ajax.delete(`calendar/${id}`)
        .then(async (res) => {
            if (res.data.status === 'success') {
                await getDayList()
            }
            if (res.data.message) {
                iAlert(res.data)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            isSubmitting.value = false
            modalState.value = false
        })
}

onMounted(async () => {
    await getDayList()
})
</script>
