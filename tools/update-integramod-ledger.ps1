param(
    [string]$Ref = 'integramod/main',
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
    [pscustomobject][ordered]@{
        Index       = $index
        Commit      = $fields[0]
        Parents     = $fields[1]
        Date        = $fields[2]
        Author      = $fields[3]
        Subject     = $fields[4]
        Category    = if ($previous) { $previous.Category } else { 'pending' }
        Disposition = if ($previous) { $previous.Disposition } else { 'pending' }
        PortCommit  = if ($previous) { $previous.PortCommit } else { '' }
        Notes       = if ($previous) { $previous.Notes } else { '' }
    }
}

$outputDirectory = Split-Path -Parent $resolvedOutput
if (-not (Test-Path -LiteralPath $outputDirectory))
{
    New-Item -ItemType Directory -Path $outputDirectory | Out-Null
}

$rows | Export-Csv -LiteralPath $resolvedOutput -NoTypeInformation -Encoding utf8
Write-Output "Wrote $($rows.Count) commits from $Ref to $resolvedOutput"

