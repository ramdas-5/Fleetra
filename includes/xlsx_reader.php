<?php
/**
 * Fleetra — Smart Transport Management System
 * ------------------------------------------------------------------
 * includes/xlsx_reader.php
 *
 * A tiny, dependency-free reader for Microsoft Excel .xlsx workbooks.
 *
 * The project deliberately ships with no Composer dependencies, so this
 * file reads the Office Open XML container itself. An .xlsx file is a ZIP
 * archive of XML parts; we open the archive with PharData (built into PHP
 * by default) and fall back to ZipArchive when the optional zip extension
 * is the only one available.
 *
 * Only what the importer needs is implemented: sheet names, the shared
 * string table and cell values (shared strings, inline strings, booleans
 * and numbers). Formulas are read from their cached result, exactly like
 * Excel/PhpSpreadsheet expose them.
 *
 * Loading order: config.php -> functions.php -> xlsx_reader.php
 */

declare(strict_types=1);

// Defence in depth: never serve this file as a page.
if (PHP_SAPI !== 'cli'
    && isset($_SERVER['SCRIPT_FILENAME'])
    && realpath((string) $_SERVER['SCRIPT_FILENAME']) === realpath(__FILE__)
) {
    http_response_code(404);
    exit;
}

/** True when any supported ZIP backend is available. */
function xlsx_supported(): bool
{
    return class_exists('PharData') || class_exists('ZipArchive');
}

/**
 * Open an .xlsx workbook. Returns a backend handle (PharData/ZipArchive).
 *
 * @throws RuntimeException when the file cannot be opened.
 */
function xlsx_open_archive(string $path): object
{
    if (!is_file($path)) {
        throw new RuntimeException('Workbook not found: ' . $path);
    }

    if (class_exists('PharData')) {
        try {
            return new PharData($path);
        } catch (Throwable $exception) {
            // Fall through to ZipArchive below.
        }
    }

    if (class_exists('ZipArchive')) {
        $zip = new ZipArchive();
        if ($zip->open($path) === true) {
            return $zip;
        }
    }

    throw new RuntimeException('The workbook could not be opened as a ZIP archive. Ensure the xlsx file is not corrupted.');
}

/**
 * Read one entry from an opened archive.
 *
 * @param object $archive PharData|ZipArchive
 */
function xlsx_entry_content(object $archive, string $entry): ?string
{
    $entry = ltrim(str_replace('\\', '/', $entry), '/');

    if ($archive instanceof ZipArchive) {
        $content = $archive->getFromName($entry);

        return $content === false ? null : $content;
    }

    // PharData implements ArrayAccess; isset() avoids a thrown exception.
    return isset($archive[$entry]) ? (string) $archive[$entry]->getContent() : null;
}

/** Normalise an OOXML part path relative to the workbook root. */
function xlsx_resolve_part(string $target, string $base = ''): string
{
    $target = str_replace('\\', '/', $target);

    if (str_starts_with($target, '/')) {
        return ltrim($target, '/');
    }

    $path = ($base !== '' ? rtrim($base, '/') . '/' : '') . $target;
    $parts = [];

    foreach (explode('/', $path) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }
        if ($segment === '..') {
            array_pop($parts);
            continue;
        }
        $parts[] = $segment;
    }

    return implode('/', $parts);
}

/** Load a DOM document from an XML string, throwing on failure. */
function xlsx_load_dom(string $xml, string $label): DOMDocument
{
    $previous = libxml_use_internal_errors(true);
    $dom      = new DOMDocument();
    $ok       = $dom->loadXML($xml, LIBXML_NONET | LIBXML_NOERROR | LIBXML_NOWARNING);
    libxml_use_internal_errors($previous);

    if (!$ok) {
        throw new RuntimeException('Could not parse the workbook part "' . $label . '".');
    }

    return $dom;
}

/**
 * Map every worksheet name to its XML part path inside the archive.
 *
 * @return array<string, string> sheet name => part path
 */
function xlsx_sheet_map(object $archive): array
{
    $workbookXml = xlsx_entry_content($archive, 'xl/workbook.xml');
    $relsXml     = xlsx_entry_content($archive, 'xl/_rels/workbook.xml.rels');

    if ($workbookXml === null || $relsXml === null) {
        throw new RuntimeException('This file does not look like an .xlsx workbook.');
    }

    $rels = [];
    foreach (xlsx_load_dom($relsXml, 'workbook.xml.rels')->getElementsByTagName('Relationship') as $rel) {
        $id     = $rel->getAttribute('Id');
        $target = $rel->getAttribute('Target');
        if ($id !== '' && $target !== '') {
            $rels[$id] = $target;
        }
    }

    $map = [];
    foreach (xlsx_load_dom($workbookXml, 'workbook.xml')->getElementsByTagName('sheet') as $sheet) {
        $name = $sheet->getAttribute('name');

        if ($name === '') {
            continue;
        }

        // The relationship id is stored under the officeDocument namespace.
        $relId = $sheet->getAttributeNS(
            'http://schemas.openxmlformats.org/officeDocument/2006/relationships',
            'id'
        );

        if ($relId === '') {
            $relId = $sheet->getAttribute('r:id');
        }

        if ($relId === '' || !isset($rels[$relId])) {
            continue;
        }

        $map[$name] = xlsx_resolve_part($rels[$relId], 'xl');
    }

    return $map;
}

