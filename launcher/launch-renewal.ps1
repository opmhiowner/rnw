# Renewal Center launcher  v0.1
# Opens the three Renewal Center windows and places each on its own monitor, full screen.
# Edit ONLY the CONFIG block. Run once from Desktop; then use the "Renewal Center" shortcut.

# ============================== CONFIG ==============================
$BaseUrl = "https://apps.oishis.net/renewal"

# Which monitor gets which window. Monitor numbers come from Windows:
#   Settings > System > Display > click "Identify" — the big number on each screen.
# Defaults assume 1 = left, 2 = center, 3 = right. Change to match this PC.
$Layout = @{
    Media = 1     # photos + description   ->  $BaseUrl/media
    Main  = 2     # queue + record + actions  ->  $BaseUrl/
    Comps = 3     # Craigslist comps        ->  $BaseUrl/comps
}

# Optional: press F11 in each window after it opens (true = full screen kiosk look)
$FullScreen = $true
# ====================================================================

Add-Type -AssemblyName System.Windows.Forms
$screens = [System.Windows.Forms.Screen]::AllScreens

# Windows "Identify" numbers usually follow AllScreens order sorted by X position.
$ordered = $screens | Sort-Object { $_.Bounds.X }

function Get-Screen($n) {
    if ($n -lt 1 -or $n -gt $ordered.Count) {
        Write-Host "Monitor $n not found - this PC has $($ordered.Count) screen(s). Using monitor 1." -ForegroundColor Yellow
        return $ordered[0]
    }
    return $ordered[$n - 1]
}

$chrome = @(
    "$env:ProgramFiles\Google\Chrome\Application\chrome.exe",
    "${env:ProgramFiles(x86)}\Google\Chrome\Application\chrome.exe",
    "$env:LOCALAPPDATA\Google\Chrome\Application\chrome.exe"
) | Where-Object { Test-Path $_ } | Select-Object -First 1

if (-not $chrome) { Write-Host "Chrome not found. Install Google Chrome and run again." -ForegroundColor Red; exit 1 }

$windows = @(
    @{ Name = "Media"; Url = "$BaseUrl/media"; Screen = Get-Screen $Layout.Media },
    @{ Name = "Main";  Url = "$BaseUrl/";      Screen = Get-Screen $Layout.Main  },
    @{ Name = "Comps"; Url = "$BaseUrl/comps"; Screen = Get-Screen $Layout.Comps }
)

foreach ($w in $windows) {
    $b = $w.Screen.Bounds
    # Each window gets its own profile folder so Chrome treats it as a separate app window.
    $profile = Join-Path $env:LOCALAPPDATA "OishiRenewal\$($w.Name)"
    $args = @(
        "--app=$($w.Url)",
        "--user-data-dir=`"$profile`"",
        "--window-position=$($b.X),$($b.Y)",
        "--window-size=$($b.Width),$($b.Height)",
        "--no-first-run",
        "--no-default-browser-check"
    )
    if ($FullScreen) { $args += "--start-fullscreen" }
    Start-Process -FilePath $chrome -ArgumentList $args
    Start-Sleep -Milliseconds 700
}

Write-Host "Renewal Center opened on $($windows.Count) monitors." -ForegroundColor Green
