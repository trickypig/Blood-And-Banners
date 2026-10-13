---
name: publishing-the-site
description: Use when pushing Blood and Banners live - "publish", "deploy", "push it to the server", "upload the hex map editor" - or checking whether a change reached https://trickypig.com/blood/public/. Covers the deploy script, what does and does not get uploaded, and the ssh/rsync traps on the IONOS host.
---

# Publishing the Site

The site is plain static files - no build step. `scripts\deploy.ps1` rsyncs
them to `trickypig/blood/public/` on the IONOS box, which serves
<https://trickypig.com/blood/public/> (the hex map editor is
<https://trickypig.com/blood/public/hex_map_editor.html>).

## Deploying is the user's call

**Never deploy on your own initiative** - wait for the user to ask. A dry run
is always fine; it writes nothing.

```powershell
.\scripts\deploy.ps1                # dry run - shows what would change
.\scripts\deploy.ps1 -Push          # actually deploy
.\scripts\deploy.ps1 -Prune         # dry run that also lists remote extras to delete
.\scripts\deploy.ps1 -Prune -Push   # deploy and delete remote files not in the repo
```

Always run the dry run first and read it before `-Push`.

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
is refused without `-Force`.

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

After a push, load the page and hard-refresh (Ctrl+F5) - the browser caches
the old HTML and SVGs. For a quick check from the shell:

```powershell
curl.exe -sI https://trickypig.com/blood/public/hex_map_editor.html
```
