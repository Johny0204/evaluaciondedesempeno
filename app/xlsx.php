<?php

/**
 * Exportación a Excel (.xlsx) sin librerías externas (requiere extensión zip).
 * Si zip no está disponible, se exporta CSV compatible con Excel.
 * $sheets = ['Nombre hoja' => [[encabezados...], [fila...], ...], ...]
 */
function exportar_excel(string $nombreArchivo, array $sheets): void
{
    $nombreArchivo = preg_replace('/[^A-Za-z0-9_\-]/', '_', $nombreArchivo);
    if (!class_exists('ZipArchive')) {
        $first = reset($sheets);
        header('Content-Type: text/csv; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nombreArchivo . '.csv"');
        echo "\xEF\xBB\xBF";
        $out = fopen('php://output', 'w');
        foreach ($first as $row) {
            fputcsv($out, $row);
        }
        exit;
    }
    $tmp = tempnam(APP_ROOT . '/storage/exports', 'xlsx');
    xlsx_escribir($tmp, $sheets);
    header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    header('Content-Disposition: attachment; filename="' . $nombreArchivo . '.xlsx"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}

function xlsx_col(int $i): string
{
    $s = '';
    $i++;
    while ($i > 0) {
        $m = ($i - 1) % 26;
        $s = chr(65 + $m) . $s;
        $i = intdiv($i - 1, 26);
    }
    return $s;
}

function xlsx_escribir(string $path, array $sheets): void
{
    $x = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    $zip = new ZipArchive();
    $zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $n = count($sheets);
    $ct = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
    for ($i = 1; $i <= $n; $i++) {
        $ct .= '<Override PartName="/xl/worksheets/sheet' . $i . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
    }
    $ct .= '</Types>';
    $zip->addFromString('[Content_Types].xml', $ct);
    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
    $wb = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    $i = 0;
    $usados = [];
    foreach ($sheets as $name => $rows) {
        $i++;
        $safe = mb_substr(preg_replace('/[\\\\\/\?\*\[\]:]/', ' ', (string)$name), 0, 31) ?: "Hoja$i";
        while (in_array($safe, $usados, true)) {
            $safe = mb_substr($safe, 0, 28) . $i;
        }
        $usados[] = $safe;
        $wb .= '<sheet name="' . $x($safe) . '" sheetId="' . $i . '" r:id="rId' . $i . '"/>';
        $rels .= '<Relationship Id="rId' . $i . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . $i . '.xml"/>';
        $sx = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetViews><sheetView workbookViewId="0"><pane ySplit="1" topLeftCell="A2" activePane="bottomLeft" state="frozen"/></sheetView></sheetViews><sheetData>';
        foreach (array_values($rows) as $r => $row) {
            $sx .= '<row r="' . ($r + 1) . '">';
            foreach (array_values($row) as $c => $val) {
                $ref = xlsx_col($c) . ($r + 1);
                $style = $r === 0 ? ' s="1"' : '';
                if ($val === null || $val === '') {
                    $sx .= '<c r="' . $ref . '"' . $style . '/>';
                } elseif ((is_int($val) || is_float($val)) && $r > 0) {
                    $sx .= '<c r="' . $ref . '"><v>' . $val . '</v></c>';
                } else {
                    $sx .= '<c r="' . $ref . '" t="inlineStr"' . $style . '><is><t xml:space="preserve">' . $x($val) . '</t></is></c>';
                }
            }
            $sx .= '</row>';
        }
        $sx .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet' . $i . '.xml', $sx);
    }
    $wb .= '</sheets></workbook>';
    $rels .= '<Relationship Id="rId' . ($n + 1) . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/></Relationships>';
    $zip->addFromString('xl/workbook.xml', $wb);
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<fonts count="2"><font><sz val="11"/><name val="Calibri"/></font><font><b/><sz val="11"/><color rgb="FFFFFFFF"/><name val="Calibri"/></font></fonts>'
        . '<fills count="3"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill><fill><patternFill patternType="solid"><fgColor rgb="FF1F4E79"/></patternFill></fill></fills>'
        . '<borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders>'
        . '<cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs>'
        . '<cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="0" fontId="1" fillId="2" borderId="0" xfId="0" applyFont="1" applyFill="1"/></cellXfs>'
        . '<cellStyles count="1"><cellStyle name="Normal" xfId="0" builtinId="0"/></cellStyles></styleSheet>');
    $zip->close();
}

/** Lee la primera hoja de un .xlsx o un .csv y devuelve un arreglo de filas. */
function leer_hoja(string $path, string $nombreOriginal): array
{
    $ext = strtolower(pathinfo($nombreOriginal, PATHINFO_EXTENSION));
    if ($ext === 'csv' || $ext === 'txt') {
        $content = file_get_contents($path);
        if (strncmp($content, "\xEF\xBB\xBF", 3) === 0) {
            $content = substr($content, 3);
        }
        if (!mb_check_encoding($content, 'UTF-8')) {
            $content = mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
        }
        $firstLine = strtok($content, "\n");
        $delim = substr_count($firstLine, ';') > substr_count($firstLine, ',') ? ';' : ',';
        $rows = [];
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $content);
        rewind($fh);
        while (($r = fgetcsv($fh, 0, $delim)) !== false) {
            if ($r === [null]) {
                continue;
            }
            $rows[] = array_map(fn($v) => trim((string)$v), $r);
        }
        fclose($fh);
        return $rows;
    }
    if ($ext !== 'xlsx') {
        throw new RuntimeException('Formato no soportado. Use .xlsx o .csv');
    }
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('El servidor no tiene la extensión zip habilitada; use un archivo .csv');
    }
    $zip = new ZipArchive();
    if ($zip->open($path) !== true) {
        throw new RuntimeException('No se pudo abrir el archivo Excel.');
    }
    $shared = [];
    if (($ss = $zip->getFromName('xl/sharedStrings.xml')) !== false) {
        $xml = simplexml_load_string($ss);
        foreach ($xml->si as $si) {
            if (isset($si->t)) {
                $shared[] = (string)$si->t;
            } else {
                $t = '';
                foreach ($si->r as $run) {
                    $t .= (string)$run->t;
                }
                $shared[] = $t;
            }
        }
    }
    $sheetPath = 'xl/worksheets/sheet1.xml';
    $wbRels = $zip->getFromName('xl/_rels/workbook.xml.rels');
    $wbXml = $zip->getFromName('xl/workbook.xml');
    if ($wbRels && $wbXml) {
        $wb = simplexml_load_string($wbXml);
        $wb->registerXPathNamespace('m', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $first = $wb->xpath('//m:sheets/m:sheet')[0] ?? null;
        if ($first) {
            $rid = (string)$first->attributes('http://schemas.openxmlformats.org/officeDocument/2006/relationships')['id'];
            $rels = simplexml_load_string($wbRels);
            foreach ($rels->Relationship as $rel) {
                if ((string)$rel['Id'] === $rid) {
                    $target = ltrim((string)$rel['Target'], '/');
                    $sheetPath = strpos($target, 'xl/') === 0 ? $target : 'xl/' . $target;
                }
            }
        }
    }
    $sheet = $zip->getFromName($sheetPath);
    $zip->close();
    if ($sheet === false) {
        throw new RuntimeException('No se encontró la hoja en el archivo.');
    }
    $xml = simplexml_load_string($sheet);
    $rows = [];
    foreach ($xml->sheetData->row as $row) {
        $r = [];
        foreach ($row->c as $c) {
            $ref = (string)$c['r'];
            $col = 0;
            if (preg_match('/^([A-Z]+)/', $ref, $m)) {
                foreach (str_split($m[1]) as $ch) {
                    $col = $col * 26 + (ord($ch) - 64);
                }
                $col--;
            }
            $t = (string)$c['t'];
            if ($t === 's') {
                $v = $shared[(int)$c->v] ?? '';
            } elseif ($t === 'inlineStr') {
                $v = (string)$c->is->t;
            } else {
                $v = (string)$c->v;
            }
            $r[$col] = trim($v);
        }
        if ($r) {
            $max = max(array_keys($r));
            $full = [];
            for ($i = 0; $i <= $max; $i++) {
                $full[] = $r[$i] ?? '';
            }
            $rows[] = $full;
        }
    }
    return $rows;
}
