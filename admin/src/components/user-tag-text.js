import { humanizeI18nKey } from '@/config/i18n-message-utils'

/**
 * Vietnamese fallbacks for the Users tagging UI. The Core admin keys reach the runtime pack only after
 * `cms:translation-sync` + `cms:translation-generate`; until then a missing key must never leak as raw text.
 */
const FALLBACK = {
    'text.user-tag.placeholder': 'Chọn hoặc gõ tên tag mới',
    'text.user-tag.create_option': 'Tạo tag "{name}"',
    'text.user-tag.match_all': 'Có đủ',
    'text.user-tag.match_any': 'Có bất kỳ',
    'text.user-tag.attach': 'Gắn tag',
    'text.user-tag.detach': 'Bỏ tag',
    'text.user-tag.select_all_filter': 'Chọn tất cả {total} người theo bộ lọc',
    'text.user-tag.all_selected': 'Đang chọn cả {total} người theo bộ lọc',
    'text.user-tag.clear_selection': 'Bỏ chọn',
    'text.user-tag.confirm_all': 'Tag sẽ áp dụng cho cả {total} người khớp bộ lọc, không chỉ các dòng đang hiện. Tiếp tục?',
    'text.user-tag.done': 'Đã gắn tag cho {affected} người',
    'text.user-tag.failed': 'Chưa cập nhật được tag. Hãy thử lại.',
    'text.user-tag.filter_placeholder': 'Tag',
    'text.user-tag.role_placeholder': 'Nhóm',
    'text.user-tag.pick_placeholder': 'Chọn tag',
    'text.user-tag.title_attach': 'Gắn tag cho {count} người',
    'text.user-tag.done_detach': 'Đã bỏ tag khỏi {affected} người',
    'text.user-tag.remove_chip': 'Gỡ tag {name}',
    'text.user-tag.removed': 'Đã gỡ tag {name}',
    'text.user-tag.detach_confirm': 'Gỡ tag {tags} khỏi {count} người?',
    'text.user-tag.detach_need_filter': 'Chọn tag ở bộ lọc để gỡ',
    'text.user-tag.detach_one_done': 'Đã gỡ tag {name} khỏi {count} người',
    'text.user-tag.undo': 'Hoàn tác',
    'text.user-tag.no_tags_on_selection': 'Những người đã chọn chưa có tag nào',
    'text.user-tag.more_label': 'Và {count} tag khác',
}

/** uuid => name of every tag the page has seen, so a prefilled select and confirm texts can show names. */
export const userTagNames = new Map()

const fill = (text, params) => String(text).replace(/\{(\w+)\}/g, (m, k) => (params[k] ?? m))

/** Build `tt(key, params)` over vue-i18n's `t`/`te`; falls back to the Vietnamese text when the runtime pack lacks the key. */
export const makeTagText = ({ t, te }) => (key, params = {}) => {
    const out = t(key, params)
    // The global `missing` handler returns a humanized key ("Title Attach"), so that never counts as a real translation.
    const known = typeof te === 'function' ? te(key) : true
    if (known && typeof out === 'string' && out !== key && out !== humanizeI18nKey(key)) return out
    return fill(FALLBACK[key] ?? key, params)
}
