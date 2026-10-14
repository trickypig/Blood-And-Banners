param(
    [Parameter(Mandatory)]
    [string]$Destination,

    [string]$PublishedWebRoot
)

$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$project = Join-Path $repositoryRoot 'src\BloodAndBanners.Web\BloodAndBanners.Web.csproj'
$temporaryPublishRoot = $null

function Copy-ReleaseFile {
    param(
        [Parameter(Mandatory)]
        [string]$Source,

        [Parameter(Mandatory)]
        [string]$Destination
    )

    if (-not (Test-Path -LiteralPath $Source -PathType Leaf)) {
        throw "Required release file was not found at '$Source'."
    }
    $item = Get-Item -LiteralPath $Source -Force
    if (($item.Attributes -band [IO.FileAttributes]::ReparsePoint) -ne 0) {
        throw "Refusing to package symbolic link '$Source'."
    }
    New-Item -ItemType Directory -Path (Split-Path -Parent $Destination) -Force | Out-Null
    Copy-Item -LiteralPath $Source -Destination $Destination -Force
}

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

    Get-ChildItem -LiteralPath $playRoot -Recurse -File |
        Where-Object {
            $_.Name -match '^appsettings\.Development\.json(?:\.br|\.gz)?$' -or
            $_.Name -match '\.map(?:\.br|\.gz)?$' -or
            $_.Name -match '^index\.html\.(?:br|gz)$'
        } |
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

    $deploymentTemplate = Join-Path $repositoryRoot 'scripts\deployment\play'
    Copy-Item -LiteralPath (Join-Path $deploymentTemplate '.htaccess') -Destination $playRoot -Force
    $gatewayRoot = Join-Path $playRoot 'api'
    New-Item -ItemType Directory -Path $gatewayRoot -Force | Out-Null
    Copy-Item -LiteralPath (Join-Path $deploymentTemplate 'api\index.php') -Destination $gatewayRoot -Force

    $privateRoot = Join-Path $destinationRoot 'server\api'
    if (-not $privateRoot.StartsWith($expectedPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to stage outside destination '$destinationRoot'."
    }
    if (Test-Path -LiteralPath $privateRoot) {
        Remove-Item -LiteralPath $privateRoot -Recurse -Force
    }
    New-Item -ItemType Directory -Path $privateRoot -Force | Out-Null

    $apiSourceRoot = Join-Path $repositoryRoot 'api'
    Copy-ReleaseFile -Source (Join-Path $apiSourceRoot 'autoload.php') -Destination (Join-Path $privateRoot 'autoload.php')
    Copy-ReleaseFile -Source (Join-Path $repositoryRoot 'data\gameData.json') -Destination (Join-Path $privateRoot 'data\gameData.json')

    $sourceCodeRoot = Join-Path $apiSourceRoot 'src'
    Get-ChildItem -LiteralPath $sourceCodeRoot -Recurse -File -Filter '*.php' |
        Where-Object {
            ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0 -and
            $_.FullName.Substring($sourceCodeRoot.Length).TrimStart([char[]]'\/') -notmatch '(^|[\\/])\.'
        } |
        ForEach-Object {
            $relative = $_.FullName.Substring($sourceCodeRoot.Length).TrimStart([char[]]'\/')
            Copy-ReleaseFile -Source $_.FullName -Destination (Join-Path (Join-Path $privateRoot 'src') $relative)
        }

    Get-ChildItem -LiteralPath (Join-Path $apiSourceRoot 'migrations') -File -Filter '*.mysql.sql' |
        Where-Object { -not $_.Name.StartsWith('.') -and ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0 } |
        ForEach-Object {
            Copy-ReleaseFile -Source $_.FullName -Destination (Join-Path (Join-Path $privateRoot 'migrations') $_.Name)
        }

    Get-ChildItem -LiteralPath (Join-Path $apiSourceRoot 'scenarios') -File -Filter '*.json' |
        Where-Object { -not $_.Name.StartsWith('.') -and ($_.Attributes -band [IO.FileAttributes]::ReparsePoint) -eq 0 } |
        ForEach-Object {
            Copy-ReleaseFile -Source $_.FullName -Destination (Join-Path (Join-Path $privateRoot 'scenarios') $_.Name)
        }

    Copy-ReleaseFile -Source (Join-Path $apiSourceRoot 'tools\migrate.php') -Destination (Join-Path $privateRoot 'tools\migrate.php')

    Write-Host "Game release staged at '$playRoot'."
}
finally {
    if ($null -ne $temporaryPublishRoot) {
        Remove-Item -LiteralPath $temporaryPublishRoot -Recurse -Force -ErrorAction SilentlyContinue
    }
}
