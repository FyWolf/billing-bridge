# Billing Bridge

Pelican Panel plugin exposing the provisioning endpoints used by the external
[HexaLabs billing service](https://github.com/FyWolf/HexalabsStorefront).

**No Filament UI. No composer packages. No database migrations.**

That is deliberate. The billing plugin this replaces was ~7,900 lines, half of it Filament, and a
Filament major bump broke the whole thing. This one registers routes and nothing else, so there is
no UI surface to break when the panel upgrades.

## What it does

Nine endpoints under `/api/application/billing/*`, all gated on a custom `billing` ACL resource:

| Method | Path | Purpose |
|---|---|---|
| `POST` | `/users` | Create (or adopt) the panel account for a billing customer |
| `GET` | `/users/external/{externalId}` | Look one up by billing customer id |
| `POST` | `/servers` | Provision a server — idempotent on `external_id` |
| `GET` | `/servers/{server}` | Current state |
| `POST` | `/servers/{server}/suspend` | Suspend |
| `POST` | `/servers/{server}/unsuspend` | Unsuspend |
| `PATCH` | `/servers/{server}/plan` | Apply new limits + egg environment after a plan change |
| `DELETE` | `/servers/{server}` | Delete |
| `GET` | `/eggs` | Eggs and their variables, for the billing app's local cache |

It also registers `HexalabsSchema`, an OAuth provider letting panel users sign in with their
billing account.

## Why a plugin rather than the core API

Two things the application API cannot do:

1. **Least-loaded-node selection.** `DeploymentPlanner` picks the node with the lowest CPU
   commitment from a pinned set. Doing that externally would mean paginating every node's
   allocations and every server's CPU over HTTP on each order.
2. **Scoped credentials.** The core suspend/delete routes require `server: write`, which grants
   delete rights over *every* server on the panel. Routing through this plugin lets the billing
   service hold `billing: write` and nothing else, and the bridge refuses any server it did not
   create (verified via `external_id`).

Never issue the billing service a root-admin `pacc_` key — those bypass the entire application ACL.

## Install

Clone into the panel's `plugins/` directory. **The directory must be named `billing-bridge`**, to
match `id` in `plugin.json`, or the panel throws `PluginIdMismatchException`:

```bash
cd /var/www/pelican/plugins
git clone https://github.com/FyWolf/billing-bridge.git billing-bridge
```

Enable it from the panel admin under **Plugins**, then confirm:

```bash
php artisan route:list | grep billing
```

Create an application API key granting **`billing`: write** only, and give it to the billing app as
`PANEL_API_KEY`.

## OAuth (optional)

To let panel users sign in with their billing account, set on the panel:

```dotenv
OAUTH_HEXALABS_ENABLED=true
OAUTH_HEXALABS_BASE_URL=https://billing.hexalabshosting.fr
OAUTH_HEXALABS_CLIENT_ID=...
OAUTH_HEXALABS_CLIENT_SECRET=...
OAUTH_HEXALABS_SHOULD_CREATE_MISSING_USERS=true
OAUTH_HEXALABS_SHOULD_LINK_MISSING_USERS=true
```

`SHOULD_LINK_MISSING_USERS` matters: the billing app pre-creates panel accounts at registration, so
without linking a customer's first login would create a second account.

Callback URI is `https://<panel>/auth/oauth/callback/hexalabs`.

## Releases

Every push to `main` cuts a release automatically. The workflow reads the current version from
`plugin.json`, derives the bump from the commit messages since the last tag, writes it back to
`plugin.json` and `updater.json`, tags, and publishes a zip.

| Commit since last tag | Bump |
|---|---|
| `feat!:` … or `BREAKING CHANGE` in the body | major |
| `feat:` / `feat(scope):` | minor |
| anything else — `fix:`, `chore:`, `docs:` … | patch |

The highest bump found wins. To set a version by hand, run the workflow from the Actions tab and
fill in the **version** input.

The bump commit carries `[skip ci]`, and pushes made with `GITHUB_TOKEN` do not trigger workflows
anyway, so the release cannot loop.

### Panel auto-update

`plugin.json` points `update_url` at `updater.json` on `main`, which the panel polls:

```json
{
  "*": {
    "version": "1.0.0",
    "download_url": "https://github.com/FyWolf/billing-bridge/releases/latest/download/billing-bridge.zip"
  }
}
```

`"*"` means "any panel version". The workflow rewrites `download_url` from the repository context on
each release, so renaming or forking the repo self-corrects — but if you rename it, update
`update_url` in `plugin.json` by hand once, since the panel reads that before it ever sees
`updater.json`.

The zip contains a `billing-bridge/` root directory because the panel requires the extracted
directory to match `id`.

## Compatibility

`plugin.json` pins `"panel_version": "^2.0"`, so the panel disables it rather than letting it break
silently after an incompatible upgrade.

Verify against an actual panel checkout after any panel bump — `php -l` only parses, and will not
catch parent or interface signature drift:

```php
$loader = require '/path/to/panel/vendor/autoload.php';
$loader->setPsr4('Hexalabs\\BillingBridge\\', __DIR__ . '/src');
// then class_exists() every class and reflect its methods
```
