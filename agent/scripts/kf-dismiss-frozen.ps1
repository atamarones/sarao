# Cierra el aviso "Error occurred / The application seems to be frozen" de KaraFun Player 2 pulsando OK
# (nunca "Terminate"). El aviso es modal y BLOQUEA el arranque de KaraFun hasta que alguien lo cierra.
# Antes de pulsar OK desmarca "Send this error via Internet" y "Attach a Screenshot image", para que
# KaraFun no envíe un informe con una captura de la pantalla del bar.
# Usa la API de Win32 (los botones se llaman "&OK", "&Terminate"…; UI Automation no los encontraba).
param([switch]$ListOnly)

Add-Type -TypeDefinition @'
using System; using System.Text; using System.Runtime.InteropServices; using System.Collections.Generic;
public static class KfDlg {
  public delegate bool EnumProc(IntPtr h, IntPtr l);
  [DllImport("user32.dll")] static extern bool EnumWindows(EnumProc f, IntPtr l);
  [DllImport("user32.dll")] static extern bool EnumChildWindows(IntPtr p, EnumProc f, IntPtr l);
  [DllImport("user32.dll")] static extern uint GetWindowThreadProcessId(IntPtr h, out uint pid);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] static extern int GetWindowText(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll", CharSet=CharSet.Unicode)] static extern int GetClassName(IntPtr h, StringBuilder s, int n);
  [DllImport("user32.dll")] public static extern IntPtr SendMessage(IntPtr h, uint m, IntPtr w, IntPtr l);
  public static string Text(IntPtr h){ var s = new StringBuilder(256); GetWindowText(h, s, 256); return s.ToString(); }
  public static string Cls(IntPtr h){ var s = new StringBuilder(256); GetClassName(h, s, 256); return s.ToString(); }
  public static List<IntPtr> Dialogs(uint pid){
    var r = new List<IntPtr>();
    EnumWindows((h, l) => { uint p; GetWindowThreadProcessId(h, out p);
      if (p == pid && Cls(h) == "#32770" && Text(h) == "Error occurred") r.Add(h); return true; }, IntPtr.Zero);
    return r;
  }
  public static List<IntPtr> Buttons(IntPtr dlg){
    var r = new List<IntPtr>();
    EnumChildWindows(dlg, (h, l) => { if (Cls(h) == "Button") r.Add(h); return true; }, IntPtr.Zero);
    return r;
  }
}
'@

$BM_SETCHECK = 0x00F1
$BM_CLICK = 0x00F5
$procs = Get-Process KaraFunPlayer -ErrorAction SilentlyContinue
if (-not $procs) { 'KaraFunPlayer not running'; exit 1 }
$found = $false
foreach ($p in $procs) {
  foreach ($dlg in [KfDlg]::Dialogs([uint32]$p.Id)) {
    $found = $true
    $buttons = [KfDlg]::Buttons($dlg)
    $label = @{}
    foreach ($b in $buttons) { $label[$b] = ([KfDlg]::Text($b) -replace '&', '') }
    "dialog $dlg buttons: " + (($buttons | ForEach-Object { $label[$_] }) -join ' | ')
    if ($ListOnly) { continue }
    foreach ($b in $buttons) {
      if ($label[$b] -match '^(Send this error via Internet|Attach a Screenshot image)$') {
        [void][KfDlg]::SendMessage($b, $BM_SETCHECK, [IntPtr]0, [IntPtr]0)
      }
    }
    $ok = $buttons | Where-Object { $label[$_] -eq 'OK' } | Select-Object -First 1
    if ($ok) { [void][KfDlg]::SendMessage($ok, $BM_CLICK, [IntPtr]0, [IntPtr]0); '  -> pressed OK' }
    else { '  -> OK button not found' }
  }
}
if (-not $found) { 'no watchdog dialog' }
