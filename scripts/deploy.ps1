<#
.SYNOPSIS
    Pushes the site to https://trickypig.com/blood/public/.

.DESCRIPTION
    Dry run unless you pass -Push. Read what it says it would change, then
    run it again with -Push.

    Only files git tracks are uploaded, as they are in the working tree, so
    uncommitted edits to tracked files do go up but a brand-new file does not
    until it is at least `git add`ed. Repo plumbing (this script, .claude,
    .gitignore) never goes up. The files are staged into a temp folder and
    rsynced from there, so stray local files cannot leak onto the site.

    rsync runs inside WSL. Git Bash has no usable rsync - see the
    publishing-the-site skill.

    Connection details come from .vscode/sftp.json rather than being repeated
    here, so the VS Code SFTP extension and this script can never drift apart.
    That file is gitignored, which also keeps the host out of the repo. The
    script authenticates with the WSL ssh key, never the password in it.

.PARAMETER Push
    Actually transfer. Without it nothing is written, local or remote.

.PARAMETER Prune
    Also delete remote files that are not in the repo (old uploads, .bak
    files). Off by default because the live folder has historically held
    files that never came from git.

.PARAMETER Force
    Allow a -Prune that deletes an unreasonable share of the live site.

.EXAMPLE
    .\scripts\deploy.ps1
    .\scripts\deploy.ps1 -Push
    .\scripts\deploy.ps1 -Prune
    .\scripts\deploy.ps1 -Prune -Push
