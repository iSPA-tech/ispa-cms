/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

/**
 * @class MapElementMixin
 *
 * Extends components to include the following fields:
 *
 * @property $map        The Google map (valid only after the promise returns)
 *
 *
 * */

/*
 * Copyright (c) 2025 iSPA Team - team@ispa.io.
 * SPDX-License-Identifier: AGPL-3.0-only
 * This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
 * See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
 */

export default {
    inject: {
        $mapPromise: { default: 'abcdef' },
    },

    provide() {
        // Note: although this mixin is not "providing" anything,
        // components' expect the `$map` property to be present on the component.
        // In order for that to happen, this mixin must intercept the $mapPromise
        // .then(() =>) first before its component does so.
        //
        // Since a provide() on a mixin is executed before a provide() on the
        // component, putting this code in provide() ensures that the $map is
        // already set by the time the
        // component's provide() is called.
        this.$mapPromise.then((map) => {
            this.$map = map
        })

        return {}
    },
}
