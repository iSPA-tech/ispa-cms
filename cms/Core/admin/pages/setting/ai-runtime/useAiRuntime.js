import { message } from 'ant-design-vue'
import { computed, inject, provide, reactive, ref } from 'vue'
import { useI18n } from 'vue-i18n'
import ajax from '@/ajax'

const INJECTION_KEY = Symbol('ai-runtime')

// Last 12 UTC months, newest first.
const buildMonthOptions = () => {
    const now = new Date()
    return Array.from({ length: 12 }, (_, i) => {
        const d = new Date(Date.UTC(now.getUTCFullYear(), now.getUTCMonth() - i, 1))
        const value = `${d.getUTCFullYear()}-${String(d.getUTCMonth() + 1).padStart(2, '0')}`
        return { value, label: value }
    })
}

export const formatInt = (n) => Number(n || 0).toLocaleString('vi-VN')
export const formatUsd = (n) => `$${Number(n || 0).toFixed(4)}`
export const formatUsdShort = (n) => `$${Number(n || 0).toFixed(2)}`
// Latency in seconds with one decimal and a comma separator ("10,9 s").
export const formatSeconds = (totalMs, count) =>
    count ? `${(totalMs / count / 1000).toFixed(1).replace('.', ',')} s` : '—'
export const percent = (part, total) =>
    total ? Math.round((Number(part || 0) / Number(total)) * 100) : 0
export const requestError = (error, fallback) =>
    error?.response?.data?.message || error?.message || fallback

export const splitModels = (value) =>
    String(value || '')
        .split(',')
        .map((item) => item.trim())
        .filter(Boolean)

/**
 * Creates the shared AI Runtime state and provides it to descendants.
 * Every backend field that may be missing is read defensively.
 */
