import { HttpResponse, http } from 'msw'

const sampleSemesters = [
    { id: 1, name: 'Semester 1' },
    { id: 2, name: 'Semester 2' },
]

const sampleInstructors = [
    { id: 11, name: 'Dr. Ada Lovelace' },
    { id: 12, name: 'Dr. Alan Turing' },
]

let subjectsScenario = 'default'
let subjectsDelayMs = 0

const sampleResearchPeriodsInitial = [
    {
        uuid: 'rp-1',
        name: 'Period Alpha',
        description: 'Alpha desc',
        subject: { name: 'Algebra' },
        semester: { name: 'Semester 1' },
        instructor: { name: 'Dr. Ada' },
        start_date: '2026-01-01',
        end_date: '2026-02-01',
        status: 'active',
    },
    {
        uuid: 'rp-2',
        name: 'Period Beta',
        description: 'Beta desc',
        subject: { name: 'Biology' },
        semester: { name: 'Semester 1' },
        instructor: { name: 'Dr. Curie' },
        start_date: '2026-02-02',
        end_date: '2026-03-01',
        status: 'status_draft',
    },
    {
        uuid: 'rp-3',
        name: 'Period Gamma',
        description: 'Gamma desc',
        subject: { name: 'Chemistry' },
        semester: { name: 'Semester 2' },
        instructor: { name: 'Dr. Turing' },
        start_date: '2026-03-02',
        end_date: '2026-04-01',
        status: 'active',
    },
]

const defaultSubjects = [
    {
        uuid: 'sub-uuid-1',
        code: 'SUB-001',
        name: 'Algebra',
        name_english: 'Algebra',
        semester: sampleSemesters[0],
        instructor: sampleInstructors[0],
        is_required: true,
        statuses: { name: 'Active' },
    },
    {
        uuid: 'sub-uuid-2',
        code: 'SUB-002',
        name: 'Biology',
        name_english: 'Biology',
        semester: sampleSemesters[1],
        instructor: sampleInstructors[1],
        is_required: false,
        statuses: { name: 'Draft' },
    },
]

const filteredSubjects = [
    {
        uuid: 'sub-uuid-3',
        code: 'SUB-101',
        name: 'Organic Chemistry',
        name_english: 'Organic Chemistry',
        semester: sampleSemesters[1],
        instructor: sampleInstructors[1],
        is_required: false,
        statuses: { name: 'Draft' },
    },
]

const sampleEnumerates = [
    { id: 1, enum_key: 'active', name: 'Active', type: 'status', name_alias: 'Active' },
]

let createSubjectAttempts = 0
let researchPeriods = [...sampleResearchPeriodsInitial]
let researchPeriodBulkCalls = 0

const subjectDetail = {
    uuid: 'sub-edit-uuid',
    code: 'SUB-EDIT',
    name: 'Edited Subject',
    name_english: 'Edited Subject',
    description: 'Existing description',
    credits: 3,
    department: 'Science',
    level: '200',
    is_required: true,
    semester_id: 2,
    instructor_id: 12,
    status: 'active',
}

export const resetResearchPeriodsData = () => {
    researchPeriods = [...sampleResearchPeriodsInitial]
}

export const resetResearchPeriodBulkCalls = () => {
    researchPeriodBulkCalls = 0
}

export const getResearchPeriodBulkCalls = () => researchPeriodBulkCalls

export const setSubjectsScenario = (
    scenario: 'default' | 'empty' | '401' | '403' | '500',
    options: { delayMs?: number } = {}
) => {
    subjectsScenario = scenario
    subjectsDelayMs = options.delayMs ?? 0
}

export const resetSubjectsScenario = () => {
    subjectsScenario = 'default'
    subjectsDelayMs = 0
}

