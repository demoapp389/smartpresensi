<?php

namespace App\Services;

use Symfony\Component\HttpFoundation\BinaryFileResponse;
use ZipArchive;

/**
 * Service pembuat file Microsoft Excel OpenXML (.xlsx) native PHP tanpa dependensi pihak ketiga.
 * Menggunakan ekstensi built-in ZipArchive.
 */
class SimpleXlsxExporter
{
    /**
     * Sanitasi nilai sel untuk mencegah CSV/Excel Formula Injection.
     * Jika diawali '=', '+', '-', '@', '\t', '\r' dan bukan angka murni, tambahkan apostrof (').
     * Tanda '-' atau '+' tunggal dipertahankan agar placeholder tetap terbaca.
     */
    public static function sanitizeCellValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        $str = (string) $value;
        if ($str === '') {
            return '';
        }

        if (in_array($str, ['-', '+'], true)) {
            return $str;
        }

        if (in_array($str[0], ['=', '+', '-', '@', "\t", "\r"], true) && ! is_numeric($str)) {
            return "'".$str;
        }

        return $str;
    }

    /**
     * Konversi indeks kolom (0-based) menjadi nama kolom Excel (A, B, C, ..., Z, AA, AB, ...).
     */
    public static function getColumnLetter(int $index): string
    {
        $letter = '';
        $index += 1;

        while ($index > 0) {
            $remainder = ($index - 1) % 26;
            $letter = chr(65 + $remainder).$letter;
            $index = intdiv($index - $remainder, 26);
        }

        return $letter;
    }

    /**
     * Generate file .xlsx ke file temporary dan kembalikan BinaryFileResponse.
     *
     * @param  array<int, string>  $headers
     * @param  array<int, array<int, mixed>>  $rows
     * @param  array<int, float|int>  $columnWidths
     */
    public static function download(
        string $filename,
        array $headers,
        array $rows,
        string $sheetTitle = 'Rekap Presensi',
        array $columnWidths = []
    ): BinaryFileResponse {
        $tempPath = tempnam(sys_get_temp_dir(), 'xlsx_');
        if (! $tempPath) {
            throw new \RuntimeException('Gagal membuat file temporary.');
        }

        $zip = new ZipArchive;
        if ($zip->open($tempPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new \RuntimeException('Gagal menginisialisasi ZipArchive untuk XLSX.');
        }

        // 1. [Content_Types].xml
        $contentTypes = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            .'<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            .'<Default Extension="xml" ContentType="application/xml"/>'
            .'<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
            .'<Override PartName="/xl/worksheets/sheet1.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>'
            .'<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
            .'</Types>';
        $zip->addFromString('[Content_Types].xml', $contentTypes);

        // 2. _rels/.rels
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            .'</Relationships>';
        $zip->addFromString('_rels/.rels', $rels);

        // 3. xl/_rels/workbook.xml.rels
        $wbRels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            .'<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet1.xml"/>'
            .'<Relationship Id="rId2" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>'
            .'</Relationships>';
        $zip->addFromString('xl/_rels/workbook.xml.rels', $wbRels);

        // 4. xl/workbook.xml
        $safeSheetTitle = htmlspecialchars($sheetTitle, ENT_XML1, 'UTF-8');
        $workbook = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
            .'<sheets>'
            .'<sheet name="'.$safeSheetTitle.'" sheetId="1" r:id="rId1"/>'
            .'</sheets>'
            .'</workbook>';
        $zip->addFromString('xl/workbook.xml', $workbook);

        // 5. xl/styles.xml (Style 0 = Normal, Style 1 = Bold Header)
        $styles = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            .'<fonts count="2">'
            .'<font><sz val="11"/><name val="Segoe UI"/></font>'
            .'<font><b/><sz val="11"/><name val="Segoe UI"/></font>'
            .'</fonts>'
            .'<fills count="2">'
            .'<fill><patternFill patternType="none"/></fill>'
            .'<fill><patternFill patternType="gray125"/></fill>'
            .'</fills>'
            .'<borders count="1">'
            .'<border><left/><right/><top/><bottom/></border>'
            .'</borders>'
            .'<cellStyleXfs count="1">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0"/>'
            .'</cellStyleXfs>'
            .'<cellXfs count="2">'
            .'<xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/>'
            .'<xf numFmtId="0" fontId="1" fillId="0" borderId="0" xfId="0" applyFont="1"/>'
            .'</cellXfs>'
            .'</styleSheet>';
        $zip->addFromString('xl/styles.xml', $styles);

        // 6. xl/worksheets/sheet1.xml
        $sheetXml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            .'<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';

        if (! empty($columnWidths)) {
            $sheetXml .= '<cols>';
            foreach ($columnWidths as $colIndex => $width) {
                $columnNumber = $colIndex + 1;
                $sheetXml .= '<col min="'.$columnNumber.'" max="'.$columnNumber.'" width="'.round((float) $width, 2).'" customWidth="1"/>';
            }
            $sheetXml .= '</cols>';
        }

        $sheetXml .= '<sheetData>';

        $rowNumber = 1;

        // Render Baris Header (Bold)
        if (! empty($headers)) {
            $sheetXml .= '<row r="'.$rowNumber.'">';
            foreach ($headers as $colIndex => $head) {
                $cellRef = self::getColumnLetter($colIndex).$rowNumber;
                $safeVal = htmlspecialchars(self::sanitizeCellValue($head), ENT_XML1, 'UTF-8');
                $sheetXml .= '<c r="'.$cellRef.'" t="inlineStr" s="1"><is><t>'.$safeVal.'</t></is></c>';
            }
            $sheetXml .= '</row>';
            $rowNumber++;
        }

        // Render Baris Data
        foreach ($rows as $rowData) {
            $sheetXml .= '<row r="'.$rowNumber.'">';
            foreach ($rowData as $colIndex => $cellVal) {
                $cellRef = self::getColumnLetter($colIndex).$rowNumber;
                $safeVal = htmlspecialchars(self::sanitizeCellValue($cellVal), ENT_XML1, 'UTF-8');
                $sheetXml .= '<c r="'.$cellRef.'" t="inlineStr"><is><t>'.$safeVal.'</t></is></c>';
            }
            $sheetXml .= '</row>';
            $rowNumber++;
        }

        $sheetXml .= '</sheetData></worksheet>';
        $zip->addFromString('xl/worksheets/sheet1.xml', $sheetXml);

        $zip->close();

        $response = response()->download($tempPath, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);

        return $response->deleteFileAfterSend(true);
    }
}
