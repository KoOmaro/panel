<?php

use Tests\Support\BareWorkspacePaths;

test('the frontend builds workspace links with the workspace in them', function () {
    expect(BareWorkspacePaths::in(resource_path('js')))->toBe([], 'Build these with a Wayfinder route and onWorkspace(), or workspacePath(\'/...\') from @/lib/workspaceRoute.');
});

test('the check finds bare workspace paths and leaves wrapped and account paths alone', function () {
    $source = <<<'JS'
        router.post('/projects', data);
        fetch(`/machines/${id}/files`);
        <a href="/monitors">Monitors</a>
        const a = workspacePath('/machines');
        const b = workspacePath(
            `/machines/${id}/settings/status`,
        );
        router.delete(`/settings/sessions/${key}`);
        router.visit('/settings/profile');
        const c = '/machinery';
        const d = '/api/v1/machines';
    JS;

    expect(BareWorkspacePaths::segments())->toContain('machines', 'projects', 'monitors', 'settings')
        ->and(BareWorkspacePaths::inSource($source, 'Fixture.vue'))->toBe([
            "Fixture.vue:1  '/projects'",
            'Fixture.vue:2  `/machines/${id}/files`',
            'Fixture.vue:3  "/monitors"',
        ]);
});
