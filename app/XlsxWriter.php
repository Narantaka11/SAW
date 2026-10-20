<?php
declare(strict_types=1);

final class XlsxWriter
{
    public static function write(array $sheets, string $targetPath): void
    {
        if (!class_exists(ZipArchive::class) || !class_exists(XMLWriter::class)) {
            throw new RuntimeException('Ekstensi ZipArchive dan XMLWriter diperlukan untuk ekspor XLSX.');
        }

        $temporaryDirectory = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'saw-xlsx-' . bin2hex(random_bytes(8));
        if (!mkdir($temporaryDirectory, 0700)) {
            throw new RuntimeException('Direktori sementara ekspor tidak dapat dibuat.');
        }

        $zipPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'report.xlsx';
        $zip = new ZipArchive();
        $zipOpen = false;
        try {
            if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new RuntimeException('File ekspor Excel tidak dapat dibuat.');
            }
            $zipOpen = true;

            $titles = array_slice(array_keys($sheets), 0, 30);
            $zip->addFromString('[Content_Types].xml', self::contentTypes(count($titles)));
            $zip->addFromString('_rels/.rels', self::packageRelationships());
            $zip->addFromString('xl/workbook.xml', self::workbook($titles));
            $zip->addFromString('xl/_rels/workbook.xml.rels', self::workbookRelationships(count($titles)));

            foreach ($titles as $index => $title) {
                $worksheetPath = $temporaryDirectory . DIRECTORY_SEPARATOR . 'sheet-' . ($index + 1) . '.xml';
                self::writeWorksheet($worksheetPath, $sheets[$title]);
                $zip->addFile($worksheetPath, 'xl/worksheets/sheet' . ($index + 1) . '.xml');
            }

            $zip->close();
            $zipOpen = false;
            if (!copy($zipPath, $targetPath)) {
                throw new RuntimeException('File ekspor Excel tidak dapat disimpan.');
            }
        } finally {
            if ($zipOpen) {
                $zip->close();
            }
            foreach (glob($temporaryDirectory . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
                if (is_file($file)) {
                    unlink($file);
                }
            }
            rmdir($temporaryDirectory);
        }
    }

    private static function writeWorksheet(string $path, iterable $rows): void
    {
        $writer = new XMLWriter();
        if (!$writer->openUri($path)) {
            throw new RuntimeException('Worksheet ekspor tidak dapat ditulis.');
        }
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElement('worksheet');
        $writer->writeAttribute('xmlns', 'http://schemas.openxmlformats.org/spreadsheetml/2006/main');
        $writer->startElement('sheetData');

        $rowNumber = 0;
        foreach ($rows as $row) {
            $rowNumber++;
            if ($rowNumber > 1_048_576) {
                throw new RuntimeException('Jumlah baris ekspor melebihi batas Excel per lembar.');
            }

            $writer->startElement('row');
            $writer->writeAttribute('r', (string) $rowNumber);
            foreach (array_values($row) as $columnIndex => $value) {
                if ($value === null || $value === '') {
                    continue;
                }

                $cellReference = self::columnName($columnIndex + 1) . $rowNumber;
                $writer->startElement('c');
                $writer->writeAttribute('r', $cellReference);
                if (is_int($value) || (is_float($value) && is_finite($value))) {
                    $writer->writeAttribute('t', 'n');
                    $writer->writeElement('v', is_int($value) ? (string) $value : sprintf('%.15g', $value));
                } else {
                    $writer->writeAttribute('t', 'inlineStr');
                    $writer->startElement('is');
                    $writer->startElement('t');
                    $writer->writeAttribute('xml:space', 'preserve');
                    $writer->text((string) $value);
                    $writer->endElement();
                    $writer->endElement();
                }
                $writer->endElement();
            }
            $writer->endElement();
        }

        $writer->endElement();
        $writer->endElement();
        $writer->endDocument();
        $writer->flush();
    }

    private static function columnName(int $number): string
    {
        $name = '';
        while ($number > 0) {
            $number--;
            $name = chr(65 + ($number % 26)) . $name;
            $number = intdiv($number, 26);
        }

        return $name;
    }

    private static function contentTypes(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
            . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
            . '<Default Extension="xml" ContentType="application/xml"/>'
            . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $xml .= '<Override PartName="/xl/worksheets/sheet' . $index
                . '.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
        }

        return $xml . '</Types>';
    }

    private static function packageRelationships(): string
    {
        return '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
            . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
            . '</Relationships>';
    }

    private static function workbook(array $titles): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" '
            . 'xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        foreach ($titles as $index => $title) {
            $xml .= '<sheet name="' . htmlspecialchars($title, ENT_XML1 | ENT_QUOTES, 'UTF-8')
                . '" sheetId="' . ($index + 1) . '" r:id="rId' . ($index + 1) . '"/>';
        }

        return $xml . '</sheets></workbook>';
    }

    private static function workbookRelationships(int $sheetCount): string
    {
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
        for ($index = 1; $index <= $sheetCount; $index++) {
            $xml .= '<Relationship Id="rId' . $index
                . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" '
                . 'Target="worksheets/sheet' . $index . '.xml"/>';
        }

        return $xml . '</Relationships>';
    }
}
