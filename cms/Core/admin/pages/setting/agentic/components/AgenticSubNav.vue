<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <div class="flex items-center gap-3 text-sm">
        <router-link
            v-for="item in visibleItems"
            :key="item.name"
            :to="{ name: item.name }"
            class="text-ink-muted hover:text-ink"
            active-class="!font-semibold !text-info-ink"
        >
            {{ t(item.labelKey) }}
            <slot :name="item.name" />
        </router-link>
    </div>
</template>

<script setup>
import { computed } from 'vue'
import { useI18n } from 'vue-i18n'
import { useAgenticPermissions } from '../composables/useAgenticPermissions'

defineOptions({ name: 'AgenticSubNav' })

const { t } = useI18n()
const { canProvisionAgentic } = useAgenticPermissions()

const items = [
    { name: 'setting-agentic', labelKey: 'text.agentic.dashboard.title' },
    { name: 'setting-agentic-approvals', labelKey: 'text.agentic.inbox.title' },
    { name: 'setting-agentic-admin', labelKey: 'text.agentic.admin.title', requiresAdmin: true },
]

const visibleItems = computed(() => items.filter((item) => !item.requiresAdmin || canProvisionAgentic.value))
</script>
