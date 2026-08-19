$ErrorActionPreference = 'Stop'

$base = Split-Path -Parent $MyInvocation.MyCommand.Path
$data = Get-Content -LiteralPath (Join-Path $base 'meta_hotmart_result.json') -Raw | ConvertFrom-Json
$output = Join-Path $base 'cruzamento_meta_hotmart.xlsx'

$excel = New-Object -ComObject Excel.Application
$excel.Visible = $false
$excel.DisplayAlerts = $false

try {
    $wb = $excel.Workbooks.Add()
    while ($wb.Worksheets.Count -lt 3) { [void]$wb.Worksheets.Add() }
    while ($wb.Worksheets.Count -gt 3) { $wb.Worksheets.Item($wb.Worksheets.Count).Delete() }

    $summary = $wb.Worksheets.Item(1)
    $summary.Name = 'Resumo'
    $found = $wb.Worksheets.Item(2)
    $found.Name = 'Compradores'
    $notFound = $wb.Worksheets.Item(3)
    $notFound.Name = 'Nao encontrados'

    function Set-Header($sheet, [string[]]$headers) {
        for ($i = 0; $i -lt $headers.Count; $i++) {
            $sheet.Cells.Item(1, $i + 1).Value2 = $headers[$i]
        }
        $range = $sheet.Range($sheet.Cells.Item(1,1), $sheet.Cells.Item(1,$headers.Count))
        $range.Font.Bold = $true
        $range.Interior.Color = 0x1F4E78
        $range.Font.Color = 0xFFFFFF
    }

    function Write-Rows($sheet, [string[]]$headers, $rows) {
        Set-Header $sheet $headers
        $r = 2
        foreach ($row in $rows) {
            for ($c = 0; $c -lt $headers.Count; $c++) {
                $name = $headers[$c]
                $sheet.Cells.Item($r, $c + 1).Value2 = [string]($row.$name)
            }
            $r++
        }
        $used = $sheet.UsedRange
        [void]$used.Columns.AutoFit()
        $sheet.Application.ActiveWindow.SplitRow = 1
        $sheet.Application.ActiveWindow.FreezePanes = $true
        if ($rows.Count -gt 0) {
            $lastRow = $rows.Count + 1
            $lastCol = $headers.Count
            $list = $sheet.ListObjects.Add(1, $sheet.Range($sheet.Cells.Item(1,1), $sheet.Cells.Item($lastRow,$lastCol)), $null, 1)
            $list.TableStyle = 'TableStyleMedium2'
        }
    }

    $summary.Cells.Item(1,1).Value2 = 'Cruzamento Meta x Hotmart'
    $summary.Cells.Item(1,1).Font.Bold = $true
    $summary.Cells.Item(1,1).Font.Size = 16
    $summary.Range('A1:D1').Merge()
    $summary.Cells.Item(3,1).Value2 = 'Métrica'
    $summary.Cells.Item(3,2).Value2 = 'Valor'
    $summary.Range('A3:B3').Font.Bold = $true
    $summary.Range('A3:B3').Interior.Color = 0x1F4E78
    $summary.Range('A3:B3').Font.Color = 0xFFFFFF

    $metrics = @(
        @('Leads na planilha Meta', $data.summary.source_rows),
        @('Leads com e-mail', $data.summary.source_with_email),
        @('Leads com telefone', $data.summary.source_with_phone),
        @('Leads que compraram', $data.summary.matched_leads),
        @('Leads sem compra localizada', $data.summary.unmatched_leads),
        @('Linhas de match lead x venda', $data.summary.matched_lines),
        @('Transações confirmadas encontradas', $data.summary.matched_transactions),
        @('Faturamento bruto encontrado', ('R$ {0:N2}' -f [double]$data.summary.gross_revenue)),
        @('Líquido produtor encontrado', ('R$ {0:N2}' -f [double]$data.summary.producer_net)),
        @('Critério de venda', 'Status Hotmart Aprovado/Completo; reembolso, cancelado, chargeback e pendente fora'),
        @('Campos usados no Meta', ('E-mail: ' + ($data.summary.email_columns -join ', ') + ' | Telefone: ' + ($data.summary.phone_columns -join ', ')))
    )
    for ($i = 0; $i -lt $metrics.Count; $i++) {
        $summary.Cells.Item($i + 4, 1).Value2 = $metrics[$i][0]
        $summary.Cells.Item($i + 4, 2).Value2 = [string]$metrics[$i][1]
    }
    $summary.Columns.AutoFit()

    $foundHeaders = @('lead_row','lead_name','lead_email','lead_phone','lead_phone_norm','lead_created_at','match_criterion','transaction_code','sale_status','sale_date','buyer_name','buyer_email','buyer_phone','product_name','price_name','gross_revenue','producer_net')
    Write-Rows $found $foundHeaders $data.details

    $notFoundHeaders = @('lead_row','lead_name','lead_email','lead_phone','lead_phone_norm','lead_created_at')
    Write-Rows $notFound $notFoundHeaders $data.unmatched

    $wb.SaveAs($output, 51)
    Write-Output $output
}
finally {
    if ($wb) { $wb.Close($true) | Out-Null }
    $excel.Quit()
    [System.Runtime.InteropServices.Marshal]::ReleaseComObject($excel) | Out-Null
}
