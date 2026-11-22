<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div>
        <breadcrumbs
            :items="[
                { name: 'language-manage', icon: 'translate-variant', title: $t('nav.a.languages') },
                { text: $t('nav.b.create'), title: $t('nav.b.create') },
            ]"
        >
            <i-button :to="{ name: 'language-manage' }" as="router-link" variant="default">
                <template #icon>
                    <i-icon class="size-3.5" icon="arrow-left" />
                </template>
                <span>{{ $t('nav.b.back') }}</span>
            </i-button>
            <i-button
                v-if="rule('language.create')"
                :disabled="btnState"
                :loading="btnState"
                form="language-form"
                native-type="submit"
                variant="primary"
            >
                <template #icon>
                    <i-icon class="size-3.5" icon="content-save" />
                </template>
                <span>{{ $t('nav.b.create') }}</span>
            </i-button>
        </breadcrumbs>

        <i-box>
            <language-form v-if="rule('language.create')" />
            <no-permission-error v-else></no-permission-error>
        </i-box>
    </div>
</template>

<script setup>
import { defineComponent, inject, onMounted } from 'vue'
import { rule } from '@/mixins'
import LanguageForm from './components/language-form.vue'

defineComponent({
    name: 'LanguageCreate',
})
const btnState = inject('btnState')
const resetForm = inject('resetForm')

onMounted(() => {
    resetForm()
})
</script>
