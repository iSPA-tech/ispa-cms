<!--
  Copyright (c) 2025 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<template>
    <div :class="$attrs.class" class="vue-map-container">
        <div ref="vue-map" class="vue-map min-h-full"></div>
        <div class="vue-map-hidden">
            <slot></slot>
        </div>
        <slot name="visible"></slot>
    </div>
</template>

<script>
import bindEvents from '../utils/bindEvents.js'
import { bindProps, getPropsValues } from '../utils/bindProps.js'
import mountableMixin from '../utils/mountableMixin.js'
import TwoWayBindingWrapper from '../utils/TwoWayBindingWrapper.js'
import WatchPrimitiveProperties from '../utils/WatchPrimitiveProperties.js'
import { mappedPropsToVueProps } from './build-component.js'

const props = {
    center: {
        required: true,
        twoWay: true,
        type: Object,
        noBind: true,
    },
    zoom: {
        required: false,
        twoWay: true,
        type: Number,
        noBind: true,
    },
    heading: {
        type: Number,
        twoWay: true,
    },
    mapTypeId: {
        twoWay: true,
        type: String,
    },
    tilt: {
        twoWay: true,
        type: Number,
    },
    options: {
        type: Object,
        default() {
            return {}
        },
    },
}

const events = [
    'bounds_changed',
    'click',
    'dblclick',
    'drag',
    'dragend',
    'dragstart',
    'idle',
    'mousemove',
    'mouseout',
    'mouseover',
    'resize',
    'rightclick',
    'tilesloaded',
]

// Plain Google Maps methods exposed here for convenience
const linkedMethods = ['panBy', 'panTo', 'panToBounds', 'fitBounds'].reduce((all, methodName) => {
    all[methodName] = function () {
        if (this.$mapObject) {
            this.$mapObject[methodName].apply(this.$mapObject, arguments)
        }
    }
    return all
}, {})

// Other convenience methods exposed by Vue Google Maps
const customMethods = {
    resize() {
        if (this.$mapObject) {
            google.maps.event.trigger(this.$mapObject, 'resize')
        }
    },
    resizePreserveCenter() {
        if (!this.$mapObject) {
            return
        }

        const oldCenter = this.$mapObject.getCenter()
        google.maps.event.trigger(this.$mapObject, 'resize')
        this.$mapObject.setCenter(oldCenter)
    },
}

export default {
    mixins: [mountableMixin],
    props: mappedPropsToVueProps({
        ...props,
        ...events.reduce(
            (obj, eventName) => ({
                ...obj,
                [`on${eventName.charAt(0).toUpperCase()}${eventName.slice(1)}`.replace(
                    /[-_]+(.)?/g,
                    (_, c) => (c ? c.toUpperCase() : '')
                )]: Function,
            }),
            {}
        ),
    }),
    inheritAttrs: false,

    provide() {
        this.$mapPromise = new Promise((resolve, reject) => {
            this.$mapPromiseDeferred = { resolve, reject }
        })
        return {
            $mapPromise: this.$mapPromise,
        }
    },
    emits: ['center_changed', 'bounds_changed', 'map_changed'],
    computed: {
        finalLat() {
            return this.center && typeof this.center.lat === 'function'
                ? this.center.lat()
                : this.center.lat
        },
        finalLng() {
            return this.center && typeof this.center.lng === 'function'
                ? this.center.lng()
                : this.center.lng
        },
        finalLatLng() {
            return { lat: this.finalLat, lng: this.finalLng }
        },
    },
    watch: {
        //  Watch longitude and latitude properties center object, then re-render the map with new coordinates
        props: {
            handler: function (val) {
                bindProps(this, this.$mapObject, val)
            },
            deep: true,
        },
    },
    mounted() {
        const getPlace = async () => {
            let address = {}
            const geocoder = new google.maps.Geocoder()
            const latLng = new google.maps.LatLng(this.finalLatLng.lat, this.finalLatLng.lng)

            await geocoder.geocode({ location: latLng }, (results, status) => {
                if (status === 'OK' && results[0]) {
                    address = results[0]
                } else {
                    address.error = 'Address not found'
                }
            })

            return address
        }

        return this.$gmapApiPromiseLazy()
            .then(async () => {
                // getting the DOM element where to create the map
                const element = this.$refs['vue-map']

                // creating the map
                const options = {
                    ...this.options,
                    ...getPropsValues(this, props),
                }
                delete options.options
                this.$mapObject = await new google.maps.Map(element, options)

                // binding properties (two and one way)
                bindProps(this, this.$mapObject, props)
                // binding events
                bindEvents(this, this.$mapObject, events)

                // manually trigger center and zoom
                TwoWayBindingWrapper((increment, decrement, shouldUpdate) => {
                    this.$mapObject.addListener('center_changed', () => {
                        if (shouldUpdate()) {
                            this.$emit('center_changed', this.$mapObject.getCenter())
                        }
                        decrement()
                    })

                    const updateCenter = () => {
                        increment()
                        this.$mapObject.setCenter(this.finalLatLng)
                    }

                    WatchPrimitiveProperties(this, ['finalLat', 'finalLng'], updateCenter)
                })
                this.$mapObject.addListener('bounds_changed', () => {
                    this.$emit('bounds_changed', this.$mapObject.getBounds())
                })
                this.$mapObject.addListener('dragend', async () => {
                    const address = await getPlace()

                    this.$emit('map_changed', address)
                })

                this.$mapPromiseDeferred.resolve(this.$mapObject)

                return this.$mapObject
            })
            .catch((error) => {
                console.error('VueMap error:', error)
                throw error
            })
    },
    methods: {
        ...customMethods,
        ...linkedMethods,
    },
}
</script>