export function provideAiRuntime() {
    const { t } = useI18n()
    const monthOptions = buildMonthOptions()
    const currentMonth = monthOptions[0].value

    const overview = ref({ drivers: [], providers: [], tasks: [], pricing: {}, budget: {} })
    const overviewLoading = ref(false)
    const overviewError = ref('')
    const usage = ref(null)
    const currentUsage = ref(null)
    const usageLoading = ref(false)
    const usageError = ref('')
    const usageMonth = ref(currentMonth)
    const activeTab = ref('overview')
    const killBusy = ref(false)
    const editingTaskKey = ref('')
    const editingProviderKey = ref('')
    const testingKey = ref('')
    const testResults = reactive({})

    const paused = computed(() => Boolean(overview.value.budget?.kill_switch))
    const pricing = computed(() => overview.value.pricing || {})
    const drivers = computed(() => overview.value.drivers || [])

    const taskRows = computed(() => {
        const byTask = new Map((usage.value?.by_task || []).map((r) => [r.task_key, r]))
        return (overview.value.tasks || []).map((row) => {
            const u = byTask.get(row.task)
            const effective =
                row.effective ||
                (row.provider || row.model
                    ? { provider: row.provider, model: row.model, source: 'task' }
                    : { provider: '', model: '', source: 'unknown' })
            return {
                ...row,
                label: row.label || row.task,
                configurable: row.configurable !== false,
                effective,
                calls: Number(u?.request_count || 0),
                failed: Number(u?.failed_count || 0),
                cost: Number(u?.estimated_cost_usd || 0),
                usage: u || null,
            }
        })
    })
    const taskLabel = (key) => taskRows.value.find((r) => r.task === key)?.label || key

    const usageSpentPercent = computed(() => {
        const cap = Number(usage.value?.monthly_budget_usd || 0)
        if (!usage.value || cap <= 0) return null
        return Math.round((Number(usage.value.estimated_spent_usd || 0) / cap) * 100)
    })

    const spend = computed(() => {
        const u = currentUsage.value
        if (!u) return null
        const cap = Number(u.monthly_budget_usd ?? overview.value.budget?.monthly_usd ?? 0)
        const spent = Number(u.estimated_spent_usd || 0)
        const pct = cap > 0 ? Math.round((spent / cap) * 100) : null
        const warnAt = Number(overview.value.budget?.warning_percent || 80)
        const level = pct === null ? 'ok' : pct >= 100 ? 'danger' : pct >= warnAt ? 'warning' : 'ok'
        const monthNo = Number(String(currentMonth).slice(5))
        const text =
            cap > 0
                ? t('text.ai_runtime.spend.capped', {
                      month: monthNo,
                      spent: formatUsdShort(spent),
                      cap: formatUsdShort(cap),
                      percent: pct,
                  })
                : t('text.ai_runtime.spend.uncapped', { month: monthNo, spent: formatUsdShort(spent) })
        return { text, level }
    })

    const applyOverview = (data) => {
        if (data) overview.value = data
    }

    const loadOverview = async () => {
        overviewLoading.value = true
        overviewError.value = ''
        try {
            const res = await ajax.get('ai-runtime')
            applyOverview(res.data?.data)
        } catch (error) {
            overviewError.value = requestError(error, t('text.ai_runtime.load.overview_failed'))
        } finally {
            overviewLoading.value = false
        }
    }

    const loadUsage = async (month) => {
        const target = typeof month === 'string' ? month : usageMonth.value
        usageLoading.value = true
        usageError.value = ''
        try {
            const res = await ajax.get('ai-runtime/usage', { params: { month: target } })
            usage.value = res.data?.data || null
            if (target === currentMonth) currentUsage.value = usage.value
        } catch (error) {
            usage.value = null
            usageError.value = requestError(error, t('text.ai_runtime.load.usage_failed'))
        } finally {
            usageLoading.value = false
        }
    }

    const loadAll = async () => {
        await Promise.all([loadOverview(), loadUsage(currentMonth)])
    }

    // Refreshes the header chip (current month) and, if different, the selected month.
    const refreshUsage = async () => {
        const jobs = [loadUsage(usageMonth.value)]
        if (usageMonth.value !== currentMonth) {
            jobs.push(
                ajax
                    .get('ai-runtime/usage', { params: { month: currentMonth } })
                    .then((res) => {
                        currentUsage.value = res.data?.data || null
                    })
                    .catch(() => {})
            )
        }
        await Promise.all(jobs)
    }

    // Generic PUT helper: returns { ok, error } and never throws.
    const put = async (url, body, okText, failText) => {
        try {
            const res = await ajax.put(url, body)
            applyOverview(res.data?.data)
            if (okText) message.success(okText)
            return { ok: true }
        } catch (error) {
            const text = requestError(error, failText)
            message.error(text)
            return { ok: false, error: text }
        }
    }

    const setKillSwitch = async (value) => {
        if (killBusy.value) return
        killBusy.value = true
        try {
            await put(
                'ai-runtime/budget',
                { kill_switch: Boolean(value) },
                t(value ? 'text.ai_runtime.kill.paused' : 'text.ai_runtime.kill.resumed'),
                t(value ? 'text.ai_runtime.kill.pause_failed' : 'text.ai_runtime.kill.resume_failed')
            )
        } finally {
            killBusy.value = false
        }
    }

    // Spike fields are sent only when the backend reported them (spike_known), so an older
    // backend is never fed values it did not offer.
    const saveBudget = async ({ monthly_usd, warning_percent, spike_known, spike_enabled, spike_multiplier, spike_min_usd }) => {
        const body = {
            monthly_usd: Number(monthly_usd) || 0,
            warning_percent: Number(warning_percent) || 80,
        }
        if (spike_known) {
            body.spike_enabled = Boolean(spike_enabled)
            body.spike_multiplier = Number(spike_multiplier) || 3
            body.spike_min_usd = Number(spike_min_usd) || 0
        }
        const result = await put(
            'ai-runtime/budget',
            body,
            t('text.ai_runtime.budget.saved'),
            t('text.ai_runtime.budget.save_failed')
        )
        if (result.ok) await refreshUsage()
        return result
    }

    // Only the edited task row is sent; the backend only touches rows it receives.
    const saveTask = (form) =>
        put(
            'ai-runtime/tasks',
            {
                tasks: [
                    {
                        task: form.task,
                        provider: form.provider || '',
                        model: form.model || '',
                        temperature:
                            form.temperature === null || form.temperature === undefined
                                ? null
                                : form.temperature,
                        reasoning_effort: form.reasoning_effort || '',
                        fallback_models: (form.fallback_list || []).join(','),
                    },
                ],
            },
            t('text.ai_runtime.tasks.saved'),
            t('text.ai_runtime.tasks.save_failed')
        )

    const providerPayload = (row) => ({
        key: row.key,
        model: row.model || null,
        base_uri: row.base_uri || null,
        api_key: row.api_key || null,
        clear_api_key: row.clear_api_key || false,
    })

    // Only the edited provider is sent; default_provider is omitted so it stays untouched.
    const saveProvider = (form) =>
        put(
            'ai-runtime/providers',
            { providers: [providerPayload(form)] },
            t('text.ai_runtime.providers.saved'),
            t('text.ai_runtime.providers.save_failed')
        )

    // The endpoint requires a non-empty providers list and overwrites model/base_uri of every
    // row it receives, so the stored values are echoed back unchanged (api_key stays null = keep).
    const saveDefaultProvider = (value) =>
        put(
            'ai-runtime/providers',
            {
                default_provider: value || '',
                providers: (overview.value.providers || []).map((p) => providerPayload({ ...p, api_key: '' })),
            },
            t('text.ai_runtime.providers.default_saved'),
            t('text.ai_runtime.providers.default_save_failed')
        )

    const testProvider = async (row) => {
        testingKey.value = row.key
        delete testResults[row.key]
        try {
            const res = await ajax.post('ai-runtime/test', {
                provider: row.key,
                model: row.model || row.env_model || null,
            })
            const data = res.data?.data || {}
            testResults[row.key] = {
                ok: Boolean(data.ok),
                latency_ms: data.latency_ms,
                error: (data.errors || []).join(' · ') || t('text.ai_runtime.providers.connection_failed'),
            }
        } catch (error) {
            testResults[row.key] = { ok: false, error: requestError(error, t('text.ai_runtime.providers.connection_failed')) }
        } finally {
            testingKey.value = ''
        }
    }

    // Recent calls of one task; returns { ok, data, error } and never throws.
    const fetchTaskCalls = async (task) => {
        try {
            const res = await ajax.get('ai-runtime/calls', { params: { task, limit: 20 } })
            return { ok: true, data: res.data?.data || {} }
        } catch (error) {
            return { ok: false, error: requestError(error, t('text.ai_runtime.load.calls_failed')) }
        }
    }

    const openTask = (key) => {
        activeTab.value = 'tasks'
        editingTaskKey.value = key
    }

    const ctx = {
        monthOptions, currentMonth, overview, overviewLoading, overviewError, usage, currentUsage,
        usageLoading, usageError, usageMonth, activeTab, killBusy, editingTaskKey, editingProviderKey,
        testingKey, testResults, paused, pricing, drivers, taskRows, taskLabel, usageSpentPercent, spend,
        loadOverview, loadUsage, loadAll, setKillSwitch, saveBudget, saveTask, saveProvider,
        saveDefaultProvider, testProvider, openTask, fetchTaskCalls,
    }
    provide(INJECTION_KEY, ctx)
    return ctx
}

export const useAiRuntime = () => inject(INJECTION_KEY)

// "12/05 14:03:22" local time; empty for missing/invalid input.
export const formatDateTime = (iso) => {
    const d = new Date(iso)
    if (!iso || Number.isNaN(d.getTime())) return '—'
    const p = (n) => String(n).padStart(2, '0')
    return `${p(d.getDate())}/${p(d.getMonth() + 1)} ${p(d.getHours())}:${p(d.getMinutes())}:${p(d.getSeconds())}`
}
// Single-call latency in seconds ("10,9 s").
export const formatCallSeconds = (ms) =>
    ms === null || ms === undefined ? '—' : `${(Number(ms) / 1000).toFixed(1).replace('.', ',')} s`
