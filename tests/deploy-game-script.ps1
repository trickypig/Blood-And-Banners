$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$helpers = Join-Path $repositoryRoot 'scripts\deployment\DeploymentHelpers.psm1'
$deployer = Join-Path $repositoryRoot 'scripts\deploy-game.ps1'
$activationScript = Join-Path $repositoryRoot 'scripts\deployment\activate-game-release.sh'

function Invoke-WslTest {
    param([string[]]$Arguments)

    $Arguments = @($Arguments | ForEach-Object { $_.Replace("`r`n", "`n") })
    $previousErrorAction = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        $output = & wsl.exe -e @Arguments 2>&1
        return [pscustomobject]@{ ExitCode = $LASTEXITCODE; Output = @($output) }
    }
    finally {
        $ErrorActionPreference = $previousErrorAction
    }
}

Import-Module $helpers -Force

try {
    $layout = New-GameRemoteLayout '/trickypig/blood/public/' '20261013T120000Z-abcdef1'
    if ($layout.Root -ne 'trickypig/blood') { throw 'remote account root mismatch' }
    if ($layout.Stage -ne 'trickypig/blood/.deploy/20261013T120000Z-abcdef1') { throw 'remote stage mismatch' }
    if ($layout.LivePublic -ne 'trickypig/blood/public/play') { throw 'public target mismatch' }
    if ($layout.LiveServer -ne 'trickypig/blood/server/api') { throw 'server target mismatch' }

    foreach ($invalid in @(
        @('/trickypig/blood/', '20261013T120000Z-abcdef1'),
        @('/trickypig/blood/public/', 'bad release'),
        @('/trickypig/blood/public/', '20261013T120000Z-abc;rm'),
        @(' /trickypig/blood/public/', '20261013T120000Z-abcdef1')
    )) {
        $rejected = $false
        try { New-GameRemoteLayout $invalid[0] $invalid[1] | Out-Null } catch { $rejected = $true }
        if (-not $rejected) { throw "invalid layout input was accepted: $($invalid -join ' ')" }
    }

    $compatibility = Assert-CompatibilityPayload '{"apiVersion":"1.0","minimumMauiVersion":"1.0.0"}'
    if ($compatibility.apiVersion -ne '1.0' -or $compatibility.minimumMauiVersion -ne '1.0.0') {
        throw 'valid compatibility JSON was not returned intact'
    }
    foreach ($invalidPayload in @(
        '<?php declare(strict_types=1);',
        '<!doctype html><title>Not JSON</title>',
        '{"apiVersion":"2.0","minimumMauiVersion":"1.0.0"}',
        '{"apiVersion":"1.0"}'
    )) {
        $rejected = $false
        try { Assert-CompatibilityPayload $invalidPayload | Out-Null } catch { $rejected = $true }
        if (-not $rejected) { throw 'unsafe compatibility response was accepted' }
    }

    $preview = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $deployer -CommandPreview 2>&1 | Out-String
    if ($LASTEXITCODE -ne 0) { throw "default command preview failed: $preview" }
    if ($preview -notmatch 'ssh ' -or $preview -notmatch 'rsync .*--dry-run') {
        throw 'default preview is missing read-only SSH or rsync dry-run checks'
    }
    if ($preview -match '(?im)\b(mkdir|mv|rm|chmod)\b|migrate\.php|\bactivate\b|\brollback\b') {
        throw 'default preview contains a remote mutation'
    }

    $secretMarker = 'preview-secret-must-not-appear'
    $previousDbPass = $env:DB_PASS
    $env:DB_PASS = $secretMarker
    try {
        $pushPreview = & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $deployer -CommandPreview -Push 2>&1 | Out-String
    }
    finally {
        $env:DB_PASS = $previousDbPass
    }
    if ($LASTEXITCODE -ne 0) { throw "push command preview failed: $pushPreview" }
    foreach ($expected in @('mkdir', 'rsync', 'php8.4-cli -l', 'migrate.php', 'activate', '/play/', 'service-worker.js', '/play/api/v1/compatibility', 'rollback')) {
        if ($pushPreview -notmatch [regex]::Escape($expected)) { throw "push preview is missing '$expected'" }
    }
    if ($pushPreview.Contains($secretMarker)) { throw 'push preview leaked an environment value' }

    $tempResult = Invoke-WslTest @('mktemp', '-d', '/tmp/blood-activate-test.XXXXXX')
    if ($tempResult.ExitCode -ne 0) { throw 'could not create a WSL activation fixture' }
    $wslTemp = $tempResult.Output[0].ToString().Trim()
    if ($wslTemp -notmatch '^/tmp/blood-activate-test\.[A-Za-z0-9]+$') { throw 'unsafe WSL fixture path' }
    $activationBase64 = [Convert]::ToBase64String([IO.File]::ReadAllBytes($activationScript))

    try {
        $setup = @'
set -eu
fixture=$1
root="$fixture/trickypig/blood"
release=20261013T120000Z-abcdef1
bad_release=20261013T110000Z-abcdef0
mkdir -p "$root/public/play" "$root/server/api" "$root/.deploy/$release/public/play" "$root/.deploy/$release/server/api" "$root/.deploy/$bad_release/public/play" "$root/.deploy/$bad_release/server/api/tools" "$fixture/bin"
printf old-public > "$root/public/play/original.txt"
printf old-server > "$root/server/api/original.php"
printf new-public > "$root/.deploy/$release/public/play/new.txt"
printf new-server > "$root/.deploy/$release/server/api/new.php"
printf bad-public > "$root/.deploy/$bad_release/public/play/bad.txt"
printf lint-failure > "$root/.deploy/$bad_release/server/api/bad.php"
printf migrate > "$root/.deploy/$bad_release/server/api/tools/migrate.php"
printf config > "$root/.env"
chmod 600 "$root/.env"
printf '%s' '__ACTIVATION_BASE64__' | base64 -d > "$fixture/activate-game-release.sh"
cat > "$fixture/bin/php8.4-cli" <<'SH'
#!/bin/sh
if test "${1:-}" = -l; then
    if grep -q lint-failure "$2"; then
        exit 1
    fi
    exit 0
fi
touch '__MIGRATION_MARKER__'
exit 0
SH
cat > "$fixture/bin/mv" <<'SH'
#!/bin/sh
count_file='__MV_COUNT_FILE__'
count=0
test ! -f "$count_file" || count=$(cat "$count_file")
count=$((count + 1))
printf '%s' "$count" > "$count_file"
if test "$count" -eq 1; then
    exec /bin/mv "$@"
fi
exit 97
SH
chmod +x "$fixture/bin/php8.4-cli" "$fixture/bin/mv" "$fixture/activate-game-release.sh"
'@
        $setup = $setup.Replace('__ACTIVATION_BASE64__', $activationBase64)
        $setup = $setup.Replace('__MV_COUNT_FILE__', "$wslTemp/mv-count")
        $setup = $setup.Replace('__MIGRATION_MARKER__', "$wslTemp/migration-called")
        $setupResult = Invoke-WslTest @('sh', '-c', $setup, 'setup', $wslTemp)
        if ($setupResult.ExitCode -ne 0) { throw "could not prepare activation fixture: $($setupResult.Output -join "`n")" }

        $lintFailure = Invoke-WslTest @(
            'sh', '-c',
            'cd "$1" && PATH="$1/bin:$PATH" sh ./activate-game-release.sh activate trickypig/blood 20261013T110000Z-abcdef0',
            'lint', $wslTemp
        )
        if ($lintFailure.ExitCode -eq 0) { throw 'activation accepted an invalid PHP file' }
        $lintStopCheck = Invoke-WslTest @(
            'sh', '-c',
            'test ! -e "$1/migration-called" && test -f "$1/trickypig/blood/public/play/original.txt" && test -f "$1/trickypig/blood/server/api/original.php" && test -f "$1/trickypig/blood/.deploy/20261013T110000Z-abcdef0/public/play/bad.txt"',
            'check', $wslTemp
        )
        if ($lintStopCheck.ExitCode -ne 0) { throw 'PHP lint failure did not stop before migration and live changes' }
        Invoke-WslTest @('rm', '-f', '--', "$wslTemp/mv-count") | Out-Null

        $failedActivation = Invoke-WslTest @(
            'sh', '-c',
            'cd "$1" && PATH="$1/bin:$PATH" sh ./activate-game-release.sh activate trickypig/blood 20261013T120000Z-abcdef1',
            'activate', $wslTemp
        )
        if ($failedActivation.ExitCode -eq 0) { throw 'forced activation failure unexpectedly succeeded' }

        $failureCheck = Invoke-WslTest @(
            'sh', '-c',
            'test -f "$1/trickypig/blood/public/play/original.txt" && test -f "$1/trickypig/blood/server/api/original.php" && test -f "$1/trickypig/blood/.deploy/20261013T120000Z-abcdef1/public/play/new.txt" && test -f "$1/trickypig/blood/.deploy/20261013T120000Z-abcdef1/server/api/new.php"',
            'check', $wslTemp
        )
        if ($failureCheck.ExitCode -ne 0) { throw 'failed activation did not restore both live directories and retain the stage' }

        $successSetup = @'
set -eu
fixture=$1
root="$fixture/trickypig/blood"
release=20261013T130000Z-abcdef2
mkdir -p "$root/.deploy/$release/public/play" "$root/.deploy/$release/server/api" "$fixture/success-bin"
printf next-public > "$root/.deploy/$release/public/play/next.txt"
printf next-server > "$root/.deploy/$release/server/api/next.php"
cp "$fixture/bin/php8.4-cli" "$fixture/success-bin/php8.4-cli"
'@
        $successSetupResult = Invoke-WslTest @('sh', '-c', $successSetup, 'setup', $wslTemp)
        if ($successSetupResult.ExitCode -ne 0) { throw 'could not prepare successful activation fixture' }

        $activation = Invoke-WslTest @(
            'sh', '-c',
            'cd "$1" && PATH="$1/success-bin:$PATH" sh ./activate-game-release.sh activate trickypig/blood 20261013T130000Z-abcdef2',
            'activate', $wslTemp
        )
        if ($activation.ExitCode -ne 0) { throw "successful activation fixture failed: $($activation.Output -join "`n")" }

        $rollback = Invoke-WslTest @(
            'sh', '-c',
            'cd "$1" && PATH="$1/success-bin:$PATH" sh ./activate-game-release.sh rollback trickypig/blood 20261013T130000Z-abcdef2',
            'rollback', $wslTemp
        )
        if ($rollback.ExitCode -ne 0) { throw "explicit rollback failed: $($rollback.Output -join "`n")" }

        $rollbackCheck = Invoke-WslTest @(
            'sh', '-c',
            'test -f "$1/trickypig/blood/public/play/original.txt" && test -f "$1/trickypig/blood/server/api/original.php" && test -f "$1/trickypig/blood/.deploy/20261013T130000Z-abcdef2/public/play/next.txt" && test -f "$1/trickypig/blood/.deploy/20261013T130000Z-abcdef2/server/api/next.php"',
            'check', $wslTemp
        )
        if ($rollbackCheck.ExitCode -ne 0) { throw 'explicit rollback did not restore both previous directories' }
    }
    finally {
        if ($null -ne $wslTemp -and $wslTemp -match '^/tmp/blood-activate-test\.[A-Za-z0-9]+$') {
            Invoke-WslTest @('rm', '-rf', '--', $wslTemp) | Out-Null
        }
    }

    Write-Host 'PASS: game deployment previews and activation rollback are safe.'
}
finally {
    Remove-Module DeploymentHelpers -ErrorAction SilentlyContinue
}
