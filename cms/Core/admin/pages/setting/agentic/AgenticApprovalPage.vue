<!---------------------------------------------------------------------------------------------------------------------
  - Copyright (c) 2025 iSPA Team - team@ispa.io.                                                                      -
  --------------------------------------------------------------------------------------------------------------------->

<template>
    <template v-if="canViewAgentic">
        <!-- A reading width keeps long proposals comfortable on wide screens. -->
        <div class="mx-auto max-w-5xl space-y-4">
            <breadcrumbs
                :items="[
                    { name: 'approvals', text: t('nav.a.approvals'), icon: 'clipboard-check-outline' },
                    { text: t('text.agentic.approval.detail_title') },
                ]"
            >
                <router-link :to="{ name: 'approvals' }" data-testid="approval-back">
                    <a-button>{{ t('text.agentic.approval.back_to_list') }}</a-button>
                </router-link>
            </breadcrumbs>

            <AgenticApprovalDetail :approval-id="route.params.id" />
        </div>
    </template>
    <no-permission-error v-else />
</template>

<script setup>
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { useAgenticPermissions } from './composables/useAgenticPermissions'
import AgenticApprovalDetail from './AgenticApprovalDetail.vue'

defineOptions({ name: 'AgenticApprovalPage' })

const { t } = useI18n()
const route = useRoute()
const { canViewAgentic } = useAgenticPermissions()
</script>
