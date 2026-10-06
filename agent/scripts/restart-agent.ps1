# Reinicia el agente del bar. Con -ResyncLocal, además vuelve a subir el catálogo local a la nube
# (útil después de mover canciones de «Por aprobar» a su carpeta y pulsar Actualizar en KaraFun).
#   powershell -ExecutionPolicy Bypass -File "C:\Users\Sarao Pub\Desktop\sarao\agent\scripts\restart-agent.ps1"
#   powershell -ExecutionPolicy Bypass -File "C:\Users\Sarao Pub\Desktop\sarao\agent\scripts\restart-agent.ps1" -ResyncLocal
param([switch]$ResyncLocal)
$ErrorActionPreference = 'Stop'
$agent = Split-Path -Parent $PSScriptRoot
$task = 'Sarao - Agente de karaoke'

Stop-ScheduledTask -TaskName $task -ErrorAction SilentlyContinue
Get-CimInstance Win32_Process -Filter "Name = 'pythonw.exe'" | Where-Object { $_.CommandLine -match 'sarao_agent' } |
    ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
Start-Sleep -Seconds 2

if ($ResyncLocal) {
    $state = Join-Path $agent 'data\state.json'
    if (Test-Path $state) {
        # Se edita con Python para escribir UTF-8 sin BOM (el mismo formato que usa el agente).
        & (Join-Path $agent '.venv\Scripts\python.exe') -c "import json,pathlib; p=pathlib.Path(r'$state'); s=json.loads(p.read_text(encoding='utf-8-sig')); s.pop('last_local_sync', None); p.write_text(json.dumps(s, indent=2), encoding='utf-8')"
    }
    'El catálogo local se volverá a subir en ~3 minutos.'
}

Start-ScheduledTask -TaskName $task
Start-Sleep -Seconds 5
"Agente: $((Get-ScheduledTask -TaskName $task).State)"
