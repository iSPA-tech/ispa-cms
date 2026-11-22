<template>
    <component
        :is="resolvedTag"
        :aria-disabled="isDisabled ? 'true' : undefined"
        :aria-label="resolvedAriaLabel"
        :class="resolvedClass"
        :tabindex="isDisabled && !isButton ? -1 : undefined"
        :title="resolvedTitle"
        v-bind="{ ...elementBindings, ...attrsWithoutClass }"
        @click="handleClick"
    >
        <span v-if="loading" :class="[iconClass, spinnerClass]" aria-hidden="true" />
        <slot v-else-if="hasIconSlot" name="icon" />
        <slot name="left" />
        <slot />
        <slot name="right" />
    </component>
</template>

<script setup>
import { computed, defineComponent, useAttrs, useSlots } from 'vue'
import { RouterLink } from 'vue-router'

defineComponent({
    name: 'IButton',
})

// Emit click events to parent consumers.
const emit = defineEmits(['click'])

// Public props to control button appearance, behavior, and routing targets.
const props = defineProps({
    variant: {
        type: String,
        default: 'default',
    },
    shape: {
        type: String,
        default: 'default',
    },
    size: {
        type: String,
        default: 'sm',
    },
    loading: {
        type: Boolean,
        default: false,
    },
    disabled: {
        type: Boolean,
        default: false,
    },
    block: {
        type: Boolean,
        default: false,
    },
    as: {
        type: String,
        default: 'button',
    },
    to: {
        type: [String, Object, Array],
        default: null,
    },
    href: {
        type: String,
        default: '',
    },
    nativeType: {
        type: String,
        default: 'button',
    },
    ariaLabel: {
        type: String,
        default: '',
    },
    title: {
        type: String,
        default: '',
    },
})

// Raw attributes forwarded from the parent component.
const attrs = useAttrs()
const slots = useSlots()

// Disabled when explicitly disabled or while loading.
const isDisabled = computed(() => props.disabled || props.loading)
// True when rendering a native button element.
// A `to` target makes it a router link even when `as` stays at its default: a plain <button> silently ignores `to`
// (Webmail "Service plans" page, 2026-10-27), and pages pass `:to` without `as` throughout.
const resolvedAs = computed(() => (props.as === 'button' && props.to ? 'router-link' : props.as))
const isButton = computed(() => resolvedAs.value === 'button')
const hasIconSlot = computed(() => Boolean(slots.icon))

// Base utility classes shared across all variants.
const baseClass =
    'inline-flex items-center justify-center gap-1.5 font-semibold inset-ring transition-all'
const iconClass = 'i-button__icon'

// Padding/size classes by size variant.
const sizeClasses = {
    xs: 'px-2 py-[5px] text-xs',
    sm: 'px-2.5 py-1.5 text-xs',
    md: 'px-3 py-2 text-sm',
    lg: 'px-3.5 py-2.5 text-sm',
}

// Shape classes for corners.
const shapeClasses = {
    flat: 'rounded-none',
    default: 'rounded-md',
    circle: 'rounded-full',
}

// Brand/neutral token variants (no dark: variants needed, tokens swap in dark mode).
const primaryClasses = 'bg-primary text-on-primary hover:bg-primary-hover inset-ring-transparent'
const ghostClasses =
    'bg-transparent text-ink-muted hover:bg-surface-sunken hover:text-ink inset-ring-transparent'
// Semantic variants on design tokens (soft fill + ink text); raw colour names below stay for explicit legacy use.
const neutralClasses = 'bg-surface text-ink inset-ring-border-strong hover:bg-surface-hover'

