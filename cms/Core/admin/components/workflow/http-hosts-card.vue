<template>
    <section class="i-box space-y-3" data-testid="workflow-http-hosts">
        <div class="space-y-1">
            <h3 class="m-0 text-sm font-semibold">{{ t('text.workflow_http_hosts.title') }}</h3>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.workflow_http_hosts.intro') }}</p>
        </div>

        <a-alert v-if="notice" type="success" show-icon closable :message="notice" @close="notice = ''" />
        <a-alert v-if="actionError" type="error" show-icon closable :message="actionError" @close="actionError = ''" />

        <a-skeleton v-if="loading && !loaded" active :paragraph="{ rows: 1 }" />
        <a-alert v-else-if="loadError" type="error" show-icon :message="t('text.workflow_http_hosts.load_failed')">
            <template #action>
                <i-button size="sm" variant="default" @click="load">{{ t('text.workflow_http_hosts.retry') }}</i-button>
            </template>
        </a-alert>
        <form v-else class="space-y-2" @submit.prevent="save">
            <label for="wf-http-hosts" class="block text-sm font-medium">{{ t('text.workflow_http_hosts.label') }}</label>
            <a-select
                id="wf-http-hosts"
                v-model:value="hosts"
                mode="tags"
                class="w-full"
                :token-separators="[',', ' ']"
                :open="false"
                :placeholder="t('text.workflow_http_hosts.placeholder')"
                data-testid="wf-http-hosts-input"
            />
            <p class="m-0 text-xs text-ink-muted">{{ t('text.workflow_http_hosts.help') }}</p>
            <p v-if="!hosts.length && !serverHosts.length" class="m-0 text-xs text-ink-muted" data-testid="wf-http-hosts-empty">
                {{ t('text.workflow_http_hosts.empty') }}
            </p>
            <p v-for="message in fieldErrors" :key="message" class="m-0 text-xs text-danger-ink">{{ message }}</p>
            <div v-if="serverHosts.length" class="space-y-1">
                <p class="m-0 text-xs text-ink-muted">{{ t('text.workflow_http_hosts.server_list') }}</p>
                <div class="flex flex-wrap gap-1">
                    <a-tag v-for="host in serverHosts" :key="host">{{ host }}</a-tag>
                </div>
            </div>
            <div class="flex justify-end">
                <i-button variant="primary" native-type="submit" :disabled="saving" :loading="saving" data-testid="wf-http-hosts-save">
                    {{ t('text.workflow_http_hosts.save') }}
                </i-button>
            </div>
        </form>
    </section>
</template>

<script setup>
/**
 * "Websites workflows may contact" (Webmaster only; shown on Settings > Workflow credentials). Edits the site-wide
 * outbound host list used by http_request, rss_read, sitemap_read and media_import. Hosts set on the server are shown
 * read-only.
 */
import { defineComponent, onMounted, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'

defineComponent({ name: 'WorkflowHttpHostsCard' })

const { t } = useI18n()
const URL = 'utilities/workflow/http-hosts'
const quiet = { silent: true }

const hosts = ref([])
const serverHosts = ref([])
const loading = ref(false)
const loaded = ref(false)
const loadError = ref(false)
const saving = ref(false)
const notice = ref('')
const actionError = ref('')
const fieldErrors = ref([])

function apply(data) {
    hosts.value = Array.isArray(data?.hosts) ? [...data.hosts] : []
    serverHosts.value = Array.isArray(data?.server_hosts) ? data.server_hosts : []
}

async function load() {
    if (loading.value) return
    loading.value = true
    loadError.value = false
    try {
        apply((await ajax.get(URL, quiet)).data?.data)
        loaded.value = true
    } catch (e) {
        loadError.value = true
    } finally {
        loading.value = false
    }
}

async function save() {
    if (saving.value) return
    saving.value = true
    notice.value = ''
    actionError.value = ''
    fieldErrors.value = []
    try {
        apply((await ajax.put(URL, { hosts: hosts.value }, quiet)).data?.data)
        notice.value = t('text.workflow_http_hosts.saved')
        saving.value = false
    } catch (e) {
        const errors = e?.response?.data?.errors || {}
        fieldErrors.value = Object.entries(errors).map(([key, value]) => {
            const index = Number(String(key).split('.')[1])
            const host = Number.isInteger(index) ? hosts.value[index] : ''
            return `${host ? host + ': ' : ''}${t('text.workflow_http_hosts.invalid')}`
        })
        if (!fieldErrors.value.length) actionError.value = t('text.workflow_http_hosts.save_failed')
        saving.value = false
    }
}

onMounted(load)
</script>
