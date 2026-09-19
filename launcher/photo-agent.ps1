# Renewal Center photo agent  v0.1
# A tiny read-only web server on http://localhost:8765/ that hands the
# office photo drive to the Media window. Chrome treats localhost as a
# secure origin, so an https page can load these images with no flags.
# Started by launch-renewal.ps1; can also be run on its own.
#
#   GET /list?pcode=XXXX     -> {"ok":true,"pcode":"XXXX","path":"...","files":["a.jpg",...]}
#   GET /photo/XXXX/a.jpg    -> the file
#   GET /ping                -> {"ok":true,"root":"..."}
#
# Edit ONLY the CONFIG block. {pcode} = the property code from Renewal Center.
# ============================== CONFIG ==============================
$PhotoRoot   = "\\server\photos"          # the mapped/UNC folder that holds one folder per property
$FolderRule  = "{pcode}"                   # folder name pattern inside $PhotoRoot (e.g. "{pcode}" or "{pcode}\Listing")
$Port        = 8765
$Extensions  = @(".jpg", ".jpeg", ".png", ".gif", ".webp", ".bmp")
$MaxFiles    = 60
# ====================================================================

$ErrorActionPreference = "Stop"
$prefix = "http://localhost:$Port/"
$listener = New-Object System.Net.HttpListener
$listener.Prefixes.Add($prefix)
try { $listener.Start() } catch {
    Write-Host "Photo agent: port $Port is busy (already running?) - $_" -ForegroundColor Yellow
    exit 0
}
Write-Host "Photo agent on $prefix serving $PhotoRoot  (Ctrl+C to stop)" -ForegroundColor Green

$mime = @{ ".jpg"="image/jpeg"; ".jpeg"="image/jpeg"; ".png"="image/png"; ".gif"="image/gif"; ".webp"="image/webp"; ".bmp"="image/bmp" }

function Send-Json($ctx, $obj, $code = 200) {
    $bytes = [Text.Encoding]::UTF8.GetBytes(($obj | ConvertTo-Json -Compress -Depth 4))
    $ctx.Response.StatusCode = $code
    $ctx.Response.ContentType = "application/json; charset=utf-8"
    $ctx.Response.Headers["Access-Control-Allow-Origin"] = "*"
    $ctx.Response.Headers["Cache-Control"] = "no-store"
    $ctx.Response.OutputStream.Write($bytes, 0, $bytes.Length)
    $ctx.Response.Close()
}
function Safe-Code($s) {
    # property codes are letters/digits/dash/underscore/space/dot only; anything else is refused
    if ($null -eq $s -or $s -notmatch '^[A-Za-z0-9 _.\-]{1,60}$' -or $s -match '\.\.') { return $null }
    return $s
}
function Folder-For($pcode) {
    $sub = $FolderRule.Replace("{pcode}", $pcode)
    return (Join-Path $PhotoRoot $sub)
}

while ($listener.IsListening) {
    try { $ctx = $listener.GetContext() } catch { break }
    try {
        $path = $ctx.Request.Url.AbsolutePath
        $q = $ctx.Request.QueryString
        if ($ctx.Request.HttpMethod -eq "OPTIONS") { Send-Json $ctx @{ok=$true}; continue }

        if ($path -eq "/ping") { Send-Json $ctx @{ ok = $true; root = $PhotoRoot; rule = $FolderRule; version = "0.1" }; continue }

        if ($path -eq "/list") {
            $pcode = Safe-Code $q["pcode"]
            if (-not $pcode) { Send-Json $ctx @{ ok = $false; error = "bad pcode" } 400; continue }
            $dir = Folder-For $pcode
            if (-not (Test-Path -LiteralPath $dir)) { Send-Json $ctx @{ ok = $true; pcode = $pcode; path = $dir; files = @() }; continue }
            $files = Get-ChildItem -LiteralPath $dir -File | Where-Object { $Extensions -contains $_.Extension.ToLower() } |
                     Sort-Object Name | Select-Object -First $MaxFiles | ForEach-Object { $_.Name }
            Send-Json $ctx @{ ok = $true; pcode = $pcode; path = $dir; files = @($files) }
            continue
        }

        if ($path -like "/photo/*") {
            $parts = $path.Substring(7).Split("/", 2)
            $pcode = Safe-Code ([Uri]::UnescapeDataString($parts[0]))
            $name  = if ($parts.Count -gt 1) { [Uri]::UnescapeDataString($parts[1]) } else { "" }
            if (-not $pcode -or $name -match '[\\/]' -or $name -match '\.\.' -or $name -eq "") { Send-Json $ctx @{ ok = $false; error = "bad path" } 400; continue }
            $file = Join-Path (Folder-For $pcode) $name
            $ext = [IO.Path]::GetExtension($file).ToLower()
            if (-not ($Extensions -contains $ext) -or -not (Test-Path -LiteralPath $file)) { Send-Json $ctx @{ ok = $false; error = "not found" } 404; continue }
            $bytes = [IO.File]::ReadAllBytes($file)
            $ctx.Response.StatusCode = 200
            $ctx.Response.ContentType = $mime[$ext]
            $ctx.Response.Headers["Access-Control-Allow-Origin"] = "*"
            $ctx.Response.Headers["Cache-Control"] = "private, max-age=3600"
            $ctx.Response.ContentLength64 = $bytes.Length
            $ctx.Response.OutputStream.Write($bytes, 0, $bytes.Length)
            $ctx.Response.Close()
            continue
        }

        Send-Json $ctx @{ ok = $false; error = "use /list?pcode=... or /photo/<pcode>/<file>" } 404
    } catch {
        try { Send-Json $ctx @{ ok = $false; error = "$_" } 500 } catch {}
    }
}
