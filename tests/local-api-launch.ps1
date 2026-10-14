$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$launcher = Join-Path $repositoryRoot 'scripts\start-api.ps1'
$listener = [System.Net.Sockets.TcpListener]::new(
    [System.Net.IPAddress]::Loopback,
    0
)
$listener.Start()
$port = ([System.Net.IPEndPoint]$listener.LocalEndpoint).Port
$listener.Stop()

$runId = [Guid]::NewGuid().ToString('N')
$stdoutPath = Join-Path ([IO.Path]::GetTempPath()) "blood-banners-api-$runId.out.log"
$stderrPath = Join-Path ([IO.Path]::GetTempPath()) "blood-banners-api-$runId.err.log"
$databasePath = Join-Path ([IO.Path]::GetTempPath()) "blood-banners-api-$runId.sqlite"
$previousSigningKey = $env:BLOOD_BANNERS_SIGNING_KEY
$previousDsn = $env:BLOOD_BANNERS_DSN
$env:BLOOD_BANNERS_SIGNING_KEY = 'local-development-signing-key-32-bytes'
$env:BLOOD_BANNERS_DSN = "sqlite:$databasePath"

try {
    $process = Start-Process powershell.exe -ArgumentList @(
        '-NoProfile',
        '-ExecutionPolicy', 'Bypass',
        '-File', "`"$launcher`"",
        '-Port', $port
    ) -WorkingDirectory ([IO.Path]::GetTempPath()) -RedirectStandardOutput $stdoutPath `
        -RedirectStandardError $stderrPath -PassThru

    $deadline = [DateTimeOffset]::UtcNow.AddSeconds(15)
    do {
        if ($process.HasExited) {
            $stderr = if (Test-Path $stderrPath) { Get-Content $stderrPath -Raw } else { '' }
            throw "Local API launcher exited before serving requests. $stderr"
        }

        try {
            $response = Invoke-RestMethod -Uri "http://localhost:$port/api/v1/compatibility"
            break
        }
        catch {
            Start-Sleep -Milliseconds 100
        }
    } while ([DateTimeOffset]::UtcNow -lt $deadline)

    if ($null -eq $response) {
        throw 'Local API launcher did not become ready within 15 seconds.'
    }
    if ($response.apiVersion -ne '1.0') {
        throw "Expected API version 1.0, received '$($response.apiVersion)'."
    }
    if (-not (Test-Path -LiteralPath $databasePath -PathType Leaf)) {
        throw 'Local API migration did not create its SQLite database.'
    }

    Write-Host 'PASS: local API starts from outside the repository and serves compatibility data.'
}
finally {
    if ($null -ne $process -and -not $process.HasExited) {
        Get-CimInstance Win32_Process -Filter "ParentProcessId = $($process.Id)" |
            ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
        Stop-Process -Id $process.Id -Force
        $process.WaitForExit()
    }
    $env:BLOOD_BANNERS_SIGNING_KEY = $previousSigningKey
    $env:BLOOD_BANNERS_DSN = $previousDsn
    Remove-Item -LiteralPath $stdoutPath, $stderrPath -Force -ErrorAction SilentlyContinue
    Remove-Item -LiteralPath $databasePath, "$databasePath-shm", "$databasePath-wal" -Force -ErrorAction SilentlyContinue
}
