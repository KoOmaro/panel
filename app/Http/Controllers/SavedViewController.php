<?php

namespace App\Http\Controllers;

use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use App\Support\Audit\NotAudited;
use App\Support\SavedViews\SavedViews;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class SavedViewController extends Controller
{
    #[NotAudited('Personal: your own saved views of a list')]
    public function store(Request $request, Workspace $workspace): RedirectResponse
    {
        $page = (string) $request->validate(['page' => ['required', 'string', Rule::in(SavedViews::pages())]])['page'];
        $data = $request->validate(['name' => ['required', 'string', 'max:'.SavedViews::NAME_MAX], ...SavedViews::rules($page)]);
        $user = $this->user($request);
        $this->roomFor($workspace, $user, $page);

        $view = SavedView::query()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $user->id,
            'page' => $page,
            'name' => trim((string) $data['name']),
            'filters' => SavedViews::normalize($page, (array) $data['filters']),
            'position' => (int) SavedView::query()->ownedBy($workspace, $user, $page)->max('position') + 1,
        ]);

        return redirect(SavedViews::url($workspace, $view));
    }

    #[NotAudited('Personal: your own saved views of a list')]
    public function update(Request $request, Workspace $workspace, SavedView $savedView): RedirectResponse
    {
        $this->owned($request, $workspace, $savedView);
        $data = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:'.SavedViews::NAME_MAX],
            ...SavedViews::rules($savedView->page, required: false),
        ]);

        if (array_key_exists('name', $data)) {
            $savedView->name = trim((string) $data['name']);
        }
        if (array_key_exists('filters', $data)) {
            $savedView->filters = SavedViews::normalize($savedView->page, (array) $data['filters']);
        }
        $savedView->save();

        return back();
    }

    #[NotAudited('Personal: your own saved views of a list')]
    public function duplicate(Request $request, Workspace $workspace, SavedView $savedView): RedirectResponse
    {
        $this->owned($request, $workspace, $savedView);
        $user = $this->user($request);
        $this->roomFor($workspace, $user, $savedView->page);

        SavedView::query()->ownedBy($workspace, $user, $savedView->page)->where('position', '>', $savedView->position)->increment('position');
        $copy = $savedView->replicate();
        $copy->name = Str::limit(__(':name copy', ['name' => $savedView->name]), SavedViews::NAME_MAX, '');
        $copy->position = $savedView->position + 1;
        $copy->save();

        return redirect(SavedViews::url($workspace, $copy));
    }

    #[NotAudited('Personal: your own saved views of a list')]
    public function destroy(Request $request, Workspace $workspace, SavedView $savedView): RedirectResponse
    {
        $this->owned($request, $workspace, $savedView);
        $savedView->delete();

        return back();
    }

    private function owned(Request $request, Workspace $workspace, SavedView $view): void
    {
        abort_unless($view->workspace_id === $workspace->id && $view->user_id === $this->user($request)->id, 404);
    }

    private function roomFor(Workspace $workspace, User $user, string $page): void
    {
        if (SavedView::query()->ownedBy($workspace, $user, $page)->count() >= SavedView::MAX_PER_PAGE) {
            throw ValidationException::withMessages(['name' => __('You can keep up to :count views here. Delete one to add another.', ['count' => SavedView::MAX_PER_PAGE])]);
        }
    }

    private function user(Request $request): User
    {
        /** @var User $user */
        $user = $request->user();

        return $user;
    }
}
