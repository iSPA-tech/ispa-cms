/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import buildComponent from './build-component.js'

const props = {
    draggable: {
        type: Boolean,
    },
    editable: {
        type: Boolean,
    },
    options: {
        twoWay: false,
        type: Object,
    },
    path: {
        type: Array,
        twoWay: true,
    },
}

const events = [
    'click',
    'dblclick',
    'drag',
    'dragend',
    'dragstart',
    'mousedown',
    'mousemove',
    'mouseout',
    'mouseover',
    'mouseup',
    'rightclick',
]

export default buildComponent({
    mappedProps: props,
    props: {
        deepWatch: {
            type: Boolean,
            default: false,
        },
    },
    events,

    name: 'polyline',
    ctr: () => google.maps.Polyline,

    afterCreate() {
        let clearEvents = () => {}

        this.$watch(
            'path',
            (path) => {
                if (path) {
                    clearEvents()

                    this.$polylineObject.setPath(path)

                    const mvcPath = this.$polylineObject.getPath()
                    const eventListeners = []

                    const updatePaths = () => {
                        this.$emit('path_changed', this.$polylineObject.getPath())
                    }

                    eventListeners.push([mvcPath, mvcPath.addListener('insert_at', updatePaths)])
                    eventListeners.push([mvcPath, mvcPath.addListener('remove_at', updatePaths)])
                    eventListeners.push([mvcPath, mvcPath.addListener('set_at', updatePaths)])

                    clearEvents = () => {
                        eventListeners.map(
                            (
                                [obj, listenerHandle] // eslint-disable-line no-unused-vars
                            ) => google.maps.event.removeListener(listenerHandle)
                        )
                    }
                }
            },
            {
                deep: this.deepWatch,
                immediate: true,
            }
        )
    },
})
