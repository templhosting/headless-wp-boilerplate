# Headless WP Boilerplate

WordPress as a headless backend.
One site by default, switchable to a multisite network when you need to host many customer sites that must not see each other's data.
Each site exposes REST endpoints for a contact form and a newsletter, guarded by per-site API keys.

This repository is a `wp-content` overlay and nothing else: must-use plugins, two feature plugins, one theme.
Core, uploads and the database never enter git.
In development the overlay is bind-mounted into the official `wordpress` image; in production it is rsynced to a [Templ](https://templ.io) host.

## Setup with an AI agent

This repo is built to be set up by an AI coding agent.
Clone it, open it in your AI coding harness, and say:

> Guide me through setting up this headless WordPress boilerplate for my project and deploying it to Templ.

The agent will interview you about naming, single-site vs multisite, and hosting before it touches anything - that script lives in `AGENTS.md` > Setting this repo up for a human.
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

## What you get

- Three admin screens - **Settings > API keys**, and top-level **Contact Form** and **Newsletter** - with an independent copy per site under multisite.
- Key-protected REST endpoints for submissions and subscribers. Every route needs a key except the token unsubscribe, which is opened from an email client that cannot carry one.
- A theme that closes the public frontend: anonymous visitors are sent to `wp-login.php`, logged-in users to the admin, and nothing is served but the API.
- Seven hooks for extending without forking.

A headless frontend should proxy these endpoints through its own server so the key stays server-side.

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
