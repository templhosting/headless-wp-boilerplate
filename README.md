# Headless WP Boilerplate

A boilerplate for running WordPress as a headless backend.
It boots a single site by default and can be switched to a multisite network for hosting many isolated customer sites.
Each site exposes REST endpoints for a contact form and a newsletter, guarded by per-site API keys.

WordPress core, uploads and the database never enter git.
This repository is a `wp-content` overlay: must-use plugins, feature plugins and one theme, bind-mounted into the official `wordpress` image in development and rsynced to a Templ host in production.

## Setup with an AI agent

This repo is built to be set up by an AI coding agent. Clone it, open it in your favorite AI coding harness (Claude Code, OpenCode, Cursor, or similar), and tell the agent:

> Guide me through setting up this headless WordPress boilerplate for my project and deploying it to Templ.

The agent reads `AGENTS.md` and `CONTEXT.md` for the full contract; here is the setup it should walk you through, so you know what to expect and can nudge it:

1. **Check the container runtime.** Podman or Docker must be installed and running; there is no host PHP requirement. The agent should verify this before anything else.
2. **Rename the project.** Rename the directory and the `name` fields in `package.json` and `composer.json` to something that fits your project. If you are rebranding the code too, see [What to change when you fork](#what-to-change-when-you-fork); the agent can do the prefix rename for you.
3. **Pick a free port (optional).** The stack defaults to `8080`. If that is taken, the agent should set `TEMPL_HEADLESS_PORT` in a `.env` file.
4. **Boot the dev stack.** `composer dev:up` (or `pnpm dev`) brings up a single site on <http://localhost:8080>, installs it, and activates the theme and plugins. The agent should confirm it is up and mint a first API key to prove the endpoints work.
5. **Decide single-site or multisite.** Single-site is the default and suits one site. If you are hosting many isolated customer sites, the agent should switch you to multisite with `composer dev:reset:multisite` (see [Multisite](#multisite)).
6. **Set up deployment to Templ.** This is the part that needs your Templ panel:
   - In the [Templ panel](https://templ.io), create a website for this project. Note its **app id** and **SSH host**.
   - The agent should offer to generate an SSH keypair for you (e.g. `ssh-keygen -t ed25519`) and print the **public** key. Upload that public key to the site in the Templ panel so the deploy can connect.
   - The agent should verify SSH connectivity to the host before the first deploy (`ssh <host>` should connect and accept the host key).
   - Copy `.templ.mjs.example` to `.templ.mjs` and fill in the `app`, `host` and `dst` from the panel. `.templ.mjs` is gitignored because it names real servers.
   - First deploy: `pnpm run deploy production`. The MU plugin must land before the feature plugins are activated; the deploy's `sshCmd` activates them after the files are in place, so a first deploy handles the ordering on its own.
7. **Point at your own git remote (optional).** If you cloned this boilerplate, the agent should set the remote to your own repository so your work has a home.

The rest of this README is the manual reference for each of those steps.

## Quick start

The toolchain lives in containers; there is no host PHP requirement.
You need podman (or Docker) and either Composer or pnpm to run the shortcuts.

```sh
composer dev:up     # or: pnpm dev
```

That brings up a single site on <http://localhost:8080>, installs it, and activates the theme and plugins.
Override the port with `TEMPL_HEADLESS_PORT` in `.env`.

- Admin: <http://localhost:8080/wp-admin/> (`admin` / `password`)
- REST base: <http://localhost:8080/wp-json/>

Tear down and reprovision from empty with `composer dev:reset`.

## Admin screens

The site has:

- **Settings > API Keys** - mint, revoke and delete the keys that unlock this site's endpoints.
- **Contact Form** - read and delete submissions.
- **Newsletter** - read subscribers, filter by status, delete, and export CSV.

Under multisite each subsite has its own independent copy of all three.

## Mint a key

From WP Admin (Settings > API Keys), or from the CLI:

```sh
composer dev:cli wp templ-headless key create "Marketing site"
```

The plaintext key is printed once and never again; only its hash is stored.

Under multisite, reaching a subsite always needs `--url`:

```sh
composer dev:cli wp templ-headless key create "Marketing site" --url=http://localhost:8080/customer-one/
```

## Endpoints

Every endpoint is key-protected except the token unsubscribe.
Send the key as `Authorization: Bearer <key>`.
Full reference in [`docs/api.md`](docs/api.md).

```sh
KEY=thl_...

# A submission
curl -X POST http://localhost:8080/wp-json/templ-contact-form/v1/submissions \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"name":"Ada","email":"ada@example.com","subject":"Hi","message":"Hello"}'

# A newsletter signup
curl -X POST http://localhost:8080/wp-json/templ-newsletter/v1/subscribers \
  -H "Authorization: Bearer $KEY" -H 'Content-Type: application/json' \
  -d '{"email":"reader@example.com"}'

# Confirm a key works, without side effects
curl http://localhost:8080/wp-json/templ-headless/v1/ping \
  -H "Authorization: Bearer $KEY"
```

Under multisite the same endpoints live under each subsite's path, e.g. `http://localhost:8080/customer-one/wp-json/...`.

A headless frontend is expected to proxy these through its own server so the key stays server-side; `docs/api.md` shows the shape.

## Multisite

Multisite is off by default. Turn it on when you need to host many customer sites on one install, each with its own posts, options and API keys, invisible to every other site. This tenant isolation is the reason to reach for it; a single site needs none of it.

Switching mode needs an empty database, so reprovision:

```sh
composer dev:reset:multisite     # or: pnpm run dev:reset:multisite
```

This stands up a subdirectory network, creates a sample subsite `customer-one`, and network-activates the theme and plugins. Under the hood it sets `TEMPL_HEADLESS_MULTISITE=1`, which `tools/init.sh` reads to choose `wp core multisite-install` over `wp core install`.

- Network admin: <http://localhost:8080/wp-admin/network/> (`admin` / `password`)
- Main site admin: <http://localhost:8080/wp-admin/>
- Sample subsite admin: <http://localhost:8080/customer-one/wp-admin/>

Add a subsite from Network Admin > Sites > Add New, or:

```sh
composer dev:cli wp site create --slug=customer-two --title="Customer Two"
```

It is headless and API-ready immediately: no theme to switch, no plugin to activate.

To go back to single-site, reprovision with the plain `composer dev:reset`.

## Tests

```sh
composer dev:test             # unit suite, then integration suite (single-site)
composer dev:lint             # phpcs
```

The multisite suite tests tenant isolation and needs a multisite stack:

```sh
composer dev:reset:multisite
composer dev:test:multisite
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

The example's `sshCmd` activates the plugins for a single site; if the target runs multisite, add `--network` to the activate. On an existing install the must-use plugin in `wp-content/mu-plugins` has to be in place before the feature plugins are activated; they refuse to bootstrap without it and show an admin notice rather than fataling.

## What to change when you fork

- **Naming.** The prefixes `Templ\Headless`, `templ_headless_`, `TEMPL_HEADLESS_` and the text domains are listed in `phpcs.xml`; rename them together if you rebrand.
- **Version floors.** PHP 8.3 and WordPress 7.1 appear in four places that must move as one; see `AGENTS.md` > Version floors.
- **The sample subsite.** `tools/init.sh` creates `customer-one` under multisite; change or drop it there.

`AGENTS.md` is the decision record, `CONTEXT.md` is the glossary, and `docs/extending.md` walks through adding a field, adding double opt-in, and adding a whole new plugin that reuses the key auth.
