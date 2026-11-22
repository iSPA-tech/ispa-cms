/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import buildComponent from './build-component'

const props = {
    center: {
        type: Object,
        twoWay: true,
        required: true,
    },
    radius: {
        type: Number,
        twoWay: true,
    },
    draggable: {
        type: Boolean,
        default: false,
    },
    editable: {
        type: Boolean,
        default: false,
    },
    options: {
        type: Object,
        twoWay: false,
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
    name: 'circle',
    ctr: () => google.maps.Circle,
    events,
})
