param(
    [Parameter(Mandatory)]
    [string]$Destination,

    [string]$PublishedWebRoot
)

$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$project = Join-Path $repositoryRoot 'src\BloodAndBanners.Web\BloodAndBanners.Web.csproj'
$temporaryPublishRoot = $null

try {
    if ([string]::IsNullOrWhiteSpace($PublishedWebRoot)) {
        $temporaryPublishRoot = Join-Path ([IO.Path]::GetTempPath()) "blood-banners-publish-$([Guid]::NewGuid().ToString('N'))"
        & dotnet publish $project -c Release -o $temporaryPublishRoot
        if ($LASTEXITCODE -ne 0) {
            throw "dotnet publish failed with exit code $LASTEXITCODE."
        }
        $PublishedWebRoot = Join-Path $temporaryPublishRoot 'wwwroot'
    }

    $sourceRoot = [IO.Path]::GetFullPath($PublishedWebRoot)
    if (-not (Test-Path -LiteralPath $sourceRoot -PathType Container)) {
        throw "Published web root was not found at '$sourceRoot'."
    }

    $destinationRoot = [IO.Path]::GetFullPath($Destination)
    $playRoot = Join-Path $destinationRoot 'public\play'
    $expectedPrefix = $destinationRoot.TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
    if (-not $playRoot.StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to stage outside destination '$destinationRoot'."
    }

    if (Test-Path -LiteralPath $playRoot) {
        Remove-Item -LiteralPath $playRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Path $playRoot -Force | Out-Null
    Get-ChildItem -LiteralPath $sourceRoot -Force | Copy-Item -Destination $playRoot -Recurse -Force

    Remove-Item -LiteralPath (Join-Path $playRoot 'appsettings.Development.json') -Force -ErrorAction SilentlyContinue
    Get-ChildItem -LiteralPath $playRoot -Recurse -File -Filter '*.map' |
        Remove-Item -Force

    $indexPath = Join-Path $playRoot 'index.html'
    $index = Get-Content -Raw -LiteralPath $indexPath
    $baseTag = '<base href="/" />'
    $baseMatches = [regex]::Matches($index, [regex]::Escape($baseTag))
    if ($baseMatches.Count -ne 1) {
        throw "Expected exactly one '$baseTag' in '$indexPath'; found $($baseMatches.Count)."
    }
    $index = $index.Replace($baseTag, '<base href="/play/" />')
    [IO.File]::WriteAllText($indexPath, $index, [Text.UTF8Encoding]::new($false))

    Write-Host "Game release staged at '$playRoot'."
}
finally {
    if ($null -ne $temporaryPublishRoot) {
        Remove-Item -LiteralPath $temporaryPublishRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
