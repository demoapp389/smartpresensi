<?php

namespace App\Services;

use RuntimeException;
use ZipArchive;

/**
 * Service pembaca file Microsoft Excel OpenXML (.xlsx) native PHP tanpa dependensi pihak ketiga.
 * Menggunakan ekstensi built-in ZipArchive dan SimpleXML.
 */
class SimpleXlsxReader
{
    /**
     * Membaca file .xlsx dan mengembalikan array 2D [baris][kolom].
     *
     * @return array<int, array<int, string>>
     */
    public static function parse(string $filePath): array
    {
        if (! file_exists($filePath)) {
            throw new RuntimeException("File tidak ditemukan: {$filePath}");
        }

        $zip = new ZipArchive;
        if ($zip->open($filePath) !== true) {
            throw new RuntimeException('Gagal membuka file XLSX sebagai ZipArchive.');
        }

        // 1. Baca shared strings jika ada (xl/sharedStrings.xml)
        $sharedStrings = [];
        $sharedXmlContent = $zip->getFromName('xl/sharedStrings.xml');
        if ($sharedXmlContent !== false) {
            $sXml = @simplexml_load_string($sharedXmlContent);
            if ($sXml && isset($sXml->si)) {
                foreach ($sXml->si as $si) {
                    $text = '';
                    if (isset($si->t)) {
                        $text = (string) $si->t;
                    } elseif (isset($si->r)) {
                        foreach ($si->r as $r) {
                            $text .= (string) ($r->t ?? '');
                        }
                    }
                    $sharedStrings[] = $text;
                }
            }
        }

        // 2. Baca worksheet pertama (xl/worksheets/sheet1.xml atau fallback)
        $sheetXmlContent = $zip->getFromName('xl/worksheets/sheet1.xml');
        if ($sheetXmlContent === false) {
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if ($stat && preg_match('#xl/worksheets/sheet\d+\.xml#i', $stat['name'])) {
                    $sheetXmlContent = $zip->getFromIndex($i);
                    break;
                }
            }
        }

        $zip->close();

        if ($sheetXmlContent === false) {
            throw new RuntimeException('Tidak ditemukan lembar kerja (worksheet) dalam file XLSX.');
        }

        $sheetXml = @simplexml_load_string($sheetXmlContent);
        if (! $sheetXml || ! isset($sheetXml->sheetData->row)) {
            return [];
        }

        $rows = [];
        foreach ($sheetXml->sheetData->row as $rowNode) {
            $rowCells = [];
            $maxColIndex = -1;

            foreach ($rowNode->c as $cell) {
                $ref = (string) $cell['r']; // e.g. "A1", "C2"
                $colIndex = self::columnLetterToIndex($ref);

                $type = (string) ($cell['t'] ?? '');
                $val = '';

                if ($type === 's') {
                    $idx = (int) ($cell->v ?? 0);
                    $val = $sharedStrings[$idx] ?? '';
                } elseif ($type === 'inlineStr' && isset($cell->is->t)) {
                    $val = (string) $cell->is->t;
                } else {
                    $val = isset($cell->v) ? (string) $cell->v : '';
                }

                $rowCells[$colIndex] = trim($val);
                if ($colIndex > $maxColIndex) {
                    $maxColIndex = $colIndex;
                }
            }

            // Normalisasi agar setiap baris memiliki elemen berurutan
            if ($maxColIndex >= 0) {
                $filledRow = [];
                for ($c = 0; $c <= $maxColIndex; $c++) {
                    $filledRow[$c] = $rowCells[$c] ?? '';
                }

                // Cek apakah seluruh baris kosong
                $hasContent = false;
                foreach ($filledRow as $cVal) {
                    if ($cVal !== '') {
                        $hasContent = true;
                        break;
                    }
                }

                if ($hasContent) {
                    $rows[] = $filledRow;
                }
            }
        }

        return $rows;
    }

    /**
     * Konversi referensi cell (misal: "A1", "BC23") menjadi index kolom 0-based.
     */
    public static function columnLetterToIndex(string $cellRef): int
    {
        preg_match('/^([A-Z]+)/i', $cellRef, $matches);
        $letters = strtoupper($matches[1] ?? 'A');

        $index = 0;
        $len = strlen($letters);
        for ($i = 0; $i < $len; $i++) {
            $index = $index * 26 + (ord($letters[$i]) - ord('A') + 1);
        }

        return max(0, $index - 1);
    }
}
