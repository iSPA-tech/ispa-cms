import ajax from '@/ajax'

// Admin API of things (/api/v2/admin/skeleton/things); rows are addressed by uuid.
const base = 'skeleton/things'

export const getSkeletonThings = (params = {}) => ajax.get(base, { params }).then((res) => res.data || {})
export const createSkeletonThing = (payload) => ajax.post(base, payload).then((res) => res.data || {})
export const updateSkeletonThing = (uuid, payload) => ajax.put(`${base}/${uuid}`, payload).then((res) => res.data || {})
export const deleteSkeletonThing = (uuid) => ajax.delete(`${base}/${uuid}`).then((res) => res.data || {})
