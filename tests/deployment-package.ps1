$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$helpers = Join-Path $repositoryRoot 'scripts\deployment\DeploymentHelpers.psm1'
$builder = Join-Path $repositoryRoot 'scripts\build-game-release.ps1'
$runId = [Guid]::NewGuid().ToString('N')
$testRoot = Join-Path ([IO.Path]::GetTempPath()) "blood-banners-package-test-$runId"
$publishedWebRoot = Join-Path $testRoot 'published-wwwroot'
$release = Join-Path $testRoot 'release'

try {
    Import-Module $helpers -Force

    $protection = @(Get-LegacyRsyncProtection)
    if ($protection.Count -ne 1 -or $protection[0] -ne '--filter=protect /play/***') {
        throw 'legacy rsync protection does not preserve /play/'
    }

    foreach ($safePath in @(
        'trickypig/blood/public/play',
        '/trickypig/blood/server/api/',
        'trickypig\blood\.deploy'
    )) {
        Assert-SafeDeploymentPath $safePath
    }

    foreach ($unsafePath in @(
        '/',
        '.',
        'public',
        '../trickypig/blood/public/play',
        'trickypig/blood/public/play/..',
        'elsewhere/trickypig/blood/public/play'
    )) {
        $rejected = $false
        try {
            Assert-SafeDeploymentPath $unsafePath
        }
        catch {
            $rejected = $true
        }
        if (-not $rejected) {
            throw "unsafe deployment path was accepted: $unsafePath"
        }
    }

    New-Item -ItemType Directory -Path $publishedWebRoot -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'index.html') -Value '<base href="/" />'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'appsettings.Development.json') -Value '{"ApiBaseUrl":"http://localhost:8080"}'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'app.js.map') -Value '{}'
    Copy-Item -LiteralPath (Join-Path $repositoryRoot 'src\BloodAndBanners.Web\wwwroot\manifest.webmanifest') -Destination $publishedWebRoot
    Copy-Item -LiteralPath (Join-Path $repositoryRoot 'src\BloodAndBanners.Web\wwwroot\service-worker.published.js') -Destination (Join-Path $publishedWebRoot 'service-worker.js')

    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $builder -Destination $release -PublishedWebRoot $publishedWebRoot
    if ($LASTEXITCODE -ne 0) {
        throw "Release builder exited with code $LASTEXITCODE."
    }

    $requiredPrivate = @(
        'server/api/autoload.php',
        'server/api/src/Config/AppConfig.php',
        'server/api/migrations/001_initial.mysql.sql',
        'server/api/scenarios/proving-field.json',
        'server/api/tools/migrate.php'
    )
    foreach ($relative in $requiredPrivate) {
        if (-not (Test-Path -LiteralPath (Join-Path $release $relative) -PathType Leaf)) {
            throw "missing $relative"
        }
    }

    $forbidden = Get-ChildItem -LiteralPath (Join-Path $release 'public\play') -Recurse -Force |
        Where-Object {
            $_.Name -in @('.env', 'AppConfig.php', '001_initial.mysql.sql') -or
            $_.Extension -in @('.sqlite', '.map')
        }
    if ($forbidden) {
        throw "private files entered public: $($forbidden.FullName -join ', ')"
    }

    $privateFiles = Get-ChildItem -LiteralPath (Join-Path $release 'server\api') -Recurse -File -Force
    if ($privateFiles | Where-Object { $_.Name -match '\.sqlite' -or $_.FullName -match '[\\/]tests?[\\/]' }) {
        throw 'disallowed test or SQLite content entered the private package'
    }

    $releaseRoots = @(Get-ChildItem -LiteralPath $release -Directory -Force | Select-Object -ExpandProperty Name | Sort-Object)
    if (($releaseRoots -join ',') -ne 'public,server') {
        throw "unexpected release roots: $($releaseRoots -join ', ')"
    }

    Write-Host 'PASS: deployment package contains only the public and private allowlists.'
}
finally {
    Remove-Module DeploymentHelpers -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
}
