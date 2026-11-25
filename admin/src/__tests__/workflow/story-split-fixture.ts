/**
 * Step settings and links of real workflows, as the server stores them, for the step form round-trip specs.
 * - `storySplitSteps` / `storySplitEdges`: a module sample workflow "Story (split)"
 *   (a module install command's steps() / edges()), copied value for value.
 * - `coreDefaultConfigs`: `default_config` of Core nodes (cms/Core/config/workflow_nodes*.php), plus settings
 *   written the way the Core runners document them.
 */
export const storySplitSteps: Record<string, [string, string, Record<string, unknown>]> = {
    trigger: ['trigger_manual', 'Start', { storeAs: 'vars.trigger' }],
    select: ['sample_module.select_places', 'Pick churches due for a story', { limit: 3, parish_only: false, with_photo_only: false, storeAs: 'vars.selection' }],
    each: ['loop_each', 'For each church', { collectionExpr: 'vars.selection.place_uuids', itemVar: 'vars.place_uuid', indexVar: 'vars.place_index', maxItems: 20 }],
    lock: ['lock', 'One run per church', { keyExpr: "'church-story:' ~ vars.place_uuid", ttl_seconds: 3600, on_busy: 'skip' }],
    research: ['sample_module.research_place', 'Research the story', { place_uuid: 'vars.place_uuid', model: null, revision: 'vars.approval', storeAs: 'vars.draft' }],
    gate: ['assert_condition', 'A draft to review?', { expression: 'vars.draft.pending == true', message: 'No story to review for this church.' }],
    jev: ['sample_module.jev_review', 'JEV second opinion', { place_uuid: 'vars.draft.place_uuid', version: 'vars.draft.version', storeAs: 'vars.jev' }],
    route: ['switch', 'JEV verdict', { valueExpr: 'vars.jev.verdict', cases: [{ key: 'fail', match: 'fail' }] }],
    review: [
        'approval',
        'A person reviews the story',
        {
            mode: 'human',
            review: 'vars.draft.review',
            title: 'Church story: {{ vars.draft.name }}',
            editable_fields: ['summary', 'history'],
            reviewer_permission: 'sample-module.stories.review',
            reject_reasons: [
                { key: 'wrong_info', label_key: 'text.sample_module.story_reject.wrong_info' },
                { key: 'poor_writing', label_key: 'text.sample_module.story_reject.poor_writing' },
            ],
            revision_options: [
                {
                    key: 'length',
                    label_key: 'text.sample_module.story_revision.length',
                    choices: [
                        { value: 'shorter', label_key: 'text.sample_module.story_revision.length_shorter' },
                        { value: 'longer', label_key: 'text.sample_module.story_revision.length_longer' },
                    ],
                },
            ],
            max_rounds: 3,
            assignees: [],
            notify: true,
            storeAs: 'vars.approval',
        },
    ],
    publish: ['sample_module.publish_place_story', 'Publish the approved story', { approval_id: 'vars.approval.approval_id', storeAs: 'vars.published' }],
    notify: ['notify', 'Tell the editors', { permission: 'sample-module.stories.review', message: 'Church story published: {{ vars.draft.name }}', storeAs: 'vars.notified' }],
}

export const storySplitEdges: Array<[string, string, string | null]> = [
    ['trigger', 'select', null],
    ['select', 'each', null],
    ['each', 'lock', 'loop'],
    ['lock', 'research', 'acquired'],
    ['lock', 'each', 'busy'],
    ['research', 'gate', null],
    ['research', 'each', 'error'],
    ['gate', 'jev', null],
    ['gate', 'each', 'error'],
    ['jev', 'route', null],
    ['jev', 'each', 'error'],
    ['route', 'each', 'case:fail'],
    ['route', 'review', 'default'],
    ['review', 'publish', 'approved'],
    ['review', 'research', 'changes_requested'],
    ['review', 'each', 'rejected'],
    ['review', 'each', 'expired'],
    ['publish', 'notify', null],
    ['publish', 'each', 'error'],
    ['notify', 'each', null],
]

/** The sample as designer nodes and edges (a main link has the canvas handle `output`). */
export const storySplitGraph = () => ({
    nodes: Object.entries(storySplitSteps).map(([code, [type, name, config]]) => ({
        id: code,
        type: 'step',
        data: { name, step_code: code, step_type: type, is_start: code === 'trigger', step_config: JSON.parse(JSON.stringify(config)) },
    })),
    edges: storySplitEdges.map(([source, target, handle], index) => ({
        id: `e${index}`,
        source,
        target,
        sourceHandle: handle ?? 'output',
        targetHandle: 'input',
    })),
})

export const coreDefaultConfigs: Record<string, Record<string, unknown>> = {
    approval: { mode: 'human', title: '', review: 'vars.draft.review', editable_fields: [], expires_after: '3d', storeAs: 'vars.approval' },
    jev_review: { criteria: '', content: 'vars.draft', min_confidence: 0.8, mode: 'active', storeAs: 'vars.jev' },
    notify: { permission: 'agentic.approve', message: '', storeAs: 'vars.notified' },
    ask_and_wait: { question: '', options: [], expires_after: '1d', storeAs: 'vars.answer' },
    ai_prompt: { instructions: 'Write about {{ vars.place.name }}\nin {{vars.locale}}.\n', inputs: [], output: 'text', storeAs: 'vars.prompt' },
    ai_translate: { text: '{{ vars.draft.body }}', target_locale: 'vi', html_preserve: false, storeAs: 'vars.translate' },
    ai_rewrite: { text: '', style: '', keep_facts: true, revision: '{{ vars.approval }}', storeAs: 'vars.rewrite' },
    http_request: { method: 'GET', url: 'https://example.org/api/{{ vars.place.slug }}?lang={{  vars.locale  }}', auth: 'none', body_type: 'none', response: 'auto', storeAs: 'vars.http' },
    html_extract: { html: '{{ vars.http.body }}', selectors: [], storeAs: 'vars.page' },
    telegram_send: { credential: '$bind:news-bot', chat: '-100123', text: 'New: {{ vars.draft.name }} ({{ vars.items | length }} items)', storeAs: 'vars.telegram' },
    email_send: { to: '{{ vars.editor.email }}', subject: '', body: '', storeAs: 'vars.email' },
    loop_each: { collectionExpr: 'vars.items', itemVar: 'vars.item' },
    assert_condition: { expression: 'true', message: '' },
    change: { targetExpr: 'vars.target', changes: [], save: true },
}
