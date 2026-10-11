param(
    [ValidateRange(1, 65535)]
    [int]$Port = 8080,

    [string]$BindAddress = 'localhost'
)

$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$publicRoot = Join-Path $repositoryRoot 'api\public'
$router = Join-Path $repositoryRoot 'api\router.php'

if (-not (Get-Command php -ErrorAction SilentlyContinue)) {
    throw 'PHP was not found on PATH. Install PHP 8.1 or newer before starting the API.'
}
if (-not (Test-Path -LiteralPath $router -PathType Leaf)) {
    throw "The PHP router was not found at '$router'."
}

& php -S "${BindAddress}:$Port" -t $publicRoot $router
if ($LASTEXITCODE -ne 0) {
    exit $LASTEXITCODE
}
