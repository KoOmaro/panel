import { usePage } from '@inertiajs/vue3';
import type { RouteDefinition } from '@/wayfinder';

type Method = 'get' | 'post' | 'put' | 'delete' | 'patch' | 'head' | 'options';

function current(): { base: string | null; slug: string | null } {
    const props = usePage().props as {
        workspaceBase?: string | null;
        workspace?: { slug?: string } | null;
    };

    return {
        base: props.workspaceBase ?? null,
        slug: props.workspace?.slug ?? null,
    };
}

export function workspacePath(path: string): string {
    const { base } = current();
    const clean = path.startsWith('/') ? path : `/${path}`;

    if (!base) return clean;

    return clean === '/' ? base : `${base}${clean}`;
}

export function workspaceAddress(url: string): string {
    return url.replace(/^https?:\/\//, '').replace(/\/$/, '');
}

export function onWorkspace<TMethod extends Method>(
    route: RouteDefinition<TMethod>,
): RouteDefinition<TMethod> {
    const url = new URL(route.url, 'http://localhost');
    const { base, slug } = current();
    let path = url.pathname;

    if (base === '' && slug) {
        if (path !== `/${slug}` && !path.startsWith(`/${slug}/`)) return route;
        path = path.slice(slug.length + 1) || '/';
    }

    return { ...route, url: `${path}${url.search}${url.hash}` };
}
