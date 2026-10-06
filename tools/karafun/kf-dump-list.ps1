param(
  [Parameter(Mandatory)][int]$CatalogId,
  [Parameter(Mandatory)][string]$OutCsv,
  [int]$PageSize = 100
)
# Pages through getList for one KaraFun catalog over a single WebSocket and writes id,title,artist,duration to CSV.
$ErrorActionPreference = 'Stop'
$ws = New-Object System.Net.WebSockets.ClientWebSocket
$ct = [System.Threading.CancellationToken]::None
$ws.ConnectAsync([Uri]"ws://127.0.0.1:57570/", $ct).Wait()
$buf = New-Object byte[] 1048576

function Send([string]$m) {
  $b = [Text.Encoding]::UTF8.GetBytes($m)
  $ws.SendAsync((New-Object System.ArraySegment[byte] -ArgumentList (,$b)), 'Text', $true, $ct).Wait()
}
function Receive-List([int]$timeoutMs) {
  $sw = [Diagnostics.Stopwatch]::StartNew(); $ms = New-Object IO.MemoryStream
  while ($sw.ElapsedMilliseconds -lt $timeoutMs) {
    $t = $ws.ReceiveAsync((New-Object System.ArraySegment[byte] -ArgumentList (,$buf)), $ct)
    if (-not $t.Wait([int]([Math]::Max(50, $timeoutMs - $sw.ElapsedMilliseconds)))) { break }
    $ms.Write($buf, 0, $t.Result.Count)
    if ($t.Result.EndOfMessage) {
      $s = [Text.Encoding]::UTF8.GetString($ms.ToArray()); $ms.SetLength(0)
      if ($s.StartsWith('<list')) { return [xml]$s }
    }
  }
  throw "timeout waiting for list"
}

$rows = New-Object System.Collections.Generic.List[object]
$offset = 0; $total = $null
do {
  Send "<action type=`"getList`" id=`"$CatalogId`" offset=`"$offset`" limit=`"$PageSize`"></action>"
  $x = Receive-List 30000
  if ($null -eq $total) { $total = [int]$x.DocumentElement.GetAttribute('total') }
  $items = @($x.DocumentElement.SelectNodes('item'))
  foreach ($i in $items) {
    $rows.Add([pscustomobject]@{
      id = [int]$i.GetAttribute('id'); title = $i.SelectSingleNode('title').InnerText
      artist = $i.SelectSingleNode('artist').InnerText; duration = $i.SelectSingleNode('duration').InnerText
    })
  }
  $offset += $PageSize
  # The "total" attribute is unreliable (seen "2614" and "2" for the same folder): page until a short page.
} while ($items.Count -eq $PageSize)
$ws.Abort()
$rows | Export-Csv -NoTypeInformation -Encoding UTF8 $OutCsv
"catalog=$CatalogId total=$total rows=$($rows.Count) -> $OutCsv"
