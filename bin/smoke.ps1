<#
 .SYNOPSIS
  Checks Schedulely against a real Teme development site, using only the public console.

 .DESCRIPTION
  Enables the plugin (agreeing to what it asks for), runs its migration, confirms the four
  tables exist, and runs the site's health check. It changes nothing but this plugin's own
  state. It does NOT create content: Teme gives a plugin author no console command for that
  (see FINDINGS.md, H9), so the scheduling run itself is not exercised here.

 .PARAMETER Site
  The folder of the Teme development site. Defaults to ..\teme-dev beside this plugin.
#>
param(
    [string] $Site = (Join-Path $PSScriptRoot '..\..\teme-dev')
)

$ErrorActionPreference = 'Stop'
Set-Location $Site

function Step($name, [scriptblock] $block) {
    Write-Host "== $name"
    & $block
    if ($LASTEXITCODE -ne 0) { throw "$name failed (exit $LASTEXITCODE)" }
}

Step 'Plugin is found' { php artisan teme:plugins }
Step 'Enable (agrees to what the manifest asks for)' { php artisan teme:plugins:enable krafty-sprouts/schedulely --agree }
Step 'Run waiting database changes' { php artisan teme:plugins:migrate krafty-sprouts/schedulely }

Write-Host '== Tables exist'
$tables = 'schedulely_queue', 'schedulely_slots', 'schedulely_state', 'schedulely_reorder_log'
foreach ($table in $tables) {
    $answer = php artisan tinker --execute="echo Illuminate\Support\Facades\Schema::hasTable('$table') ? 'yes' : 'no';" | Select-Object -Last 1
    if ($answer -ne 'yes') { throw "Table $table is missing." }
    Write-Host "  $table"
}

Step 'Health check' { php artisan teme:doctor }
Write-Host 'Schedulely is enabled and its tables exist.'
