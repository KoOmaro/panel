<?php

namespace App\Support\Deploys;

use App\Models\DaemonJob;
use App\Models\Machine;
use App\Support\Images\ImageReference;
use Illuminate\Validation\ValidationException;

/**
 * An app deployed from an image instead of a repository. The job sends a
 * one-line Dockerfile, `FROM <image>@<digest>`, so the daemon makes a
 * release from it like any built app: health checked, swapped in without
 * downtime, kept for rolling back. The release is named by the digest, so
 * each new image is a new version and an older one can be gone back to.
 */
final class ImageDeploy
{
    public const string DOCKERFILE = 'Dockerfile';

    private const string FROM = '/^FROM (\S+)@(sha256:[a-f0-9]{64})\n?$/';

    /**
     * The image a deploy job runs, when it's one of these.
     *
     * @param  array<string, mixed>  $job
     * @return array{reference: ImageReference, digest: string}|null
     */
    public static function of(array $job): ?array
    {
        if (filled($job['repo'] ?? null) || filled($job['compose_file'] ?? null) || ($job['dockerfile'] ?? null) !== self::DOCKERFILE) {
            return null;
        }
        $files = (array) ($job['files'] ?? []);
        $from = $files[self::DOCKERFILE] ?? null;
        if (count($files) !== 1 || ! is_string($from) || preg_match(self::FROM, $from, $m) !== 1) {
            return null;
        }
        $reference = ImageReference::parse($m[1]);

        return $reference === null ? null : ['reference' => $reference, 'digest' => $m[2]];
    }

    /**
     * Whether a deploy job made an app: built from a repository, or from an
     * image. Template stacks (files only, no repository) aren't apps.
     *
     * @param  array<string, mixed>  $job
     */
    public static function isApp(array $job): bool
    {
        return filled($job['repo'] ?? null) || self::of($job) !== null;
    }

    /** The release name for an image: the first 40 hex digits of its digest. */
    public static function commitOf(string $digest): string
    {
        return substr((string) preg_replace('/^sha256:/', '', $digest), 0, 40);
    }

    /**
     * @param  array{service: string, port: int|string, health_path?: string|null, domain?: string|null, wildcard?: bool|null}  $data
     * @param  array<string, string>  $env
     * @return array<string, mixed>
     */
    public static function job(array $data, ImageReference $image, string $digest, array $env): array
    {
        $health = trim((string) ($data['health_path'] ?? ''));

        return [
            'type' => 'deploy',
            'service' => $data['service'],
            'repo' => '',
            'commit' => self::commitOf($digest),
            'files' => [self::DOCKERFILE => 'FROM '.$image->pullName().'@'.$digest."\n"],
            'dockerfile' => self::DOCKERFILE,
            'env' => $env,
            'health' => $health === '' ? null : ['path' => $health, 'expected_status' => 200, 'timeout_secs' => 30, 'retries' => 5],
            'domains' => DeployRequest::domains($data['domain'] ?? null, (bool) ($data['wildcard'] ?? false)),
            'port' => (int) $data['port'],
        ];
    }

    /**
     * The same deploy with a newer image of the same tag.
     *
     * @param  array<string, mixed>  $job
     * @return array<string, mixed>|null
     */
    public static function withDigest(array $job, string $digest): ?array
    {
        $found = self::of($job);
        if ($found === null) {
            return null;
        }

        return [...$job, 'commit' => self::commitOf($digest), 'files' => [self::DOCKERFILE => 'FROM '.$found['reference']->pullName().'@'.$digest."\n"]];
    }

    /**
     * The image the app's live release runs: the release the machine runs
     * now, or its last image deploy when the machine hasn't said yet.
     *
     * @return array{reference: ImageReference, digest: string, job: DaemonJob}|null
     */
    public static function live(Machine $machine, string $app): ?array
    {
        $live = AppReleases::live($machine)[$app] ?? null;
        $deploys = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $app)
            ->where('state', 'succeeded')->latest()->limit(30)->get();
        $job = ($live === null ? null : $deploys->first(fn (DaemonJob $job) => str_starts_with((string) ($job->command['job']['commit'] ?? ''), $live)))
            ?? $deploys->first();
        $found = $job === null ? null : self::of((array) ($job->command['job'] ?? []));

        return $found === null ? null : [...$found, 'job' => $job];
    }

    /**
     * The image a release container runs, by its name (serveros-web-…).
     *
     * @return array{app: string, reference: ImageReference, digest: string}|null
     */
    public static function ofContainer(Machine $machine, string $name): ?array
    {
        $release = AppReleases::parse($name);
        if ($release === null) {
            return null;
        }
        $job = DaemonJob::query()->where('machine_id', $machine->id)->where('kind', 'deploy')->where('service', $release['app'])
            ->where('state', 'succeeded')->latest()->limit(30)->get()
            ->first(fn (DaemonJob $job) => str_starts_with((string) ($job->command['job']['commit'] ?? ''), $release['commit']));
        $found = $job === null ? null : self::of((array) ($job->command['job'] ?? []));

        return $found === null ? null : ['app' => $release['app'], ...$found];
    }

    /**
     * Settings for the app, from `KEY=value` lines.
     *
     * @return array<string, string>
     *
     * @throws ValidationException
     */
    public static function env(?string $lines, string $field = 'extra_env'): array
    {
        $env = [];
        foreach (preg_split('/\R/', (string) $lines) ?: [] as $number => $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }
            [$key, $value] = array_pad(explode('=', $line, 2), 2, null);
            $key = trim((string) $key);
            if ($value === null || preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', $key) !== 1) {
                throw ValidationException::withMessages([$field => __('Line :line should look like KEY=value.', ['line' => $number + 1])]);
            }
            $env[$key] = trim($value);
        }

        return $env;
    }
}
