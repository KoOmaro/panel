import type { RequestPayload } from '@inertiajs/core';
import { router } from '@inertiajs/vue3';
import { trans } from 'laravel-vue-i18n';
import { computed, reactive, ref, watch } from 'vue';
import type { FilledIconName } from '@/components/icons/filledIcons';
import { onWorkspace } from '@/lib/workspaceRoute';
import savedViewRoutes from '@/routes/workspace/saved-views';

export type SavedViewFilters = Record<string, string | string[] | null>;

export type SavedView<F extends SavedViewFilters = SavedViewFilters> = {
    id: number;
    name: string;
    filters: F;
};

export type SavedViewSummaryRow = {
    icon: FilledIconName;
    label: string;
    value: string;
    empty?: boolean;
};

type Errors = Record<string, string>;

function sortDeep(value: unknown): unknown {
    if (Array.isArray(value)) {
        return value
            .map(sortDeep)
            .sort((a, b) => JSON.stringify(a).localeCompare(JSON.stringify(b)));
    }
    if (value && typeof value === 'object') {
        return Object.fromEntries(
            Object.keys(value)
                .sort()
                .map((key) => [
                    key,
                    sortDeep((value as Record<string, unknown>)[key]),
                ]),
        );
    }

    return value;
}

export function useSavedViews<F extends SavedViewFilters>(options: {
    page: string;
    workspace: () => string;
    views: () => SavedView<F>[];
    active: () => number | null;
    defaults: F;
    current: () => F;
    apply: (filters: F) => void;
}) {
    const busy = ref(false);
    const activeId = ref<number | null>(options.active());

    const full = (filters: Partial<F>): F => ({
        ...options.defaults,
        ...filters,
    });
    const key = (filters: Partial<F>): string =>
        JSON.stringify(sortDeep(full(filters)));

    const views = computed(() => options.views());
    const active = computed(
        () => views.value.find((view) => view.id === activeId.value) ?? null,
    );
    const currentKey = computed(() => key(options.current()));
    const changed = computed(
        () => !!active.value && key(active.value.filters) !== currentKey.value,
    );

    function matches(view: SavedView<F>): boolean {
        return key(view.filters) === currentKey.value;
    }

    function setAddress(id: number | null): void {
        const url = new URL(window.location.href);
        if (id === null) url.searchParams.delete('view');
        else url.searchParams.set('view', String(id));

        router.replace({
            url: `${url.pathname}${url.search}${url.hash}`,
            preserveState: true,
            preserveScroll: true,
            props: (props) => ({ ...props, activeView: id }),
        });
    }

    function select(view: SavedView<F>): void {
        activeId.value = view.id;
        options.apply(full(view.filters));
        setAddress(view.id);
    }

    function leave(): void {
        if (activeId.value === null) return;
        activeId.value = null;
        options.apply(full({}));
        setAddress(null);
    }

    function revert(): void {
        if (active.value) options.apply(full(active.value.filters));
    }

    function send(
        method: 'post' | 'patch' | 'delete',
        url: string,
        data: RequestPayload = {},
    ): Promise<Errors | null> {
        busy.value = true;

        return new Promise((resolve) => {
            let settled = false;
            const done = (errors: Errors | null): void => {
                settled = true;
                resolve(errors);
            };
            const visit = {
                preserveState: true,
                preserveScroll: true,
                onSuccess: () => done(null),
                onError: (errors: Errors) => done(errors),
                onFinish: () => {
                    busy.value = false;
                    if (!settled) done({ view: trans('That didn’t work.') });
                },
            };

            if (method === 'delete') router.delete(url, visit);
            else router[method](url, data, visit);
        });
    }

    const route = (view: SavedView<F>) => ({
        workspace: options.workspace(),
        savedView: view.id,
    });

    function create(name: string): Promise<Errors | null> {
        return send(
            'post',
            onWorkspace(savedViewRoutes.store(options.workspace())).url,
            { page: options.page, name, filters: options.current() },
        );
    }

    function rename(view: SavedView<F>, name: string): Promise<Errors | null> {
        return send(
            'patch',
            onWorkspace(savedViewRoutes.update(route(view))).url,
            { name },
        );
    }

    async function save(view: SavedView<F>): Promise<Errors | null> {
        const errors = await send(
            'patch',
            onWorkspace(savedViewRoutes.update(route(view))).url,
            { filters: options.current() },
        );
        if (!errors && activeId.value !== view.id) {
            activeId.value = view.id;
            setAddress(view.id);
        }

        return errors;
    }

    function duplicate(view: SavedView<F>): Promise<Errors | null> {
        return send(
            'post',
            onWorkspace(savedViewRoutes.duplicate(route(view))).url,
        );
    }

    async function remove(view: SavedView<F>): Promise<Errors | null> {
        const wasActive = activeId.value === view.id;
        const errors = await send(
            'delete',
            onWorkspace(savedViewRoutes.destroy(route(view))).url,
        );
        if (!errors && wasActive) {
            activeId.value = null;
            options.apply(full({}));
            setAddress(null);
        }

        return errors;
    }

    watch(options.active, (id) => {
        if (id === activeId.value) return;
        activeId.value = id;
        const view = views.value.find((v) => v.id === id);
        options.apply(full(view?.filters ?? {}));
    });

    if (active.value) options.apply(full(active.value.filters));

    return reactive({
        views,
        active,
        changed,
        busy,
        matches,
        select,
        leave,
        revert,
        create,
        rename,
        save,
        duplicate,
        remove,
    });
}

export type SavedViews<F extends SavedViewFilters = SavedViewFilters> =
    ReturnType<typeof useSavedViews<F>>;
