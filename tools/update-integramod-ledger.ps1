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
    'ee67d29d8c882c38a13f21cea6b89b7c0d724322' = @{
        Category = 'phpbb-maintenance'
        Disposition = 'ported'
        PortCommit = '8390004044'
        Notes = 'Applicable fixes from the unofficial upstream 2.0.25 state were merged; phpBB2 Plus retains its audited 2.0.23-based version identity.'
    }
    '389e9c6da809efe65d01b40ae4be93bf29baac0c' = @{
        Category = 'social-profiles'
        Disposition = 'ported'
        PortCommit = '877dd25b43'
        Notes = 'Social-profile links were adapted to the Plus schema, registration, profile, messaging, member and group views.'
    }
    '77c3116101009d3e27e7b064ee4a0d5b93f7d99c' = @{
        Category = 'encoding-and-languages'
        Disposition = 'ported'
        PortCommit = '8390004044'
        Notes = 'UTF-8 changes merged; language packs were deliberately filtered to English and German.'
    }
    '73438b8e5a3119abfc54b34b3625061bbb6c76ec' = @{
        Category = 'social-profiles'
        Disposition = 'ported'
        PortCommit = '877dd25b43'
        Notes = 'Missing social fields were integrated additively without removing the legacy AIM, Yahoo and MSN fields.'
    }
    'a1eae1cb6459fe0815e6f8a9e68fb7b23e048058' = @{
        Category = 'social-profiles'
        Disposition = 'ported'
        PortCommit = '877dd25b43'
        Notes = 'Profile icons were completed for every bundled style and corrected where upstream URLs were malformed.'
    }
    'd2b3f68a2c91d7692fa2137dc9c56ebf131dde7d' = @{
        Category = 'social-profiles'
        Disposition = 'ported'
        PortCommit = '877dd25b43'
        Notes = 'Registration handling was adapted to the phpBB2 Plus registration flow.'
    }
    'f20a6cd51ee7fb53e9e228060a80f13b2a4a6016' = @{
        Category = 'social-profiles'
        Disposition = 'ported'
        PortCommit = '877dd25b43'
        Notes = 'The avatar-gallery and profile-field interaction was adapted to the Plus implementation.'
    }
    'f17e0b6f53847fa08dc244768c874ce50650c69a' = @{
        Category = 'module'
        Disposition = 'superseded'
        PortCommit = '8390004044'
        Notes = 'phpBB2 Plus already contains a broader portal. Compatible final-tree changes were merged without replacing it with the incomplete IM Portal package.'
    }
    '0427498ff5cdfc08ff6650253110cb3ef9fda72d' = @{
        Category = 'source-package'
        Disposition = 'source-only'
        PortCommit = '8390004044'
        Notes = 'The Admin Userlist package is retained for provenance; upstream later removed its installed product files.'
    }
    'da56d945d43966cab3a6d84cb07ac8f3ff8cca4c' = @{
        Category = 'source-package'
        Disposition = 'source-only'
        PortCommit = '8390004044'
        Notes = 'This updates the standalone cookie utility under _contrib; the audited product integration is recorded in 6d79e66d98.'
    }
    '4b636a1a1bb62a2a50ba6666f7ac828814589236' = @{
        Category = 'source-package'
        Disposition = 'source-only'
        PortCommit = '8390004044'
        Notes = 'The Registration Spam MOD instruction package is retained but was not installed into the product tree.'
    }
    '9f6632145c4ea983267bf2c2177794558972908a' = @{
        Category = 'source-package'
        Disposition = 'source-only'
        PortCommit = '8390004044'
        Notes = 'The Rules and Policies MOD is retained as source; upstream did not install it into the final product tree.'
    }
    'fcca00195ad04ddeb0b68bde6ccc2a9112f6d8dc' = @{
        Category = 'source-packages'
        Disposition = 'source-only'
        PortCommit = '8390004044'
        Notes = 'This mixed commit adds or updates historical MOD packages. They remain source-only; non-English payloads were filtered.'
    }
    '26df2e4a6fcd1d39c749c50e54187921e0de3223' = @{
        Category = 'source-package'
        Disposition = 'superseded'
        PortCommit = '8390004044'
        Notes = 'The paFileDB 1.0.1 source package is older than the integrated phpBB2 Plus PAFileDB and is retained only for provenance.'
    }
    '5b33eff6ce61a286eaddc7527524d7b138ee5d0a' = @{
        Category = 'database-driver'
        Disposition = 'merged'
        PortCommit = '8390004044'
        Notes = 'The experimental PDO driver is retained exactly as upstream source, but upstream never exposed it through its installer and phpBB2 Plus does not advertise it as supported.'
    }
    '2ceb4c94362573e6b7cfc5c0295eec2d9c2b0ffa' = @{
        Category = 'source-package'
        Disposition = 'source-only'
        PortCommit = '8390004044'
        Notes = 'The Digests MOD is retained as a source package and is not installed into the product tree.'
    }
    'f6f20aaa945b1b831c7d3d7661f476b16d253b00' = @{
        Category = 'source-package'
        Disposition = 'superseded'
        PortCommit = '8390004044'
        Notes = 'Later changes to the old paFileDB package remain in history; the newer Plus implementation stays authoritative.'
    }
    '240495a8d8e0abf95befa4db7579a6f60940d799' = @{
        Category = 'source-package'
        Disposition = 'source-only'
        PortCommit = '8390004044'
        Notes = 'The Log Actions MOD is retained as source and is not installed into the product tree.'
    }
    '454a44305d7cf54ee619b640c3472882adb3d6b4' = @{
        Category = 'source-package'
        Disposition = 'source-only'
        PortCommit = '8390004044'
        Notes = 'This modifies the uninstall helper of the source-only Log Actions MOD.'
    }
    'fa10a62aafc8a66445d7d75a2dae7822b3feb4c3' = @{
        Category = 'history-cleanup'
        Disposition = 'superseded'
        PortCommit = '1ed866ed293bfe11304b6be25f915b944edd6bbe'
        Notes = 'An accidental duplicate folder was added here and removed by the immediately following upstream commit.'
    }
    '0c987e128454370604317fccce60f2215ee7697e' = @{
        Category = 'privacy-antispam-and-assets'
        Disposition = 'ported'
        PortCommit = '6d79e66d98'
        Notes = 'Cookie-consent and StopForumSpam behavior was integrated with Plus-specific configuration and templates; related profile work is in 877dd25b43.'
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
    '1e2378c21478bd2b0e184452e8ddde2514e01021' = @{
        Category = 'social-profiles'
        Disposition = 'ported'
        PortCommit = '877dd25b43'
        Notes = 'Registration and profile fields were completed across the Plus data flow and every bundled style.'
    }
    '4e9717dcb5f67185abce8bb7e29192f887da2007' = @{
        Category = 'php-compatibility'
        Disposition = 'ported'
        PortCommit = 'e73ab955cc'
        Notes = 'PHP compatibility helpers were adapted to phpBB2 Plus; Plus-only syntax fixes are in 24adf2341. The upstream IPv6 claim is not adopted because the retained phpBB2 session schema is IPv4-specific.'
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
