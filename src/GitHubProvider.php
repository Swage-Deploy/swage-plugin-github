<?php

namespace Swage\GitHub;

use App\Models\SourceControlConnection;
use App\SourceControl\ConnectedAccount;
use App\SourceControl\HasSettings;
use App\SourceControl\InvalidWebhookException;
use App\SourceControl\ProviderSettings;
use App\SourceControl\Push;
use App\SourceControl\Setting;
use App\SourceControl\SourceControlClient;
use App\SourceControl\SourceControlException;
use App\SourceControl\SourceControlProvider;
use Illuminate\Http\Request;
use Laravel\Socialite\Facades\Socialite;
use Laravel\Socialite\Two\AbstractProvider;
use Laravel\Socialite\Two\GithubProvider as SocialiteGitHub;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Throwable;

class GitHubProvider implements HasSettings, SourceControlProvider
{
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
            new Setting('client_id', __('Client ID'), __('From the OAuth app.')),
            new Setting('client_secret', __('Client secret'), __('Generated in the OAuth app. Shown only once by GitHub.'), Setting::SECRET),
        ];
    }

    public function isConfigured(): bool
    {
        return filled($this->credential('client_id')) && filled($this->credential('client_secret'));
    }

    public function setupInstructions(): string
    {
        return __('Create an OAuth app under GitHub\'s Settings → Developer settings → OAuth Apps, with :url as its authorization callback URL, and enter its client ID and secret.', [
            'url' => route('source-control.callback', $this->id()),
        ]);
    }

    /**
     * Ask for access to repositories, to add deploy keys and webhooks, and to list the user's organizations.
     */
    public function redirect(): RedirectResponse
    {
        return $this->socialite()->scopes(['repo', 'read:org'])->redirect();
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
        );
    }

    /**
     * GitHub OAuth app tokens never expire, so there is nothing to refresh.
     */
    public function client(SourceControlConnection $connection): SourceControlClient
    {
        return new GitHubClient($connection->token);
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
