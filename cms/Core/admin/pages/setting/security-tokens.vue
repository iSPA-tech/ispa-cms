<template>
    <div class="max-w-5xl mx-auto space-y-6" data-testid="security-tokens-page">
        <section class="i-box space-y-1">
            <h3 class="m-0 text-sm font-semibold">{{ t('text.security_tokens.page.title') }}</h3>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.page.description') }}</p>
            <p class="m-0 text-sm text-ink-muted" data-testid="security-tokens-browser-note">
                {{ t('text.security_tokens.page.browser_note') }}
            </p>
        </section>

        <a-tabs v-model:active-key="activeTab" data-testid="security-tokens-tabs">
            <a-tab-pane key="mine" :tab="t('text.security_tokens.tab.mine')">
                <section class="i-box space-y-3">
                    <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.page.mine_intro') }}</p>
                    <token-list mode="mine" />
                </section>
            </a-tab-pane>
            <template v-if="webmaster">
                <a-tab-pane key="all" :tab="t('text.security_tokens.tab.all')">
                    <section v-if="activeTab === 'all'" class="i-box space-y-3">
                        <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.page.all_intro') }}</p>
                        <token-list mode="all" :initial-filters="initialFilters" />
                    </section>
                </a-tab-pane>
                <a-tab-pane key="lifetime" :tab="t('text.security_tokens.tab.lifetime')">
                    <section v-if="activeTab === 'lifetime'" class="i-box">
                        <token-settings-form section="lifetime" />
                    </section>
                </a-tab-pane>
                <a-tab-pane key="cleanup" :tab="t('text.security_tokens.tab.cleanup')">
                    <div v-if="activeTab === 'cleanup'" class="space-y-4">
                        <section class="i-box">
                            <token-settings-form section="cleanup" />
                        </section>
                        <section class="i-box space-y-3" data-testid="revoke-section">
                            <h4 class="m-0 text-sm font-semibold">{{ t('text.security_tokens.page.revoke_title') }}</h4>
                            <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.page.revoke_intro') }}</p>
                            <a-alert v-if="doneNotice" type="success" show-icon closable :message="doneNotice" data-testid="revoke-done" @close="doneNotice = ''" />
                            <div class="flex flex-wrap items-start gap-3">
                                <revoke-all-dialog v-for="a in AUDIENCES" :key="a" scope="audience" :audience="a" @done="onDone" />
                                <revoke-all-dialog scope="all" @done="onDone" />
                            </div>
                        </section>
                    </div>
                </a-tab-pane>
            </template>
        </a-tabs>
    </div>
</template>

<script setup>
/**
 * "Sessions and tokens" (Settings > System). Four tabs: My devices (every operator: the API tokens of their own account),
 * All tokens, Lifetime settings and Cleanup (Webmaster only). Browser sessions are not listed, only API tokens; the page
 * says so. The step-up grant held in memory is dropped when the page closes.
 */
import { computed, defineComponent, onBeforeUnmount, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { useRoute } from 'vue-router'
import { isWebmaster } from '@/hooks/useCan'
import { AUDIENCES, dropGrant } from '@/libs/securityTokens'
import RevokeAllDialog from '../../components/security/revoke-all-dialog.vue'
import TokenList from '../../components/security/token-list.vue'
import TokenSettingsForm from '../../components/security/token-settings-form.vue'

defineComponent({ name: 'SettingSecurityTokens' })

const { t } = useI18n()
const webmaster = computed(() => isWebmaster())
const route = useRoute()
/** Tab and filters asked for in the URL (the my-tasks warnings link here); Webmaster tabs only for a Webmaster. */
const requestedTab = String(route?.query?.tab ?? '')
const activeTab = ref(['all', 'lifetime', 'cleanup'].includes(requestedTab) && isWebmaster() ? requestedTab : 'mine')
const initialFilters = { never: route?.query?.never === '1', legacy: route?.query?.legacy === '1' }
const doneNotice = ref('')

const onDone = (e) => {
    doneNotice.value = t('text.security_tokens.list.revoked_others', { count: e?.revoked ?? 0 })
}

onBeforeUnmount(dropGrant)
</script>
