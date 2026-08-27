[CmdletBinding()]
param(
    [string]$SourceStyle = 'phpBB2/templates/fisubsilversh',
    [string]$FallbackStyle = 'phpBB2/templates/subSilver'
)

$ErrorActionPreference = 'Stop'

$sourceRoot = (Resolve-Path -LiteralPath $SourceStyle).Path
$fallbackRoot = (Resolve-Path -LiteralPath $FallbackStyle).Path
$copied = 0

Get-ChildItem -LiteralPath $sourceRoot -Recurse -File -Filter '*.tpl' | ForEach-Object {
    $relativePath = $_.FullName.Substring($sourceRoot.Length + 1)
    $destination = Join-Path $fallbackRoot $relativePath

    if (-not (Test-Path -LiteralPath $destination)) {
        $destinationDirectory = Split-Path -Parent $destination
        if (-not (Test-Path -LiteralPath $destinationDirectory)) {
            New-Item -ItemType Directory -Path $destinationDirectory | Out-Null
        }

        Copy-Item -LiteralPath $_.FullName -Destination $destination
        $copied++
    }
}

Write-Output "Added $copied missing phpBB2 Plus templates to $FallbackStyle."
