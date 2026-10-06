param([string[]]$Messages, [int]$ReadMs = 3000, [int]$MaxChars = 2500)
# Probe client for the KaraFun Player 2 WebSocket server (port 57570)
$ws = New-Object System.Net.WebSockets.ClientWebSocket
$ct = [System.Threading.CancellationToken]::None
$ws.ConnectAsync([Uri]"ws://127.0.0.1:57570/", $ct).Wait()
"CONNECTED: $($ws.State)"

$buf = New-Object byte[] 1048576
$script:pending = $null

function Read-All($ms) {
  $sw = [Diagnostics.Stopwatch]::StartNew()
  $sb = New-Object Text.StringBuilder
  while ($sw.ElapsedMilliseconds -lt $ms -and $ws.State -eq 'Open') {
    if ($null -eq $script:pending) {
      $seg = New-Object System.ArraySegment[byte] -ArgumentList (,$buf)
      $script:pending = $ws.ReceiveAsync($seg, $ct)
    }
    $left = [Math]::Max(50, $ms - $sw.ElapsedMilliseconds)
    if (-not $script:pending.Wait([int]$left)) { break }
    $res = $script:pending.Result
    $script:pending = $null
    [void]$sb.Append([Text.Encoding]::UTF8.GetString($buf, 0, $res.Count))
    if ($res.EndOfMessage) {
      $s = $sb.ToString()
      "<<< (" + $s.Length + " chars) " + $s.Substring(0, [Math]::Min($MaxChars, $s.Length))
      $sb.Clear() | Out-Null
    }
  }
}

Read-All 1500
foreach ($m in $Messages) {
  ">>> $m"
  $bytes = [Text.Encoding]::UTF8.GetBytes($m)
  $ws.SendAsync((New-Object System.ArraySegment[byte] -ArgumentList (,$bytes)), 'Text', $true, $ct).Wait()
  Read-All $ReadMs
}
$ws.Abort()
