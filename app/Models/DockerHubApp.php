<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * An app deployed from a Docker Hub repository's tag, and whether a push of
 * a new image to that tag redeploys it. Docker Hub's webhooks carry no
 * signature, so each app's webhook address has its own secret in it: only
 * its hash is looked up, and the address is kept encrypted to show again.
 *
 * @property int $id
 * @property int $machine_id
 * @property string $service the app's name
 * @property int|null $integration_id the Docker Hub account, null once it's disconnected
 * @property string $repository namespace/name, like acme/web
 * @property string $tag
 * @property string|null $digest the image ServerOS last deployed
 * @property bool $redeploy_on_push
 * @property int|null $push_user_id who turned it on, whom push deploys run as
 * @property string|null $hook_key sha256 of the address's secret
 * @property string|null $hook_token
 * @property CarbonImmutable|null $first_delivery_at
 * @property CarbonImmutable|null $last_delivery_at
 * @property CarbonImmutable|null $last_push_at a push of the watched tag
 * @property string|null $last_push_digest
 * @property CarbonImmutable|null $created_at
 * @property CarbonImmutable|null $updated_at
 * @property-read Machine $machine
 * @property-read Integration|null $integration
 */
#[Hidden(['hook_key', 'hook_token'])]
class DockerHubApp extends Model
{
    public const string TOKEN_PATTERN = '[A-Za-z0-9]{40}';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'redeploy_on_push' => 'boolean',
            'hook_token' => 'encrypted',
            'first_delivery_at' => 'datetime',
            'last_delivery_at' => 'datetime',
            'last_push_at' => 'datetime',
        ];
    }

    public static function keyOf(string $token): string
    {
        return hash('sha256', $token);
    }

    public static function forToken(string $token): ?self
    {
        return self::query()->where('hook_key', self::keyOf($token))->with('machine.workspace', 'integration')->first();
    }

    /** A new secret for the webhook address: the old address stops working. */
    public function newHook(): string
    {
        $token = Str::random(40);
        $this->forceFill(['hook_key' => self::keyOf($token), 'hook_token' => $token, 'first_delivery_at' => null, 'last_delivery_at' => null]);

        return $token;
    }

    public function hookUrl(): ?string
    {
        return $this->hook_token === null ? null : rtrim((string) config('app.url'), '/').'/api/docker-hub/webhook/'.$this->hook_token;
    }

    /** @return BelongsTo<Machine, $this> */
    public function machine(): BelongsTo
    {
        return $this->belongsTo(Machine::class);
    }

    /** @return BelongsTo<Integration, $this> */
    public function integration(): BelongsTo
    {
        return $this->belongsTo(Integration::class);
    }
}
