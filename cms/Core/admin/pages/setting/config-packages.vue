<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="max-w-5xl mx-auto space-y-6" data-testid="config-packages-page">
        <template v-if="!allowed">
            <section v-if="accountReady" class="i-box" role="alert" data-testid="config-packages-not-allowed">
                <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.not_allowed') }}</p>
            </section>
        </template>
        <template v-else>
            <section class="i-box space-y-1">
                <h3 class="m-0 text-sm font-semibold">{{ t('text.config_package.title') }}</h3>
                <p class="m-0 text-sm text-ink-muted">{{ t('text.config_package.description') }}</p>
            </section>
            <a-tabs v-model:activeKey="activeTab">
                <a-tab-pane key="export" :tab="t('text.config_package.tab_export')">
                    <export-tab />
                </a-tab-pane>
                <a-tab-pane key="import" :tab="t('text.config_package.tab_import')">
                    <import-tab />
                </a-tab-pane>
                <a-tab-pane key="history" :tab="t('text.config_package.tab_history')">
                    <history-tab />
                </a-tab-pane>
            </a-tabs>
        </template>
    </div>
</template>

<script setup>
import { computed, defineComponent, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { hasAccountData, isWebmaster } from '@/hooks/useCan'
import ExportTab from '../../components/config-package/export-tab.vue'
import HistoryTab from '../../components/config-package/history-tab.vue'
import ImportTab from '../../components/config-package/import-tab.vue'

defineComponent({ name: 'SettingConfigPackages' })

const { t } = useI18n()

/** Webmaster only: the API and the route guard refuse everyone else; this keeps the page body empty. */
const allowed = computed(() => isWebmaster())

/** The account is cached: before that the page shows nothing instead of a not-allowed flash. */
const accountReady = computed(() => hasAccountData())

/** Selected tab key: export, import or history. */
const activeTab = ref('export')
</script>