// Enabled-state color classes by variant.
const variantEnabledClasses = {
    primary: primaryClasses,
    ghost: ghostClasses,
    neutral: neutralClasses,
    'tone-success': 'bg-success-soft text-success-ink inset-ring-transparent hover:opacity-90',
    'tone-warning': 'bg-warning-soft text-warning-ink inset-ring-transparent hover:opacity-90',
    'tone-danger': 'bg-danger-soft text-danger-ink inset-ring-transparent hover:opacity-90',
    'tone-info': 'bg-info-soft text-info-ink inset-ring-transparent hover:opacity-90',
    gray:
        'bg-gray-50 text-gray-600 inset-ring-gray-500/10 hover:bg-gray-100 ' +
        'dark:bg-gray-400/10 dark:text-gray-400 dark:inset-ring-gray-400/20 dark:hover:bg-gray-400/20',
    red:
        'bg-red-50 text-red-700 inset-ring-red-600/10 hover:bg-red-100 ' +
        'dark:bg-red-400/10 dark:text-red-400 dark:inset-ring-red-400/20 dark:hover:bg-red-400/20',
    yellow:
        'bg-yellow-50 text-yellow-800 inset-ring-yellow-600/20 hover:bg-yellow-100 ' +
        'dark:bg-yellow-400/10 dark:text-yellow-500 dark:inset-ring-yellow-400/20 dark:hover:bg-yellow-400/20',
    green:
        'bg-green-50 text-green-700 inset-ring-green-600/20 hover:bg-green-100 ' +
        'dark:bg-green-400/10 dark:text-green-400 dark:inset-ring-green-400/20 dark:hover:bg-green-400/20',
    blue:
        'bg-blue-50 text-blue-700 inset-ring-blue-700/10 hover:bg-blue-100 ' +
        'dark:bg-blue-400/10 dark:text-blue-400 dark:inset-ring-blue-400/30 dark:hover:bg-blue-400/20',
    indigo:
        'bg-indigo-50 text-indigo-700 inset-ring-indigo-700/10 hover:bg-indigo-100 ' +
        'dark:bg-indigo-400/10 dark:text-indigo-400 dark:inset-ring-indigo-400/30 dark:hover:bg-indigo-400/20',
    purple:
        'bg-purple-50 text-purple-700 inset-ring-purple-700/10 hover:bg-purple-100 ' +
        'dark:bg-purple-400/10 dark:text-purple-400 dark:inset-ring-purple-400/30 dark:hover:bg-purple-400/20',
    pink:
        'bg-pink-50 text-pink-700 inset-ring-pink-700/10 hover:bg-pink-100 ' +
        'dark:bg-pink-400/10 dark:text-pink-400 dark:inset-ring-pink-400/20 dark:hover:bg-pink-400/20',
}

// Disabled-state color classes by variant.
const variantDisabledClasses = {
    primary: 'bg-primary text-on-primary inset-ring-transparent',
    ghost: 'bg-transparent text-ink-muted inset-ring-transparent',
    neutral: 'bg-surface text-ink-faint inset-ring-border',
    'tone-success': 'bg-success-soft text-success-ink inset-ring-transparent',
    'tone-warning': 'bg-warning-soft text-warning-ink inset-ring-transparent',
    'tone-danger': 'bg-danger-soft text-danger-ink inset-ring-transparent',
    'tone-info': 'bg-info-soft text-info-ink inset-ring-transparent',
    gray:
        'bg-gray-50 text-gray-600 inset-ring-gray-500/10 ' +
        'dark:bg-gray-400/10 dark:text-gray-400 dark:inset-ring-gray-400/20',
    red:
        'bg-red-50 text-red-700 inset-ring-red-600/10 ' +
        'dark:bg-red-400/10 dark:text-red-400 dark:inset-ring-red-400/20',
    yellow:
        'bg-yellow-50 text-yellow-800 inset-ring-yellow-600/20 ' +
        'dark:bg-yellow-400/10 dark:text-yellow-500 dark:inset-ring-yellow-400/20',
    green:
        'bg-green-50 text-green-700 inset-ring-green-600/20 ' +
        'dark:bg-green-400/10 dark:text-green-400 dark:inset-ring-green-400/20',
    blue:
        'bg-blue-50 text-blue-700 inset-ring-blue-700/10 ' +
        'dark:bg-blue-400/10 dark:text-blue-400 dark:inset-ring-blue-400/30',
    indigo:
        'bg-indigo-50 text-indigo-700 inset-ring-indigo-700/10 ' +
        'dark:bg-indigo-400/10 dark:text-indigo-400 dark:inset-ring-indigo-400/30',
    purple:
        'bg-purple-50 text-purple-700 inset-ring-purple-700/10 ' +
        'dark:bg-purple-400/10 dark:text-purple-400 dark:inset-ring-purple-400/30',
    pink:
        'bg-pink-50 text-pink-700 inset-ring-pink-700/10 ' +
        'dark:bg-pink-400/10 dark:text-pink-400 dark:inset-ring-pink-400/20',
}

