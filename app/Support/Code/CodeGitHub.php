<?php

namespace App\Support\Code;

use App\Models\Workspace;
use App\Support\GitHub\GitHubApp;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Support\Facades\Cache;
use RuntimeException;

/**
 * Reads the code repository's open pull requests through the workspace's
 * GitHub App: the ones that change ServerOS's files, what they change, who
 * reviewed them and their checks when GitHub shares them. Every answer is
 * kept for a minute, and a failed one for a few seconds.
 *
 * @phpstan-import-type Repository from CodeRepository
 *
 * @phpstan-type PullFile array{path: string, relative: string, previous: string|null, status: string, additions: int, deletions: int, patch: string|null}
 * @phpstan-type Pull array{number: int, title: string, url: string, author: string|null, draft: bool, from: string, to: string, sha: string, updated: string|null, files: list<PullFile>}
 * @phpstan-type Review array{author: string, state: string, at: string|null}
 * @phpstan-type Check array{name: string, state: string, url: string|null}
 * @phpstan-type Details array{reviews: list<Review>|null, checks: list<Check>|null, contents: array<string, string|null>}
 */
final class CodeGitHub
{
    private const int SECONDS = 60;

    private const int FAILED_SECONDS = 15;

    private const int MAX_PULLS = 20;

    private const int MAX_FILES = 30;

    public function __construct(private readonly GitHubApp $github) {}

    /**
     * @param  Repository  $repository
     * @return array{pulls: list<Pull>, error: string|null}
     */
    public function pullRequests(Workspace $workspace, array $repository): array
    {
        $key = self::key($workspace, $repository, 'pulls');
        $cached = Cache::get($key);
        if (is_array($cached) && is_array($cached['pulls'] ?? null) && array_key_exists('error', $cached)) {
            /** @var array{pulls: list<Pull>, error: string|null} $cached */
            return $cached;
        }

        try {
            $client = $this->client($workspace, $repository);
            $pulls = [];
            foreach ($this->get($client, $repository, '/pulls', ['state' => 'open', 'sort' => 'updated', 'direction' => 'desc', 'per_page' => self::MAX_PULLS]) as $pull) {
                if (! is_array($pull) || ! is_int($pull['number'] ?? null) || ! is_string($pull['head']['sha'] ?? null)) {
                    continue;
                }
                $files = $this->files($client, $repository, $pull['number']);
                if ($files === []) {
                    continue;
                }
                $pulls[] = [
                    'number' => $pull['number'],
                    'title' => (string) ($pull['title'] ?? ''),
                    'url' => is_string($pull['html_url'] ?? null) ? $pull['html_url'] : $repository['url'].'/pull/'.$pull['number'],
                    'author' => is_string($pull['user']['login'] ?? null) ? $pull['user']['login'] : null,
                    'draft' => ($pull['draft'] ?? false) === true,
                    'from' => (string) ($pull['head']['ref'] ?? ''),
                    'to' => (string) ($pull['base']['ref'] ?? ''),
                    'sha' => $pull['head']['sha'],
                    'updated' => is_string($pull['updated_at'] ?? null) ? $pull['updated_at'] : null,
                    'files' => $files,
                ];
            }
            $answer = ['pulls' => $pulls, 'error' => null];
        } catch (RuntimeException $e) {
            $answer = ['pulls' => [], 'error' => $e->getMessage()];
        }
        Cache::put($key, $answer, $answer['error'] === null ? self::SECONDS : self::FAILED_SECONDS);

        return $answer;
    }

    /**
     * Reviews, checks and the pull request's version of each file it changes.
     *
     * @param  Repository  $repository
     * @param  Pull  $pull
     * @return Details
     */
    public function details(Workspace $workspace, array $repository, array $pull): array
    {
        $key = self::key($workspace, $repository, "pull:{$pull['number']}:{$pull['sha']}");
        $cached = Cache::get($key);
        if (is_array($cached) && array_key_exists('reviews', $cached) && array_key_exists('checks', $cached) && is_array($cached['contents'] ?? null)) {
            /** @var Details $cached */
            return $cached;
        }

        try {
            $client = $this->client($workspace, $repository);
        } catch (RuntimeException) {
            return ['reviews' => null, 'checks' => null, 'contents' => []];
        }
        $details = [
            'reviews' => $this->reviews($client, $repository, $pull['number']),
            'checks' => $this->checks($client, $repository, $pull['sha']),
            'contents' => [],
        ];
        foreach (array_slice($pull['files'], 0, self::MAX_FILES) as $file) {
            if ($file['status'] !== 'removed') {
                $details['contents'][$file['path']] = $this->content($client, $repository, $file['path'], $pull['sha']);
            }
        }
        Cache::put($key, $details, self::SECONDS);

        return $details;
    }

    /** @param  Repository  $repository */
    public static function forget(Workspace $workspace, array $repository): void
    {
        Cache::forget(self::key($workspace, $repository, 'pulls'));
    }

