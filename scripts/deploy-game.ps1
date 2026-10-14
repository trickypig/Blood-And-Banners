<#
.SYNOPSIS
    Builds and safely deploys the game at https://blood.trickypig.com/play/.

.DESCRIPTION
    The default is a read-only remote dry run. Pass -Push only after reviewing
    that output and confirming the remote .env is mode 600.
#>
[CmdletBinding()]
param(
    [switch]$Push,
    [switch]$CommandPreview
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$helpers = Join-Path $PSScriptRoot 'deployment\DeploymentHelpers.psm1'
$activationScript = Join-Path $PSScriptRoot 'deployment\activate-game-release.sh'
Import-Module $helpers -Force

function ConvertTo-WslPath([string]$Path) {
    $full = [IO.Path]::GetFullPath($Path)
    $drive = $full.Substring(0, 1).ToLowerInvariant()
    return "/mnt/$drive$($full.Substring(2).Replace('\', '/'))"
}

function Invoke-Wsl {
    param([string[]]$Arguments)

    $output = & wsl.exe -e @Arguments 2>&1
    return [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = @($output) }
}

function Assert-CommandSuccess($Result, [string]$Description) {
    if ($Result.ExitCode -ne 0) {
        throw "$Description failed:`n$($Result.Output -join "`n")"
    }
}

function Resolve-SftpConfig {
    $direct = Join-Path $repositoryRoot '.vscode\sftp.json'
    if (Test-Path -LiteralPath $direct -PathType Leaf) {
        return $direct
    }

    $commonDirectory = (& git -C $repositoryRoot rev-parse --path-format=absolute --git-common-dir).Trim()
    if ($LASTEXITCODE -eq 0 -and $commonDirectory -ne '') {
        $shared = Join-Path (Split-Path -Parent $commonDirectory) '.vscode\sftp.json'
        if (Test-Path -LiteralPath $shared -PathType Leaf) {
            return $shared
        }
    }
    throw 'No .vscode/sftp.json was found in this worktree or its primary checkout.'
}

function Read-DeployTarget {
    $config = Get-Content -Raw -LiteralPath (Resolve-SftpConfig) | ConvertFrom-Json
    foreach ($field in @('host', 'username', 'remotePath')) {
        if (-not $config.PSObject.Properties.Name.Contains($field)) {
            throw "sftp.json is missing '$field'."
        }
    }
    if ($config.host -notmatch '^[A-Za-z0-9.-]+$' -or $config.username -notmatch '^[A-Za-z0-9._-]+$') {
        throw 'The deployment host or username contains unsupported characters.'
    }

    return [pscustomobject]@{
        Host = [string]$config.host
        User = [string]$config.username
        Layout = New-GameRemoteLayout ([string]$config.remotePath) $releaseId
    }
}

$revision = (& git -C $repositoryRoot rev-parse --short=12 HEAD).Trim().ToLowerInvariant()
if ($LASTEXITCODE -ne 0 -or $revision -notmatch '^[0-9a-f]{7,40}$') {
    throw 'Could not determine a safe Git revision for the release ID.'
}
$releaseId = "$([DateTime]::UtcNow.ToString('yyyyMMddTHHmmssZ'))-$revision"
$previewLayout = New-GameRemoteLayout '/trickypig/blood/public/' $releaseId

if ($CommandPreview) {
    Write-Output "ssh USER@HOST `"test -d '$($previewLayout.Root)' && test -f '$($previewLayout.Root)/.env' && stat -c %a '$($previewLayout.Root)/.env'`""
    Write-Output "rsync -rltz --dry-run --itemize-changes RELEASE/ USER@HOST:$($previewLayout.Root)/"
    if ($Push) {
        Write-Output "ssh USER@HOST `"test ! -e '$($previewLayout.Stage)' && mkdir -p '$($previewLayout.Stage)'`""
        Write-Output "rsync -rltz RELEASE/ USER@HOST:$($previewLayout.Stage)/"
        Write-Output "rsync activate-game-release.sh USER@HOST:$($previewLayout.Stage)/activate-game-release.sh"
        Write-Output "ssh USER@HOST `"find '$($previewLayout.Stage)/server/api' -name '*.php' -exec php8.4-cli -l {} \;`""
        Write-Output "ssh USER@HOST `"php8.4-cli '$($previewLayout.Stage)/server/api/tools/migrate.php' --env-file '$($previewLayout.Root)/.env'`""
        Write-Output "ssh USER@HOST `"sh '$($previewLayout.Stage)/activate-game-release.sh' activate '$($previewLayout.Root)' '$releaseId'`""
        Write-Output 'curl.exe --fail-with-body https://blood.trickypig.com/play/'
        Write-Output 'curl.exe --fail-with-body https://blood.trickypig.com/play/service-worker.js'
        Write-Output 'curl.exe --fail-with-body https://blood.trickypig.com/play/smoke/deep-link'
        Write-Output 'curl.exe --fail-with-body https://blood.trickypig.com/play/api/v1/compatibility'
        Write-Output "ssh USER@HOST `"sh '$($previewLayout.Stage)/activate-game-release.sh' rollback '$($previewLayout.Root)' '$releaseId'`""
    }
    exit 0
}

$releaseRoot = Join-Path ([IO.Path]::GetTempPath()) "blood-game-deploy-$([Guid]::NewGuid().ToString('N'))"

try {
    & php (Join-Path $repositoryRoot 'api\tests\run.php')
    if ($LASTEXITCODE -ne 0) { throw 'PHP tests failed.' }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $repositoryRoot 'tests\game-release-build.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'Game release checks failed.' }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $repositoryRoot 'tests\deployment-package.ps1')
    if ($LASTEXITCODE -ne 0) { throw 'Deployment package checks failed.' }
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $repositoryRoot 'scripts\build-game-release.ps1') -Destination $releaseRoot
    if ($LASTEXITCODE -ne 0) { throw 'Game release build failed.' }

    $target = Read-DeployTarget
    $layout = $target.Layout

    Assert-CommandSuccess (Invoke-Wsl @('sh', '-c', 'command -v rsync')) 'WSL rsync check'
    $homeResult = Invoke-Wsl @('sh', '-c', 'printf %s "$HOME"')
    Assert-CommandSuccess $homeResult 'WSL home check'
    $wslHome = $homeResult.Output[0].ToString().Trim()
    if ($wslHome -notmatch '^/[A-Za-z0-9._/-]+$' -or $wslHome -match '(^|/)\.\.(/|$)') {
        throw 'WSL home path contains unsupported characters.'
    }
    $keyPath = "$wslHome/.ssh/id_ed25519"
    $keyCheck = Invoke-Wsl @('sh', '-c', 'test -f "$HOME/.ssh/id_ed25519" && stat -c %a "$HOME/.ssh/id_ed25519"')
    Assert-CommandSuccess $keyCheck 'WSL SSH key check'
    if ($keyCheck.Output[0].ToString().Trim() -ne '600') {
        throw 'WSL SSH key must be mode 600.'
    }

    $sshOptions = @('-i', $keyPath, '-o', 'RemoteCommand=none', '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=20')
    $sshCommand = "ssh -i $keyPath -o RemoteCommand=none -o BatchMode=yes -o ConnectTimeout=20"
    $remote = "$($target.User)@$($target.Host)"
    $readOnlyCheck = "test -d '$($layout.Root)' && test -f '$($layout.Root)/.env' && stat -c %a '$($layout.Root)/.env'"
    $checkResult = Invoke-Wsl (@('ssh') + $sshOptions + @($remote, $readOnlyCheck))
    Assert-CommandSuccess $checkResult 'Remote game-root check'
    $envMode = $checkResult.Output[-1].ToString().Trim()
    Write-Host "Remote .env mode: $envMode"

    $source = (ConvertTo-WslPath $releaseRoot).TrimEnd('/') + '/'
    $dryRunDestination = "$remote`:$($layout.Root)/"
    $dryRun = Invoke-Wsl @('rsync', '-rltz', '--human-readable', '--dry-run', '--itemize-changes', '-e', $sshCommand, $source, $dryRunDestination)
    Assert-CommandSuccess $dryRun 'Game rsync dry run'
    $dryRun.Output | ForEach-Object { Write-Host $_ }

    if (-not $Push) {
        Write-Host 'Dry run only - nothing was written.' -ForegroundColor Yellow
        Write-Host 'Review the changes, set the remote .env to mode 600, then rerun with -Push.' -ForegroundColor Yellow
        return
    }

    $createStage = "test ! -e '$($layout.Stage)' && mkdir -p '$($layout.Stage)'"
    Assert-CommandSuccess (Invoke-Wsl (@('ssh') + $sshOptions + @($remote, $createStage))) 'Remote stage creation'

    $stageDestination = "$remote`:$($layout.Stage)/"
    Assert-CommandSuccess (Invoke-Wsl @('rsync', '-rltz', '--human-readable', '-e', $sshCommand, $source, $stageDestination)) 'Release upload'
    $activationSource = ConvertTo-WslPath $activationScript
    $activationDestination = "$remote`:$($layout.Stage)/activate-game-release.sh"
    Assert-CommandSuccess (Invoke-Wsl @('rsync', '-ltz', '--human-readable', '-e', $sshCommand, $activationSource, $activationDestination)) 'Activation script upload'

    $activateCommand = "sh '$($layout.Stage)/activate-game-release.sh' activate '$($layout.Root)' '$releaseId'"
    Assert-CommandSuccess (Invoke-Wsl (@('ssh') + $sshOptions + @($remote, $activateCommand))) 'Release activation'

    $staticHealthUrls = @(
        'https://blood.trickypig.com/play/',
        'https://blood.trickypig.com/play/service-worker.js',
        'https://blood.trickypig.com/play/smoke/deep-link'
    )
    try {
        foreach ($url in $staticHealthUrls) {
            & curl.exe --fail-with-body --silent --show-error --output NUL $url
            if ($LASTEXITCODE -ne 0) { throw "HTTPS verification failed for $url" }
        }

        $compatibilityUrl = 'https://blood.trickypig.com/play/api/v1/compatibility'
        $compatibilityJson = & curl.exe --fail-with-body --silent --show-error $compatibilityUrl
        if ($LASTEXITCODE -ne 0) { throw "HTTPS verification failed for $compatibilityUrl" }
        Assert-CompatibilityPayload ($compatibilityJson -join "`n") | Out-Null
    }
    catch {
        $rollbackCommand = "sh '$($layout.Stage)/activate-game-release.sh' rollback '$($layout.Root)' '$releaseId'"
        $rollbackResult = Invoke-Wsl (@('ssh') + $sshOptions + @($remote, $rollbackCommand))
        Assert-CommandSuccess $rollbackResult 'Automatic rollback'
        throw
    }

    Write-Host "Deployed $releaseId successfully." -ForegroundColor Green
    Write-Host "Previous release retained at $($layout.Previous)." -ForegroundColor Green
}
finally {
    Remove-Item -LiteralPath $releaseRoot -Recurse -Force -ErrorAction SilentlyContinue
    Remove-Module DeploymentHelpers -ErrorAction SilentlyContinue
}
