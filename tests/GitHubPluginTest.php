<?php

/*
 * Runs inside a Swage checkout: it uses Swage's models, factories and test helpers. See the README's Development
 * section.
 */

use App\Jobs\DeploySite;
use App\Jobs\InstallRepository;
use App\Models\ProviderSetting;
use App\Models\Site;
use App\Models\SourceControlConnection;
use App\Models\User;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProviders;
use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\GithubProvider as SocialiteGitHub;
use Laravel\Socialite\Two\User as SocialiteUser;
use Livewire\Livewire;
use Swage\GitHub\GitHubClient;
use Swage\GitHub\GitHubProvider;
use Swage\GitHub\GitHubServiceProvider;

beforeEach(function () {
    $this->app->register(GitHubServiceProvider::class, force: true);
});

test('the plugin registers github as a provider, configured from its config page or .env', function () {
    config(['services.github.client_id' => null, 'services.github.client_secret' => null]);
    $provider = app(SourceControlProviders::class)->get('github');

    expect($provider)->toBeInstanceOf(GitHubProvider::class)
        ->and($provider->isConfigured())->toBeFalse()
        ->and(collect($provider->settings())->pluck('key')->all())->toBe(['client_id', 'client_secret']);
});

test('github is configured from its config page, which takes over from .env', function () {
    config(['services.github.client_id' => 'env-client', 'services.github.client_secret' => 'env-secret']);

    Livewire::actingAs(User::factory()->create(['is_admin' => true]))->test('pages::source-control.configure', ['provider' => 'github'])
        ->set('values.client_id', 'page-client')
        ->set('values.client_secret', 'page-secret')
        ->call('save')
        ->assertHasNoErrors();

    expect(app(SourceControlProviders::class)->get('github')->isConfigured())->toBeTrue()
        ->and(ProviderSetting::where('key', 'client_secret')->value('value'))->toBe('page-secret')
        ->and(DB::table('provider_settings')->where('key', 'client_secret')->value('value'))->not->toContain('page-secret');

    $location = $this->actingAs(User::factory()->create())->get(route('source-control.redirect', 'github'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('page-client')
        ->and($query['redirect_uri'])->toBe(route('source-control.callback', 'github'));
});

test('github still works from .env until it is configured on its page', function () {
    config(['services.github.client_id' => 'env-client', 'services.github.client_secret' => 'env-secret']);

    expect(app(SourceControlProviders::class)->get('github')->isConfigured())->toBeTrue();

    $location = $this->actingAs(User::factory()->create())->get(route('source-control.redirect', 'github'))->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('env-client');
});

test('github pushes to the deploy branch trigger a deployment when quick deploy is on', function () {
    Queue::fake();
    $site = Site::factory()->withRepository()->create(['quick_deploy' => true, 'webhook_secret' => 'secret']);

    $sendPush = function (string $branch, string $secret) use ($site) {
        $payload = json_encode(['ref' => "refs/heads/{$branch}", 'pusher' => ['name' => 'taylor']]);

        return $this->call('POST', route('webhooks.source-control', ['github', $site]), server: [
            'CONTENT_TYPE' => 'application/json',
            'HTTP_X_GITHUB_EVENT' => 'push',
            'HTTP_X_HUB_SIGNATURE_256' => 'sha256='.hash_hmac('sha256', $payload, $secret),
        ], content: $payload);
    };

    $sendPush('main', 'forged')->assertForbidden();
    $sendPush('feature', 'secret')->assertNoContent();
    Queue::assertNothingPushed();

    $sendPush('main', 'secret')->assertStatus(202);
    Queue::assertPushed(DeploySite::class);
    expect($site->deployments()->sole()->triggered_by)->toBe('Push by taylor');
});

test('installing a repository adds a deploy key to github and the server', function () {
    Http::fake(['api.github.com/repos/acme/app/keys' => Http::response(['id' => 123], 201)]);
    $shell = fakeShell();
    $site = Site::factory()->create(['source_control' => 'github', 'repository' => 'acme/app', 'branch' => 'main']);
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'github']);

    InstallRepository::dispatchSync($site);

    $site->refresh();

    expect($site->deploy_key_id)->toBe('123')
        ->and($site->clone_url)->toBe('git@github.com:acme/app.git')
        ->and($site->deploy_key_public)->toStartWith('ssh-ed25519 ');

    Http::assertSent(fn ($request) => $request['key'] === $site->deploy_key_public && $request['read_only'] === true);
    $shell->assertRan("sudo -u {$site->system_user} sh -c 'umask 077 && cat > \"\$1\"' sh '{$site->deployKeyPath()}'");
    $shell->assertRan("| sudo -u {$site->system_user} tee '{$site->deployKeyPath()}.pub'");
});

