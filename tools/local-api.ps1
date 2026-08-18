<#
.SYNOPSIS
    Bring up a local API the console can talk to — one command, on Windows or PowerShell 7 anywhere.

.DESCRIPTION
    The PowerShell counterpart of tools/local-api.sh. Same behaviour, same findings baked in:

      * PHP is often not on PATH — this looks in the places Windows installers actually
        use (Laragon, XAMPP, Herd) rather than failing with "not recognized" on a machine
        that plainly has PHP.
      * The suite and the image code need memory_limit above the 128M default, and that
        failure is a *fatal* the exit status misreports (evidence ledger, finding L-02).
      * The MFA challenge lives in the cache, so an `array` store makes the second factor
        always report an expired attempt — the challenge issued by one request does not
        exist for the next.
      * `artisan --env=X` OVERRIDES APP_ENV whatever the env file says, and DatabaseSeeder
        creates the development staff account only when the environment is local or
        testing. Running as any other name migrates and seeds happily and leaves you with
        no account to sign in with.

    Deliberately NOT a substitute for `docker compose up`. This runs on SQLite, so response
    shapes are real and everything about concurrency, row locking and lockForUpdate is not —
    release-gate blocker 4 is untouched by anything proven here.

.PARAMETER Command
    up     migrate, seed and serve on :8000
    down   stop the server
    reset  throw the database away
    staff  give the seeded account a usable password

.EXAMPLE
    ./tools/local-api.ps1 up
    ./tools/local-api.ps1 staff
#>

[CmdletBinding()]
param(
    [Parameter(Position = 0)]
    [ValidateSet('up', 'down', 'reset', 'staff')]
    [string]$Command = 'up',

    [int]$Port = 8000
)

$ErrorActionPreference = 'Stop'
Set-StrictMode -Version Latest

$Root = Split-Path -Parent $PSScriptRoot
Set-Location $Root

# `local`, and the name matters more than it looks — see the note above about --env.
$EnvName  = 'local'
$EnvFile  = Join-Path $Root ".env.$EnvName"
$StateDir = if ($env:TAYTAY_LOCAL_STATE) { $env:TAYTAY_LOCAL_STATE } else { Join-Path $Root 'storage/local-api' }
$Db       = Join-Path $StateDir 'integration.sqlite'

function Write-Step([string]$Message) {
    Write-Host ''
    Write-Host $Message -ForegroundColor Cyan
}

function Resolve-Php {
    $onPath = Get-Command php -ErrorAction SilentlyContinue
    if ($onPath) { return $onPath.Source }

    # The places Windows installers actually put it, plus Herd for PowerShell on macOS.
    $candidates = @(
        'C:\laragon\bin\php\php-8.3\php.exe'
        'C:\xampp\php\php.exe'
        'C:\tools\php\php.exe'
        "$env:LOCALAPPDATA\Herd\bin\php.exe"
        "$HOME/Library/Application Support/Herd/bin/php"
    )

    foreach ($candidate in $candidates) {
        if ($candidate -and (Test-Path $candidate)) { return $candidate }
    }

    throw "No php on PATH, and none at any known install location. Install PHP 8.3+ or add it to PATH."
}

$Php = Resolve-Php

function Invoke-Php {
    param([Parameter(ValueFromRemainingArguments = $true)][string[]]$Arguments)

    # -d memory_limit on every invocation: finding L-02. The image-derivation path
    # exhausts the 128M default and dies with a fatal the exit status does not report.
    & $Php '-d' 'memory_limit=1G' @Arguments
    if ($LASTEXITCODE -ne 0) { throw "php $($Arguments -join ' ') exited $LASTEXITCODE" }
}