    /**
     * @param  Repository  $repository
     * @return list<PullFile>
     */
    private function files(PendingRequest $client, array $repository, int $number): array
    {
        $files = [];
        foreach ($this->get($client, $repository, "/pulls/{$number}/files", ['per_page' => 100]) as $file) {
            if (! is_array($file) || ! is_string($file['filename'] ?? null)) {
                continue;
            }
            $relative = CodeRepository::relative($repository['folder'], $file['filename']);
            $previous = is_string($file['previous_filename'] ?? null) ? CodeRepository::relative($repository['folder'], $file['previous_filename']) : null;
            if ($relative === null && $previous === null) {
                continue;
            }
            $files[] = [
                'path' => $file['filename'],
                'relative' => $relative ?? $previous,
                'previous' => $previous,
                'status' => $relative === null ? 'removed' : (string) ($file['status'] ?? 'modified'),
                'additions' => (int) ($file['additions'] ?? 0),
                'deletions' => (int) ($file['deletions'] ?? 0),
                'patch' => is_string($file['patch'] ?? null) ? mb_substr($file['patch'], 0, 60_000) : null,
            ];
        }

        return $files;
    }

    /**
     * The newest review from each person, approvals and change requests
     * first: a comment after an approval doesn't take it back.
     *
     * @param  Repository  $repository
     * @return list<Review>|null
     */
    private function reviews(PendingRequest $client, array $repository, int $number): ?array
    {
        try {
            $reviews = $this->get($client, $repository, "/pulls/{$number}/reviews", ['per_page' => 100]);
        } catch (RuntimeException) {
            return null;
        }
        $latest = [];
        foreach ($reviews as $review) {
            if (! is_array($review)) {
                continue;
            }
            $author = is_string($review['user']['login'] ?? null) ? $review['user']['login'] : null;
            $state = match ($review['state'] ?? null) {
                'APPROVED' => 'approved',
                'CHANGES_REQUESTED' => 'changes_requested',
                'COMMENTED' => 'commented',
                'DISMISSED' => 'dismissed',
                default => null,
            };
            if ($author === null || $state === null) {
                continue;
            }
            $decided = in_array($latest[$author]['state'] ?? null, ['approved', 'changes_requested'], true);
            if ($state !== 'commented' || ! $decided) {
                $latest[$author] = ['author' => $author, 'state' => $state, 'at' => is_string($review['submitted_at'] ?? null) ? $review['submitted_at'] : null];
            }
        }

        return array_values(array_filter($latest, fn (array $review) => $review['state'] !== 'dismissed'));
    }

    /**
     * Check runs on the pull request's commit. ServerOS's GitHub App doesn't
     * ask to read checks, so GitHub usually turns this down: that's null.
     *
     * @param  Repository  $repository
     * @return list<Check>|null
     */
    private function checks(PendingRequest $client, array $repository, string $sha): ?array
    {
        try {
            $answer = $this->get($client, $repository, "/commits/{$sha}/check-runs", ['per_page' => 50]);
        } catch (RuntimeException) {
            return null;
        }
        if (! is_array($answer['check_runs'] ?? null)) {
            return null;
        }
        $checks = [];
        foreach ($answer['check_runs'] as $run) {
            if (! is_array($run) || ! is_string($run['name'] ?? null)) {
                continue;
            }
            $checks[] = [
                'name' => $run['name'],
                'state' => ($run['status'] ?? null) !== 'completed' ? 'pending' : match ($run['conclusion'] ?? null) {
                    'success' => 'success',
                    'failure', 'timed_out', 'cancelled', 'action_required', 'startup_failure' => 'failure',
                    default => 'neutral',
                },
                'url' => is_string($run['html_url'] ?? null) ? $run['html_url'] : null,
            ];
        }

        return $checks;
    }

    /** @param  Repository  $repository */
    private function content(PendingRequest $client, array $repository, string $path, string $sha): ?string
    {
        try {
            $file = $this->get($client, $repository, '/contents/'.implode('/', array_map('rawurlencode', explode('/', $path))), ['ref' => $sha]);
        } catch (RuntimeException) {
            return null;
        }
        if (! is_string($file['content'] ?? null) || ($file['encoding'] ?? 'base64') !== 'base64') {
            return null;
        }
        $content = base64_decode(str_replace(["\n", "\r"], '', $file['content']), true);

        return is_string($content) ? $content : null;
    }

    /**
     * @param  Repository  $repository
     *
     * @throws RuntimeException
     */
    private function client(Workspace $workspace, array $repository): PendingRequest
    {
        $token = $this->github->reviewTokenFor($workspace, $repository['owner'], $repository['name'])
            ?? throw new RuntimeException(__('ServerOS’s GitHub App can’t read :repo. Check what it can reach in Settings › GitHub.', ['repo' => $repository['repo']]));

        return $this->github->rest($token)->connectTimeout(3);
    }

    /**
     * @param  Repository  $repository
     * @param  array<string, mixed>  $query
     * @return array<mixed>
     *
     * @throws RuntimeException
     */
    private function get(PendingRequest $client, array $repository, string $path, array $query = []): array
    {
        try {
            $response = $client->get('https://api.github.com/repos/'.rawurlencode($repository['owner']).'/'.rawurlencode($repository['name']).$path, $query);
        } catch (ConnectionException) {
            throw new RuntimeException(__('GitHub didn’t answer. Try again in a minute.'));
        }
        $json = $response->json();
        if (! $response->successful() || ! is_array($json)) {
            throw new RuntimeException(GitHubApp::failure($response, $repository['repo']));
        }

        return $json;
    }

    /** @param  Repository  $repository */
    private static function key(Workspace $workspace, array $repository, string $what): string
    {
        return "code:github:{$workspace->id}:".sha1(strtolower($repository['repo']).'|'.$repository['folder']).":{$what}";
    }
}
