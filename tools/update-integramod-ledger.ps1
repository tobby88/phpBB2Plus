param(
    [string]$Ref = 'integramod/main',
    [string]$MappedRef = '',
    [string]$MergeCommit = '',
    [string]$OutputPath = 'docs/upstream/integramod/commits.csv'
)

$ErrorActionPreference = 'Stop'

$repositoryRoot = (& git rev-parse --show-toplevel).Trim()
if (-not $repositoryRoot)
{
    throw 'This script must run inside the phpBB2Plus Git repository.'
}

$resolvedOutput = Join-Path $repositoryRoot $OutputPath
$existingRows = @{}
if (Test-Path -LiteralPath $resolvedOutput)
{
    foreach ($row in (Import-Csv -LiteralPath $resolvedOutput))
    {
        $existingRows[$row.Commit] = $row
    }
}

$format = '%H%x09%P%x09%aI%x09%an%x09%s'
$history = @(& git log --reverse --topo-order "--format=$format" $Ref)
if ($LASTEXITCODE -ne 0)
{
    throw "Unable to read upstream history from $Ref."
}

$mappedHistory = @()
if ($MappedRef)
{
    $mappedHistory = @(& git log --reverse --topo-order "--format=$format" $MappedRef)
    if ($LASTEXITCODE -ne 0)
    {
        throw "Unable to read mapped history from $MappedRef."
    }
    if ($mappedHistory.Count -ne $history.Count)
    {
        throw "Mapped history contains $($mappedHistory.Count) commits; expected $($history.Count)."
    }
}

$index = 0
$rows = foreach ($line in $history)
{
    $fields = $line -split "`t", 5
    if ($fields.Count -ne 5)
    {
        throw "Unexpected Git log record: $line"
    }

    $index++
    $previous = $existingRows[$fields[0]]
    $mappedCommit = if ($previous -and $previous.PSObject.Properties['MappedCommit']) { $previous.MappedCommit } else { '' }
    if ($MappedRef)
    {
        $mappedFields = $mappedHistory[$index - 1] -split "`t", 5
        if ($mappedFields.Count -ne 5 -or $mappedFields[2] -ne $fields[2] -or $mappedFields[3] -ne $fields[3] -or $mappedFields[4] -ne $fields[4])
        {
            throw "Mapped commit $index does not match upstream metadata."
        }
        $mappedCommit = $mappedFields[0]
    }

    $disposition = if ($previous) { $previous.Disposition } else { 'pending' }
    $portCommit = if ($previous) { $previous.PortCommit } else { '' }
    if ($MergeCommit -and $disposition -eq 'pending')
    {
        $disposition = 'merged'
        $portCommit = $MergeCommit
    }

    [pscustomobject][ordered]@{
        Index       = $index
        Commit      = $fields[0]
        MappedCommit = $mappedCommit
        Parents     = $fields[1]
        Date        = $fields[2]
        Author      = $fields[3]
        Subject     = $fields[4]
        Category    = if ($previous -and $previous.Category -ne 'pending') { $previous.Category } else { 'upstream' }
        Disposition = $disposition
        PortCommit  = $portCommit
        Notes       = if ($previous) { $previous.Notes } else { '' }
    }
}

$integrationPolicy = @{
    '1fcb58ef5b7e08ce7db594ec46fdb6b8c9fced7a' = @{
        Category = 'base'
        Disposition = 'already-present'
        PortCommit = '57c44026ea'
        Notes = 'Mapped phpBB 2.0.23 snapshot used as the explicit merge base.'
    }
    '77c3116101009d3e27e7b064ee4a0d5b93f7d99c' = @{
        Category = 'encoding-and-languages'
        Disposition = 'ported'
        PortCommit = '8390004044'
        Notes = 'UTF-8 changes merged; language packs were deliberately filtered to English and German.'
    }
    'a4cd17201486110695e27aeb38ff42a89448378e' = @{
        Category = 'style'
        Disposition = 'ported'
        PortCommit = '8390004044'
        Notes = 'Redistributable style code merged; BootstrapMade HeroBiz demo media and proprietary form files excluded.'
    }
    '64f3cc8f9149cd69f1f830077dd5ac8c94cd4242' = @{
        Category = 'style'
        Disposition = 'ported'
        PortCommit = '8390004044'
        Notes = 'Redistributable style code merged; BootstrapMade HeroBiz demo media and proprietary form files excluded.'
    }
    '4e9717dcb5f67185abce8bb7e29192f887da2007' = @{
        Category = 'php-compatibility'
        Disposition = 'ported'
        PortCommit = 'e73ab955cc'
        Notes = 'Compatibility helpers adapted to phpBB2 Plus; Plus-only syntax fixes are in 24adf2341.'
    }
    '9a860a721925af2bf8bcfd9f25bf04ba551cc74d' = @{
        Category = 'versioning'
        Disposition = 'not-applicable'
        PortCommit = '8390004044'
        Notes = 'Upstream version identity is retained in history; phpBB2 Plus keeps its own version identity.'
    }
}

foreach ($row in $rows)
{
    if ($integrationPolicy.ContainsKey($row.Commit))
    {
        $policy = $integrationPolicy[$row.Commit]
        $row.Category = $policy.Category
        $row.Disposition = $policy.Disposition
        $row.PortCommit = $policy.PortCommit
        $row.Notes = $policy.Notes
    }
}

$outputDirectory = Split-Path -Parent $resolvedOutput
if (-not (Test-Path -LiteralPath $outputDirectory))
{
    New-Item -ItemType Directory -Path $outputDirectory | Out-Null
}

$rows | Export-Csv -LiteralPath $resolvedOutput -NoTypeInformation -Encoding utf8
Write-Output "Wrote $($rows.Count) commits from $Ref to $resolvedOutput"
