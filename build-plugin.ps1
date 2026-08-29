<#
.SYNOPSIS
    Packages Kerry Football Admin into a WordPress-installable zip.

.DESCRIPTION
    Reads the version from the plugin header, stages only the files that
    actually ship, and compresses them with a top-level "kerry-football-admin"
    folder so the archive installs cleanly via
    wp-admin > Plugins > Add New > Upload Plugin.

    Excluded from the package (verified unreferenced by plugin code):
      Vendor/                              vendored PhpSpreadsheet, never loaded;
                                           no composer autoloader exists and both
                                           importers use native fgetcsv()
      kerry-football-admin BACKUP.php      stale copy of the main file, not loaded
      assets/css/kerry-football-styles.min.css
                                           dead file; the enqueue handle is named
                                           "kerry-football-styles" but points at
                                           kf-styles.css
      CLAUDE.md, .git/, .gitignore         development-only
      desktop.ini / Thumbs.db / .DS_Store  OS + OneDrive noise

.PARAMETER OutputDir
    Where to write the zip. Defaults to a "dist" folder beside the plugin.

.PARAMETER KeepStaging
    Leave the staging folder in place for inspection instead of deleting it.

.PARAMETER Force
    Overwrite an existing zip for this version. Without it, the build stops rather than
    replacing a package that was already produced - previous builds are kept, not clobbered.

.EXAMPLE
    .\build-plugin.ps1
    Builds dist\kerry-football-admin-v1.4.8.zip
#>

[CmdletBinding()]
param(
    [string] $OutputDir,
    [switch] $KeepStaging,
    [switch] $Force
)

$ErrorActionPreference = 'Stop'

$PluginSlug = 'kerry-football-admin'
$RepoRoot   = $PSScriptRoot
$MainFile   = Join-Path $RepoRoot "$PluginSlug.php"

if (-not (Test-Path $MainFile)) {
    throw "Cannot find $PluginSlug.php in $RepoRoot. Run this script from the plugin folder."
}

# --- Read the version straight from the plugin header (single source of truth) ---
$header = (Get-Content $MainFile -TotalCount 20) -join "`n"
$match  = [regex]::Match($header, '(?im)^\s*\*\s*Version:\s*(?<v>[0-9][0-9A-Za-z\.\-]*)\s*$')
if (-not $match.Success) {
    throw "Could not parse the 'Version:' line from the plugin header in $MainFile."
}
$Version = $match.Groups['v'].Value

if (-not $OutputDir) { $OutputDir = Join-Path $RepoRoot 'dist' }
if (-not (Test-Path $OutputDir)) { New-Item -ItemType Directory -Path $OutputDir -Force | Out-Null }

$ZipName = "$PluginSlug-v$Version.zip"
$ZipPath = Join-Path $OutputDir $ZipName

# Built packages are kept, never replaced. Previous versions are the only record of what was
# actually shipped, so an existing zip for this version is treated as a stop, not something to
# overwrite silently. Bump the Version: header (normal case) or pass -Force to replace it.
if ( (Test-Path $ZipPath) -and (-not $Force) ) {
    throw "$ZipName already exists in $OutputDir. Bump the Version: header in $PluginSlug.php, or re-run with -Force to overwrite it."
}

# --- Staging area (outside the repo so it can never be packaged into itself) ---
$StageRoot = Join-Path ([System.IO.Path]::GetTempPath()) "kf-build-$Version"
$StageDir  = Join-Path $StageRoot $PluginSlug
if (Test-Path $StageRoot) { Remove-Item $StageRoot -Recurse -Force }
New-Item -ItemType Directory -Path $StageDir -Force | Out-Null

# --- Exclusions, matched against the repo-relative path with forward slashes ---
$ExcludeDirs = @(
    '.git/',
    '.github/',
    '.claude/',
    'Vendor/',
    'dist/',
    'node_modules/'
)

$ExcludeFiles = @(
    "$PluginSlug BACKUP.php",
    'assets/css/kerry-football-styles.min.css',
    'CLAUDE.md',
    '.gitignore',
    'build-plugin.ps1'
)

$ExcludePatterns = @(
    '\.zip$',
    '(^|/)desktop\.ini$',
    '(^|/)Thumbs\.db$',
    '(^|/)\.DS_Store$',
    '\.bak$',
    '(^|/)~\$'
)

function Test-ShouldExclude {
    param([string] $RelativePath)

    foreach ($dir in $ExcludeDirs) {
        if ($RelativePath -like "$dir*") { return $true }
    }
    foreach ($file in $ExcludeFiles) {
        if ($RelativePath -ieq $file) { return $true }
    }
    foreach ($pattern in $ExcludePatterns) {
        if ($RelativePath -imatch $pattern) { return $true }
    }
    return $false
}

Write-Host ""
Write-Host "Packaging $PluginSlug v$Version" -ForegroundColor Cyan
Write-Host ("-" * 46)

