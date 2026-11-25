<script setup>
// The workflow designer's dotted grid. It lives in the admin shell because module admin code (cms/Core/admin) may
// import only the packages listed in src/config/shared-packages.json, and @vue-flow/background is not one of them:
// a direct import builds locally but fails on a release build ("Rolldown failed to resolve import").
import { Background } from '@vue-flow/background'
</script>

<template>
    <Background :gap="20" :size="1.5" pattern-color="#cbd5e1" />
</template>