export const handlers = [
    http.get(/\/api\/v2\/admin\/__msw__\/ping$/i, () => {
        return HttpResponse.json({ status: 'ok' })
    }),
    http.get(/\/api\/v2\/admin\/education\/subjects$/i, ({ request }) => {
        if (subjectsScenario === '401') {
            return HttpResponse.json(
                { status: 'error', message: 'Unauthorized' },
                { status: 401, delay: subjectsDelayMs }
            )
        }

        if (subjectsScenario === '403') {
            return HttpResponse.json(
                { status: 'error', message: 'Forbidden' },
                { status: 403, delay: subjectsDelayMs }
            )
        }

        if (subjectsScenario === '500') {
            return HttpResponse.json(
                { status: 'error', message: 'Server error' },
                { status: 500, delay: subjectsDelayMs }
            )
        }

        const url = new URL(request.url)
        const search = url.searchParams.get('search') || ''
        const status = url.searchParams.get('status')
        const semesterId = url.searchParams.get('semester_id')

        let data = subjectsScenario === 'empty' ? [] : defaultSubjects
        if (subjectsScenario === 'default') {
            if (
                search.toLowerCase().includes('chem') ||
                status === 'status_draft' ||
                semesterId === '2'
            ) {
                data = filteredSubjects
            }
        }

        return HttpResponse.json(
            {
                status: 'success',
                data: {
                    data,
                    current_page: 1,
                    total: data.length,
                    per_page: data.length,
                },
            },
            { status: 200, delay: subjectsDelayMs }
        )
    }),
    http.get(/\/api\/v2\/admin\/education\/semesters$/i, () =>
        HttpResponse.json({
            data: { data: sampleSemesters, current_page: 1, total: sampleSemesters.length },
        })
    ),
    http.get(/\/api\/v2\/admin\/education\/instructors$/i, () =>
        HttpResponse.json({
            data: { data: sampleInstructors, current_page: 1, total: sampleInstructors.length },
        })
    ),
    http.get(/\/api\/v2\/admin\/enumerate$/i, () => HttpResponse.json({ data: sampleEnumerates })),
    http.get(/\/api\/v2\/admin\/education\/subjects\/sub-edit-uuid$/i, () =>
        HttpResponse.json({ data: subjectDetail })
    ),
    http.post(/\/api\/v2\/admin\/education\/subjects$/i, async ({ request }) => {
        createSubjectAttempts += 1
        if (createSubjectAttempts === 1) {
            return HttpResponse.json(
                { message: 'Validation failed', errors: { code: ['Code must be unique'] } },
                { status: 422 }
            )
        }

        const body = (await request.json().catch(() => ({}))) as any
        return HttpResponse.json({
            status: 'success',
            data: {
                uuid: 'sub-new-uuid',
                ...body,
            },
        })
    }),
    http.put(/\/api\/v2\/admin\/education\/subjects\/sub-edit-uuid$/i, async ({ request }) => {
        const body = (await request.json().catch(() => ({}))) as any
        Object.assign(subjectDetail, body)
        return HttpResponse.json({ status: 'success', data: { ...subjectDetail } })
    }),
    http.get(/\/api\/v2\/admin\/education\/course-evaluations\/periods$/i, () =>
        HttpResponse.json({
            status: 'success',
            data: {
                data: researchPeriods,
                current_page: 1,
                total: researchPeriods.length,
                per_page: researchPeriods.length,
            },
        })
    ),
    http.post(
        /\/api\/v2\/admin\/education\/course-evaluations\/periods\/mass-destroy$/i,
        async ({ request }) => {
            researchPeriodBulkCalls += 1
            const body = ((await request.json().catch(() => ({}))) as any) || {}
            const ids = Array.isArray(body.ids) ? body.ids : []

            if (body.force_fail) {
                return HttpResponse.json(
                    { status: 'error', message: 'Bulk delete failed' },
                    { status: 409 }
                )
            }

            researchPeriods = researchPeriods.filter((item) => !ids.includes(item.uuid))

            return HttpResponse.json({
                status: 'success',
                data: {
                    affected_count: ids.length,
                    ids,
                },
            })
        }
    ),
]
