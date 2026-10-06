# Imprime True si KaraFunPlayer responde a Windows, False si está colgado o no existe.
$p = Get-Process KaraFunPlayer -ErrorAction SilentlyContinue | Select-Object -First 1
if ($p) { $p.Responding } else { $false }
