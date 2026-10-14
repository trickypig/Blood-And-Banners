Set-StrictMode -Version Latest

function Get-LegacyRsyncProtection {
    return @('--filter=protect /play/***')
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

Export-ModuleMember -Function Get-LegacyRsyncProtection, Assert-SafeDeploymentPath
