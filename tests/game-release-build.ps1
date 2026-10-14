$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$builder = Join-Path $repositoryRoot 'scripts\build-game-release.ps1'
$runId = [Guid]::NewGuid().ToString('N')
$testRoot = Join-Path ([IO.Path]::GetTempPath()) "blood-banners-release-test-$runId"
$publishedWebRoot = Join-Path $testRoot 'published-wwwroot'
$destination = Join-Path $testRoot 'release'
$playRoot = Join-Path $destination 'public\play'

try {
    New-Item -ItemType Directory -Path $publishedWebRoot -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'index.html') -Value '<base href="/" />'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'index.html.br') -Value 'compressed-root-base'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'index.html.gz') -Value 'compressed-root-base'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'appsettings.Development.json') -Value '{"ApiBaseUrl":"http://localhost:8080"}'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'appsettings.Development.json.br') -Value 'compressed-development-settings'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'appsettings.Development.json.gz') -Value 'compressed-development-settings'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'app.js.map') -Value '{}'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'app.js.map.br') -Value 'compressed-source-map'
    Set-Content -LiteralPath (Join-Path $publishedWebRoot 'app.js.map.gz') -Value 'compressed-source-map'
    Copy-Item -LiteralPath (Join-Path $repositoryRoot 'src\BloodAndBanners.Web\wwwroot\manifest.webmanifest') -Destination $publishedWebRoot
    Copy-Item -LiteralPath (Join-Path $repositoryRoot 'src\BloodAndBanners.Web\wwwroot\service-worker.published.js') -Destination (Join-Path $publishedWebRoot 'service-worker.js')

    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File $builder -Destination $destination -PublishedWebRoot $publishedWebRoot
    if ($LASTEXITCODE -ne 0) {
        throw "Release builder exited with code $LASTEXITCODE."
    }

    $index = Get-Content -Raw -LiteralPath (Join-Path $playRoot 'index.html')
    if ($index -notmatch '<base href="/play/"') {
        throw 'base path is not /play/'
    }
    if (Test-Path -LiteralPath (Join-Path $playRoot 'index.html.br')) {
        throw 'stale compressed index leaked'
    }
    if (Test-Path -LiteralPath (Join-Path $playRoot 'index.html.gz')) {
        throw 'stale compressed index leaked'
    }
    if (Get-ChildItem -LiteralPath $playRoot -Recurse -File | Where-Object { $_.Name -like 'appsettings.Development.json*' }) {
        throw 'development settings leaked'
    }
    if (Get-ChildItem -LiteralPath $playRoot -Recurse -File | Where-Object { $_.Name -match '\.map(?:\.br|\.gz)?$' }) {
        throw 'source maps leaked'
    }

    $manifest = Get-Content -Raw -LiteralPath (Join-Path $playRoot 'manifest.webmanifest') | ConvertFrom-Json
    if ($manifest.scope -ne './' -or $manifest.start_url -ne './') {
        throw 'manifest scope mismatch'
    }

    $localhostMatches = Get-ChildItem -LiteralPath $playRoot -Recurse -File |
        Select-String -SimpleMatch 'http://localhost'
    if ($localhostMatches) {
        throw 'localhost URL leaked into staged release'
    }

    $serviceWorker = Get-Content -Raw -LiteralPath (Join-Path $playRoot 'service-worker.js')
    if ($serviceWorker -notmatch 'new URL\(self\.registration\.scope\)') {
        throw 'service worker does not derive baseUrl from its registration scope'
    }
    if ($serviceWorker -notmatch '\$\{baseUrl\.pathname\}api/') {
        throw 'service worker does not bypass the mounted API path'
    }
    if ($serviceWorker -notmatch 'appsettings\\\.Development\\\.json') {
        throw 'service worker does not exclude development settings from its offline cache'
    }

    $htaccessPath = Join-Path $playRoot '.htaccess'
    $gatewayPath = Join-Path $playRoot 'api\index.php'
    if (-not (Test-Path -LiteralPath $htaccessPath -PathType Leaf)) {
        throw 'Apache play rules were not staged'
    }
    if (-not (Test-Path -LiteralPath $gatewayPath -PathType Leaf)) {
        throw 'public API gateway was not staged'
    }
    if (Test-Path -LiteralPath (Join-Path $playRoot 'api\src')) {
        throw 'private API source leaked into the public tree'
    }

    $htaccess = Get-Content -Raw -LiteralPath $htaccessPath
    foreach ($requiredRule in @(
        'Options -Indexes',
        'RewriteBase /play/',
        'LimitRequestBody 1048576',
        'Cache-Control "no-cache"',
        'Cache-Control "public, max-age=31536000, immutable"'
    )) {
        if (-not $htaccess.Contains($requiredRule)) {
            throw "Apache rules are missing '$requiredRule'"
        }
    }

    if ($htaccess -match '(?im)^\s*AddHandler\b') {
        throw 'Apache rules override the PHP handler managed by IONOS'
    }

    $apiRewrite = $htaccess.IndexOf('RewriteRule ^api(?:/|$) /play/api/index.php')
    $fileExclusion = $htaccess.IndexOf('RewriteCond %{REQUEST_FILENAME} -f')
    $directoryExclusion = $htaccess.IndexOf('RewriteCond %{REQUEST_FILENAME} -d')
    $spaFallback = $htaccess.IndexOf('RewriteRule ^ /play/index.html')
    if ($apiRewrite -lt 0 -or $fileExclusion -lt 0 -or $directoryExclusion -lt 0 -or $spaFallback -lt 0) {
        throw 'Apache rewrite rules are incomplete'
    }
    if ($apiRewrite -ge $spaFallback -or $fileExclusion -ge $spaFallback -or $directoryExclusion -ge $spaFallback) {
        throw 'API and real-path rules must precede the SPA fallback'
    }

    Write-Host 'PASS: game release builder creates a clean /play/ package.'
}
finally {
    Remove-Item -LiteralPath $testRoot -Recurse -Force -ErrorAction SilentlyContinue
}
