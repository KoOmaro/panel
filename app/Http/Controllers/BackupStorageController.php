<?php

namespace App\Http\Controllers;

use App\Models\Activity;
use App\Models\Integration;
use App\Models\IntegrationBucket;
use App\Models\Workspace;
use App\Support\Audit\AuditContext;
use App\Support\Audit\Audited;
use App\Support\Integrations\IntegrationFailed;
use App\Support\Integrations\Provider;
use App\Support\Integrations\Storage\BackupStorage;
use App\Support\Integrations\Storage\StoresBackups;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;

/** Backups in a connected storage account (Backblaze B2, Cloudflare R2), from its account page. */
class BackupStorageController extends Controller
{
    #[Audited]
    public function store(Request $request, Workspace $workspace, Integration $integration, BackupStorage $storage): RedirectResponse
    {
        $provider = $this->provider($workspace, $integration);
        $options = collect($provider->bucketOptions())->keyBy('key');
        $data = $request->validate([
            'name' => ['nullable', 'string', 'max:60'],
            'keep_days' => ['nullable', 'integer', Rule::in(BackupStorage::KEEP_DAYS)],
            'location' => ['nullable', 'string', Rule::in(array_column($options->get('location')['options'] ?? [], 'value'))],
            'jurisdiction' => ['nullable', 'string', Rule::in(array_column($options->get('jurisdiction')['options'] ?? [], 'value'))],
            'schedule' => ['nullable', Rule::in(BackupStorage::SCHEDULES)],
            'machines' => ['array', 'max:500'],
            'machines.*' => ['integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
        ], [], ['keep_days' => __('how long to keep backups')]);

        $before = (int) IntegrationBucket::query()->where('integration_id', $integration->id)->max('id');
        try {
            ['bucket' => $bucket, 'warning' => $warning] = $storage->setUp($integration, $provider, $request->user(), [
                ...$data,
                'keep_days' => isset($data['keep_days']) ? (int) $data['keep_days'] : null,
                'machines' => array_values(array_map('intval', $data['machines'] ?? [])),
            ]);
        } catch (IntegrationFailed $e) {
            $made = IntegrationBucket::query()->where('integration_id', $integration->id)->where('id', '>', $before)->first();
            if ($made !== null) {
                $this->record($workspace, $integration, $provider, $made, 'integration.storage_setup', ['ok' => false]);
            } else {
                AuditContext::nothingChanged();
            }

            throw ValidationException::withMessages(['storage' => $e->getMessage()]);
        }

        $this->record($workspace, $integration, $provider, $bucket, 'integration.storage_setup', [
            'ok' => $bucket->state === IntegrationBucket::READY,
            'keep_days' => $bucket->keep_days,
            'machines' => count($data['machines'] ?? []),
        ]);
        $this->toast($bucket, $warning);

        return back();
    }

    #[Audited]
    public function retry(Request $request, Workspace $workspace, Integration $integration, IntegrationBucket $bucket, BackupStorage $storage): RedirectResponse
    {
        $provider = $this->provider($workspace, $integration, $bucket);

        try {
            $warning = $storage->finish($bucket, $provider, $request->user());
        } catch (IntegrationFailed $e) {
            $this->record($workspace, $integration, $provider, $bucket, 'integration.storage_retry', ['ok' => false]);

            throw ValidationException::withMessages(['storage' => $e->getMessage()]);
        }
        $this->record($workspace, $integration, $provider, $bucket, 'integration.storage_retry', ['ok' => $bucket->state === IntegrationBucket::READY]);
        $this->toast($bucket, $warning);

        return back();
    }

    #[Audited]
    public function keys(Request $request, Workspace $workspace, Integration $integration, IntegrationBucket $bucket, BackupStorage $storage): RedirectResponse
    {
        $provider = $this->provider($workspace, $integration, $bucket);
        abort_unless($bucket->state === IntegrationBucket::NEEDS_KEYS, 409);
        $data = $request->validate([
            'access_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^\S+$/'],
            'secret_key' => ['required', 'string', 'min:16', 'max:128', 'regex:/^\S+$/'],
        ], [], ['access_key' => __('access key ID'), 'secret_key' => __('secret access key')]);

        $problem = $storage->addKeys($bucket, $provider, trim($data['access_key']), trim($data['secret_key']), $request->user());
        if ($problem !== null) {
            AuditContext::nothingChanged();

            throw ValidationException::withMessages(['secret_key' => $problem]);
        }
        $this->record($workspace, $integration, $provider, $bucket, 'integration.storage_keys');
        $this->toast($bucket, null);

        return back();
    }

    #[Audited]
    public function update(Request $request, Workspace $workspace, Integration $integration, IntegrationBucket $bucket, BackupStorage $storage): RedirectResponse
    {
        $provider = $this->provider($workspace, $integration, $bucket);
        $data = $request->validate([
            'keep_days' => ['nullable', 'integer', Rule::in(BackupStorage::KEEP_DAYS)],
            'schedule' => ['nullable', Rule::in(BackupStorage::SCHEDULES)],
            'machines' => ['array', 'max:500'],
            'machines.*' => ['integer', Rule::exists('machines', 'id')->where('workspace_id', $workspace->id)],
        ], [], ['keep_days' => __('how long to keep backups')]);

        try {
            $kept = $request->has('keep_days') && $storage->retention($bucket, $provider, isset($data['keep_days']) ? (int) $data['keep_days'] : null);
        } catch (IntegrationFailed $e) {
            AuditContext::nothingChanged();

            throw ValidationException::withMessages(['keep_days' => $e->getMessage()]);
        }
        $moved = $request->has('machines') && $bucket->destination !== null
            ? $storage->choose($bucket, array_values(array_map('intval', $data['machines'] ?? [])), (string) ($data['schedule'] ?? 'daily'), $request->user())
            : 0;

        if ($kept) {
            $this->record($workspace, $integration, $provider, $bucket, 'integration.storage_retention', ['keep_days' => $bucket->keep_days]);
        } elseif ($moved === 0) {
            AuditContext::nothingChanged();
        }

        return back();
    }

    private function provider(Workspace $workspace, Integration $integration, ?IntegrationBucket $bucket = null): Provider&StoresBackups
    {
        abort_unless($integration->workspace_id === $workspace->id, 404);
        abort_unless($bucket === null || $bucket->integration_id === $integration->id, 404);
        Gate::authorize('manageMachines', $workspace);
        $provider = $integration->provider();
        abort_unless($provider instanceof StoresBackups, 404);
        if ($integration->status === Integration::FAILING) {
            throw ValidationException::withMessages(['storage' => __(':provider stopped taking this key. Connect it again first.', ['provider' => $provider->name()])]);
        }

        return $provider;
    }

    /** @param  array<string, mixed>  $meta */
    private function record(Workspace $workspace, Integration $integration, Provider $provider, IntegrationBucket $bucket, string $action, array $meta = []): void
    {
        Activity::record($workspace, $action, $bucket->bucket, null, request()->user(), ['provider' => $provider->name(), 'account' => $integration->label, ...$meta]);
    }

    private function toast(IntegrationBucket $bucket, ?string $warning): void
    {
        Inertia::flash('toast', match (true) {
            $warning !== null => ['type' => 'error', 'message' => $warning],
            $bucket->state === IntegrationBucket::NEEDS_KEYS => ['type' => 'success', 'message' => __('Made :bucket. Add an access key for it to finish.', ['bucket' => $bucket->bucket])],
            default => ['type' => 'success', 'message' => __('Backups go to :bucket now. A test upload went through.', ['bucket' => $bucket->bucket])],
        });
    }
}