function Initialize-Environment {
    New-Item -ItemType Directory -Force -Path $StateDir | Out-Null

    if (-not (Test-Path $EnvFile)) {
        if (-not (Test-Path (Join-Path $Root '.env'))) {
            throw "No .env to derive from. Run 'composer setup' first."
        }

        Write-Step "Creating .env.$EnvName (gitignored — it inherits real .env values)"

        (Get-Content (Join-Path $Root '.env')) |
            ForEach-Object {
                $_ -replace '^APP_ENV=.*',          "APP_ENV=$EnvName" `
                   -replace '^DB_CONNECTION=.*',    'DB_CONNECTION=sqlite' `
                   -replace '^QUEUE_CONNECTION=.*', 'QUEUE_CONNECTION=sync' `
                   -replace '^SESSION_DRIVER=.*',   'SESSION_DRIVER=array'
            } | Set-Content $EnvFile
    }

    # Re-point the env file at the database THIS run intends to use, every time.
    #
    # Without it, a leftover env file keeps its old DB_DATABASE, the file wins over the
    # script's own variable, and `up` reports "Nothing to migrate" while serving a database
    # you did not mean. Two sources for one fact, diverging silently.
    $dbForEnv = $Db -replace '\\', '/'
    (Get-Content $EnvFile) |
        ForEach-Object {
            $_ -replace '^DB_DATABASE=.*', "DB_DATABASE=$dbForEnv" `
               -replace '^CACHE_STORE=.*', 'CACHE_STORE=file'
        } | Set-Content $EnvFile

    if (-not (Test-Path $Db)) { New-Item -ItemType File -Path $Db | Out-Null }
}

function Invoke-Up {
    Initialize-Environment

    Write-Step 'Migrating (SQLite — not a substitute for PostgreSQL)'
    Invoke-Php 'artisan' 'migrate' "--env=$EnvName" '--force'

    Write-Step 'Seeding synthetic data'
    try { Invoke-Php 'artisan' 'db:seed' "--env=$EnvName" '--force' } catch { Write-Warning $_ }
    try { Invoke-Php 'artisan' 'db:seed' "--env=$EnvName" '--class=DemoDataSeeder' '--force' } catch { Write-Warning $_ }

    Write-Step "Serving on http://127.0.0.1:$Port"
    Write-Host "Health:  curl http://127.0.0.1:$Port/api/v1/health"
    Write-Host "Stop:    ./tools/local-api.ps1 down"
    Invoke-Php 'artisan' 'serve' "--env=$EnvName" '--host=127.0.0.1' "--port=$Port"
}

function Invoke-Down {
    $stopped = $false

    Get-CimInstance Win32_Process -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine -match 'artisan serve' -and $_.CommandLine -match "port=$Port" } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force; $stopped = $true }

    # PowerShell 7 on macOS or Linux: Win32_Process does not exist.
    if (-not $IsWindows) {
        $pids = (& pgrep -f "artisan serve.*--port=$Port") 2>$null
        foreach ($processId in $pids) {
            if ($processId) { Stop-Process -Id $processId -Force -ErrorAction SilentlyContinue; $stopped = $true }
        }
    }

    if ($stopped) { Write-Step 'Server stopped.' } else { Write-Step "No server running on :$Port." }
}

function Invoke-Reset {
    try { Invoke-Down } catch { }
    if (Test-Path $Db) { Remove-Item $Db -Force }
    Write-Step "Database removed. Run 'up' to rebuild it."
}

function Invoke-Staff {
    Initialize-Environment

    # A fixed, obviously synthetic password. It authenticates nobody anywhere else, and it
    # is printed on purpose so it can be pasted into a local sign-in form.
    $password = 'integration-only-not-a-real-credential'

    $env:DB    = $Db
    $env:PW    = $password
    $env:EMAIL = 'staff@example.test'

    # Every value comes from the environment and every statement is prepared, so there is
    # not a single quoted literal for two shells to disagree about.
    $script = @'
$pdo = new PDO("sqlite:" . getenv("DB"));
$find = $pdo->prepare("select uuid from accounts where email = ?");
$find->execute([getenv("EMAIL")]);
if ($find->fetchColumn() === false) {
    fwrite(STDERR, "no seeded staff account - run up first" . PHP_EOL);
    exit(1);
}
$pdo->prepare("update accounts set password_hash = ? where email = ?")
    ->execute([password_hash(getenv("PW"), PASSWORD_BCRYPT, ["cost" => 12]), getenv("EMAIL")]);
echo "password set" . PHP_EOL;
'@

    Invoke-Php '-r' $script

    Write-Host ''
    Write-Host '  Sign in with:' -ForegroundColor Green
    Write-Host '    email     staff@example.test'
    Write-Host "    password  $password"
    Write-Host ''
    Write-Host "  The account has no second factor, so the API answers 'mfa-enrolment-required'"
    Write-Host '  with a token that reaches enrolment and nothing else (ADR 0043).'
    Write-Host ''
}

switch ($Command) {
    'up'    { Invoke-Up }
    'down'  { Invoke-Down }
    'reset' { Invoke-Reset }
    'staff' { Invoke-Staff }
}
