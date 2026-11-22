<template>
    <a-tag :color="color">{{ $t(`text.skeleton.things.status_${status}`) }}</a-tag>
</template>

<script setup>
import { computed } from 'vue'

defineOptions({ name: 'SkeletonThingStatusTag' })

// Status values of SkeletonThingStatus (draft, published).
const props = defineProps({ status: { type: String, required: true } })
const color = computed(() => (props.status === 'published' ? 'green' : 'default'))
</script>
