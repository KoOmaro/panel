<?php

namespace App\Mcp\Tools\Deploy;

use App\Mcp\McpContext;
use App\Mcp\Present;
use App\Mcp\Schema;
use App\Mcp\Scopes;
use App\Mcp\Tool;
use App\Mcp\ToolError;
use App\Models\DeploySource;
use App\Models\Machine;
use App\Support\Deploys\DeployOnPush;

final class UpdateAppSettings extends Tool
{
    private const string PUBLIC_ENV = '/^(NEXT_PUBLIC_|VITE_|NUXT_PUBLIC_|PUBLIC_|REACT_APP_|EXPO_PUBLIC_)/';

    public function __construct(private readonly Deploys $deploys) {}

    public function name(): string
    {
        return 'update_app_settings';
    }

    public function title(): string
    {
        return 'Change an app’s settings';
    }

    public function description(): string
    {
        return 'Changes an app’s settings (its environment variables) and deploys its live version again with them: the same code, domain, port and health check, with only the settings named here changed. set adds or replaces settings by name ({"API_URL": "https://..."}); remove takes names to drop. The rest stay as they are, and the answer only ever names settings, never their values. '
            .'The live commit is deployed again with them: an up-to-date daemon starts it beside the running container and switches over once it’s healthy, with no downtime and no rebuild (an older daemon replaces it in place, a moment of downtime); its data stays, since volumes and databases aren’t touched. Settings a frontend build bakes in (NEXT_PUBLIC_, VITE_ and the like) need a new build, so they’re refused here: change them with deploy_template. '
            .'only_newest_push changes how deploying each push works for it (get_app’s deployOnPush.onlyNewest, on by default): on, a push that comes in while the app is deploying waits, and a newer push replaces it, so only the newest commit is built; off, every push deploys straight away. It’s saved without deploying anything. '
            .'Check with the person first: say the app, the machine and which settings change. app is the name it was deployed as (list_deploys or get_app shows it). Returns the queued job; follow it with get_job or list_deploys.';
    }

    public function inputSchema(): array
    {
        return Schema::object([
            'machine' => Schema::machine(),
            'app' => Schema::string('The app: the name it was deployed as (like shop), or app:<name>.', maxLength: 300),
            'set' => ['type' => 'object', 'description' => 'Settings to add or replace, by name, like {"API_URL": "https://api.example.com"}. Values are text.', 'additionalProperties' => ['type' => 'string']],
            'remove' => Schema::array(Schema::string('A setting’s name.', maxLength: 128), 'Names of settings to remove.', maxItems: 50),
            'only_newest_push' => Schema::boolean('With deploying each push on: only build the newest of the pushes that wait while it deploys (on), or deploy every push straight away (off).'),
            'wait' => Deploys::waitSchema(),
        ], ['machine', 'app']);
    }

    public function scope(): string
    {
        return Scopes::WRITE;
    }