// Full class list based on props and attrs.
const resolvedClass = computed(() => {
    const variantKeyMap = {
        default: 'neutral',
        muted: 'ghost',
        secondary: 'ghost',
        success: 'tone-success',
        warning: 'tone-warning',
        danger: 'tone-danger',
        info: 'tone-info',
    }
    const resolvedVariantKey = variantKeyMap[props.variant] || props.variant
    const variants = isDisabled.value ? variantDisabledClasses : variantEnabledClasses
    const resolvedVariant = variants[resolvedVariantKey] || variants.gray
    const resolvedSize = sizeClasses[props.size] || sizeClasses.sm
    const resolvedShape = shapeClasses[props.shape] || shapeClasses.default

    return [
        baseClass,
        resolvedVariant,
        resolvedSize,
        resolvedShape,
        props.block ? 'w-full' : null,
        isDisabled.value ? 'pointer-events-none select-none opacity-40 cursor-not-allowed!' : null,
        attrs.class,
    ]
})

// Resolve the rendered tag/component based on `as`.
const resolvedTag = computed(() => {
    if (resolvedAs.value === 'router-link') {
        return RouterLink
    }

    if (resolvedAs.value === 'a') {
        return 'a'
    }

    return 'button'
})

// Native button type (only for actual buttons).
const resolvedType = computed(() => (resolvedAs.value === 'button' ? props.nativeType : undefined))
// Anchor href (only for `a` tags).
const resolvedHref = computed(() => (resolvedAs.value === 'a' ? props.href : undefined))
// Router-link target (only for router links).
const resolvedTo = computed(() => (resolvedAs.value === 'router-link' ? props.to : undefined))

// Tag-specific attributes, only those that apply: an `href: undefined` handed to RouterLink overrides the href it
// resolves, so a router link ended up with no href (no open-in-new-tab, no URL on hover).
const elementBindings = computed(() => {
    const bindings = {}
    if (resolvedTo.value) bindings.to = resolvedTo.value
    if (resolvedHref.value) bindings.href = resolvedHref.value
    if (resolvedType.value) bindings.type = resolvedType.value
    if (isButton.value) bindings.disabled = isDisabled.value
    return bindings
})

// Forward all attrs except class to avoid duplicates.
const attrsWithoutClass = computed(() => {
    const { class: className, ...rest } = attrs
    return rest
})

const resolvedAriaLabel = computed(() => props.ariaLabel || undefined)
const resolvedTitle = computed(() => props.title || undefined)

// Spinner size and styling for loading state.
const spinnerClass = computed(() => {
    const sizeClass = props.size === 'lg' || props.size === 'md' ? 'h-4 w-4' : 'h-3 w-3'
    return `${sizeClass} rounded-full border-2 border-current border-r-transparent animate-spin`
})

// Guard click when disabled and emit otherwise.
const handleClick = (event) => {
    if (isDisabled.value) {
        event.preventDefault()
        event.stopPropagation()
        return
    }

    emit('click', event)
}
</script>
