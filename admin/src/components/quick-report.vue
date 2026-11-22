<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div class="flex items-center">
        <div class="flex-1"></div>
        <div>
            <a-select
                v-model="requestChart.type"
                @change="getCounter"
                :options="dateTimeType.map((item) => ({ label: $t(item.text), value: item.value }))"
                class="w-32 text-sm"
            />
        </div>
    </div>
    <p v-if="chartMeta.data_quality" class="mt-2 mb-0 text-xs text-ink-faint">
        {{ trackingQualityLabel(chartMeta.data_quality, chartMeta.exact_from_date, $t) }}
        <span v-if="chartMeta.timezone"> · {{ chartMeta.timezone }}</span>
    </p>
    <!-- Traffic over time on the design-system chart (token colours, one axis, shared tooltip). -->
    <div class="min-h-[260px]">
        <i-chart
            v-if="currentChart.series.length > 0"
            type="trend"
            :series="currentChart.series"
            :categories="currentChart.categories"
        />
    </div>
</template>

<script setup>
import { computed, defineComponent, onMounted, ref, inject } from 'vue'
import ajax from '@/ajax'
import { cookie, COOKIE_KEY_TOKEN } from '@/config'
import { rule } from '@/mixins'
import { normalizeTrackingChart, trackingQualityLabel } from '@/libs/tracking'

const props = defineProps({
    type: { type: String, default: null },
    targetId: { type: Number, default: (val = 0) => val >>> 0 },
    canView: { type: Boolean, default: null },
})

defineComponent({
    name: 'QuickReport',
})

const dateTimeType = inject('dateTimeType')

const requestChart = ref({
    type: 'last_30_days',
    target_type: props.type || null,
    target_id: props.targetId || null,
})
const chartMeta = ref({})
const currentChart = ref({ categories: [], series: [] })

const canFetchReport = computed(() => {
    if (props.canView !== null) {
        return props.canView
    }

    const targetType = String(props.type || '').toLowerCase()

    if (targetType.includes('post')) {
        return rule('post.viewAny')
    }

    if (targetType.includes('item')) {
        return rule(['place_item.viewAny', 'item.viewAny'])
    }

    if (targetType.includes('place')) {
        return rule('place.viewAny')
    }

    return rule(['post.viewAny', 'place.viewAny', 'place_item.viewAny', 'item.viewAny', 'contact.viewAny', 'comment.viewAny'])
})

/** Get the counter */
const getCounter = async () => {
    if (!canFetchReport.value) {
        return
    }

    await ajax.post(`track/chart`, requestChart.value).then((res) => {
        if (res.data.status === 'success') {
            const chart = normalizeTrackingChart(res.data.data)
            chartMeta.value = chart
            currentChart.value = { categories: chart.label, series: chart.series }
        }
    })
}

onMounted(() => {
    if (cookie.get(COOKIE_KEY_TOKEN) && canFetchReport.value) {
        getCounter()
    }
})
</script>
