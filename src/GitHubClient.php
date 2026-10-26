<?php

namespace Swage\GitHub;

use App\SourceControl\SourceControlClient;
use App\SourceControl\SourceControlException;
use Illuminate\Http\Client\PendingRequest;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Http;

/**
 * Acts on GitHub as a connected account. A GitHub App's user token, which starts with ghu_, only reaches the
 * repositories the app is installed on, so owners and repositories are listed from its installations. An OAuth app's
 * token lists the user's account and organizations.
 */
class GitHubClient implements SourceControlClient
{
    /**
     * The most pages of 100 fetched from a listing, which caps each owner's repositories at 1,000.
     */
    public const int MAX_PAGES = 10;

    /**
     * The login of the account the token belongs to, once fetched.
     */
    protected ?string $login = null;

    /**
     * The GitHub App's installations, once fetched.
     *
     * @var Collection<string, int>|null
     */
    protected ?Collection $installationIds = null;

    public function __construct(protected string $token) {}

    public function username(): string
    {
        return $this->login ??= $this->succeeded($this->request()->get('/user'))->json('login');
    }

    /**
     * The user's own account, then their organizations.
     *
     * For a GitHub App, these are the accounts and organizations the app is installed on. For an OAuth app,
     * organizations are only listed once the user has granted the read:org scope, and only those that allow Swage.
     */
    public function owners(): array
    {
        if ($this->isAppToken()) {
            $accounts = $this->installations()->keys()->sort(SORT_NATURAL | SORT_FLAG_CASE)->values();
            $own = $accounts->first(fn (string $login): bool => strcasecmp($login, $this->username()) === 0);

            return array_values(array_filter([$own, ...$accounts->reject(fn (string $login): bool => $login === $own)]));
        }

        $organizations = $this->paginate('/user/orgs')
            ->pluck('login')
            ->sort(SORT_NATURAL | SORT_FLAG_CASE)
            ->values()
            ->all();

        return [$this->username(), ...$organizations];
    }

    /**
     * Adding deploy keys and webhooks needs admin access, so other repositories are left out. Most recently pushed
     * first.
     */
    public function repositories(string $owner): array
    {
        if ($this->isAppToken()) {
            $installation = $this->installations()->first(fn (int $id, string $login): bool => strcasecmp($login, $owner) === 0);

            return $installation === null ? [] : $this->paginate("/user/installations/{$installation}/repositories", key: 'repositories')
                ->filter(fn (array $repository): bool => $repository['permissions']['admin'] ?? false)
                ->pluck('full_name')
                ->values()
                ->all();
        }

        $repositories = strcasecmp($owner, $this->username()) === 0
            ? $this->paginate('/user/repos', ['affiliation' => 'owner', 'sort' => 'pushed'])
            : $this->paginate('/orgs/'.rawurlencode($owner).'/repos', ['type' => 'all', 'sort' => 'pushed']);

        return $repositories
            ->filter(fn (array $repository): bool => $repository['permissions']['admin'] ?? false)
            ->pluck('full_name')
            ->values()
            ->all();
    }

    public function defaultBranch(string $repository): string
    {
        return $this->succeeded($this->request()->get("/repos/{$repository}"))->json('default_branch');
    }

    public function cloneUrl(string $repository): string
    {
        return "git@github.com:{$repository}.git";
    }

    /**
     * Organizations and enterprises can turn deploy keys off, which GitHub reports as a validation error. That is a
     * setting on GitHub, so the error says where to change it.
     */
    public function addDeployKey(string $repository, string $title, string $publicKey): string
    {
        $response = $this->request(retry: false)->post("/repos/{$repository}/keys", [
            'title' => $title,
            'key' => $publicKey,
            'read_only' => true,
        ]);

        if ($response->status() === 422 && str_contains(collect($response->json('errors', []))->pluck('message')->implode(' '), 'Deploy keys are disabled')) {
            $owner = strtok($repository, '/');

            throw new SourceControlException("GitHub does not allow deploy keys on {$repository}. The {$owner} organization, or its enterprise, has turned deploy keys off. An organization owner can allow them in the organization's settings on GitHub, under its deploy keys policy. Then install the repository again.");
        }

        return (string) $this->succeeded($response)->json('id');
    }

