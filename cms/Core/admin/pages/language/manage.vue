<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="space-y-4">
        <breadcrumbs
            :items="[
                {
                    icon: 'translate-variant',
                    title: $t('nav.a.languages'),
                    text: $t('nav.a.languages'),
                },
            ]"
        >
            <template #description>{{ $t('text.language.description') }}</template>
            <i-button
                v-if="rule('language.create')"
                :to="{ name: 'language-create' }"
                as="router-link"
                variant="primary"
            >
                <template #icon>
                    <i-icon class="size-3.5" icon="plus-thick" />
                </template>
                <span>{{ $t('nav.b.create') }}</span>
            </i-button>
        </breadcrumbs>

        <i-box>
            <a-table
                v-if="rule('language.viewAny')"
                :columns="columns"
                :data-source="languages?.data || []"
                :locale="{ emptyText: $t('text.language.empty') }"
                :pagination="false"
                :row-class-name="(row) => (isDeleted(row) ? 'opacity-60' : '')"
                :scroll="{ x: 960 }"
                row-key="id"
                size="small"
            >
                <template #headerCell="{ column }">
                    <template v-if="column.title">{{ $t(column.title) }}</template>
                </template>
                <template #bodyCell="{ column: col, record: row }">
                    <template v-if="col.key === 'flag'">
                        <img
                            v-if="row.flag"
                            :alt="row.name"
                            :src="`${apiUrl}images/flags/4x3/${row.flag}`"
                            class="h-4 w-auto"
                        />
                    </template>
                    <template v-if="col.key === 'name'">
                        <router-link
                            v-if="rule('language.update') && !isDeleted(row)"
                            :title="$t('nav.b.update')"
                            :to="{ name: 'language-update', params: { id: row.id } }"
                            class="text-cyan-500 hover:text-cyan-600"
                        >
                            {{ row.name }}
                        </router-link>
                        <span v-else>{{ row.name }}</span>
                        <span v-if="row.code" class="ml-2 text-xs font-medium text-blk-500">{{
                            row.code
                        }}</span>
                        <span class="block text-xs text-blk-500">
                            {{ [row.path, row.path_alias].filter(Boolean).join(' · ') }}
                        </span>
                    </template>
                    <template v-if="col.key === 'status'">
                        <a-tag :color="bareStatus(row.status) === 'published' ? 'green' : 'default'">
                            {{ statusLabel(row.status) }}
                        </a-tag>
                        <a-tag v-if="row.is_locked">
                            <i-icon class="size-3 inline-block" icon="lock" />
                            {{ $t('text.config.locked') }}
                        </a-tag>
                        <a-tag v-if="isDeleted(row)" color="red">
                            {{ $t('text.language.deleted') }}
                        </a-tag>
                    </template>
                    <template v-if="['for_web', 'for_adm', 'for_app'].indexOf(col.key) > -1">
                        <a-switch
                            v-model:checked="row[col.key]"
                            :aria-label="$t('text.language.aria_use_for', { platform: $t(col.title), name: row.name })"
                            :data-column="col.key"
                            :data-language-id="row.id"
                            :disabled="!rule('language.update') || isDeleted(row)"
                            show-icon
                            size="small"
                            @change="quickUpdate(row.id, col.key, $event)"
                        />
                    </template>
                    <template v-if="['default_web', 'default_adm', 'default_app'].indexOf(col.key) > -1">
                        <a-tooltip
                            :title="
                                row[col.colCondition]
                                    ? ''
                                    : $t('text.language.default_requires_platform')
                            "
                        >
                            <span class="inline-block">
                                <a-switch
                                    v-model:checked="row[col.key]"
                                    :aria-label="$t('text.language.aria_default_for', { platform: $t(col.title), name: row.name })"
                                    :data-column="col.key"
                                    :data-language-id="row.id"
                                    :disabled="
                                        !rule('language.update') ||
                                        isDeleted(row) ||
                                        !row[col.colCondition]
                                    "
                                    size="small"
                                    @change="quickUpdate(row.id, col.key, $event)"
                                />
                            </span>
                        </a-tooltip>
                    </template>
                    <template v-if="col.key === 'action'">
                        <div class="inline-flex items-center gap-2">
                            <i-button
                                v-if="rule('language.update') && !isDeleted(row)"
                                :aria-label="$t('nav.b.update')"
                                :title="$t('nav.b.update')"
                                :to="{ name: 'language-update', params: { id: row.id } }"
                                as="router-link"
                                size="xs"
                                variant="default"
                            >
                                <i-icon class="size-4" icon="edit" />
                            </i-button>
                            <a-popconfirm
                                v-if="isDeleted(row)"
                                :cancel-text="$t('nav.b.cancel')"
                                :ok-text="$t('nav.b.restore')"
                                :title="$t('text.restore-confirm')"
                                ok-type="danger"
                                @confirm="onRestoreLanguage(row.id)"
                            >
                                <i-button
                                    :aria-label="$t('nav.b.restore')"
                                    :title="$t('nav.b.restore')"
                                    size="xs"
                                    variant="success"
                                >
                                    <i-icon class="size-4" icon="undo" />
                                </i-button>
                            </a-popconfirm>
                            <a-popconfirm
                                v-else
                                :cancel-text="$t('nav.b.cancel')"
                                :ok-text="$t('nav.b.delete')"
                                :title="$t('text.del-confirm')"
                                ok-type="danger"
                                @confirm="onDeleteLanguage(row.id)"
                            >
                                <i-button
                                    :aria-label="$t('nav.b.delete')"
                                    :title="$t('nav.b.delete')"
                                    size="xs"
                                    variant="danger"
                                >
                                    <i-icon class="size-4" icon="delete" />
                                </i-button>
                            </a-popconfirm>
                        </div>
                    </template>
                </template>
            </a-table>
            <no-permission-error v-else></no-permission-error>
        </i-box>
    </div>