function fakeGitHubAccounts(): void
{
    Http::fake([
        'api.github.com/user/orgs*' => Http::response([['login' => 'zeta'], ['login' => 'acme']]),
        'api.github.com/user/repos*' => Http::response([
            ['full_name' => 'jane/blog', 'permissions' => ['admin' => true]],
        ]),
        'api.github.com/user' => Http::response(['login' => 'jane']),
        'api.github.com/orgs/acme/repos*' => Http::response([
            ['full_name' => 'acme/app', 'permissions' => ['admin' => true]],
            ['full_name' => 'acme/readonly', 'permissions' => ['admin' => false]],
        ]),
        'api.github.com/repos/acme/app' => Http::response(['default_branch' => 'trunk']),
    ]);
}

test('repositories are chosen from an account or organization, loading only that owner\'s repositories', function () {
    fakeGitHubAccounts();
    $site = Site::factory()->create();
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'github']);

    $page = Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site]);

    expect($page->instance()->owners)->toBe(['jane', 'acme', 'zeta'])
        ->and($page->instance()->repositories)->toBe([]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'repos'));

    $page->set('owner', 'acme')
        ->assertSet('repository', '')
        ->set('repository', 'acme/app')
        ->assertSet('branch', 'trunk');

    expect($page->instance()->repositories)->toBe(['acme/app']);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.github.com/orgs/acme/repos'));
    Http::assertNotSent(fn ($request) => str_starts_with($request->url(), 'https://api.github.com/user/repos'));

    $page->set('owner', 'jane')->assertSet('repository', '')->assertSet('branch', '');

    expect($page->instance()->repositories)->toBe(['jane/blog']);
    Http::assertSent(fn ($request) => str_starts_with($request->url(), 'https://api.github.com/user/repos') && $request['affiliation'] === 'owner');
});

test('repositories are not fetched for an owner the user does not belong to', function () {
    fakeGitHubAccounts();
    $site = Site::factory()->create();
    SourceControlConnection::factory()->for($site->server->user)->create(['provider' => 'github']);

    $page = Livewire::actingAs($site->server->user)->test('pages::sites.show', ['server' => $site->server, 'site' => $site])
        ->set('owner', 'someone-else');

    expect($page->instance()->repositories)->toBe([]);
    Http::assertNotSent(fn ($request) => str_contains($request->url(), 'someone-else'));
});

test('repository listings follow every page', function () {
    Http::fake([
        'api.github.com/user' => Http::response(['login' => 'jane']),
        'api.github.com/orgs/acme/repos*' => Http::sequence()
            ->push(collect(range(1, 100))->map(fn ($i) => ['full_name' => "acme/repo-{$i}", 'permissions' => ['admin' => true]])->all(), 200, ['Link' => '<https://api.github.com/orgs/acme/repos?page=2>; rel="next"'])
            ->push([['full_name' => 'acme/repo-101', 'permissions' => ['admin' => true]]]),
    ]);

    expect((new GitHubClient('token'))->repositories('acme'))->toHaveCount(101);
});

test('connecting github asks for access to repositories and organizations', function () {
    config(['services.github' => ['client_id' => 'client', 'client_secret' => 'secret', 'redirect' => 'https://swage.test/auth/github/callback']]);

    $location = $this->actingAs(User::factory()->create())->get(route('source-control.redirect', 'github'))->assertRedirect()->headers->get('Location');

    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect(explode(',', $query['scope']))->toContain('repo', 'read:org');
});

