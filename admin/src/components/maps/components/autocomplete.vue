<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <input
        ref="mapInput"
        v-model="innerValue"
        :class="[
            'm-0 relative px-1 py-1 border shadow-xs border-gray-300/80',
            'focus:ring-1 focus:ring-blue-500/50 focus:border-blue-500 focus:outline-hidden',
            'focus:shadow-outline-blue',
        ]"
        v-bind="$attrs"
        v-on="$attrs"
    />
</template>

<script>
import { bindProps, getPropsValues } from '../utils/bindProps.js'
import downArrowSimulator from '../utils/simulateArrowDown.js'
import { mappedPropsToVueProps } from './build-component'
import { defineComponent, ref, watch } from 'vue'

const mappedProps = {
    bounds: {
        type: Object,
    },
    componentRestrictions: {
        type: Object,
        // Do not bind -- must check for undefined
        // in the property
        noBind: true,
        default: {
            country: ['vn'],
        },
    },
    types: {
        type: Array,
        default: function () {
            return []
        },
    },
}

const props = {
    selectFirstOnEnter: {
        required: false,
        type: Boolean,
        default: false,
    },
    options: {
        type: Object,
    },
    modelValue: {
        type: String,
        default: '',
    },
}

export default defineComponent({
    name: 'Autocomplete',
    props: {
        ...mappedPropsToVueProps(mappedProps),
        ...props,
    },
    setup(props, { emit }) {
        const mapInput = ref(null)
        const innerValue = ref(props.modelValue)

        watch(
            () => props.modelValue,
            (val) => {
                innerValue.value = val
            }
        )

        watch(
            () => innerValue.value,
            (val) => {
                emit('update:modelValue', val)
            }
        )

        return {
            innerValue,
            mapInput,
        }
    },
    mounted() {
        this.$gmapApiPromiseLazy().then(() => {
            if (this.selectFirstOnEnter) {
                downArrowSimulator(this.$refs.input.value)
            }

            if (typeof google.maps.places.Autocomplete !== 'function') {
                throw new Error(
                    "google.maps.places.Autocomplete is undefined. Did you add 'places' to libraries when loading Google Maps?"
                )
            }

            /* eslint-disable no-unused-vars */
            const finalOptions = {
                ...getPropsValues(this, mappedProps),
                ...this.options,
            }

            this.$autocomplete = new google.maps.places.Autocomplete(
                this.$refs.mapInput,
                finalOptions
            )
            bindProps(this, this.$autocomplete, mappedProps)

            this.$watch('componentRestrictions', (v) => {
                if (v !== undefined) {
                    this.$autocomplete.setComponentRestrictions(v)
                }
            })

            // Not using `bindEvents` because we also want
            // to return the result of `getPlace()`
            this.$autocomplete.addListener('place_changed', () => {
                this.$emit('place_changed', this.$autocomplete.getPlace())
            })
        })
    },
})
</script>
