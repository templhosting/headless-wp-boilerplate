---
name: templ-hosting
description: >-
  How to interact with a WordPress site hosted on Templ: find the SSH
  credentials, run WP-CLI over SSH against the live site, purge cache and
  restart PHP with the Templ container CLI, and configure multisite. Use when a
  task touches a deployed Templ site rather than the local Docker stack -
  reading a live option, listing plugins on production, debugging a deploy,
  connecting over SSH/SFTP, or setting up a subdirectory multisite. Triggers on:
  Templ panel, .templ.mjs, templssh.com, app_<id>, templ-ccli, "on production",
  "on the live site", pnpm run deploy.
metadata:
  author: emanuel
  version: "2026.1.0"
---

# Templ Hosting - Operating a Live Site

Templ is managed WordPress hosting. Every website is a container with full SSH access, and `wp`, `git`, `composer`, `node` and `templ` preinstalled. Public documentation: <https://docs.templ.site/>.

This skill covers the **live site**. The local Docker stack is a different thing entirely - see `AGENTS.md` for `composer dev:up` and friends. Never assume a command that works locally works the same way over SSH.

## Where the credentials live

Connection details for this project's targets are in **`.templ.mjs`** in the repo root. It is gitignored because it names real servers; **`.templ.mjs.example`** is the committed template. Read `.templ.mjs` first - it is the source of truth for host, port and app id:

```js
production: {
  app: 20323,                        // Templ app id, from the panel
  host: 'sweden1.templssh.com',      // SSH host
  port: 26042,                       // never 22 in practice
  dst: 'app_20323/public/wp-content',
  sshCmd: '...',                     // runs on the server after rsync
}
```

If `.templ.mjs` is absent, the site has not been wired up yet. Do not guess a host. Ask, or point at the Templ Panel: the website's **Overview** tab has **SFTP/SSH → SSH Tools → SSH Command** with a ready-made command, and an **SSH Config** variant that can be pasted into `~/.ssh/config` so `ssh <domain>` just works.

Derived facts, given an app id of `20323`:

| Thing | Value |
| --- | --- |
| SSH user | `user_20323` |
| Webroot | `/home/user_20323/app_20323` (also `~/app_20323`) |
| `wp` binary | `/usr/bin/wp` |
| Templ CLI binary | `/usr/bin/templ-ccli` |

The SSH user and the app directory share the site's numeric id. Authentication is by SSH key (upload the public key in the panel) or password.

## Running WP-CLI over SSH

Prefer one-shot non-interactive commands. Two things are mandatory in that form: the **absolute path to `wp`**, because the login shell's `PATH` is not set up for a non-interactive command, and **`--path`**, because there is no working directory to infer the install from.

```sh
ssh -p 26042 user_20323@sweden1.templssh.com \
  "/usr/bin/wp --path=/home/user_20323/app_20323 option get home"
```

Quote the whole remote command. Keep `--path` on every invocation.

Common calls, same shape:

```sh
# what is actually installed and active
"/usr/bin/wp --path=/home/user_20323/app_20323 plugin list"

# confirm a deploy landed and the MU plugin is present
"/usr/bin/wp --path=/home/user_20323/app_20323 plugin list --status=must-use"

# core version and update state
"/usr/bin/wp --path=/home/user_20323/app_20323 core version"

# flush the WP object cache (not the Templ edge cache - see below)
"/usr/bin/wp --path=/home/user_20323/app_20323 cache flush"
```

When the site is fataling, add `--skip-plugins --skip-themes` so WP-CLI can load at all:

```sh
"/usr/bin/wp --path=/home/user_20323/app_20323 --skip-plugins --skip-themes plugin deactivate <slug>"
```

For an exploratory session, `ssh -p 26042 user_20323@sweden1.templssh.com`, then `cd ~/app_20323` and use plain `wp`.

**Read before you write.** `option get`, `plugin list`, `core version` are free. Anything that mutates production - `search-replace`, `plugin deactivate`, `user update`, `db query` - gets confirmed with the user first, and `search-replace` gets a `--dry-run` pass first.

## Templ container CLI

`templ` is a shell shortcut that only exists inside an interactive SSH session. Remotely, call the binary:

```sh
ssh -p 26042 user_20323@sweden1.templssh.com /usr/bin/templ-ccli cache purge
ssh -p 26042 user_20323@sweden1.templssh.com /usr/bin/templ-ccli php restart
```

`templ cache purge` clears the **Templ page cache**. That is a different layer from `wp cache flush`. After a deploy that changes markup or rewrite rules, purge the Templ cache too, otherwise the change is invisible from the outside while being correct on disk. If a change to PHP is not taking effect, `php restart` clears opcache.

## Multisite

Enabling multisite is the normal WordPress procedure - edit `wp-config.php`, run Network Setup. See <https://docs.templ.site/development/multisite>.

**Subdirectory networks need one extra step that cannot be done over SSH.** In the Templ Panel, open the website and go to **Website → Advanced → Site Options**, then activate the **multisite** toggle. Without it, the platform does not route subdirectory site URLs correctly and subsites 404 no matter how correct WordPress's own config is. Docs: <https://docs.templ.site/development/multisite#subdirectory-install>.

If subsites 404 on a network that looks correctly configured in WordPress, check that toggle before debugging anything else.

**Domain-based networks** (subdomain or separate domains) need each domain added to the website as an additional domain in the panel instead: <https://docs.templ.site/domains/additional-domains>.

WP-CLI on a network needs to be told which site it means. Network-wide operations take `--network`; per-site operations take `--url`:

```sh
"/usr/bin/wp --path=/home/user_20323/app_20323 plugin activate <slug> --network"
"/usr/bin/wp --path=/home/user_20323/app_20323 --url=https://example.com/customer-one/ option get home"
"/usr/bin/wp --path=/home/user_20323/app_20323 site list"
```

## Deploying

`pnpm run deploy <target>` rsyncs the `wp-content` overlay and then runs the target's `sshCmd` on the server. Always `pnpm run deploy` - bare `pnpm deploy` is a reserved pnpm builtin and silently does not run this script. `--dry` prints the rsync command without running it; use it before any first deploy to a new target.

The deploy ships `wp-content` and nothing else. Core, uploads and `wp-config.php` live on the server and are never touched. Full contract and the MU-plugin ordering rule are in `AGENTS.md`; do not duplicate them here.

## Docs map

- <https://docs.templ.site/> - root
- <https://docs.templ.site/development/ssh-wp-cli> - SSH, WP-CLI, Node, Templ CLI
- <https://docs.templ.site/development/multisite> - multisite, incl. the subdirectory panel toggle
- <https://docs.templ.site/development/git-push> - git push deployment
- <https://docs.templ.site/websites/file-access> - SSH keys, passwords, SFTP
- <https://docs.templ.site/platform/cron> - scheduled WP-CLI commands

Fetch the relevant page rather than guessing at panel navigation or flags; the platform changes and the docs carry a "last updated" date.
