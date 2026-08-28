param(
    [string[]]$PhpExecutables = @(),
    [switch]$SkipPhpLint
)

$ErrorActionPreference = 'Stop'

function Assert-True
{
    param(
        [bool]$Condition,
        [string]$Message
    )

    if (-not $Condition)
    {
        throw $Message
    }
}

function Split-SqlList
{
    param([string]$Text)

    $items = New-Object System.Collections.Generic.List[string]
    $buffer = New-Object System.Text.StringBuilder
    $quoted = $false
    $escaped = $false

    foreach ($character in $Text.ToCharArray())
    {
        if ($escaped)
        {
            [void]$buffer.Append($character)
            $escaped = $false
            continue
        }

        if ($character -eq '\' -and $quoted)
        {
            [void]$buffer.Append($character)
            $escaped = $true
            continue
        }

        if ($character -eq "'")
        {
            $quoted = -not $quoted
            [void]$buffer.Append($character)
            continue
        }

        if ($character -eq ',' -and -not $quoted)
        {
            $items.Add($buffer.ToString().Trim())
            [void]$buffer.Clear()
            continue
        }

        [void]$buffer.Append($character)
    }

    $items.Add($buffer.ToString().Trim())
    return @($items)
}

$repositoryRoot = (& git rev-parse --show-toplevel).Trim()
Assert-True ($LASTEXITCODE -eq 0 -and $repositoryRoot) 'Run this test inside the phpBB2Plus repository.'
Set-Location $repositoryRoot

$expectedLanguages = @('lang_english', 'lang_german')
$actualLanguages = @(Get-ChildItem 'phpBB2/language' -Directory | Sort-Object Name | Select-Object -ExpandProperty Name)
Assert-True (($actualLanguages -join ',') -eq ($expectedLanguages -join ',')) "Unexpected product languages: $($actualLanguages -join ', ')"

$foreignLanguageDirectories = @(Get-ChildItem 'phpBB2', 'mods' -Recurse -Directory |
    Where-Object { $_.Name -like 'lang_*' -and $_.Name -notin $expectedLanguages })
Assert-True ($foreignLanguageDirectories.Count -eq 0) "Non-English/German language directories remain: $($foreignLanguageDirectories.FullName -join ', ')"

$expectedStyles = @('BS', 'BS_subIce', 'BS_subSilver', 'fisubsilversh', 'prosilver', 'prosilver_se', 'subSilver')
$actualStyles = @(Get-ChildItem 'phpBB2/templates' -Directory |
    Where-Object { $_.Name -ne 'assets' } | Sort-Object Name | Select-Object -ExpandProperty Name)
Assert-True (($actualStyles -join ',') -eq (($expectedStyles | Sort-Object) -join ',')) "Unexpected style set: $($actualStyles -join ', ')"

foreach ($style in $expectedStyles)
{
    Assert-True (Test-Path "phpBB2/templates/$style/$style.cfg") "Missing image configuration for style $style."
    Assert-True (Test-Path "phpBB2/templates/$style/theme_info.cfg") "Missing theme metadata for style $style."
}

$referenceRoot = (Resolve-Path 'phpBB2/templates/fisubsilversh').Path
$fallbackRoot = (Resolve-Path 'phpBB2/templates/subSilver').Path
$missingFallbacks = @()
foreach ($template in (Get-ChildItem $referenceRoot -Recurse -Filter '*.tpl' -File))
{
    $relative = [IO.Path]::GetRelativePath($referenceRoot, $template.FullName)
    if (-not (Test-Path (Join-Path $fallbackRoot $relative)))
    {
        $missingFallbacks += $relative
    }
}
Assert-True ($missingFallbacks.Count -eq 0) "subSilver fallback templates are missing: $($missingFallbacks -join ', ')"

$ledger = @(Import-Csv 'docs/upstream/integramod/commits.csv')
Assert-True ($ledger.Count -eq 201) "IntegraMOD ledger has $($ledger.Count) rows instead of 201."
Assert-True (@($ledger | Where-Object { -not $_.MappedCommit }).Count -eq 0) 'IntegraMOD ledger contains unmapped commits.'
Assert-True (@($ledger | Where-Object { $_.Disposition -eq 'pending' -or -not $_.Disposition }).Count -eq 0) 'IntegraMOD ledger contains pending dispositions.'

$reachable = @{}
foreach ($commit in (& git rev-list HEAD))
{
    $reachable[$commit] = $true
}
$unreachableMapped = @($ledger | Where-Object { -not $reachable.ContainsKey($_.MappedCommit) })
Assert-True ($unreachableMapped.Count -eq 0) "Mapped IntegraMOD commits are not reachable from HEAD: $($unreachableMapped.MappedCommit -join ', ')"

$productionLedger = @(Import-Csv 'docs/upstream/production-compatibility/commits.csv')
Assert-True ($productionLedger.Count -eq 56) "Production compatibility ledger has $($productionLedger.Count) rows instead of 56."
$validProductionDispositions = @('ported', 'already-present', 'superseded', 'not-applicable')
$invalidProductionRows = @($productionLedger | Where-Object { $_.Disposition -notin $validProductionDispositions })
Assert-True ($invalidProductionRows.Count -eq 0) 'Production compatibility ledger contains an invalid disposition.'
$unmappedProductionRows = @($productionLedger | Where-Object {
    $_.Disposition -ne 'not-applicable' -and -not $_.PortCommit
})
Assert-True ($unmappedProductionRows.Count -eq 0) 'An applicable production compatibility commit has no public port commit.'
$unreachableProductionPorts = @($productionLedger | Where-Object {
    $_.PortCommit -and -not $reachable.ContainsKey($_.PortCommit)
})
Assert-True ($unreachableProductionPorts.Count -eq 0) "Production port commits are not reachable from HEAD: $($unreachableProductionPorts.PortCommit -join ', ')"

$upstreamPaths = @(& git ls-tree -r --name-only $ledger[-1].MappedCommit)
$headPaths = @{}
foreach ($path in (& git ls-tree -r --name-only HEAD))
{
    $headPaths[$path] = $true
}
$missingUpstreamPaths = @($upstreamPaths | Where-Object { -not $headPaths.ContainsKey($_) })
$unexpectedMissingPaths = @($missingUpstreamPaths | Where-Object {
    $_ -notmatch '^phpBB2/_develop/' -and
    $_ -notmatch '^phpBB2/install/schemas/(ms_access_primer\.zip|mssql_(basic|schema)\.sql|postgres_(basic|schema)\.sql)$' -and
    $_ -notmatch 'lang_(dutch|italian)' -and
    $_ -notlike '*Dutch Language.txt' -and
    $_ -notmatch '^phpBB2/templates/assets/(css/main\.css|img/.*|js/main\.js|vendor/php-email-form/validate\.js)$' -and
    $_ -notmatch '^phpBB2/forms/php-email-form/(php-email-form\.php|validate\.js)$' -and
    $_ -ne 'phpBB2/templates/BS/index.html'
})
Assert-True ($missingUpstreamPaths.Count -eq 108) "Expected 108 documented upstream exclusions, found $($missingUpstreamPaths.Count)."
Assert-True ($unexpectedMissingPaths.Count -eq 0) "Unexpected final-tree omissions: $($unexpectedMissingPaths -join ', ')"

$textExtensions = @('.php', '.tpl', '.html', '.htm', '.css', '.js', '.txt', '.sql', '.xml', '.cfg', '.inc')
$strictUtf8 = New-Object System.Text.UTF8Encoding($false, $true)
$invalidUtf8 = @()
foreach ($file in (Get-ChildItem 'phpBB2', 'update', 'mods' -Recurse -File |
    Where-Object { $_.Extension.ToLowerInvariant() -in $textExtensions }))
{
    try
    {
        [void]$strictUtf8.GetString([IO.File]::ReadAllBytes($file.FullName))
    }
    catch
    {
        $invalidUtf8 += $file.FullName
    }
}
Assert-True ($invalidUtf8.Count -eq 0) "Files are not valid UTF-8: $($invalidUtf8 -join ', ')"

foreach ($language in $expectedLanguages)
{
    $languageMain = [IO.File]::ReadAllText((Resolve-Path "phpBB2/language/$language/lang_main.php"), $strictUtf8)
    Assert-True ($languageMain.Contains("`$lang['ENCODING'] = 'UTF-8';")) "$language does not declare UTF-8."

    foreach ($mailTemplate in (Get-ChildItem "phpBB2/language/$language/email" -Filter '*.tpl' -File))
    {
        $mailText = [IO.File]::ReadAllText($mailTemplate.FullName, $strictUtf8)
        Assert-True ($mailText -match '(?im)^Charset:\s*UTF-8\s*$') "$($mailTemplate.FullName) does not declare UTF-8."
    }
}

$mysqliDriver = [IO.File]::ReadAllText((Resolve-Path 'phpBB2/db/mysqli.php'), $strictUtf8)
Assert-True ($mysqliDriver.Contains("mysqli_set_charset(`$this->db_connect_id, 'utf8mb4')")) 'MySQLi does not select the utf8mb4 connection character set.'

$dbLoader = [IO.File]::ReadAllText((Resolve-Path 'phpBB2/includes/db.php'), $strictUtf8)
Assert-True ($dbLoader.Contains("function_exists('mysqli_connect')")) 'Legacy MySQL config values do not fall back to MySQLi.'

$schema = [IO.File]::ReadAllText((Resolve-Path 'phpBB2/install/schemas/mysql_schema.sql'), $strictUtf8)
$basic = [IO.File]::ReadAllText((Resolve-Path 'phpBB2/install/schemas/mysql_basic.sql'), $strictUtf8)
Assert-True (-not $schema.Contains('TYPE=MyISAM')) 'Fresh schema still uses the removed TYPE= table option.'
Assert-True ($schema.Contains('ENGINE=MyISAM DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci')) 'Fresh schema does not declare its utf8mb4 table encoding.'
Assert-True (-not $schema.Contains('DEFAULT CHARSET=utf8 COLLATE=utf8_unicode_ci')) 'Fresh schema still contains three-byte UTF-8 table declarations.'

foreach ($field in @('user_fb', 'user_ig', 'user_pt', 'user_twr', 'user_skp', 'user_tg', 'user_li', 'user_tt', 'user_dc'))
{
    Assert-True (([regex]::Matches($schema, "(?m)^\s*$field\s")).Count -eq 1) "Fresh schema contains $field an unexpected number of times."
}
Assert-True (([regex]::Matches($basic, "(?m)^INSERT INTO phpbb_config .*'cookie_consent_enable'")).Count -eq 1) 'cookie_consent_enable is missing or duplicated.'
Assert-True (([regex]::Matches($basic, "(?m)^INSERT INTO phpbb_config .*'sfs_enable'")).Count -eq 1) 'sfs_enable is missing or duplicated.'

$userInserts = @([regex]::Matches($basic, '(?m)^INSERT INTO phpbb_users \((?<columns>.+)\) VALUES \(\s*(?<values>.+)\);$'))
Assert-True ($userInserts.Count -eq 2) "Expected two seed-user inserts, found $($userInserts.Count)."
foreach ($insert in $userInserts)
{
    $columns = @(Split-SqlList $insert.Groups['columns'].Value)
    $values = @(Split-SqlList $insert.Groups['values'].Value)
    Assert-True ($columns.Count -eq $values.Count) "Seed-user insert has $($columns.Count) columns but $($values.Count) values."
}

foreach ($blockedPath in @(
    'phpBB2/forms/php-email-form',
    'phpBB2/templates/assets/css/main.css',
    'phpBB2/templates/assets/img/hero-img.svg',
    'phpBB2/templates/assets/js/main.js',
    'phpBB2/templates/assets/vendor/php-email-form/validate.js'
))
{
    Assert-True (-not (Test-Path $blockedPath)) "License-blocked asset is present: $blockedPath"
}

if (-not $SkipPhpLint -and $PhpExecutables.Count -eq 0)
{
    $workspaceRoot = Split-Path (Split-Path $repositoryRoot -Parent) -Parent
    foreach ($version in @('5.6.40', '7.4.33', '8.5.9'))
    {
        $candidate = Join-Path $workspaceRoot ".tools/php-$version/php.exe"
        if (Test-Path $candidate)
        {
            $PhpExecutables += $candidate
        }
    }
}

if (-not $SkipPhpLint)
{
    $phpFiles = @(Get-ChildItem 'phpBB2', 'update', 'mods' -Recurse -Filter '*.php' -File)
    $phpFiles += @(Get-ChildItem 'phpBB2/templates' -Recurse -Filter '*.cfg' -File)
    foreach ($phpExecutable in $PhpExecutables)
    {
        Assert-True (Test-Path $phpExecutable) "PHP executable not found: $phpExecutable"
        $lintFailures = @()
        foreach ($file in $phpFiles)
        {
            $lintOutput = @(& $phpExecutable -d display_errors=1 -d error_reporting=-1 -l $file.FullName 2>&1)
            if ($LASTEXITCODE -ne 0 -or ($lintOutput -join "`n") -match 'Deprecated: Methods with the same name')
            {
                $lintFailures += "$($file.FullName): $($lintOutput -join ' ')"
            }
        }
        Assert-True ($lintFailures.Count -eq 0) "PHP lint failed with $phpExecutable`n$($lintFailures -join "`n")"
        Write-Output "PHP lint passed: $phpExecutable ($($phpFiles.Count) files)"
    }
}

Write-Output "Integration checks passed: $($ledger.Count) IntegraMOD commits, $($productionLedger.Count) production compatibility commits, $($actualStyles.Count) styles, $($actualLanguages.Count) languages."
