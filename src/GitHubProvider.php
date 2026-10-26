<?php

namespace Swage\GitHub;

use App\Models\SourceControlConnection;
use App\SourceControl\ConnectedAccount;
use App\SourceControl\HasSettings;
use App\SourceControl\InvalidWebhookException;
use App\SourceControl\LinksToCommits;
use App\SourceControl\ProviderSettings;
use App\SourceControl\Push;
use App\SourceControl\Setting;
use App\SourceControl\SourceControlClient;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProvider;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GithubProvider as SocialiteGitHub;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

/**
 * GitHub, connected through a GitHub App or an OAuth app.
 *
 * A GitHub App is the recommended setup: its tokens reach only the repositories the app is installed on, with only the
 * permissions it was given. An OAuth app has to ask for the repo scope, which reaches every private repository the user
 * can and lets the token push to them.
 */
class GitHubProvider implements HasSettings, LinksToCommits, SourceControlProvider
{
    /**
     * The permissions a GitHub App needs: Administration to add and remove deploy keys, Webhooks for quick deploy, and
     * Metadata, which GitHub always grants. It never needs a repository's contents, since servers clone with their
     * deploy key.
     */
    public const array APP_PERMISSIONS = ['Administration' => 'Read and write', 'Webhooks' => 'Read and write', 'Metadata' => 'Read-only'];

    public function id(): string
    {
        return 'github';
    }

    public function name(): string
    {
        return 'GitHub';
    }

    public function settings(): array
    {
        return [
            new Setting('client_id', __('Client ID'), $this->isConfigured() && ! $this->isGitHubApp()
                ? __('This is an OAuth app, which can only be given access to every private repository of whoever connects, and can push to them. Create a GitHub App instead and enter its client ID, then have everyone reconnect GitHub.')
                : __('From the GitHub App\'s settings page. A GitHub App\'s client ID starts with Iv.')),
            new Setting('client_secret', __('Client secret'), __('Generated on the GitHub App\'s settings page. Shown only once by GitHub.'), Setting::SECRET),
        ];
    }

    public function isConfigured(): bool
    {
        return filled($this->credential('client_id')) && filled($this->credential('client_secret'));
    }

    public function setupInstructions(): string
    {
        return __('Create a GitHub App under GitHub\'s Settings → Developer settings → GitHub Apps, or in your organization\'s settings, with :url as its callback URL and webhooks turned off. Give it these repository permissions and no others: :permissions. Install it on the repositories Swage should deploy, then enter its client ID and a client secret.', [
            'url' => route('source-control.callback', $this->id()),
            'permissions' => collect(self::APP_PERMISSIONS)->map(fn (string $access, string $permission): string => "{$permission} ({$access})")->implode(', '),
        ]);
    }

    /**
     * Determine if the credentials are a GitHub App's, whose client IDs start with Iv, rather than an OAuth app's.
     */
    public function isGitHubApp(): bool
    {
        return str_starts_with((string) $this->credential('client_id'), 'Iv');
    }

    /**
     * A GitHub App's access comes from its permissions and where it is installed, so it asks for no scopes. An OAuth
     * app has to ask for every repository, to add deploy keys and webhooks, and for the user's organizations.
     */
    public function redirect(): RedirectResponse
    {
        return $this->isGitHubApp()
            ? $this->socialite()->setScopes([])->redirect()
            : $this->socialite()->scopes(['repo', 'read:org'])->redirect();
    }

    public function connectedAccount(Request $request): ConnectedAccount
    {
        try {
            $user = $this->socialite()->user();
        } catch (Throwable $exception) {
            throw new SourceControlException('GitHub did not authorize the connection.', previous: $exception);
        }

        return new ConnectedAccount(
            id: (string) $user->getId(),
            username: (string) $user->getNickname(),
            token: $user->token,
            refreshToken: $user->refreshToken,
            expiresAt: $user->expiresIn ? now()->addSeconds((int) $user->expiresIn) : null,
        );
    }

    public function commitUrl(string $repository, string $commit): string
    {
        return "https://github.com/{$repository}/commit/{$commit}";
    }

    /**
     * OAuth app tokens never expire. A GitHub App's tokens expire after eight hours unless the app turns that off, so
     * an expired one is refreshed first. GitHub issues a new refresh token with every refresh, which is saved for next
     * time.
     */
    public function client(SourceControlConnection $connection): SourceControlClient
    {
        if ($connection->tokenHasExpired() && filled($connection->refresh_token)) {
            $this->refresh($connection);
        }

        return new GitHubClient($connection->token);
    }

    /**
     * Exchange the refresh token for a new pair. GitHub reports a refused refresh in the body of a successful response.
     * A connection that no longer exists, such as one captured by a queued job before its owner was deleted, is
     * refreshed in memory only.
     *
     * @throws SourceControlException
     */
    protected function refresh(SourceControlConnection $connection): void
    {
        $response = Http::asForm()->acceptJson()->timeout(15)->post('https://github.com/login/oauth/access_token', [
            'grant_type' => 'refresh_token',
            'refresh_token' => $connection->refresh_token,
            'client_id' => $this->credential('client_id'),
            'client_secret' => $this->credential('client_secret'),
        ]);

        if ($response->failed() || blank($response->json('access_token'))) {
            throw new SourceControlException('GitHub refused to refresh the connection. Reconnect GitHub from your source control settings.');
        }

        $attributes = [
            'token' => $response->json('access_token'),
            'refresh_token' => $response->json('refresh_token'),
            'expires_at' => $response->json('expires_in') ? now()->addSeconds((int) $response->json('expires_in')) : null,
        ];

        $connection->exists ? $connection->update($attributes) : $connection->forceFill($attributes);
    }

    /**
     * A setting entered on the config page, or else the GITHUB_ variable in .env.
     */
    protected function credential(string $key): ?string
    {
        return app(ProviderSettings::class)->get($this->id(), $key) ?? config("services.github.{$key}");
    }

    protected function socialite(): AbstractProvider
    {
        return Socialite::buildProvider(SocialiteGitHub::class, [
            'client_id' => $this->credential('client_id'),
            'client_secret' => $this->credential('client_secret'),
            'redirect' => route('source-control.callback', $this->id()),
        ]);
    }

    /**
     * Deliveries are signed with an HMAC of their body using the webhook's secret.
     */
    public function push(Request $request, string $secret): ?Push
    {
        $signature = 'sha256='.hash_hmac('sha256', $request->getContent(), $secret);

        if (! hash_equals($signature, (string) $request->header('X-Hub-Signature-256'))) {
            throw new InvalidWebhookException('The delivery is not signed with the webhook secret.');
        }

        $ref = (string) $request->input('ref');

        if ($request->header('X-GitHub-Event') !== 'push' || ! str_starts_with($ref, 'refs/heads/')) {
            return null;
        }

        return new Push(
            branch: substr($ref, strlen('refs/heads/')),
            pusher: $request->input('pusher.name'),
        );
    }
}
