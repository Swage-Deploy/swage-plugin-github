# GitHub for Swage

Adds GitHub as a source control provider. Sites can install repositories from your account and the organizations that allow Swage, wherever you are an admin, which adding deploy keys and webhooks needs. Swage adds a read-only deploy key to the repository and, with quick deploy on, a push webhook.

## Setup

1. **Create an OAuth app** under GitHub's **Settings → Developer settings → OAuth Apps**, or in an organization's settings:
   - Homepage URL: `https://your-swage-domain`
   - Authorization callback URL: `https://your-swage-domain/auth/github/callback`

2. **Install the plugin** from the **Available** list on Swage's **Plugins** page, where it is listed as GitHub. Swage installs the newest version, rebuilds itself with it and shows its progress. The same page shows when a newer version is available.

   From the Swage host instead:

   ```bash
   sudo -u swage php /home/swage/app/current/artisan swage:plugin:install swage/github
   ```

3. **Configure it** under **Source control → GitHub → Configure**: enter the OAuth app's client ID and secret. The page also shows the callback URL to give the app.

4. **Connect your account** under **Settings → Source control** in Swage.

Swage asks GitHub for the `repo` and `read:org` scopes, to add deploy keys and webhooks to private repositories and to list your organizations. GitHub OAuth app tokens do not expire, so a connection keeps working until you disconnect it or revoke the app in GitHub.

Until a client ID and secret are entered on its config page, the plugin uses `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` from Swage's `.env`, so installs that set GitHub up there before it became a plugin keep working.

## Webhooks

GitHub signs each delivery with the webhook's secret in the `X-Hub-Signature-256` header, which Swage checks before deploying. If the panel is behind Cloudflare Access, the `webhooks/*` bypass covers GitHub's deliveries.

## Development

The plugin implements Swage's source control contract (`App\SourceControl\SourceControlProvider`, `SourceControlClient` and `HasSettings`), version 1, so it runs inside a Swage install rather than on its own.

`tests/GitHubPluginTest.php` is a Pest test that runs inside a Swage checkout: it uses Swage's models, factories and test helpers. To run it, add this plugin's `src` directory to the Swage checkout's `autoload-dev` as `Swage\\GitHub\\`, run `composer dump-autoload`, copy the test into Swage's `tests/Feature`, and run `php artisan test --filter=GitHubPluginTest`.