    public function handle(McpContext $context, array $arguments): array
    {
        $args = $this->validate($arguments, [
            'machine' => ['required', Deploys::reference()],
            'app' => ['required', Deploys::reference()],
            'set' => ['sometimes', 'array', 'max:50'],
            'set.*' => ['nullable', 'string', 'max:10000'],
            'remove' => ['sometimes', 'array', 'max:50'],
            'remove.*' => ['string', 'max:128'],
            'only_newest_push' => ['sometimes', 'boolean'],
            'wait' => Deploys::waitRules(),
        ]);

        $set = array_map(fn ($value) => (string) $value, (array) ($args['set'] ?? []));
        $remove = array_values(array_unique(array_map('strval', (array) ($args['remove'] ?? []))));
        $pushSetting = array_key_exists('only_newest_push', $args);
        if ($set === [] && $remove === [] && ! $pushSetting) {
            throw new ToolError('Say which settings to change: set some, remove some, or give only_newest_push.');
        }
        foreach ([...array_keys($set), ...$remove] as $name) {
            if (! preg_match('/^[A-Za-z_][A-Za-z0-9_]*$/', (string) $name)) {
                throw new ToolError("{$name} isn’t a setting name: use letters, digits and underscores, not starting with a digit.");
            }
            if (preg_match(self::PUBLIC_ENV, (string) $name)) {
                throw new ToolError("{$name} is read while the app is built, so changing it needs a new build: deploy it again with deploy_template.");
            }
        }
        if (($both = array_intersect(array_keys($set), $remove)) !== []) {
            throw new ToolError(implode(', ', $both).' can’t be both set and removed.');
        }

        $machine = $context->machine($args['machine']);
        $app = Deploys::appName($context, $machine, $args['app']);
        $last = Deploys::lastDeploy($machine, $app);
        if ($last === null || ! $last->isRetryable()) {
            throw new ToolError("{$app} has no successful deploy on {$machine->name} to change. list_deploys shows its deploys.");
        }

        if ($pushSetting) {
            $this->savePushSetting($context, $machine, $app, (bool) $args['only_newest_push']);
            if ($set === [] && $remove === []) {
                return [
                    'machine' => ['id' => $machine->id, 'name' => $machine->name],
                    'app' => $app,
                    'deployOnPush' => DeployOnPush::setting($machine, $app),
                    'note' => 'Saved. Nothing was deployed.',
                ];
            }
        }

        $spec = $last->command['job'];
        $repo = (string) ($spec['repo'] ?? '');
        if (Deploys::fromUpload($repo) && ! (DeploySource::fromRepoUrl($repo)?->usable() ?? false)) {
            throw new ToolError('The code uploaded for the live version has been cleared, so it can’t be deployed again. Upload it again on the app’s page in the dashboard, then change its settings.');
        }

        $env = (array) ($spec['env'] ?? []);
        if (($missing = array_values(array_diff($remove, array_keys($env)))) !== []) {
            throw new ToolError(implode(', ', $missing).' isn’t one of '.$app.'’s settings. get_app lists them.');
        }
        $changed = array_values(array_filter(array_keys($set), fn ($name) => ($env[$name] ?? null) !== $set[$name]));
        if ($changed === [] && $remove === []) {
            throw new ToolError('Those settings already have those values: nothing to change.');
        }

        $this->deploys->requireAvailable($machine);
        $spec['env'] = array_diff_key([...$env, ...$set], array_flip($remove));

        $job = $this->deploys->deploy($context, $machine, $spec);
        $job = $context->waitForJob($job, (int) ($args['wait'] ?? 0));

        return [
            'jobId' => $job->id,
            'state' => $job->state,
            'machine' => ['id' => $machine->id, 'name' => $machine->name],
            'app' => $app,
            'commit' => filled($repo) && ! Deploys::fromUpload($repo) ? ($spec['commit'] ?? null) : null,
            'added' => array_values(array_filter($changed, fn ($name) => ! array_key_exists($name, $env))),
            'replaced' => array_values(array_filter($changed, fn ($name) => array_key_exists($name, $env))),
            'removed' => $remove,
            'settings' => array_map('strval', array_keys($spec['env'])),
            'job' => Present::job($job, 20),
            'deployOnPush' => $pushSetting ? DeployOnPush::setting($machine, $app) : null,
            'deploymentsUrl' => $context->url("/machines/{$machine->id}?job={$job->id}"),
            'next' => 'The live version is deployed again with the new settings, replacing its container in place. Follow it with get_job (id '.$job->id.').',
        ];
    }

    private function savePushSetting(McpContext $context, Machine $machine, string $app, bool $onlyNewest): void
    {
        $settings = $machine->app_settings ?? [];
        $before = DeployOnPush::setting($machine, $app)['onlyNewest'];
        $settings[$app] = [...($settings[$app] ?? []), 'push_only_newest' => $onlyNewest];
        $machine->forceFill(['app_settings' => $settings])->save();

        if ($before !== $onlyNewest) {
            $context->record('app.settings', $app, $machine, ['push_only_newest' => $onlyNewest]);
        }
    }
}
