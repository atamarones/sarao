# Instala el agente del bar en este PC (no requiere administrador).
#   powershell -ExecutionPolicy Bypass -File agent\install.ps1            -> instala y programa el arranque
#   powershell -ExecutionPolicy Bypass -File agent\install.ps1 -SetToken   -> guarda o cambia el token
#   powershell -ExecutionPolicy Bypass -File agent\install.ps1 -Uninstall  -> quita el arranque automático
param([switch]$SetToken, [switch]$Uninstall)
$ErrorActionPreference = 'Stop'
$here = Split-Path -Parent $MyInvocation.MyCommand.Path
$taskName = 'Sarao - Agente de karaoke'
$venv = Join-Path $here '.venv'
$data = Join-Path $here 'data'

if ($Uninstall) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false -ErrorAction SilentlyContinue
    Get-CimInstance Win32_Process -Filter "Name = 'pythonw.exe'" | Where-Object { $_.CommandLine -match 'sarao_agent' } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force }
    "Arranque automático quitado y agente detenido."
    return
}

New-Item -ItemType Directory -Force $data | Out-Null

if ($SetToken -or -not (Test-Path (Join-Path $data 'token.txt'))) {
    $secure = Read-Host -AsSecureString 'Pega el token del agente (lo genera el panel en Karaoke)'
    $plain = [Runtime.InteropServices.Marshal]::PtrToStringAuto([Runtime.InteropServices.Marshal]::SecureStringToBSTR($secure))
    if ($plain -notmatch '^[0-9a-fA-F]{64}$') { throw 'El token debe tener 64 caracteres hexadecimales.' }
    $tokenFile = Join-Path $data 'token.txt'
    Set-Content -Path $tokenFile -Value $plain -NoNewline -Encoding ascii
    # Solo el usuario actual puede leer el token.
    icacls $tokenFile /inheritance:r /grant:r "$($env:USERNAME):(R,W)" | Out-Null
    'Token guardado.'
    if ($SetToken) { return }
}

if (-not (Test-Path (Join-Path $here 'config.json'))) {
    Copy-Item (Join-Path $here 'config.example.json') (Join-Path $here 'config.json')
    'Creado config.json a partir de config.example.json; revisa las rutas si este PC es distinto.'
}

$py = Get-ChildItem "$env:LOCALAPPDATA\Programs\Python" -Recurse -Filter python.exe -ErrorAction SilentlyContinue |
    Where-Object { $_.FullName -match 'Python31[2-9]' } | Select-Object -First 1 -ExpandProperty FullName
if (-not $py) { throw 'No se encontró Python 3.12+. Instálalo con: winget install Python.Python.3.12' }
if (-not (Test-Path $venv)) { & $py -m venv $venv }
& "$venv\Scripts\python.exe" -m pip install --quiet --disable-pip-version-check -r (Join-Path $here 'requirements.txt')

$pythonw = Join-Path $venv 'Scripts\pythonw.exe'
$action = New-ScheduledTaskAction -Execute $pythonw -Argument '-m sarao_agent' -WorkingDirectory $here
$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME
$trigger.Delay = 'PT30S'  # deja que Windows termine de arrancar
$settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries `
    -ExecutionTimeLimit ([TimeSpan]::Zero) -RestartCount 999 -RestartInterval (New-TimeSpan -Minutes 1) `
    -MultipleInstances IgnoreNew -StartWhenAvailable
$principal = New-ScheduledTaskPrincipal -UserId $env:USERNAME -LogonType Interactive -RunLevel Limited
Register-ScheduledTask -TaskName $taskName -Action $action -Trigger $trigger -Settings $settings `
    -Principal $principal -Description 'Conecta los pedidos de las mesas (saraopub.com/karaoke) con KaraFun Player 2.' -Force | Out-Null
"Programado: «$taskName» arranca al iniciar sesión y se reinicia solo si se cae."
"Diagnóstico: $venv\Scripts\python.exe -m sarao_agent --check"
