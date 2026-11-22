/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

// This piece of code was orignally written by amirnissim and can be seen here
// http://stackoverflow.com/a/11703018/2694653
// This has been ported to Vanilla.js by GuillaumeLeclerc

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

export default (input) => {
    const _addEventListener = input.addEventListener ? input.addEventListener : input.attachEvent

    function addEventListenerWrapper(type, listener) {
        // Simulate a 'down arrow' keypress on hitting 'return' when no pac suggestion is selected,
        // and then trigger the original listener.
        if (type === 'keydown') {
            const origListener = listener
            listener = function (event) {
                const suggestionSelected =
                    document.getElementsByClassName('pac-item-selected').length > 0
                if (event.which === 13 && !suggestionSelected) {
                    const simulatedEvent = document.createEvent('Event')
                    simulatedEvent.keyCode = 40
                    simulatedEvent.which = 40
                    origListener.apply(input, [simulatedEvent])
                }
                origListener.apply(input, [event])
            }
        }
        _addEventListener.apply(input, [type, listener])
    }

    input.addEventListener = addEventListenerWrapper
    input.attachEvent = addEventListenerWrapper
}
