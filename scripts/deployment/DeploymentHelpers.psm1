Set-StrictMode -Version Latest

function Get-LegacyRsyncProtection {
    # The host keeps its own files (such as certificate challenges) beside our assetlinks.json in /.well-known/.
    return @('--filter=protect /play/***', '--filter=protect /.well-known/***')
}

function Assert-SafeDeploymentPath {
    param(
        [Parameter(Mandatory)]
        [string]$Path
    )

    if ([string]::IsNullOrWhiteSpace($Path)) {
        throw "Unsafe deployment path '$Path'."
    }

    $normalized = $Path.Replace('\', '/').Trim('/')
    $allowed = @(
        'trickypig/blood/public/play',
        'trickypig/blood/server/api',
        'trickypig/blood/.deploy'
    )
    if ($normalized -notin $allowed -or $normalized -match '(^|/)\.\.(/|$)') {
        throw "Unsafe deployment path '$Path'."
    }
}

function New-GameRemoteLayout {
    param(
        [Parameter(Mandatory)]
        [string]$RemotePublicPath,

        [Parameter(Mandatory)]
        [string]$ReleaseId
    )

    if ($RemotePublicPath -ne $RemotePublicPath.Trim() -or $ReleaseId -ne $ReleaseId.Trim()) {
        throw 'Remote layout values cannot contain leading or trailing whitespace.'
    }

    $public = $RemotePublicPath.Replace('\', '/').Trim('/')
    if ($public -ne 'trickypig/blood/public') {
        throw "Remote public path must be '/trickypig/blood/public/'."
    }
    if ($ReleaseId -notmatch '^\d{8}T\d{6}Z-[0-9a-f]{7,40}$') {
        throw "Invalid release ID '$ReleaseId'."
    }

    $root = 'trickypig/blood'
    $livePublic = "$root/public/play"
    $liveServer = "$root/server/api"
    $stage = "$root/.deploy/$ReleaseId"
    Assert-SafeDeploymentPath $livePublic
    Assert-SafeDeploymentPath $liveServer
    Assert-SafeDeploymentPath "$root/.deploy"

    return [pscustomobject]@{
        Root = $root
        Stage = $stage
        LivePublic = $livePublic
        LiveServer = $liveServer
        Previous = "$root/.deploy/previous-$ReleaseId"
        ReleaseId = $ReleaseId
    }
}

function Assert-CompatibilityPayload {
    param(
        [Parameter(Mandatory)]
        [string]$Json
    )

    if ([string]::IsNullOrWhiteSpace($Json)) {
        throw 'Compatibility endpoint returned an empty response.'
    }

    try {
        $payload = $Json | ConvertFrom-Json -ErrorAction Stop
    }
    catch {
        throw 'Compatibility endpoint did not return JSON.'
    }

    if (
        ($null -eq $payload) -or
        ($payload -is [array]) -or
        (-not $payload.PSObject.Properties.Name.Contains('apiVersion')) -or
        (-not $payload.PSObject.Properties.Name.Contains('minimumMauiVersion')) -or
        (([string]$payload.apiVersion) -ne '1.0') -or
        (([string]$payload.minimumMauiVersion) -ne '1.0.0')
    ) {
        throw 'Compatibility endpoint returned an unexpected contract.'
    }

    return $payload
}

Export-ModuleMember -Function Get-LegacyRsyncProtection, Assert-SafeDeploymentPath, New-GameRemoteLayout, Assert-CompatibilityPayload
