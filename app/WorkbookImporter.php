<?php
declare(strict_types=1);

final class WorkbookImporter
{
    public const CRITERIA = ['ACH', 'AKC', 'PBR', 'PGP', 'ITB'];

    private const COLUMN_NAMES = [
        'ACH' => 'ach',
        'AKC' => 'akc',
        'PBR' => 'pbr',
        'PGP' => 'pgp',
        'ITB' => 'itb',
    ];

    public function __construct(private readonly Database $database)
    {
    }

    public function import(string $path, string $originalName): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new RuntimeException('File dataset tidak ditemukan atau tidak dapat dibaca.');
        }

        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File bukan workbook XLSX yang dapat dibuka.');
        }

        try {
            [$sheetPath, $sheetName] = $this->firstSheet($zip);
            $sharedStrings = $this->sharedStrings($zip);
            $reader = new XMLReader();
            $zipPath = str_replace('\\', '/', realpath($path) ?: $path);

            if (!$reader->open('zip://' . $zipPath . '#' . $sheetPath, null, LIBXML_NONET | LIBXML_COMPACT)) {
                throw new RuntimeException('Worksheet XLSX tidak dapat dibaca.');
            }

            $pdo = $this->database->pdo();
            $rowsImported = 0;
            $headers = null;
            $columnMap = [];
            $insert = $pdo->prepare(
                'INSERT INTO teachers(id, ach, akc, pbr, pgp, itb, missing_mask)
                 VALUES(:id, :ach, :akc, :pbr, :pgp, :itb, :missing_mask)'
            );

            $pdo->beginTransaction();

            try {
                $pdo->exec('DELETE FROM ranking_values');
                $pdo->exec('DELETE FROM ranking_scenarios');
                $pdo->exec('DELETE FROM ranking_runs');
                $pdo->exec('DELETE FROM comparisons');
                $pdo->exec('DELETE FROM teachers');

                while ($reader->read()) {
                    if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'row') {
                        continue;
                    }

                    $row = $this->readRow($reader, $sharedStrings);

                    if ($headers === null) {
                        $headers = $row;
                        $columnMap = $this->validateHeaders($headers);
                        continue;
                    }

                    if ($row === []) {
                        continue;
                    }

                    $id = trim((string) ($row[$columnMap['kd_guru_an']] ?? ''));
                    if ($id === '') {
                        throw new RuntimeException('Baris ' . ($rowsImported + 2) . ': kd_guru_an kosong.');
                    }

                    $values = [];
                    $missingMask = 0;

                    foreach (self::CRITERIA as $index => $criterion) {
                        $columnIndex = $columnMap[$criterion];
                        $rawValue = trim((string) ($row[$columnIndex] ?? ''));
                        $parameter = ':' . self::COLUMN_NAMES[$criterion];

                        if ($rawValue === '') {
                            $values[$parameter] = null;
                            $missingMask |= 1 << $index;
                            continue;
                        }

                        if (!is_numeric($rawValue)) {
                            throw new RuntimeException(
                                'Nilai ' . $criterion . ' pada baris ' . ($rowsImported + 2) . ' bukan numerik.'
                            );
                        }

                        $value = (float) $rawValue;
                        if (!is_finite($value) || $value < 0 || $value > 100) {
                            throw new RuntimeException(
                                'Nilai ' . $criterion . ' pada baris ' . ($rowsImported + 2)
                                . ' berada di luar rentang 0–100.'
                            );
                        }
                        $values[$parameter] = $value;
                    }

                    try {
                        $insert->execute([
                            ':id' => $id,
                            ...$values,
                            ':missing_mask' => $missingMask,
                        ]);
                    } catch (PDOException $exception) {
                        if ($exception->getCode() === '23000') {
                            throw new RuntimeException('ID kd_guru_an duplikat pada baris ' . ($rowsImported + 2) . ': ' . $id . '.');
                        }
                        throw $exception;
                    }
                    $rowsImported++;
                }

                $reader->close();
                if ($headers === null) {
                    throw new RuntimeException('Worksheet tidak berisi baris header.');
                }
                if ($rowsImported === 0) {
                    throw new RuntimeException('Dataset tidak berisi alternatif guru.');
                }

                $hash = hash_file('sha256', $path);
                $processedAt = gmdate('c');
                $this->database->saveMetadata('dataset_hash', $hash);
                $this->database->saveMetadata('dataset_name', $originalName);
                $this->database->saveMetadata('sheet_name', $sheetName);
                $this->database->saveMetadata('processed_at', $processedAt);
                $this->database->saveMetadata('row_count', (string) $rowsImported);

                $pdo->commit();
            } catch (Throwable $exception) {
                $reader->close();
                if ($pdo->inTransaction()) {
                    $pdo->rollBack();
                }
                throw $exception;
            }
        } finally {
            $zip->close();
        }

        return [
            'rows' => $rowsImported,
            'sheet' => $sheetName,
            'sha256' => $hash,
            'processedAt' => $processedAt,
        ];
    }

    private function firstSheet(ZipArchive $zip): array
    {
        $workbook = $zip->getFromName('xl/workbook.xml');
        $relationships = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($workbook === false || $relationships === false) {
            throw new RuntimeException('File tidak memiliki struktur workbook XLSX yang valid.');
        }

        $workbookXml = simplexml_load_string($workbook, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
        $relationshipsXml = simplexml_load_string(
            $relationships,
            SimpleXMLElement::class,
            LIBXML_NONET | LIBXML_COMPACT
        );
        if ($workbookXml === false || $relationshipsXml === false) {
            throw new RuntimeException('Metadata workbook XLSX tidak dapat dibaca.');
        }

        $relationshipMap = [];
        foreach ($relationshipsXml->Relationship as $relationship) {
            $attributes = $relationship->attributes();
            $relationshipMap[(string) $attributes['Id']] = (string) $attributes['Target'];
        }

        $namespaces = $workbookXml->getNamespaces(true);
        $relationshipNamespace = $namespaces['r'] ?? null;
        $sheets = $workbookXml->children($namespaces[''] ?? '');

        foreach ($sheets->sheets->sheet as $sheet) {
            $sheetAttributes = $sheet->attributes();
            $attributes = $sheet->attributes($relationshipNamespace);
            $target = $relationshipMap[(string) $attributes['id']] ?? null;
            if ($target === null || str_contains($target, '..')) {
                continue;
            }

            $sheetPath = ltrim($target, '/');
            if (!str_starts_with($sheetPath, 'xl/')) {
                $sheetPath = 'xl/' . $sheetPath;
            }

            if ($zip->locateName($sheetPath) !== false) {
                return [$sheetPath, (string) $sheetAttributes['name']];
            }
        }

        throw new RuntimeException('Workbook tidak memiliki worksheet yang dapat dibaca.');
    }

    private function sharedStrings(ZipArchive $zip): array
    {
        $xml = $zip->getFromName('xl/sharedStrings.xml');
        if ($xml === false) {
            return [];
        }

        $strings = [];
        $reader = new XMLReader();
        if (!$reader->XML($xml, null, LIBXML_NONET | LIBXML_COMPACT)) {
            throw new RuntimeException('Daftar shared strings XLSX tidak dapat dibaca.');
        }

        while ($reader->read()) {
            if ($reader->nodeType !== XMLReader::ELEMENT || $reader->localName !== 'si') {
                continue;
            }
            $item = $reader->readOuterXml();
            $stringXml = simplexml_load_string($item, SimpleXMLElement::class, LIBXML_NONET | LIBXML_COMPACT);
            $value = '';
            if ($stringXml !== false) {
                $namespaces = $stringXml->getNamespaces(true);
                $root = $stringXml->children($namespaces[''] ?? '');
                foreach ($root->t as $text) {
                    $value .= (string) $text;
                }
                foreach ($root->r as $run) {
                    $value .= (string) $run->t;
                }
            }
            $strings[] = $value;
        }
        $reader->close();

        return $strings;
    }

    private function readRow(XMLReader $reader, array $sharedStrings): array
    {
        $rowDepth = $reader->depth;
        $cells = [];

        while ($reader->read()) {
            if ($reader->nodeType === XMLReader::END_ELEMENT
                && $reader->localName === 'row'
                && $reader->depth === $rowDepth) {
                break;
            }

            if ($reader->nodeType !== XMLReader::ELEMENT
                || $reader->localName !== 'c'
                || $reader->depth !== $rowDepth + 1) {
                continue;
            }

            $reference = (string) $reader->getAttribute('r');
            $type = (string) $reader->getAttribute('t');
            $column = preg_replace('/\d+/', '', $reference);
            $columnIndex = $this->columnIndex((string) $column);
            $cellDepth = $reader->depth;
            $value = '';

            while ($reader->read()) {
                if ($reader->nodeType === XMLReader::END_ELEMENT
                    && $reader->localName === 'c'
                    && $reader->depth === $cellDepth) {
                    break;
                }

                if ($reader->nodeType === XMLReader::ELEMENT && $reader->localName === 'v') {
                    $value = $reader->readString();
                } elseif ($type === 'inlineStr'
                    && $reader->nodeType === XMLReader::ELEMENT
                    && $reader->localName === 't') {
                    $value .= $reader->readString();
                }
            }

            if ($type === 's' && $value !== '') {
                $value = $sharedStrings[(int) $value] ?? '';
            }
            $cells[$columnIndex] = $value;
        }

        if ($cells === []) {
            return [];
        }

        ksort($cells);
        $lastColumn = max(array_keys($cells));

        return array_replace(array_fill(0, $lastColumn + 1, ''), $cells);
    }

    private function validateHeaders(array $headers): array
    {
        $normalized = [];
        foreach ($headers as $index => $header) {
            $name = trim((string) $header);
            if ($name !== '') {
                if (isset($normalized[$name])) {
                    throw new RuntimeException('Nama kolom duplikat pada header: ' . $name . '.');
                }
                $normalized[$name] = $index;
            }
        }

        $required = ['kd_guru_an', ...self::CRITERIA];
        $missing = array_values(array_diff($required, array_keys($normalized)));
        if ($missing !== []) {
            throw new RuntimeException('Kolom wajib tidak ditemukan: ' . implode(', ', $missing) . '.');
        }

        return $normalized;
    }

    private function columnIndex(string $letters): int
    {
        $index = 0;
        foreach (str_split(strtoupper($letters)) as $letter) {
            $index = $index * 26 + ord($letter) - ord('A') + 1;
        }

        return $index - 1;
    }
}
