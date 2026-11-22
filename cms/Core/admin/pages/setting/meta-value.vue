<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div v-if="rule('meta_attribute.viewAny')" class="px-2 md:px-1 py-4 mb-12">
        <div class="flex items-center">
            <h2 class="text-xl font-bold mb-4 md:mb-6 flex-1">{{ $t('nav.a.meta_values') }}</h2>
            <div>
                <a-button class="btn-success plain" @click="openDialog">
                    <span><i-icon class="size-4" icon="plus-thick" /></span>
                    <span>{{ $t('nav.b.create') }} {{ $t('nav.a.meta_values') }}</span>
                </a-button>
            </div>
        </div>

        <a-tabs v-model:activeKey="activeTab" @tab-change="tabChange">
            <a-tab-pane
                v-for="(tab, ind) in valueType"
                :key="ind"
                :tab="$t(tab.label)"
                :name="tab.value"
            >
                <div class="inline-block min-w-full py-2 align-middle">
                    <div class="shadow-xs ring-1 ring-black/5">
                        <table class="min-w-full border-separate" style="border-spacing: 0">
                            <thead class="bg-gray-50">
                                <tr>
                                    <th
                                        class="sticky top-0 z-10 border-b border-gray-300 bg-gray-50/75 py-3 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 backdrop-blur-xs backdrop-filter sm:pl-6 lg:pl-8"
                                        scope="col"
                                    >
                                        {{ $t('nav.a.meta_values') }}
                                    </th>
                                    <th
                                        class="sticky top-0 z-10 border-b border-gray-300 bg-gray-50/75 py-3 pl-4 pr-3 text-left text-sm font-semibold text-gray-900 backdrop-blur-xs backdrop-filter sm:pl-6 lg:pl-8"
                                        scope="col"
                                    >
                                        {{ $t('text.label') }}
                                    </th>
                                    <th
                                        class="sticky top-0 z-10 border-b border-gray-300 bg-gray-50/75 py-3 pr-4 pl-3 backdrop-blur-xs backdrop-filter sm:pr-6 lg:pr-8"
                                        scope="col"
                                    >
                                        <span class="sr-only">{{ $t('nav.b.edit') }}</span>
                                    </th>
                                </tr>
                            </thead>
                            <tbody class="bg-white">
                                <tr>
                                    <td
                                        class="whitespace-nowrap py-3 pl-4 pr-3 text-sm text-gray-500 sm:pl-6 lg:pl-8"
                                        colspan="2"
                                    >
                                        {{ $t('text.no-data') }}
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                    </div>
                </div>
            </a-tab-pane>
        </a-tabs>

        <a-drawer
            v-model:open="dialogState"
            :title="`${$t(form.id > 0 ? 'nav.b.update' : 'nav.b.create')} ${$t('nav.a.meta_values')}`"
            :width="$isMobile() ? '100%' : '50%'"
            @close="closeDialog"
        >
            <a-form
                ref="formRef"
                :model="form"
                :rules="formRule"
                @finish="submitForm"
                label-position="top"
            >
                <a-form-item :label="$t('validation.attributes.type')" class="w-full" name="type">
                    <a-select
                        v-model:value="form.type"
                        :placeholder="$t('text.select')"
                        default-first-option
                        :options="
                            valueType.map((tab) => ({
                                label: $t(tab.label),
                                value: tab.value,
                            }))
                        "
                    />
                </a-form-item>

                <a-form-item :label="$t('nav.a.meta_values')" name="value">
                    <a-input v-model:value="form.value" :placeholder="$t('nav.a.meta_values')" />
                </a-form-item>

                <select-input-translate
                    v-model:value="form.label"
                    :placeholder="$t('nav.a.meta_attributes')"
                    :label="$t('nav.a.meta_attributes')"
                    name="label"
                />
            </a-form>

            <div class="flex items-center pt-4 md:pt-8">
                <div class="flex-1">
                    <a-button
                        :disabled="!rule(['meta_class.create', 'meta_class.update'])"
                        @click="closeDialog"
                    >
                        <span><i-icon icon="close" /></span>
                        <span>{{ $t('nav.b.close') }}</span>
                    </a-button>
                </div>
                <a-button
                    :disabled="!rule(['meta_class.create', 'meta_class.update'])"
                    danger
                    type="primary"
                    @click="submitForm"
                >
                    <span><i-icon icon="content-save" /></span>
                    <span>{{ $t('nav.b.save') }}</span>
                </a-button>
            </div>
        </a-drawer>
    </div>
    <no-permission-error v-else></no-permission-error>
</template>

<script setup>
import { defineComponent, onMounted, ref } from 'vue'
import { iAlert, rule } from '@/mixins'
import ajax from '@/ajax'
import SelectInputTranslate from '@/components/select-input-translate.vue'

defineComponent({
    name: 'SettingMetaValue',
})

const valueType = ref([
    { value: 'string', label: 'enum.attr.string' },
    { value: 'text', label: 'enum.attr.text' },
    { value: 'bigInt', label: 'enum.attr.bigInt' },
    { value: 'tinyInt', label: 'enum.attr.tinyInt' },
    { value: 'boolean', label: 'enum.attr.boolean' },
    { value: 'date', label: 'enum.attr.date' },
])
const dialogState = ref(false)
const activeTab = ref('string')
const formDefault = { id: 0, value: null, type: 'string', label: null }
const form = ref(formDefault)
const formRef = ref(null)
const formRule = ref({})
const resetForm = () => {
    form.value = formDefault

    formRef.value.resetFields()
    formRef.value.clearValidate()
}
const submitForm = () => {
    const call =
        form.value.id > 0
            ? ajax.put('meta-value/' + form.value.id, form.value)
            : ajax.post('meta-value', form.value)

    call.then((res) => {
        if (res.data.status === 'success') {
            if (form.value.id < 1) {
                resetForm()
                //  Re-call the list
                tabChange()
            }
        }
        if (res.data.message) {
            iAlert(res.data)
        }
    })
}

const openDialog = () => {
    dialogState.value = true

    formRef.value?.resetFields()
    formRef.value?.clearValidate()
}

const tabChange = () => {
    // metaValue.set('filter', { type: activeTab.value })
    // metaValue.callGets()
}

const closeDialog = () => {
    resetForm()
    dialogState.value = false
}

const editValue = (id) => {
    ajax.get('meta-value/' + id + '/edit', {
        params: { type: activeTab.value },
    }).then((res) => {
        if (res.data.status === 'success') {
            form.value = res.data.data
            openDialog()
        }
    })
}

onMounted(() => {
    tabChange()
})
</script>
