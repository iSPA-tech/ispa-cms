import { SkeletonShell, SkeletonThings } from './page-loaders'

export const SkeletonRouteConfig = {
    text: 'nav.a.skeleton',
    path: 'skeleton',
    redirect: { name: 'skeleton-things' },
    load: SkeletonShell,
    group: 'skeleton',
    name: 'skeleton',
    meta: { rule: 'skeleton.skeleton_thing.viewAny' },
    children: [
        {
            text: 'nav.a.skeleton-things',
            path: 'things',
            name: 'skeleton-things',
            load: SkeletonThings,
        },
    ],
}
