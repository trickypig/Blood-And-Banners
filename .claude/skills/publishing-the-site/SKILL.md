---
name: publishing-the-site
description: Use when publishing, deploying, rolling back, or verifying the Blood and Banners legacy static site or public game at trickypig.com and blood.trickypig.com.
---

# Publishing the Site

There are two independent publishers. Choose by target and never substitute one for the other.

| Target | Script | Live URL |
|---|---|---|
| Legacy static pages | `scripts\deploy.ps1` | <https://trickypig.com/blood/public/> |
| Digital game and API | `scripts\deploy-game.ps1` | <https://blood.trickypig.com/play/> |

## Deploying is the user's call

**Never deploy on your own initiative** - wait for the user to ask. A dry run
is always fine; it writes nothing.

```powershell
.\scripts\deploy.ps1                # dry run - shows what would change
.\scripts\deploy.ps1 -Push          # actually deploy
.\scripts\deploy.ps1 -Prune         # dry run that also lists remote extras to delete
.\scripts\deploy.ps1 -Prune -Push   # deploy and delete remote files not in the repo
```

Always run the matching dry run first and read it before `-Push`. A user request to inspect or dry-run is not authorization to change permissions or push.

## Publishing the game

```powershell
.\scripts\deploy-game.ps1                 # read-only remote checks and rsync dry run
.\scripts\deploy-game.ps1 -CommandPreview # print the default remote command plan
.\scripts\deploy-game.ps1 -Push           # staged production release
```

The package contains only `public/play/` and `server/api/`. The first is public PWA output plus `api/index.php`; the second is private application code, MySQL migrations, scenarios, and the migration CLI. `.env` stays at `trickypig/blood/.env`, outside both trees, and must be mode `600` before a push.

The game script builds and tests locally, uploads to `.deploy/<release-id>`, validates PHP, runs `php8.4-cli .../tools/migrate.php --env-file trickypig/blood/.env`, activates both trees, and checks the live PWA, deep-link fallback, service worker, and compatibility API. It never prints or uploads `.env` values. The former live trees remain together at `.deploy/previous-<release-id>` for rollback. If post-activation HTTPS verification fails, the script invokes rollback.

`APP_SIGNING_KEY` rotation invalidates outstanding access tokens immediately. Refresh tokens and seat credentials are independent, so clients with a valid refresh token can recover. Never rotate it casually during a release.

## Publishing legacy static pages

## What gets uploaded

Only **git-tracked** files, as they currently are in the working tree. So:

- Uncommitted edits to tracked files *do* go up.
- A brand-new file does **not** go up until it is at least `git add`ed. If a
  new image is missing from the live site, this is why.
- Only an allowlist goes up: the `.html` pages at the root, `data/` and
  `images/` (`$IncludePatterns` in the script). Source - `api/`, `src/`,
  `tests/`, `docs/`, `scripts/`, project files, dotfiles - never does. A new
  top-level folder the site needs must be added to the allowlist first.

The files are copied into `%TEMP%\blood-deploy` and synced from there, so
untracked scratch files in the repo can't leak onto the site.

Without `-Prune`, nothing on the server is ever deleted. The live folder has
held files that never came from git (e.g. `rulebook.html.bak`), so pruning is
opt-in, and a prune that would delete more than a quarter of the live files
is refused without `-Force`. Every legacy rsync invocation protects `/play/***`, so a legacy prune cannot remove the game.

## Connection

The script reads host, user and remote path from `.vscode/sftp.json`
(gitignored, shared with the VS Code SFTP extension). It authenticates with
the WSL ssh key, never the password in that file.

Note that `sftp.json` has `uploadOnSave: true`, so files saved in VS Code may
already be live before any deploy - the dry run will show them as unchanged
or as timestamp-only differences.

### Prerequisites

rsync runs **inside WSL** against the server's own rsync. The script checks
both before touching the network:

- WSL has rsync (`wsl -e sudo apt install rsync`).
- The key is at `~/.ssh/id_ed25519` **inside WSL**, mode 600. The Windows
  copy under `/mnt/c/` cannot be used: drive mounts report 0777 and ssh
  refuses it.

```bash
wsl -e bash -lc 'cp /mnt/c/Users/trick/.ssh/id_ed25519 ~/.ssh/ && chmod 600 ~/.ssh/id_ed25519'
```

Do not try to get rsync into Git Bash: the MSYS2 build's DLLs clash with Git
Bash's runtime (`cygheap base mismatch`), and a half-done attempt can leave a
zero-byte `rsync.exe` that exits 0 and transfers nothing.

### Two traps in the remote path

- `remotePath` in `sftp.json` is `/trickypig/blood/public/`, absolute against
  the SFTP jail (the login home). Over ssh a leading slash means the real
  filesystem root, so the script strips it: ssh sees
  `trickypig/blood/public/`.
- `~/.ssh/config` sets a `RemoteCommand` for the host, which breaks
  non-interactive ssh/rsync with *"Cannot execute command-line and remote
  command."* Pass `-o RemoteCommand=none`, with `-o BatchMode=yes` so a key
  problem fails loudly instead of hanging on a password prompt.

## Verifying

After a legacy push, load the page and hard-refresh (Ctrl+F5) - the browser caches the old HTML and SVGs. For a quick check from the shell:

```powershell
curl.exe -sI https://trickypig.com/blood/public/hex_map_editor.html
```

After a game push, confirm `/play/`, `/play/service-worker.js`, a deep `/play/...` path, and `/play/api/v1/compatibility`. Then smoke-test two independent browser profiles through create, join, ready, draft, one action per seat, and refresh/reconnect.

## Common mistakes

- Running `deploy.ps1` for game files: its static allowlist intentionally excludes the game package.
- Treating a clean dry run as push approval: permission change and `-Push` require an explicit user instruction.
- Putting `.env` below `public/`: it belongs at `trickypig/blood/.env`, mode `600`.
- Manually copying only the PWA: `public/play/` and `server/api/` activate and roll back as one release.
