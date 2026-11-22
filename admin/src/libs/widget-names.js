/**
 * Names of widgets and designs as the admin shows them. The API sends `display_name` / `display_description` in the
 * admin language (see `X-Admin-Locale` in `@/ajax`) next to the original `name` / `description`, which stay the fallback.
 */

/**
 * @param {?{display_name?: ?string, name?: ?string}} row Widget, design or hub catalog entry.
 * @returns {string} The name in the admin language, else the original name, else ''.
 */
export const widgetLabel = (row) => row?.display_name || row?.name || ''

/** Same rule for a design (a row of `widget.template`). */
export const designLabel = widgetLabel

/**
 * @param {?{display_description?: ?string, description?: ?string}} row Widget or hub catalog entry.
 * @returns {string} The description in the admin language, else the original one, else ''.
 */
export const widgetDescription = (row) => row?.display_description || row?.description || ''

/** Languages a widget or design name can be given in (the API accepts these keys only). */
export const WIDGET_NAME_LOCALES = ['vi', 'en']

/**
 * What the API stores for a `name_translations` / `description_translations` value: trimmed, non-blank texts of the
 * known languages, or null when none is left.
 *
 * @param {?Object<string, ?string>} value For example `{vi: ' Giới thiệu ', en: ''}`.
 * @returns {?Object<string, string>} For example `{vi: 'Giới thiệu'}`.
 */
export const cleanNameTranslations = (value) => {
    const out = {}
    for (const locale of WIDGET_NAME_LOCALES) {
        const text = typeof value?.[locale] === 'string' ? value[locale].trim() : ''
        if (text) out[locale] = text
    }
    return Object.keys(out).length > 0 ? out : null
}

/**
 * Name of a row being edited, before the API has localized it: the text of `locale`, else English, else `fallback`
 * (the same order the API uses for `display_name`).
 *
 * @param {?Object<string, ?string>} translations `name_translations` of the row.
 * @param {?string} fallback Original name.
 * @param {string} locale Admin language such as `vi`.
 * @returns {string}
 */
export const localizedName = (translations, fallback, locale) => {
    const clean = cleanNameTranslations(translations) ?? {}
    return clean[String(locale ?? '').slice(0, 2).toLowerCase()] ?? clean.en ?? fallback ?? ''
}
