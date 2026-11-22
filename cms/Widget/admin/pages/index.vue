<!--
  Copyright (c) 2026 iSPA Team - team@ispa.io.
  SPDX-License-Identifier: AGPL-3.0-only
  This file is part of iSPA CMS, free software under the GNU Affero General Public License v3.0 only.
  See the LICENSE file in the project root. This program comes with ABSOLUTELY NO WARRANTY.
-->

<!--
    Parent route shell only -- mirrors src/modules/inventory/admin/pages/inventory.vue
    and src/modules/digital-library/admin/pages/digital-library.vue. The parent route
    always redirects to site-layout-placements (see route-config.js), so this never
    renders standalone content of its own; it only hosts the matched child via
    <router-view />. Real content lives in the 4 child pages.
-->
<template>
    <router-view />
</template>
