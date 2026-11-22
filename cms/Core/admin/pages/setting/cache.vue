<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="max-w-3xl mx-auto">
        <a-form :label-col="{ span: 8 }" :model="form" :wrapper-col="{ span: 24 }">
            <div class="flex items-center pb-6 gap-x-2">
                <div class="flex-1"></div>

                <a-button
                    :icon="h(SaveOutlined)"
                    :loading="btnState"
                    class="btn-warning"
                    @click="submit(false)"
                >
                    {{ $t('nav.b.save') }}
                </a-button>
                <a-button
                    :icon="h(SyncOutlined)"
                    :loading="btnState"
                    danger
                    type="primary"
                    @click="submit(true)"
                >
                    {{ $t('nav.b.save') }} & {{ $t('nav.b.sync') }}
                </a-button>
            </div>

            <a-spin :indicator="loadingSpinner()" :spinning="!initState">
                <div v-for="(group, groupKey) in settingList" v-if="initState" :key="groupKey">
                    <a-divider
                        class="mb-0!"
                        dashed
                        orientation="left"
                        style="border-color: #0891b2"
                    >
                        <span class="text-cyan-800">{{ $t(`text.s.${groupKey}`) }}</span>
                    </a-divider>
                    <p class="mb-4 text-cyan-900/90 text-xs">{{ $t(`text.s.${groupKey}-desc`) }}</p>
                    <div v-for="(child, childInd) in group" :key="childInd">
                        <div
                            v-if="childInd !== 'unGroup'"
                            class="font-medium text-cyan-600 pl-0 md:pl-52"
                        >
                            <p class="border-b border-gray-300 mb-2 mt-4">
                                {{ $t('text.s.group-' + childInd) }}
                            </p>
                        </div>
                        <div v-if="Object.keys(child.languages).length > 0" class="space-y-4">
                            <template v-for="(name, nameKey) in child.languages" :key="nameKey">
                                <setting-items
                                    v-model="form[nameKey]"
                                    :descriptio="name.description"
                                    :items="name.items"
                                    :required="name.required"
                                    :setting-key="nameKey"
                                    @edit="editSetting"
                                />
                            </template>
                        </div>
                        <div class="space-y-4 pt-4">
                            <div v-for="(item, nameIndex) in child.list" :key="nameIndex">
                                <setting-item
                                    v-model="
                                        form[item.input_setting.title.replaceAll('.', '-')][
                                            item.setting_key
                                        ]
                                    "
                                    :item="item"
                                    @edit="editSetting"
                                />
                            </div>
                        </div>
                    </div>
                </div>
            </a-spin>
        </a-form>

        <a-card class="mt-8" :title="$t('text.cache.runtime.title')">
            <p class="mb-4 text-sm text-slate-600">
                {{ $t('text.cache.runtime.intro') }}
            </p>
            <div class="flex flex-wrap gap-3">
                <a-button
                    :icon="h(DeleteOutlined)"
                    :loading="runtimeAction === 'clear'"
                    danger
                    @click="confirmRuntimeAction('clear')"
                >
                    {{ $t('text.cache.runtime.clear') }}
                </a-button>
                <a-button
                    :icon="h(ThunderboltOutlined)"
                    :loading="runtimeAction === 'optimize'"
                    type="primary"
                    @click="confirmRuntimeAction('optimize')"
                >
                    {{ $t('text.cache.runtime.optimize') }}
                </a-button>
            </div>
        </a-card>
    </div>
</template>

<script setup>
import { ref, onMounted, watch, inject, defineComponent, nextTick, onUnmounted, h } from 'vue'
import ajax from '@/ajax'
import { iAlert, initSettingList, loadingSpinner } from '@/mixins'
import { useSettings } from '@/hooks'
import SettingItem from '@/pages/setting/setting-item.vue'
import SettingItems from '@/pages/setting/setting-items.vue'
import { DeleteOutlined, SaveOutlined, SyncOutlined, ThunderboltOutlined } from '@ant-design/icons-vue'
import { Modal } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import { useAppRuntime } from '@/runtime'
import { clearApplicationCache, optimizeApplication } from '@/libs/cache'

defineComponent({
    name: 'SettingCache',
    components: {
        'setting-item': SettingItem,
        'setting-items': SettingItems,
    },
})

const { t } = useI18n()
const listGroup = ['cache']
const { languages } = useAppRuntime()
const settingList = ref({})
const { data: settings, refetch } = useSettings(
    {},
    {
        onSuccess: (res) => {
            settingList.value = initSettingList(
                res?.data || [],
                listGroup,
                languages.value?.data || []
            )
            nextTick(() => {
                form.value = initForm(settingList.value)
            })
        },
    }
)

const editSetting = inject('editSetting')

const formDefault = () => {
    return {}
}
const btnState = ref(false)
const initState = inject('initState')
const updateInitState = inject('updateInitState')
const initForm = inject('initForm')
const form = ref(formDefault())
const runtimeAction = ref('')

const runRuntimeAction = async (action) => {
    if (runtimeAction.value) return

    runtimeAction.value = action
    try {
        const response = action === 'clear'
            ? await clearApplicationCache()
            : await optimizeApplication()
        iAlert(response.data)
    } catch (error) {
        iAlert(error, true)
    } finally {
        runtimeAction.value = ''
    }
}

const confirmRuntimeAction = (action) => {
    const clearing = action === 'clear'
    Modal.confirm({
        title: clearing
            ? t('text.cache.runtime.clear_title')
            : t('text.cache.runtime.optimize_title'),
        content: clearing
            ? t('text.cache.runtime.clear_content')
            : t('text.cache.runtime.optimize_content'),
        okText: clearing ? t('text.cache.runtime.clear_ok') : t('text.cache.runtime.optimize_ok'),
        cancelText: t('nav.b.cancel'),
        okType: clearing ? 'danger' : 'primary',
        onOk: () => runRuntimeAction(action),
    })
}

/** Submit form */
const submit = (sync = false) => {
    if (btnState.value) {
        return
    }
    btnState.value = true
    form.value.sync = sync
    ajax.post(`/setting/save`, form.value)
        .then((res) => {
            if (res.data.message) {
                iAlert(res.data)
            }
        })
        .catch((err) => {
            iAlert(err, true)
        })
        .finally(() => {
            btnState.value = false
        })
}

onMounted(() => {
    settingList.value = initSettingList(
        Array.isArray(settings.value) ? settings.value : (settings.value?.data || []),
        listGroup,
        languages.value?.data || []
    )
    nextTick(() => {
        form.value = initForm(settingList.value)
    })
})

onUnmounted(() => {
    updateInitState(false)
    form.value = {}
    runtimeAction.value = ''
})

watch(
    () => settings.value,
    (val) => {
        settingList.value = initSettingList(val?.data || [], listGroup, languages.value?.data || [])
        nextTick(() => {
            form.value = initForm(settingList.value)
        })
    }
)
</script>