/**
 * Read the shared string table into a plain list.
 *
 * @return array<int, string>
 */
function xlsx_shared_strings(object $archive): array
{
    $xml = xlsx_entry_content($archive, 'xl/sharedStrings.xml');

    if ($xml === null) {
        return [];
    }

    $strings = [];
    foreach (xlsx_load_dom($xml, 'sharedStrings.xml')->getElementsByTagName('si') as $item) {
        $text = '';
        foreach ($item->getElementsByTagName('t') as $node) {
            $text .= $node->textContent;
        }
        $strings[] = $text;
    }

    return $strings;
}

/** Convert a cell reference like "AB12" to a zero-based column index. */
function xlsx_column_index(string $reference): int
{
    if (!preg_match('/^([A-Za-z]+)/', $reference, $matches)) {
        return 0;
    }

    $letters = strtoupper($matches[1]);
    $index   = 0;
    $length  = strlen($letters);

    for ($i = 0; $i < $length; $i++) {
        $index = ($index * 26) + (ord($letters[$i]) - 64);
    }

    return $index - 1;
}

/**
 * Extract the raw value of a single cell node.
 *
 * @param DOMElement $cell
 * @param array<int, string> $sharedStrings
 */
function xlsx_cell_value(DOMElement $cell, array $sharedStrings): string
{
    $type = $cell->getAttribute('t');

    if ($type === 'inlineStr') {
        $text = '';
        foreach ($cell->getElementsByTagName('t') as $node) {
            $text .= $node->textContent;
        }

        return $text;
    }

    $valueNode = null;
    foreach ($cell->childNodes as $child) {
        if ($child instanceof DOMElement && $child->localName === 'v') {
            $valueNode = $child;
            break;
        }
    }

    if ($valueNode === null) {
        return '';
    }

    $value = $valueNode->textContent;

    if ($type === 's') {
        return $sharedStrings[(int) $value] ?? '';
    }

    if ($type === 'b') {
        return $value === '1' ? '1' : '0';
    }

    return $value;
}

/**
 * Read one worksheet into an ordered list of associative rows.
 *
 * The first non-empty row is treated as the header row; each subsequent
 * row becomes an associative array keyed by those headers. Values are
 * always returned as trimmed strings — the importer decides how to type
 * them — and short rows are padded with empty strings.
 *
 * @return array<int, array<string, string>>
 *
 * @throws RuntimeException when the sheet does not exist.
 */
function xlsx_read_sheet(string $path, string $sheetName, ?object $archive = null): array
{
    $archive ??= xlsx_open_archive($path);
    $sheets    = xlsx_sheet_map($archive);

    if (!isset($sheets[$sheetName])) {
        throw new RuntimeException('Worksheet "' . $sheetName . '" was not found in ' . basename($path) . '.');
    }

    $xml = xlsx_entry_content($archive, $sheets[$sheetName]);

    if ($xml === null) {
        throw new RuntimeException('Worksheet part for "' . $sheetName . '" is missing.');
    }

    $sharedStrings = xlsx_shared_strings($archive);
    $dom           = xlsx_load_dom($xml, $sheetName);

    // Collect a sparse 2D grid first, then trim to the header width.
    $grid = [];
    foreach ($dom->getElementsByTagName('row') as $row) {
        $values = [];

        foreach ($row->getElementsByTagName('c') as $cell) {
            $reference = $cell->getAttribute('r');
            $column    = $reference !== '' ? xlsx_column_index($reference) : count($values);
            $values[$column] = xlsx_cell_value($cell, $sharedStrings);
        }

        if ($values === []) {
            $grid[] = [];
            continue;
        }

        // Fill any gap left by skipped (empty) cells.
        $width = max(array_keys($values)) + 1;
        $line  = array_fill(0, $width, '');
        foreach ($values as $column => $value) {
            $line[$column] = $value;
        }

        $grid[] = $line;
    }

    // Locate the header row: the first row with at least one non-empty cell.
    $headerIndex = null;
    foreach ($grid as $index => $line) {
        foreach ($line as $value) {
            if (trim((string) $value) !== '') {
                $headerIndex = $index;
                break 2;
            }
        }
    }

    if ($headerIndex === null) {
        return [];
    }

    $headers = array_map(
        static fn ($value): string => trim((string) $value),
        $grid[$headerIndex]
    );
    $width   = count($headers);

    $rows = [];
    for ($i = $headerIndex + 1, $count = count($grid); $i < $count; $i++) {
        $line   = $grid[$i];
        $record = [];
        $empty  = true;

        for ($column = 0; $column < $width; $column++) {
            if ($headers[$column] === '') {
                continue;
            }

            $value = trim((string) ($line[$column] ?? ''));
            $record[$headers[$column]] = $value;

            if ($value !== '') {
                $empty = false;
            }
        }

        if (!$empty) {
            $rows[] = $record;
        }
    }

    return $rows;
}

/** List the worksheet names in a workbook, in tab order. */
function xlsx_sheet_names(string $path): array
{
    return array_keys(xlsx_sheet_map(xlsx_open_archive($path)));
}
