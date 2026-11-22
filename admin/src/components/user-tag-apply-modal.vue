<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-modal
        :cancel-text="t('nav.b.cancel')"
        :confirm-loading="busy"
        :keyboard="false"
        :mask-closable="false"
        :ok-button-props="{ disabled: !tags.length, 'data-testid': 'apply-ok' }"
        :ok-text="tt('text.user-tag.attach')"
        :open="open"
        :title="tt('text.user-tag.title_attach', { count })"
        @cancel="emit('update:open', false)"
        @ok="run"
    >
        <select-input-user-tag
            v-model="tags"
            :allow-create="true"
            :placeholder="tt('text.user-tag.placeholder')"
            data-testid="apply-tags"
        />
        <p v-if="filter" class="mt-3 text-sm" data-testid="confirm-all">{{ tt('text.user-tag.confirm_all', { total: count }) }}</p>
    </a-modal>
</template>

<script setup>
import { ref, watch } from 'vue'
import { message } from 'ant-design-vue'
import { useI18n } from 'vue-i18n'
import SelectInputUserTag from '@/components/select-input-user-tag.vue'
import { makeTagText } from '@/components/user-tag-text'
import { attachUserTags } from '@/libs'

/**
 * Dialog of the Users list bulk bar: "Gắn tag" for the picked users (`userUuids`) or, with `filter`,
 * for everyone the filter matches (the server re-runs the filter, capped at 5,000). Emits `done` after a success.
 */
const props = defineProps({
    open: { type: Boolean, default: false },
    initialTags: { type: Array, default: () => [] },
    count: { type: Number, default: 0 },
    userUuids: { type: Array, default: () => [] },
    filter: { type: Object, default: null },
})
const emit = defineEmits(['update:open', 'done'])
const { t, te } = useI18n()
const tt = makeTagText({ t, te })
const tags = ref([])
const busy = ref(false)
watch(() => props.open, (open) => { if (open) tags.value = [...props.initialTags] }, { immediate: true })

const errorText = (error) => {
    const first = Object.values(error?.response?.data?.errors || {})[0]?.[0]
    return first || error?.response?.data?.message || tt('text.user-tag.failed')
}
const run = async () => {
    if (busy.value || !tags.value.length) return
    busy.value = true
    try {
        const payload = { tag_uuids: tags.value, ...(props.filter ? { filter: props.filter } : { user_uuids: props.userUuids }) }
        const res = await attachUserTags(payload)
        message.success(tt('text.user-tag.done', { affected: res?.data?.affected ?? 0 }))
        emit('update:open', false)
        emit('done')
    } catch (error) {
        message.error(errorText(error))
    } finally {
        busy.value = false
    }
}
</script>
