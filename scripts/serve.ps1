param(
    [string]$BindHost = 'localhost',
    [ValidateRange(1, 65535)]
    [int]$Port = 8000,
    [string]$PhpBin = 'C:\php\php.exe',
    [string]$QpdfBin = 'C:\Program Files\qpdf 12.4.2\bin\qpdf.exe'
)

$ErrorActionPreference = 'Stop'

if (-not (Test-Path -LiteralPath $PhpBin -PathType Leaf)) {
    throw "PHP executable '$PhpBin' was not found. Pass -PhpBin with the path to php.exe."
}

if (-not (Test-Path -LiteralPath $QpdfBin -PathType Leaf)) {
    throw "qpdf executable '$QpdfBin' was not found. Pass -QpdfBin with the path to qpdf.exe."
}

$AppRoot = Split-Path -Parent $PSScriptRoot
$env:QPDF_BINARY = $QpdfBin

Write-Output "Starting PDF Magic in $AppRoot"
Write-Output "URL: http://${BindHost}:${Port}/"
Write-Output 'Press Ctrl+C to stop.'

Push-Location $AppRoot
try {
    & $PhpBin -S "${BindHost}:${Port}"
    if ($LASTEXITCODE -ne 0) {
        exit $LASTEXITCODE
    }
}
finally {
    Pop-Location
}