    public function deleteDeployKey(string $repository, string $keyId): void
    {
        $this->request()->delete("/repos/{$repository}/keys/{$keyId}");
    }

    /**
     * GitHub allows one webhook per URL, so a webhook Swage created before, such as one left behind when quick deploy
     * was last turned off, is updated with the new secret and reused.
     */
    public function createWebhook(string $repository, string $url, string $secret): string
    {
        $hook = [
            'active' => true,
            'events' => ['push'],
            'config' => [
                'url' => $url,
                'content_type' => 'json',
                'secret' => $secret,
            ],
        ];

        $response = $this->request(retry: false)->post("/repos/{$repository}/hooks", ['name' => 'web', ...$hook]);

        if ($response->status() === 422 && ($existing = $this->webhookFor($repository, $url))) {
            $response = $this->request(retry: false)->patch("/repos/{$repository}/hooks/{$existing}", $hook);
        }

        return (string) $this->succeeded($response)->json('id');
    }

    /**
     * Find the id of a repository's webhook that delivers to a URL.
     */
    protected function webhookFor(string $repository, string $url): ?int
    {
        return $this->paginate("/repos/{$repository}/hooks")
            ->firstWhere('config.url', $url)['id'] ?? null;
    }

    public function deleteWebhook(string $repository, string $webhookId): void
    {
        $this->request()->delete("/repos/{$repository}/hooks/{$webhookId}");
    }

    /**
     * Determine if the token is a GitHub App's user token rather than an OAuth app's.
     */
    protected function isAppToken(): bool
    {
        return str_starts_with($this->token, 'ghu_');
    }

    /**
     * The GitHub App's installations the user can reach, as installation ids keyed by account login.
     *
     * @return Collection<string, int>
     */
    protected function installations(): Collection
    {
        return $this->installationIds ??= $this->paginate('/user/installations', key: 'installations')
            ->mapWithKeys(fn (array $installation): array => [(string) $installation['account']['login'] => (int) $installation['id']]);
    }

    /**
     * Fetch every page of a listing, up to the page limit. Some listings wrap their items in an object, under the key.
     *
     * @param  array<string, string>  $query
     * @return Collection<int, array<string, mixed>>
     */
    protected function paginate(string $url, array $query = [], ?string $key = null): Collection
    {
        $items = collect();

        for ($page = 1; $page <= self::MAX_PAGES; $page++) {
            $response = $this->succeeded($this->request()->get($url, [...$query, 'per_page' => 100, 'page' => $page]));

            $items->push(...($key === null ? $response->json() : $response->json($key, [])));

            if (! str_contains((string) $response->header('Link'), 'rel="next"')) {
                break;
            }
        }

        return $items;
    }

    /**
     * Return a successful response, or throw GitHub's explanation of why the request failed.
     *
     * @throws SourceControlException
     */
    protected function succeeded(Response $response): Response
    {
        if ($response->successful()) {
            return $response;
        }

        if ($response->status() === 401) {
            throw new SourceControlException('GitHub no longer accepts Swage\'s connection, which happens when it is revoked or its token expired. Reconnect GitHub from your source control settings.');
        }

        $errors = collect($response->json('errors', []))->pluck('message')->filter()->implode(' ');

        throw new SourceControlException(trim('GitHub: '.($response->json('message') ?? "HTTP {$response->status()}").' '.$errors));
    }

    /**
     * Start a request to the GitHub API.
     *
     * Requests that create something are never retried: one that timed out may still have succeeded, and repeating it
     * fails because GitHub refuses a second key or webhook like the first.
     */
    protected function request(bool $retry = true): PendingRequest
    {
        return Http::baseUrl('https://api.github.com')
            ->withToken($this->token)
            ->accept('application/vnd.github+json')
            ->withHeaders(['X-GitHub-Api-Version' => '2022-11-28'])
            ->timeout(15)
            ->retry($retry ? 2 : 1, 200, throw: false);
    }
}
