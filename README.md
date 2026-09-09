# Headless WP Boilerplate

WordPress as a headless backend: the public frontend is closed and everything is served over REST.
One site by default, switchable to a multisite network when you need many customer sites that cannot see each other's data.

- **AI-first** - a decision record and a glossary, so a coding agent extends this codebase instead of guessing at it.
- **One-command deploy to [Templ](https://templ.io)** - `pnpm run deploy production` rsyncs the overlay and activates the plugins, and a bundled agent skill covers WP-CLI, cache purges and multisite over SSH.
- **Two example plugins** - a basic contact form and a newsletter subscriber list - showing the REST, storage and admin patterns to copy.
- **Per-site API keys** guarding every route, minted in WP Admin or WP-CLI.
- **A closed frontend** - anonymous visitors go to `wp-login.php`, logged-in users to the admin, and nothing is served but the API.
- **Tenant isolation under multisite** - each subsite gets its own keys, data and admin screens, invisible to every other site.
- **Seven hooks** for extending without forking.

This repository is a `wp-content` overlay and nothing else: must-use plugins, two example feature plugins, one theme.
Core, uploads and the database never enter git - bind-mounted into the official `wordpress` image in development, rsynced to a [Templ](https://templ.io) host in production.

The contact form and the newsletter are deliberately minimal.
They exist to demonstrate the shape of a plugin on this stack - a versioned REST namespace, a custom post type, key-protected routes, an admin screen - so you can copy one and delete both.
[`docs/extending.md`](docs/extending.md) walks through building your own on top of the same key auth.

## Setup with an AI agent

This repo is written to be handed to an AI coding agent.
Every decision that is already paid for is recorded with its reasoning, so an agent extends the codebase instead of re-deriving it - and the same notes work just as well for a human reading in.

Clone it, open it in your AI coding harness, and say:

> Guide me through setting up this headless WordPress boilerplate for my project and deploying it to Templ.

The agent interviews you about naming, single-site vs multisite and hosting before it touches anything - that script lives in `AGENTS.md` > Setting this repo up for a human.
Then it works through the steps below.

## Setup by hand

You need podman or Docker.
There is no host PHP requirement; the whole toolchain runs in containers.

Decide the first two before booting: both are cheap now and expensive later.

1. **Rename the project** in `package.json` and `composer.json`. Rebranding the code prefixes too is optional; `phpcs.xml` lists them.
2. **Choose single-site or multisite.** Single-site is the default and right for one site; multisite earns its complexity only when sites must not see each other's data.
   Switching mode needs an empty database, so choosing later means reprovisioning and losing anything already in the site.
3. **Boot the stack.**
   ```sh
   composer dev:up                  # single site, or:
   composer dev:up:multisite        # subdirectory network with a sample subsite
   ```
   The site comes up on <http://localhost:8080> with the theme and plugins active. Admin is `admin` / `password`.
   Override the port with `TEMPL_HEADLESS_PORT` in `.env`, and the sample subsite with `SAMPLE_SITE_SLUG` / `SAMPLE_SITE_TITLE`.
4. **Mint a key** from **Settings > API keys**, or `composer dev:cli wp templ-headless key create "Marketing site"`.
   The plaintext is printed once; only its hash is stored.
5. **Call an endpoint** to prove it works:
   ```sh
   curl http://localhost:8080/wp-json/templ-headless/v1/ping -H "Authorization: Bearer $KEY"
   ```
   A headless frontend should proxy these through its own server so the key stays server-side; `docs/api.md` shows the shape.
6. **Deploy to Templ.** Create the website in the [Templ panel](https://templ.io), upload your public SSH key, then:
   ```sh
   cp .templ.mjs.example .templ.mjs    # gitignored; names real hosts
   pnpm run deploy production
   ```
   Only `wp-content` ships. Always `pnpm run deploy` - bare `pnpm deploy` is a reserved pnpm builtin.

## Everyday commands

```sh
composer dev:up          # or pnpm dev
composer dev:down
composer dev:reset       # reprovision from empty
composer dev:test        # unit, then integration
composer dev:lint        # phpcs
composer dev:cli wp plugin list
pnpm run deploy production
```

## Admin screens

**Settings > API keys** mints and revokes keys; top-level **Contact Form** and **Newsletter** read the data they collect.
Under multisite each subsite gets its own independent copy of all three.

## Where to look next

| For | Read |
| --- | --- |
| Endpoints, payloads, auth headers | [`docs/api.md`](docs/api.md) |
| Adding a field, a plugin, double opt-in | [`docs/extending.md`](docs/extending.md) |
| Why the repo is built this way, and what not to break | [`AGENTS.md`](AGENTS.md) |
| What the words mean | [`CONTEXT.md`](CONTEXT.md) |
| Running WP-CLI on a live Templ site | [`.agents/skills/templ-hosting/SKILL.md`](.agents/skills/templ-hosting/SKILL.md) |
| Templ hosting itself | <https://docs.templ.site/> |

`AGENTS.md` also covers multisite, the test suites, and the version floors that must move together when you bump PHP or WordPress.

**If you fork and rebrand**, two things move as a set: the `Templ\Headless` / `templ_headless_` / `TEMPL_HEADLESS_` prefixes and text domains listed in `phpcs.xml`, and the version floors in the four places `AGENTS.md` names.
