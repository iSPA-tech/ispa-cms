<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <!-- Student activity on the admin home: same tile system as the rest of the dashboard. -->
    <i-stat-strip v-if="props.canView" class="mt-3" :cols="3" data-testid="student-report">
        <i-stat-tile
            :label="$t('text.user.active')"
            :to="{ name: 'student-manage' }"
            :value="tracker.totalStudentActive"
            :loading="loading"
        />
        <i-stat-tile
            :label="$t('text.ui.student-report.inactive')"
            :to="{ name: 'student-manage' }"
            :value="tracker.totalStudentInactive"
            :tone="tracker.totalStudentInactive > 0 ? 'warning' : 'default'"
            :loading="loading"
        />
        <i-stat-tile
            :label="$t('text.ui.student-report.total')"
            :value="tracker.totalStudent"
            :to="{ name: 'student-manage' }"
            :loading="loading"
        />
    </i-stat-strip>
</template>

<script setup>
import { defineComponent, onMounted, ref, watch } from 'vue'
import ajax from '@/ajax'

defineComponent({
    name: 'StudentReport',
})

const props = defineProps({
    refresh: {
        type: Boolean,
        default: false,
    },
    canView: {
        type: Boolean,
        default: false,
    },
})

const loading = ref(false)
const tracker = ref({
    totalStudent: 0,
    totalStudentActive: 0,
    totalStudentInactive: 0,
    totalStudentPending: 0,
})

const tracking = async () => {
    if (!props.canView) {
        return
    }

    loading.value = true
    await ajax
        .get('user/dashboard/student')
        .then((response) => {
            if (response.data && response.data.status === 'success') {
                tracker.value = { ...tracker.value, ...response.data.data }
            }
        })
        .finally(() => {
            loading.value = false
        })
}

onMounted(() => {
    if (props.canView) {
        tracking()
    }
})

watch(
    () => props.refresh,
    (value) => {
        if (value && props.canView) {
            tracking()
        }
    }
)
</script>
