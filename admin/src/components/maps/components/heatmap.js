/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

import buildComponent from './build-component.js'

const props = {
    options: {
        type: Object,
        twoWay: false,
        default: () => {},
    },
    data: {
        type: Array,
        twoWay: true,
    },
}

const events = []

export default buildComponent({
    mappedProps: props,
    name: 'heatmap',
    ctr: () => google.maps.visualization.HeatmapLayer,
    events,
})
