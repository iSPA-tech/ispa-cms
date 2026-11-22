<template>
    <!-- Connected: one short line. -->
    <section
        v-if="hub?.connected"
        class="flex flex-wrap items-center gap-x-3 gap-y-1.5 rounded-xl bg-linear-115/srgb from-brand-700 to-info px-4 py-2.5 text-white"
        data-testid="dashboard-hub-band"
        data-state="connected"
    >
        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-medium">
            <span class="size-2 rounded-full bg-band-ok" aria-hidden="true"></span>
            {{ $t('text.dashboard.band.connected') }}
        </span>
        <span class="min-w-0 flex-1 text-sm text-white/90" data-testid="band-hello">
            <strong v-if="name" class="font-semibold text-white">{{ $t('text.dashboard.band.hello', { name }) }}</strong
            ><template v-if="version">{{ name ? ' · ' : '' }}iSPA CMS {{ version }}</template>
        </span>
        <span class="ml-auto flex flex-wrap gap-x-4 gap-y-1 text-sm font-semibold">
            <router-link
                v-if="webmaster"
                :to="{ name: 'setting-modules' }"
                class="text-white underline underline-offset-3 hover:text-white/85"
                data-testid="band-store"
                >{{ $t('text.modules.install.tab.available') }}</router-link
            >
            <a :href="docsHref" target="_blank" rel="noopener noreferrer" class="text-white underline underline-offset-3 hover:text-white/85" data-testid="band-docs"
                >{{ $t('text.dashboard.band.docs') }} <span aria-hidden="true">↗</span
                ><span class="sr-only">{{ $t('text.menu_editor.open-in-a-new-tab') }}</span></a
            >
        </span>
    </section>

    <!-- Not connected: what is missing, what connecting gives, and the three steps. Only Webmasters can connect, so the
         "one step left" heading, the steps, the actions and the development note are theirs; others see the pill, the
         sentence and the docs link. -->
    <section
        v-else-if="hub"
        class="rounded-xl bg-linear-115/srgb from-brand-700 to-info px-5 py-4 text-white"
        data-testid="dashboard-hub-band"
        data-state="not-connected"
        :aria-labelledby="webmaster ? 'dashboard-hub-band-title' : undefined"
    >
        <span class="inline-flex items-center gap-1.5 rounded-full bg-white/15 px-2.5 py-0.5 text-xs font-medium">
            <span class="size-2 rounded-full bg-band-wait" aria-hidden="true"></span>
            {{ $t('text.dashboard.band.not_connected') }}
        </span>
        <h2 v-if="webmaster" id="dashboard-hub-band-title" class="mt-2 mb-0 text-lg font-semibold leading-snug text-white">
            {{ name ? $t('text.dashboard.band.title', { name }) : $t('text.dashboard.band.title_plain') }}
        </h2>
        <p class="mt-1 mb-0 max-w-3xl text-sm leading-6 text-white/85">{{ $t('text.dashboard.band.intro') }}</p>
        <ol v-if="webmaster" class="mt-3 mb-0 grid list-none grid-cols-[repeat(auto-fit,minmax(220px,1fr))] gap-2.5 p-0">
            <li
                v-for="(step, index) in steps"
                :key="step"
                class="flex gap-2.5 rounded-lg bg-white/10 px-3 py-2.5 text-[13px] leading-5"
                data-testid="band-step"
            >
                <span
                    class="flex size-5.5 shrink-0 items-center justify-center rounded-full bg-white text-xs font-bold text-brand-800"
                    aria-hidden="true"
                    >{{ index + 1 }}</span
                >
                <span>{{ $t(step) }}</span>
            </li>
        </ol>
        <p v-if="webmaster && hub.development" class="mt-2 mb-0 text-xs leading-5 text-white/90" data-testid="band-dev-note">
            {{ $t('text.dashboard.band.dev_note') }}
        </p>
        <div class="mt-3 flex flex-wrap items-center gap-2">
            <router-link
                v-if="webmaster"
                :to="{ name: 'setting-modules', query: { connect: '1' } }"
                class="inline-flex min-h-9 items-center rounded-md bg-white px-3.5 text-sm font-semibold text-brand-800 no-underline hover:bg-white/90"
                data-testid="band-connect"
                >{{ $t('text.dashboard.band.connect') }}</router-link
            >
            <a
                v-if="webmaster && hub.portal_url"
                :href="hub.portal_url"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex min-h-9 items-center rounded-md border border-white/50 px-3.5 text-sm font-semibold text-white no-underline hover:bg-white/10"
                data-testid="band-portal"
                >{{ $t('text.site_connect.portal_link') }} <span class="ml-1" aria-hidden="true">↗</span
                ><span class="sr-only">{{ $t('text.menu_editor.open-in-a-new-tab') }}</span></a
            >
            <a
                :href="docsHref"
                target="_blank"
                rel="noopener noreferrer"
                class="inline-flex min-h-9 items-center px-2 text-sm font-semibold text-white underline underline-offset-3 hover:text-white/85"
                data-testid="band-docs"
                >{{ $t('text.dashboard.band.docs') }} <span class="ml-1" aria-hidden="true">↗</span
                ><span class="sr-only">{{ $t('text.menu_editor.open-in-a-new-tab') }}</span></a
            >
        </div>
    </section>
</template>

<script setup>
/**
 * Dashboard band for the iSPA Hub connection.
 * Not connected: the steps to register in the customer portal and connect (Webmasters only). Connected: one line.
 * Both link the usage docs in the admin language.
 */
import { computed } from 'vue'
import { runtimeConfig } from '@/config/runtime'

const props = defineProps({
    /** `summary.hub`: `{ connected, development, portal_url }`; nothing renders without it. */
    hub: { type: Object, default: null },
    /** Account name for the greeting; without one the band skips the greeting. */
    name: { type: String, default: '' },
    /** iSPA CMS version shown on the connected line. */
    version: { type: String, default: '' },
    /** Only Webmasters can connect: they see the steps, the connect and portal links and the module store. */
    webmaster: { type: Boolean, default: false },
    /** Admin language; the docs open in it. */
    locale: { type: String, default: 'vi' },
})

const steps = ['text.dashboard.band.step1', 'text.dashboard.band.step2', 'text.dashboard.band.step3']

const docsHref = computed(() => `${runtimeConfig().docsUrl}/${props.locale === 'vi' ? 'vi' : 'en'}/docs`)
</script>