#>
[CmdletBinding()]
param(
    [switch]$Push,
    [switch]$Prune,
    [switch]$Force
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

# A deploy that stops should say why in one line, not dump a stack trace.
trap {
    Write-Host ''
    Write-Host $_.Exception.Message -ForegroundColor Red
    exit 1
}

$RepoRoot = Split-Path -Parent $PSScriptRoot
$SftpConfig = Join-Path $RepoRoot '.vscode\sftp.json'

# The public URL. The SFTP hostname is the hosting box, not the domain it
# serves, so it is spelled out here.
$SiteUrl = 'https://trickypig.com/blood/public/'

# Tracked files that are repo plumbing, not site content.
$ExcludePatterns = @('^\.', '^scripts/', '/\.')

# A prune that would remove more than this share of what is live is treated
# as a mistake rather than a cleanup, and needs -Force.
$MaxDeleteFraction = 0.25

# The pages the site cannot do without. Missing any of them means the staging
# went wrong, and syncing it would break the live site.
$RequiredFiles = @('index.html', 'hex_map_editor.html', 'rulebook.html')

function ConvertTo-WslPath($path) {
    $full = [IO.Path]::GetFullPath($path)
    $drive = $full.Substring(0, 1).ToLowerInvariant()
    $rest = $full.Substring(2).Replace('\', '/')
    return "/mnt/$drive$rest"
}

function Invoke-Wsl {
    param([string[]]$Arguments)
    $output = & wsl.exe -e @Arguments 2>&1
    return [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = @($output) }
}

function Read-DeployTarget {
    if (-not (Test-Path $SftpConfig)) {
        throw "No .vscode/sftp.json - this script reads the host, user and remote path from it."
    }

    $config = Get-Content $SftpConfig -Raw | ConvertFrom-Json
    foreach ($field in @('host', 'username', 'remotePath')) {
        if (-not $config.PSObject.Properties.Name.Contains($field)) {
            throw "sftp.json is missing '$field'."
        }
    }

    # remotePath is absolute against the SFTP jail, whose root is the login
    # home - but ssh lands in that same home, where a leading slash would mean
    # the real filesystem root. Strip it so ssh resolves it like the extension.
    $remote = $config.remotePath.TrimStart('/')
    if (-not $remote.EndsWith('/')) { $remote += '/' }

    return [pscustomobject]@{
        Host       = $config.host
        User       = $config.username
        RemotePath = $remote
    }
}

function Assert-Wsl {
    $rsync = Invoke-Wsl @('sh', '-c', 'command -v rsync')
    if ($rsync.ExitCode -ne 0) {
        throw "No rsync inside WSL. Install it there: wsl -e sudo apt install rsync"
    }

    # The Windows copy of the key is unusable from WSL: drive mounts report
    # 0777 and ssh refuses a world-readable key. It has to be a copy inside
    # the WSL filesystem, at 600.
    $key = Invoke-Wsl @('sh', '-c', 'test -f "$HOME/.ssh/id_ed25519" && stat -c%a "$HOME/.ssh/id_ed25519"')
    if ($key.ExitCode -ne 0) {
        throw "No key at ~/.ssh/id_ed25519 inside WSL. Copy it in and tighten it:`n" +
              "  wsl -e bash -lc 'cp /mnt/c/Users/$env:USERNAME/.ssh/id_ed25519 ~/.ssh/ && chmod 600 ~/.ssh/id_ed25519'"
    }
    if (($key.Output | Select-Object -First 1).ToString().Trim() -ne '600') {
        throw "WSL key ~/.ssh/id_ed25519 is mode $($key.Output[0]); ssh needs 600."
    }

    return (Invoke-Wsl @('sh', '-c', 'echo $HOME')).Output[0].ToString().Trim()
}

function New-Staging {
    $tracked = @(& git -C $RepoRoot ls-files)
    if ($LASTEXITCODE -ne 0) { throw "git ls-files failed - is this still a git repo?" }

    $files = @($tracked | Where-Object {
        $path = $_
        -not ($ExcludePatterns | Where-Object { $path -match $_ })
    })

    $staging = Join-Path ([IO.Path]::GetTempPath()) 'blood-deploy'
    if (Test-Path $staging) { Remove-Item -Recurse -Force $staging }

    $copied = 0
    foreach ($file in $files) {
        $source = Join-Path $RepoRoot $file
        # Tracked but deleted locally: leave it out, as the commit will.
        if (-not (Test-Path -LiteralPath $source)) { continue }
        $destination = Join-Path $staging $file
        New-Item -ItemType Directory -Force (Split-Path -Parent $destination) | Out-Null
        Copy-Item -LiteralPath $source $destination
        $copied++
    }

    foreach ($required in $RequiredFiles) {
        if (-not (Test-Path (Join-Path $staging $required))) {
            throw "Staging has no $required - refusing to sync an incomplete site."
        }
    }

    return [pscustomobject]@{ Path = $staging; Count = $copied }
}

function Get-RsyncArguments($sshCommand, $source, $destination, [switch]$DryRun) {
    # Size + mtime, not -a: the host's ownership and permissions are its own.
    $arguments = @('rsync', '-rltz', '--human-readable')
    if ($Prune) { $arguments += '--delete' }
    if ($DryRun) { $arguments += @('--dry-run', '--itemize-changes') } else { $arguments += '--verbose' }
    return $arguments + @('-e', $sshCommand, $source, $destination)
}

$target = Read-DeployTarget
$wslHome = Assert-Wsl
$staging = New-Staging

# -e is handed straight to rsync as one argument with no shell behind it, so
# the key path is spelled out - a ~ would arrive literally. RemoteCommand=none
# because ~/.ssh/config sets one for this host, which breaks non-interactive
# ssh; BatchMode so a key problem fails instead of waiting on a password.
$sshCommand = "ssh -i $wslHome/.ssh/id_ed25519 -o RemoteCommand=none -o BatchMode=yes -o ConnectTimeout=20"
$source = (ConvertTo-WslPath $staging.Path).TrimEnd('/') + '/'
$destination = "$($target.User)@$($target.Host):$($target.RemotePath)"

Write-Host ''
Write-Host "  from  $source" -ForegroundColor DarkGray
Write-Host "  to    $destination" -ForegroundColor DarkGray
Write-Host "  $($staging.Count) files$(if ($Prune) { ', pruning remote extras' })" -ForegroundColor DarkGray
Write-Host ''

$reachable = Invoke-Wsl @('sh', '-c', "$sshCommand $($target.User)@$($target.Host) 'test -d $($target.RemotePath) && echo ok'")
if ($reachable.ExitCode -ne 0) {
    Write-Host "Cannot reach the server with key auth, or $($target.RemotePath) does not exist:" -ForegroundColor Red
    $reachable.Output | ForEach-Object { Write-Host "  $_" }
    exit 1
}

$dryRun = Invoke-Wsl (Get-RsyncArguments $sshCommand $source $destination -DryRun)
if ($dryRun.ExitCode -ne 0) {
    Write-Host 'rsync dry run failed:' -ForegroundColor Red
    $dryRun.Output | ForEach-Object { Write-Host "  $_" }
    exit 1
}

$lines = @($dryRun.Output | ForEach-Object { $_.ToString() })
$deletions = @($lines | Where-Object { $_ -match '^\*deleting' })
$transfers = @($lines | Where-Object { $_ -match '^[<>]f' })

if (-not $Push) {
    # Directories whose mtime drifted itemize as their own lines and bury the
    # ones that matter; only files and deletions are worth reading.
    $interesting = @($lines | Where-Object { $_ -match '^[<>ch.]f' -or $_ -match '^\*deleting' })
    if ($interesting.Count -eq 0) {
        Write-Host '  nothing to change' -ForegroundColor DarkGray
    }
    else {
        $interesting | Select-Object -First 60 | ForEach-Object { Write-Host "  $_" }
        if ($interesting.Count -gt 60) { Write-Host "  ... and $($interesting.Count - 60) more" -ForegroundColor DarkGray }
    }

    Write-Host ''
    Write-Host 'Dry run only - nothing was written.' -ForegroundColor Yellow
    Write-Host "  $($transfers.Count) file(s) would transfer, $($deletions.Count) would be deleted." -ForegroundColor Yellow
    Write-Host '  Pass -Push to deploy.' -ForegroundColor Yellow
    return
}

# A large prune is either a long-overdue cleanup or a sign the source is
# wrong. The script cannot tell which, so it stops and lets a person look.
if ($deletions.Count -gt 0 -and -not $Force) {
    $remote = Invoke-Wsl @('sh', '-c', "$sshCommand $($target.User)@$($target.Host) 'cd $($target.RemotePath) && find . -type f | wc -l'")
    if ($remote.ExitCode -eq 0) {
        $remoteCount = [int]($remote.Output[0].ToString().Trim())
        if ($remoteCount -gt 0 -and ($deletions.Count / $remoteCount) -gt $MaxDeleteFraction) {
            Write-Host "Refusing to delete $($deletions.Count) of $remoteCount live files." -ForegroundColor Red
            Write-Host '  Check the staging is the whole site, then pass -Force if it really is right.' -ForegroundColor Red
            exit 1
        }
    }
}

Write-Host "Pushing $($transfers.Count) file(s), deleting $($deletions.Count)..." -ForegroundColor Cyan
$result = Invoke-Wsl (Get-RsyncArguments $sshCommand $source $destination)
$result.Output | ForEach-Object { Write-Host $_ }

if ($result.ExitCode -ne 0) {
    Write-Host "rsync failed with exit code $($result.ExitCode)." -ForegroundColor Red
    exit 1
}

Write-Host ''
Write-Host "Deployed - $SiteUrl" -ForegroundColor Green
