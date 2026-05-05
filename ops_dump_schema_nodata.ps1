param(
    [string]$DbName = "nanfinance",
    [string]$DbUser = "root",
    [string]$DbHost = "127.0.0.1",
    [string]$DbPort = "3306",
    [string]$OutFile = "C:\xampp\htdocs\EngNano360\database\schema.sql"
)

$mysqldump = "C:\xampp\mysql\bin\mysqldump.exe"
if (!(Test-Path $mysqldump)) {
    throw "mysqldump not found at $mysqldump"
}

$outDir = Split-Path -Parent $OutFile
if ($outDir -and !(Test-Path $outDir)) {
    New-Item -ItemType Directory -Path $outDir -Force | Out-Null
}

$cmd = "`"$mysqldump`" -u $DbUser -h $DbHost -P $DbPort --default-character-set=utf8mb4 --single-transaction --skip-lock-tables --no-data --routines --events --triggers $DbName > `"$OutFile`""
cmd /c $cmd

if (!(Test-Path $OutFile)) {
    throw "Failed to create schema dump file: $OutFile"
}

Write-Output "DONE: schema-only dump created at $OutFile"
