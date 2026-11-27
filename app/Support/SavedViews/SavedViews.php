<?php

namespace App\Support\SavedViews;

use App\Models\SavedView;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

final class SavedViews
{
    public const int NAME_MAX = 60;

    private const array PAGES = [
        'projects' => [
            'route' => 'workspace.projects.index',
            'filters' => [
                'q' => ['type' => 'text', 'max' => 200],
                'status' => ['type' => 'list', 'in' => ['live', 'deploying', 'pending', 'down', 'stopped'], 'max' => 20],
                'machines' => ['type' => 'list', 'in' => null, 'max' => 255],
                'sort' => ['type' => 'choice', 'in' => ['newest', 'updated', 'name', 'status'], 'default' => 'newest'],
                'env' => ['type' => 'text', 'max' => 40],
            ],
        ],
    ];

    private const int LIST_MAX = 50;

    /** @return list<string> */
    public static function pages(): array
    {
        return array_keys(self::PAGES);
    }

    /** @return array<string, list<mixed>> */
    public static function rules(string $page, bool $required = true): array
    {
        $fields = self::PAGES[$page]['filters'];
        $rules = ['filters' => [$required ? 'present' : 'sometimes', 'array:'.implode(',', array_keys($fields))]];

        foreach ($fields as $key => $field) {
            $rules["filters.{$key}"] = match ($field['type']) {
                'text' => ['nullable', 'string', 'max:'.$field['max']],
                'list' => ['nullable', 'array', 'max:'.self::LIST_MAX],
                'choice' => ['nullable', 'string', Rule::in($field['in'])],
            };
            if ($field['type'] === 'list') {
                $rules["filters.{$key}.*"] = ['string', 'max:'.$field['max'], ...($field['in'] === null ? [] : [Rule::in($field['in'])])];
            }
        }

        return $rules;
    }

    /**
     * @param  array<array-key, mixed>  $filters
     * @return array<string, string|list<string>|null>
     */
    public static function normalize(string $page, array $filters): array
    {
        $clean = [];
        foreach (self::PAGES[$page]['filters'] as $key => $field) {
            $value = $filters[$key] ?? null;
            $clean[$key] = match ($field['type']) {
                'text' => is_string($value) && trim($value) !== '' ? trim($value) : null,
                'list' => self::strings($value, $field['in']),
                'choice' => is_string($value) && in_array($value, $field['in'], true) ? $value : $field['default'],
            };
        }

        return $clean;
    }

    public static function url(Workspace $workspace, SavedView $view): string
    {
        return route(self::PAGES[$view->page]['route'], ['workspace' => $workspace, 'view' => $view->id]);
    }

    /** @return array{savedViews: list<array{id: int, name: string, filters: array<string, string|list<string>|null>}>, activeView: int|null} */
    public static function props(Request $request, Workspace $workspace, string $page): array
    {
        /** @var User $user */
        $user = $request->user();
        $views = SavedView::query()->ownedBy($workspace, $user, $page)->orderBy('position')->orderBy('id')->get();
        $asked = filter_var($request->query('view'), FILTER_VALIDATE_INT);

        return [
            'savedViews' => array_values($views->map(fn (SavedView $view) => [
                'id' => $view->id,
                'name' => $view->name,
                'filters' => self::normalize($page, $view->filters),
            ])->all()),
            'activeView' => is_int($asked) && $views->contains('id', $asked) ? $asked : null,
        ];
    }

    /**
     * @param  list<string>|null  $allowed
     * @return list<string>
     */
    private static function strings(mixed $value, ?array $allowed): array
    {
        $strings = array_filter(is_array($value) ? $value : [], fn (mixed $item) => is_string($item) && $item !== '' && ($allowed === null || in_array($item, $allowed, true)));
        $strings = array_values(array_unique($strings));
        sort($strings, SORT_STRING);

        return $strings;
    }
}
