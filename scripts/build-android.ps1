param(
    [ValidateSet('Debug', 'Release')]
    [string]$Configuration = 'Debug',

    # The phone's Download folder as mirrored by Windows Cross Device; pass '' to skip the copy.
    [string]$PhoneDownloads = 'C:\Users\trick\CrossDevice\moto g power 5G - 2024\storage\Download'
)

$ErrorActionPreference = 'Stop'

$repositoryRoot = Split-Path -Parent $PSScriptRoot
$project = Join-Path $repositoryRoot 'src\BloodAndBanners.Maui\BloodAndBanners.Maui.csproj'
$framework = 'net10.0-android'
$outputRoot = Join-Path $repositoryRoot "src\BloodAndBanners.Maui\bin\$Configuration\$framework"
$apkPattern = 'com.trickypig.bloodandbanners*.apk'

# A previous APK can turn read-only between builds, which makes the build fail to overwrite it.
if (Test-Path -LiteralPath $outputRoot) {
    Get-ChildItem -LiteralPath $outputRoot -Filter $apkPattern | ForEach-Object { $_.IsReadOnly = $false }
}

dotnet build $project -f $framework -c $Configuration
if ($LASTEXITCODE -ne 0) {
    throw "Android build failed with exit code $LASTEXITCODE."
}

$signedApk = Get-ChildItem -LiteralPath $outputRoot -Filter 'com.trickypig.bloodandbanners*-Signed.apk' |
    Sort-Object LastWriteTime -Descending |
    Select-Object -First 1
if ($null -eq $signedApk) {
    throw "No signed APK was found in '$outputRoot'."
}
Write-Host "Built $($signedApk.FullName)"

if ($PhoneDownloads -eq '') {
    return
}
if (-not (Test-Path -LiteralPath $PhoneDownloads)) {
    Write-Warning "Phone folder '$PhoneDownloads' is not available; the APK was not copied."
    return
}

# Only this app's APKs are replaced; other apps' downloads stay.
Get-ChildItem -LiteralPath $PhoneDownloads -Filter $apkPattern | ForEach-Object {
    Remove-Item -LiteralPath $_.FullName -Force
    Write-Host "Removed old $($_.Name) from the phone."
}
Copy-Item -LiteralPath $signedApk.FullName -Destination $PhoneDownloads
Write-Host "Copied $($signedApk.Name) to $PhoneDownloads"
