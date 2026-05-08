$files = Get-ChildItem -Path "resources\views" -Recurse -Filter "*.blade.php" |
    Select-String -Pattern "dark:" |
    Select-Object -ExpandProperty Path -Unique

foreach ($f in $files) {
    $content = [System.IO.File]::ReadAllText($f)
    # Remove dark: tailwind utility classes (e.g. dark:bg-slate-950/55, dark:text-white, dark:border-slate-700, etc.)
    $newContent = [regex]::Replace($content, '\s+dark:[a-zA-Z0-9\[\]\/\.\-_:#%()]+', '')
    if ($content -ne $newContent) {
        [System.IO.File]::WriteAllText($f, $newContent)
        Write-Host "Cleaned: $f"
    }
}

# Also clean the inline <style> dark overrides in reporting.blade.php
$reportingFile = "resources\views\workspace\monitoring\reporting.blade.php"
if (Test-Path $reportingFile) {
    $content = [System.IO.File]::ReadAllText($reportingFile)
    # Remove .dark CSS rules from inline style block
    $newContent = $content -replace '\.dark\s+\.reporting-hub-kpi\{[^}]+\}', ''
    $newContent = $newContent -replace '\.dark\s+\.reporting-hub-kpi\s+[^}]+\}', ''
    if ($content -ne $newContent) {
        [System.IO.File]::WriteAllText($reportingFile, $newContent)
        Write-Host "Cleaned inline dark CSS: $reportingFile"
    }
}

# Clean dashboard-render.js dark references
$dashFile = "resources\js\dashboard-render.js"
if (Test-Path $dashFile) {
    $content = [System.IO.File]::ReadAllText($dashFile)
    if ($content -match "dark") {
        Write-Host "Note: $dashFile contains dark references - review manually if needed"
    }
}

Write-Host "Done - all dark: classes removed from blade templates"
