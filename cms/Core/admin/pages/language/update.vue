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
                { text: form.name || $t('nav.b.update'), title: form.name || $t('nav.b.update') },
            ]"
        >
            <i-button :to="{ name: 'language-manage' }" as="router-link" variant="default">
                <template #icon>
                    <i-icon class="size-3.5" icon="arrow-left" />
                </template>
                <span>{{ $t('nav.b.back') }}</span>
            </i-button>
            <i-button
                v-if="rule('language.update')"
                :disabled="btnState"
                :loading="btnState"
                form="language-form"
                native-type="submit"
                variant="primary"
            >
                <template #icon>
                    <i-icon class="size-3.5" icon="content-save" />
                </template>
                <span>{{ $t('nav.b.update') }}</span>
            </i-button>
        </breadcrumbs>

        <i-box>
            <language-form v-if="rule('language.update')" />
            <no-permission-error v-else></no-permission-error>
        </i-box>
    </div>
</template>

<script setup>
import { defineComponent, inject, onMounted } from 'vue'
import { rule, iAlert } from '@/mixins'
import LanguageForm from './components/language-form.vue'
import { useRoute } from 'vue-router'
import { getLanguage } from '@/libs'

defineComponent({
    name: 'LanguageUpdate',
})
const btnState = inject('btnState')
const form = inject('form')
const resetForm = inject('resetForm')
const setForm = inject('setForm')
const route = useRoute()

onMounted(async () => {
    resetForm()
    try {
        setForm(await getLanguage(route.params.id, true))
    } catch (err) {
        iAlert(err, true)
    }
})
</script>