</template>
<script setup>
import { computed, defineComponent, inject } from 'vue'
import { rule } from '@/mixins'
import { apiUrl } from '@/config'
import { useSwitchLanguage, useDeleteLanguage, useRestoreLanguage, useEnumerates } from '@/hooks'
import { useAppRuntime } from '@/runtime'

const { mutate: switchLanguage } = useSwitchLanguage()
const { mutate: deleteLanguage } = useDeleteLanguage()
const { mutate: restoreLanguage } = useRestoreLanguage()

defineComponent({
    name: 'LanguageManage',
})
// Column titles are i18n keys; the headerCell slot translates them.
const columns = [
    { key: 'flag', title: '', width: 40, fixed: 'left' },
    { key: 'name', title: 'validation.attributes.name', width: 240 },
    { key: 'status', title: 'validation.attributes.status', width: 160 },
    {
        key: 'use_for',
        title: 'text.use_for',
        children: [
            { key: 'for_web', title: 'text.language.platform.web', width: 80 },
            { key: 'for_adm', title: 'text.language.platform.adm', width: 80 },
            { key: 'for_app', title: 'text.language.platform.app', width: 80 },
        ],
    },
    {
        key: 'use_for_default',
        title: 'text.default',
        children: [
            {
                key: 'default_web',
                colCondition: 'for_web',
                title: 'text.language.platform.web',
                width: 80,
            },
            {
                key: 'default_adm',
                colCondition: 'for_adm',
                title: 'text.language.platform.adm',
                width: 80,
            },
            {
                key: 'default_app',
                colCondition: 'for_app',
                title: 'text.language.platform.app',
                width: 80,
            },
        ],
    },
    { key: 'action', title: '', align: 'right', width: 120 },
]
const { languages, locale } = useAppRuntime()
const refetchLanguages = inject('languageRefetch')
const { data: enumerates } = useEnumerates()
const isVi = computed(() => locale?.value === 'vi')

/** Whether a row is soft-deleted (only restore is allowed). */
const isDeleted = (row) => row.deleted_at !== null && row.deleted_at !== undefined

/** Bare status key (`status_published` -> `published`). */
const bareStatus = (key) => String(key ?? '').replace(/^status_/, '')

/** Human label of a status enum key (bare or prefixed), falling back to the key itself. */
const statusLabel = (key) => {
    const bare = bareStatus(key)
    const item = (enumerates?.value || []).find(
        (e) => e.type === 'status' && bareStatus(e.enum_key) === bare,
    )
    if (!item) return key || ''
    return (isVi.value ? item.name : item.name_alias) || item.name || key
}

const quickUpdate = (id, col, value, callback = null) => {
    switchLanguage(
        { id, col, value },
        {
            onSuccess: (res) => {
                if (callback) {
                    callback()
                }
                refetchLanguages()
            },
            onError: () => refetchLanguages(),
        }
    )
}

const onDeleteLanguage = (id) => {
    deleteLanguage(id, {
        onSuccess: (res) => {
            refetchLanguages()
        },
    })
}
const onRestoreLanguage = (id) => {
    restoreLanguage(id, {
        onSuccess: (res) => {
            refetchLanguages()
        },
    })
}
</script>