$prefixLength = $RepoRoot.TrimEnd('\').Length + 1
$included = 0
$skipped  = 0

Get-ChildItem -Path $RepoRoot -Recurse -File -Force | ForEach-Object {
    $relative = $_.FullName.Substring($prefixLength).Replace('\', '/')

    if (Test-ShouldExclude -RelativePath $relative) {
        $skipped++
        return
    }

    $target    = Join-Path $StageDir $relative
    $targetDir = Split-Path $target -Parent
    if (-not (Test-Path $targetDir)) {
        New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
    }
    Copy-Item $_.FullName -Destination $target -Force
    $included++
}

if ($included -eq 0) {
    throw "No files were staged - check the exclusion rules."
}

# --- Sanity check: the plugin must be able to boot from what we staged ---
$requiredFiles = @(
    "$PluginSlug.php",
    'includes/kf-database-setup.php',
    'includes/kf-sports-api.php',
    'includes/kf-shortcodes.php',
    'assets/css/kf-styles.css',
    'assets/js/kf-table-controls.js',
    'assets/js/kf-game-browser.js'
)
$missing = @()
foreach ($required in $requiredFiles) {
    if (-not (Test-Path (Join-Path $StageDir $required))) { $missing += $required }
}
if ($missing.Count -gt 0) {
    throw "Staged package is missing required files: $($missing -join ', ')"
}

# --- Verify every require_once target in the main file actually shipped ---
$mainContent  = Get-Content $MainFile -Raw
$requireHits  = [regex]::Matches($mainContent, "require_once[^;]*?['""]([^'""]*?includes/[^'""]+\.php)['""]")
$missingReqs  = @()
foreach ($hit in $requireHits) {
    $incPath = $hit.Groups[1].Value -replace '.*includes/', 'includes/'
    if (-not (Test-Path (Join-Path $StageDir $incPath))) { $missingReqs += $incPath }
}
if ($missingReqs.Count -gt 0) {
    throw "Main file requires files that are not in the package: $($missingReqs -join ', ')"
}

# --- Compress ---
# NOT Compress-Archive: in Windows PowerShell 5.1 it writes entry names with
# backslash separators, which violates the ZIP spec (APPNOTE 4.4.17.1). On a
# Linux host a backslash is a legal filename character, so PHP extracts such an
# archive as a flat pile of files literally named "kerry-football-admin\includes\
# kf-sports-api.php" instead of a directory tree - a silently broken install.
# Build the entries by hand so every name uses forward slashes.
Add-Type -AssemblyName System.IO.Compression | Out-Null
Add-Type -AssemblyName System.IO.Compression.FileSystem | Out-Null

if (Test-Path $ZipPath) { Remove-Item $ZipPath -Force }

$zipStream = $null
$archive   = $null
try {
    $zipStream = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::CreateNew)
    $archive   = New-Object System.IO.Compression.ZipArchive(
        $zipStream, [System.IO.Compression.ZipArchiveMode]::Create)

    $stagePrefix = $StageRoot.TrimEnd('\').Length + 1

    Get-ChildItem -Path $StageDir -Recurse -File | Sort-Object FullName | ForEach-Object {
        $entryName = $_.FullName.Substring($stagePrefix).Replace('\', '/')
        $entry     = $archive.CreateEntry($entryName, [System.IO.Compression.CompressionLevel]::Optimal)

        $entryStream = $entry.Open()
        $fileStream  = [System.IO.File]::OpenRead($_.FullName)
        try {
            $fileStream.CopyTo($entryStream)
        } finally {
            $fileStream.Dispose()
            $entryStream.Dispose()
        }
    }
} finally {
    if ($archive)   { $archive.Dispose() }
    if ($zipStream) { $zipStream.Dispose() }
}

# --- Verify the archive we just wrote uses spec-compliant separators ---
$verifyZip = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
try {
    $badEntries = @($verifyZip.Entries | Where-Object { $_.FullName -like '*\*' })
    if ($badEntries.Count -gt 0) {
        throw "Archive contains $($badEntries.Count) entries with backslash separators - would not extract correctly on Linux."
    }
    $entryCount = $verifyZip.Entries.Count
    $rootDirs   = @($verifyZip.Entries | ForEach-Object { ($_.FullName -split '/')[0] } | Sort-Object -Unique)
    if ($rootDirs.Count -ne 1 -or $rootDirs[0] -ne $PluginSlug) {
        throw "Archive root should be exactly '$PluginSlug' but found: $($rootDirs -join ', ')"
    }
} finally {
    $verifyZip.Dispose()
}
Write-Host "Verified       : $entryCount entries, forward-slash paths, single root folder" -ForegroundColor DarkGray

if (-not $KeepStaging) {
    Remove-Item $StageRoot -Recurse -Force
} else {
    Write-Host "Staging kept at: $StageDir" -ForegroundColor DarkGray
}

$zipItem = Get-Item $ZipPath
$sizeKB  = [Math]::Round($zipItem.Length / 1KB, 1)

Write-Host "Files packaged : $included"
Write-Host "Files excluded : $skipped"
Write-Host "Archive size   : $sizeKB KB"
Write-Host ""
Write-Host "Built: $ZipPath" -ForegroundColor Green
Write-Host "Install via wp-admin > Plugins > Add New > Upload Plugin." -ForegroundColor Green
Write-Host ""
