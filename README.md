<p align="center">
    <img src="https://raw.githubusercontent.com/Swage-Deploy/swage-core/main/public/favicon.svg" width="72" height="72" alt="Swage">
</p>

<h1 align="center">GitHub for Swage</h1>

<p align="center">
    Install repositories from GitHub, with deploy keys and push to deploy.
    <br>
    <a href="https://swagedeploy.app"><strong>swagedeploy.app</strong></a> · <a href="https://swagedeploy.app/docs/source-control">Documentation</a>
</p>

---

Adds GitHub as a source control provider. Sites can install repositories from the accounts and organizations where your GitHub App is installed, wherever you are an admin, which adding deploy keys and webhooks needs. Swage adds a read-only deploy key to the repository and, with quick deploy on, a push webhook.

## Requirements

- Swage `v1.0.0` or later
- A GitHub App, created in the first step below

## Installation

1. **Create a GitHub App** under GitHub's **Settings → Developer settings → GitHub Apps**, or in an organization's settings:
   - Homepage URL: `https://your-swage-domain`
   - Callback URL: `https://your-swage-domain/auth/github/callback`
   - Webhook: untick **Active**. Swage creates its own webhook on each repository when quick deploy is turned on.
   - Repository permissions: **Administration** read and write, to add and remove deploy keys, and **Webhooks** read and write, for quick deploy. **Metadata** read-only is added automatically. Leave everything else, including **Contents**, at no access: servers clone with their deploy key, not the app.
   - Where it can be installed: **Only on this account** is enough for your own account or organization.

   Then generate a **client secret**, and **install the app** on the account or organization, choosing only the repositories Swage should deploy. Install it on more repositories later from the app's public page, `https://github.com/apps/<app-name>`, under **Configure**.

2. **Install the plugin** from the **Available** list on Swage's **Plugins** page, where it is listed as GitHub. Swage installs the newest version, rebuilds itself with it and shows its progress.

   From the Swage host instead:

   ```bash
   sudo -u swage php /home/swage/app/current/artisan swage:plugin:install swage/github
   ```

3. **Configure it** under **Plugins → System → Configure GitHub**: enter the app's client ID, which starts with `Iv`, and its client secret. The page also shows the callback URL to give the app.

4. **Connect your account** under **Settings → Source control** in Swage.

### What Swage can reach

A connection's token can only do what the app's permissions, the repositories it is installed on, and your own access all allow. It can add and remove deploy keys and webhooks on those repositories, and cannot read or push code or reach any other repository. Repositories the app is not installed on are not listed. Tokens expire after eight hours, and Swage refreshes them before they're used.

### OAuth apps

Earlier versions of this plugin were set up with an OAuth app, and that still works. But an OAuth app has to ask for the `repo` scope to add deploy keys and webhooks to private repositories, which gives its token read and write access to every private repository of whoever connects, so the config page warns about it. To switch, create a GitHub App as above, enter its client ID and secret, and have everyone reconnect GitHub under **Settings → Source control**. Then delete the OAuth app in GitHub, which revokes its tokens. Connections made before v1.0.1 of this plugin didn't keep the refresh token, so reconnect GitHub once after updating.

Until a client ID and secret are entered on its config page, the plugin uses `GITHUB_CLIENT_ID` and `GITHUB_CLIENT_SECRET` from Swage's `.env`, so installs that set GitHub up there before it became a plugin keep working.

## Webhooks

GitHub signs each delivery with the webhook's secret in the `X-Hub-Signature-256` header, which Swage checks before deploying. If the panel is behind Cloudflare Access, the `webhooks/*` bypass covers GitHub's deliveries.

## Deployments

A site's deployments link each commit to its page on GitHub.

## Updating

Swage's **Plugins** page shows when a newer version is available. Update from there, or on the Swage host:

```bash
sudo -u swage php /home/swage/app/current/artisan swage:plugin:update swage/github
```

## Development

The plugin implements Swage's source control contract (`App\SourceControl\SourceControlProvider`, `SourceControlClient` and `HasSettings`), version 1, so it runs inside a Swage install rather than on its own.

`tests/GitHubPluginTest.php` is a Pest test that runs inside a Swage checkout: it uses Swage's models, factories and test helpers. To run it:

1. Add this plugin's `src` directory to the Swage checkout's `autoload-dev` as `Swage\\GitHub\\`.
2. Run `composer dump-autoload`.
3. Copy the test into Swage's `tests/Feature`.
4. Run `php artisan test --filter=GitHubPluginTest`.

## License

Copyright (c) 2026 Node Dog Consulting. Released under the [MIT License](LICENSE).
