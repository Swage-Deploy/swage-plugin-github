<?php

namespace Swage\GitHub;

use App\SourceControl\SourceControlProviders;
use Illuminate\Support\ServiceProvider;

class GitHubServiceProvider extends ServiceProvider
{
    /**
     * Read the GITHUB_ variables in .env into services.github, which the provider falls back to when nothing is
     * entered on its config page.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/github.php', 'services.github');
    }

    public function boot(): void
    {
        $this->app->make(SourceControlProviders::class)->register(GitHubProvider::class);
    }
}
