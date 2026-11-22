<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <a-form-item
        :label="$t('validation.attributes.address')"
        :rules="[
            {
                required: true,
                trigger: 'blur',
                message: $t('validation.address-required'),
            },
        ]"
        name="address"
    >
        <div class="flex items-center gap-2">
            <GMapAutocomplete
                v-model="form.address"
                :placeholder="$t('text.address-search')"
                :value="form.address"
                class="flex-1 border rounded-sm px-3 placeholder-gray-400"
                @place_changed="(e) => setPlace(form, e)"
            >
            </GMapAutocomplete>
            <button
                class="border px-4 py-1 rounded-sm bg-white shadow-xs hover:bg-gray-50"
                type="button"
                @click="toggleMap"
            >
                <i-icon icon="map-marker" class="size-4" />
                {{ $t('text.map.pin') }}
            </button>
        </div>
    </a-form-item>

    <div v-if="mapState" class="relative">
        <GMapMap
            :center="{
                lat: defaultCenter.lat,
                lng: defaultCenter.lng,
            }"
            :options="{
                zoomControl: true,
                mapTypeControl: false,
                scaleControl: true,
                streetViewControl: false,
                rotateControl: true,
                fullscreenControl: false,
            }"
            :zoom="15"
            class="h-[300px]"
            @center_changed="setCenterPointDebounce"
            @map_changed="mapInfo"
        >
            <GMapMarker
                :draggable="true"
                :position="{
                    lat: defaultCenter.lat,
                    lng: defaultCenter.lng,
                }"
                @dragend="setCenterPointByDragMarker"
            ></GMapMarker>
        </GMapMap>
        <!--        <img-->
        <!--            class="absolute top-1/2 left-1/2 z-10 w-8 transform -translate-x-1/2 -translate-y-1/2 -mt-[18px]"-->
        <!--            src="@/assets/pin.png"-->
        <!--            alt=""-->
        <!--        />-->
        <span
            class="absolute transition-all top-2 right-2 z-10 text-xl rounded-full bg-white shadow-xs p-0 w-6 h-6 text-center leading-[22px] text-gray-500 hover:text-gray-700 hover:shadow-lg"
            role="button"
            @click="toggleMap"
        >
            <i-icon icon="close" />
        </span>
    </div>
</template>

<script setup>
import { defineComponent, inject, nextTick, ref, watch } from 'vue'
import { debounce, isEmpty } from 'lodash-es'

const setPlace = inject('setPlace')

defineComponent({
    name: 'DroppableMap',
})

const emit = defineEmits(['update:modelValue'])
const props = defineProps({
    modelValue: {
        type: Object,
        default: () => ({}),
    },
})

const form = ref(props.modelValue)
const defaultCenter = ref({
    lat: 10.487735,
    lng: 107.1787,
})
const updateForm = () => {
    emit('update:modelValue', form.value)
}
//  Set center point after drag map
const setCenterPoint = (args = {}) => {
    const { lat, lng } = args
    form.value.latitude = lat()
    form.value.longitude = lng()

    defaultCenter.value = {
        lat: lat(),
        lng: lng(),
    }
}
//  Need to debounce to prevent too many request
const setCenterPointDebounce = debounce(setCenterPoint, 1000)
const setCenterPointByDragMarker = debounce((val) => {
    setCenterPoint(val.latLng || {})
    updateForm()
}, 500)
//  Map open state
const mapState = ref(false)
//  Click to toggle map
const toggleMap = () => {
    mapState.value = !mapState.value
}

const mapInfo = (val) => {
    if (isEmpty(form.value.meta.address_object.value)) {
        form.value.address = val?.formatted_address || ''

        updateForm()
    }
}

watch(
    () => props.modelValue,
    (val) => {
        form.value = val
        if (val.latitude && val.longitude) {
            nextTick(() => {
                defaultCenter.value = {
                    lat: parseFloat(val.latitude),
                    lng: parseFloat(val.longitude),
                }
            })
        }
    },
    {
        deep: true,
    }
)
</script>