test('connecting a github app stores the refresh token its expiring tokens come with', function () {
    config(['services.github' => ['client_id' => 'client', 'client_secret' => 'secret', 'redirect' => 'https://swage.test/auth/github/callback']]);
    $user = User::factory()->create();
    $socialiteUser = (new SocialiteUser)->map(['id' => 9, 'nickname' => 'jane'])->setToken('access')->setRefreshToken('refresh')->setExpiresIn(28800);
    $socialite = Mockery::mock(SocialiteGitHub::class);
    $socialite->allows('user')->andReturn($socialiteUser);
    Socialite::shouldReceive('buildProvider')->andReturn($socialite);

    $this->actingAs($user)->get(route('source-control.callback', 'github'))->assertRedirect(route('source-control.edit'));

    expect($user->sourceControlConnection('github'))
        ->token->toBe('access')
        ->refresh_token->toBe('refresh')
        ->expires_at->isFuture()->toBeTrue();
});

test('an expired github token is refreshed and the new pair saved before github is called', function () {
    config(['services.github' => ['client_id' => 'client', 'client_secret' => 'secret']]);
    Http::fake([
        'github.com/login/oauth/access_token' => Http::response(['access_token' => 'new-token', 'refresh_token' => 'new-refresh', 'expires_in' => 28800]),
        'api.github.com/user' => Http::response(['login' => 'jane']),
    ]);
    $connection = SourceControlConnection::factory()->create(['provider' => 'github', 'token' => 'old', 'refresh_token' => 'old-refresh', 'expires_at' => now()->subMinute()]);

    app(SourceControlProviders::class)->get('github')->client($connection)->username();

    expect($connection->fresh())
        ->token->toBe('new-token')
        ->refresh_token->toBe('new-refresh')
        ->expires_at->isFuture()->toBeTrue();

    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://github.com/login/oauth/access_token' && $request['grant_type'] === 'refresh_token' && $request['refresh_token'] === 'old-refresh' && $request['client_id'] === 'client');
    Http::assertSent(fn (ClientRequest $request) => $request->url() === 'https://api.github.com/user' && $request->hasHeader('Authorization', 'Bearer new-token'));
});

test('github tokens that never expire are used as they are', function () {
    Http::fake(['api.github.com/user' => Http::response(['login' => 'jane'])]);
    $connection = SourceControlConnection::factory()->create(['provider' => 'github', 'token' => 'current', 'refresh_token' => null, 'expires_at' => null]);

    app(SourceControlProviders::class)->get('github')->client($connection)->username();

    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), 'login/oauth'));
});

test('a refused github refresh asks the user to reconnect', function () {
    Http::fake(['github.com/login/oauth/access_token' => Http::response(['error' => 'bad_refresh_token'])]);
    $connection = SourceControlConnection::factory()->create(['provider' => 'github', 'refresh_token' => 'revoked', 'expires_at' => now()->subMinute()]);

    expect(fn () => app(SourceControlProviders::class)->get('github')->client($connection))
        ->toThrow(SourceControlException::class, 'Reconnect GitHub');
});

test('a token github no longer accepts asks the user to reconnect', function () {
    Http::fake(['api.github.com/user' => Http::response(['message' => 'Bad credentials'], 401)]);

    expect(fn () => (new GitHubClient('revoked'))->username())
        ->toThrow(SourceControlException::class, 'Reconnect GitHub from your source control settings.');
});

test('requests that create something on github are never retried', function () {
    Http::fake(['api.github.com/repos/acme/app/keys' => Http::sequence()->push('Server Error', 502)->push(['id' => 123], 201)]);

    expect(fn () => (new GitHubClient('token'))->addDeployKey('acme/app', 'Swage', 'ssh-ed25519 AAAA'))
        ->toThrow(SourceControlException::class);

    Http::assertSentCount(1);
});

