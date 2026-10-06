param(
  [Parameter(Mandatory)][string]$Message,
  [Parameter(Mandatory)][string]$OutFile,
  [string]$Expect = '',          # root tag of the reply to wait for (e.g. "list"); empty = first non-status reply
  [int]$TimeoutMs = 30000
)
# Sends one action to KaraFun Player 2 (ws://127.0.0.1:57570) and saves the matching XML reply to $OutFile.
$ErrorActionPreference = 'Stop'
$ws = New-Object System.Net.WebSockets.ClientWebSocket
$ct = [System.Threading.CancellationToken]::None
try {
  $ws.ConnectAsync([Uri]"ws://127.0.0.1:57570/", $ct).Wait()
  $buf = New-Object byte[] 1048576
  $bytes = [Text.Encoding]::UTF8.GetBytes($Message)
  $ws.SendAsync((New-Object System.ArraySegment[byte] -ArgumentList (,$bytes)), 'Text', $true, $ct).Wait()
  $sw = [Diagnostics.Stopwatch]::StartNew()
  $ms = New-Object IO.MemoryStream
  while ($sw.ElapsedMilliseconds -lt $TimeoutMs -and $ws.State -eq 'Open') {
    $t = $ws.ReceiveAsync((New-Object System.ArraySegment[byte] -ArgumentList (,$buf)), $ct)
    # A pending receive cannot be abandoned and re-issued, so on timeout we stop instead of looping.
    if (-not $t.Wait([int]([Math]::Max(50, $TimeoutMs - $sw.ElapsedMilliseconds)))) { break }
    if ($t.Result.MessageType -eq 'Close') { break }
    $ms.Write($buf, 0, $t.Result.Count)
    if ($t.Result.EndOfMessage) {
      $s = [Text.Encoding]::UTF8.GetString($ms.ToArray()); $ms.SetLength(0)
      $isStatus = $s.StartsWith('<status')
      if (($Expect -and $s.StartsWith("<$Expect")) -or (-not $Expect -and -not $isStatus)) {
        [IO.File]::WriteAllText($OutFile, $s, (New-Object Text.UTF8Encoding $false))
        "OK $($s.Length) chars -> $OutFile"
        exit 0
      }
    }
  }
  "TIMEOUT or connection closed waiting for reply"; exit 1
} catch {
  "ERROR: $($_.Exception.GetBaseException().Message)"; exit 2
} finally {
  $ws.Abort()
}
