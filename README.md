# Headless WordPress Backend

A boilerplate for running WordPress multisite as a headless backend.
Fork it, bring up the dev stack, and host many customer sites as subsites of one network.
Each subsite exposes REST endpoints for a contact form and a newsletter, guarded by per-site API keys.

WordPress core, uploads and the database never enter git.
This repository is a `wp-content` overlay: must-use plugins, feature plugins and one theme, bind-mounted into the official `wordpress` image in development and rsynced to a Templ host in production.

## Quick start

The toolchain lives in containers; there is no host PHP requirement.
You need podman (or Docker) and either Composer or pnpm to run the shortcuts.

```sh
composer dev:up     # or: pnpm dev
```

That brings up a network on <http://localhost:8080>, installs it, creates a sample subsite `customer-one`, and network-activates the theme and plugins.
Override the port with `TEMPL_HEADLESS_PORT` in `.env`.

- Network admin: <http://localhost:8080/wp-admin/network/> (`admin` / `password`)
- Main site admin: <http://localhost:8080/wp-admin/>
- Sample subsite admin: <http://localhost:8080/customer-one/wp-admin/>

Tear down and reprovision from empty with `composer dev:reset`.

## Admin screens

Each subsite, independently, has:

- **Settings > API Keys** - mint, revoke and delete the keys that unlock that subsite's endpoints.
- **Contact Form** - read and delete submissions.
- **Newsletter** - read subscribers, filter by status, delete, and export CSV.

## Mint a key

From WP Admin (Settings > API Keys), or from the CLI, which always needs `--url` to reach a subsite:

```sh
composer dev:cli wp templ-headless key create "Marketing site" --url=http://localhost:8080/customer-one/
```

The plaintext key is printed once and never again; only its hash is stored.

## Endpoints

Every endpoint is key-protected except the token unsubscribe.
Send the key as `Authorization: Bearer <key>`.
Full reference in [`docs/api.md`](docs/api.md).

```sh
KEY=thl_...

# A submission
curl -X POST http://localhost:8080/customer-one/wp-json/templ-contact-form/v1/submissions \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","subject":"Hi","message":"Hello"}'

# A newsletter signup
curl -X POST http://localhost:8080/customer-one/wp-json/templ-newsletter/v1/subscribers \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"email":"reader@example.com"}'

# Confirm a key works, without side effects
curl http://localhost:8080/customer-one/wp-json/templ-headless/v1/ping \
  -H "Authorization: Bearer $KEY"
```

A headless frontend is expected to proxy these through its own server so the key stays server-side; `docs/api.md` shows the shape.

## Add a subsite

From Network Admin > Sites > Add New, or:

```sh
composer dev:cli wp site create --slug=customer-two --title="Customer Two"
```

It is headless and API-ready immediately: no theme to switch, no plugin to activate.

## Tests

```sh
composer dev:test             # unit suite, then integration suite
composer dev:test:multisite   # tenant-isolation suite
composer dev:lint             # phpcs
```

## Deploy

Deploys rsync the `wp-content` overlay to a Templ host and run a post-deploy command.
Copy the template and fill in your servers:

```sh
cp .templ.mjs.example .templ.mjs   # gitignored; names real hosts
pnpm run deploy production
```

`pnpm run deploy` without a target defaults to the current git branch.
Use `pnpm run deploy` (not `pnpm deploy`, which is a reserved pnpm builtin).

On an existing network the must-use plugin in `wp-content/mu-plugins` has to be in place before the feature plugins are activated; they refuse to bootstrap without it and show an admin notice rather than fataling.

## What to change when you fork

- **Naming.** The prefixes `Templ\Headless`, `templ_headless_`, `TEMPL_HEADLESS_` and the text domains are listed in `phpcs.xml`; rename them together if you rebrand.
- **Version floors.** PHP 8.3 and WordPress 7.1 appear in four places that must move as one; see `AGENTS.md` > Version floors.
- **The sample subsite.** `tools/init.sh` creates `customer-one`; change or drop it there.

`AGENTS.md` is the decision record, `CONTEXT.md` is the glossary, and `docs/extending.md` walks through adding a field, adding double opt-in, and adding a whole new plugin that reuses the key auth.