test('a webhook left on the repository is updated and reused instead of failing', function () {
    Http::fake([
        'api.github.com/repos/acme/app/hooks?*' => Http::response([['id' => 7, 'config' => ['url' => 'https://swage.test/webhooks/github/1']]]),
        'api.github.com/repos/acme/app/hooks/7' => Http::response(['id' => 7]),
        'api.github.com/repos/acme/app/hooks' => Http::response(['message' => 'Validation Failed', 'errors' => [['message' => 'Hook already exists on this repository']]], 422),
    ]);

    expect((new GitHubClient('token'))->createWebhook('acme/app', 'https://swage.test/webhooks/github/1', 'new-secret'))
        ->toBe('7');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH' && $request['config']['secret'] === 'new-secret');
});

test('deployments link their commit to it on github', function () {
    expect(app(SourceControlProviders::class)->get('github')->commitUrl('acme/app', 'abc1234'))
        ->toBe('https://github.com/acme/app/commit/abc1234');
});

test('a repository whose organization turned deploy keys off says where to allow them', function () {
    Http::fake(['api.github.com/repos/acme/app/keys' => Http::response([
        'message' => 'Validation Failed',
        'errors' => [['resource' => 'PublicKey', 'code' => 'custom', 'message' => 'Deploy keys are disabled for this repository']],
    ], 422)]);

    expect(fn () => (new GitHubClient('token'))->addDeployKey('acme/app', 'Swage', 'ssh-ed25519 AAAA'))
        ->toThrow(SourceControlException::class, 'The acme organization, or its enterprise, has turned deploy keys off.');
});

test('connecting through a github app asks for no scopes, since the app\'s permissions decide its access', function () {
    config(['services.github' => ['client_id' => 'Iv23liExampleApp', 'client_secret' => 'secret']]);

    $location = $this->actingAs(User::factory()->create())->get(route('source-control.redirect', 'github'))->assertRedirect()->headers->get('Location');
    parse_str((string) parse_url($location, PHP_URL_QUERY), $query);

    expect($query['client_id'])->toBe('Iv23liExampleApp')
        ->and($query['scope'] ?? '')->toBe('');
});

test('a github app\'s accounts and repositories come from where it is installed, the user\'s own account first', function () {
    Http::fake([
        'api.github.com/user/installations/11/repositories*' => Http::response(['total_count' => 2, 'repositories' => [
            ['full_name' => 'acme/app', 'permissions' => ['admin' => true]],
            ['full_name' => 'acme/readonly', 'permissions' => ['admin' => false]],
        ]]),
        'api.github.com/user/installations*' => Http::response(['total_count' => 3, 'installations' => [
            ['id' => 11, 'account' => ['login' => 'acme']],
            ['id' => 12, 'account' => ['login' => 'Jane']],
            ['id' => 13, 'account' => ['login' => 'zeta']],
        ]]),
        'api.github.com/user' => Http::response(['login' => 'jane']),
    ]);
    $client = new GitHubClient('ghu_appusertoken');

    expect($client->owners())->toBe(['Jane', 'acme', 'zeta'])
        ->and($client->repositories('ACME'))->toBe(['acme/app'])
        ->and($client->repositories('not-installed'))->toBe([]);

    Http::assertNotSent(fn (ClientRequest $request) => str_contains($request->url(), '/user/orgs') || str_contains($request->url(), '/orgs/') || str_contains($request->url(), '/user/repos'));
});

test('an oauth app is flagged on the config page as reaching every private repository', function () {
    config(['services.github' => ['client_id' => 'Ov23liOauthApp', 'client_secret' => 'secret']]);
    $provider = app(SourceControlProviders::class)->get('github');

    expect($provider->isGitHubApp())->toBeFalse()
        ->and($provider->settings()[0]->description)->toContain('This is an OAuth app, which can only be given access to every private repository');

    config(['services.github.client_id' => 'Iv23liExampleApp']);

    expect($provider->isGitHubApp())->toBeTrue()
        ->and($provider->settings()[0]->description)->not->toContain('OAuth app')
        ->and($provider->setupInstructions())->toContain('Administration (Read and write), Webhooks (Read and write), Metadata (Read-only)');
});
