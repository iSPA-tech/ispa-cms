<template>
    <div class="space-y-3" data-testid="user-tokens-tab">
        <section v-if="!allowed" class="i-box" role="alert" data-testid="user-tokens-not-allowed">
            <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.page.not_allowed') }}</p>
        </section>
        <template v-else>
            <p class="m-0 text-sm text-ink-muted">{{ t('text.security_tokens.user_tab.intro') }}</p>
            <div class="flex justify-end">
                <revoke-all-dialog scope="user" :user-uuid="uuid" :count="total" @done="onDone" />
            </div>
            <token-list ref="list" mode="user" :user-uuid="uuid" @loaded="(e) => (total = e.total)" />
        </template>
    </div>
</template>

<script setup>
/**
 * Tab of the user page (`profileTabs`, target `user`): the API tokens of that account and "revoke all of this user".
 * Webmaster only, like the API behind it; anyone else sees a short note. The tab body receives only `uuid`.
 */
import { computed, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import { isWebmaster } from '@/hooks/useCan'
import RevokeAllDialog from './revoke-all-dialog.vue'
import TokenList from './token-list.vue'

defineProps({
    /** Uuid of the account whose tokens are listed. */
    uuid: { type: String, required: true },
})

const { t } = useI18n()
const allowed = computed(() => isWebmaster())
const total = ref(0)
const list = ref(null)

/** Refresh the list after a bulk revoke. */
const onDone = () => list.value?.load()
</script>
