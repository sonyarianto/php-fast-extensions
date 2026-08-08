<?php

declare(strict_types=1);

// Generates xlsx test fixtures using PHP's ZipArchive (no external libs).
// Fixture files are written to tests/data/.

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

function xmlesc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

function col_letter(int $n): string
{
    $s = '';
    while ($n > 0) {
        $n--;
        $s = chr(65 + ($n % 26)) . $s;
        $n = intdiv($n, 26);
    }
    return $s;
}

// Cell spec: ['t' => 'n'|'s'|'inline'|'b'|'blank', 'v' => value, 's' => style index]
function write_sheet_xml(array $rows, array $shared): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">';
    $xml .= '<sheetData>';
    foreach ($rows as $rn => $row) {
        $xml .= '<row r="' . ($rn + 1) . '">';
        foreach ($row as $cn => $cell) {
            $ref = col_letter($cn + 1) . ($rn + 1);
            $sattr = isset($cell['s']) ? ' s="' . $cell['s'] . '"' : '';
            switch ($cell['t'] ?? 'n') {
                case 's':
                    $xml .= '<c r="' . $ref . '" t="s"' . $sattr . '><v>' . $cell['v'] . '</v></c>';
                    break;
                case 'inline':
                    $xml .= '<c r="' . $ref . '" t="inlineStr"><is><t>' . xmlesc($cell['v']) . '</t></is></c>';
                    break;
                case 'b':
                    $xml .= '<c r="' . $ref . '" t="b"><v>' . ($cell['v'] ? '1' : '0') . '</v></c>';
                    break;
                case 'blank':
                    $xml .= '<c r="' . $ref . '"' . $sattr . '/>';
                    break;
                default:
                    $xml .= '<c r="' . $ref . '"' . $sattr . '><v>' . $cell['v'] . '</v></c>';
            }
        }
        $xml .= '</row>';
    }
    $xml .= '</sheetData></worksheet>';
    return $xml;
}

function write_shared_strings(array $strings): string
{
    $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>';
    $xml .= '<sst xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" uniqueCount="' . count($strings) . '">';
    foreach ($strings as $s) {
        $xml .= '<si><t>' . xmlesc($s) . '</t></si>';
    }
    $xml .= '</sst>';
    return $xml;
}

function build_workbook(string $path, array $sheetNames, array $sheetXmls, array $shared): void
{
    $zip = new ZipArchive();
    if ($zip->open($path, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        fwrite(STDERR, "cannot create $path\n");
        exit(1);
    }

    $zip->addFromString('[Content_Types].xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types">'
        . '<Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/>'
        . '<Default Extension="xml" ContentType="application/xml"/>'
        . '<Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/>'
        . '<Override PartName="/xl/sharedStrings.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sharedStrings+xml"/>'
        . '<Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>'
        . '</Types>');

    $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">'
        . '<Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/>'
        . '</Relationships>');

    $sheetXml = '';
    $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships">';
    foreach ($sheetNames as $i => $name) {
        $rid = 'rId' . ($i + 1);
        $sheetXml .= '<sheet name="' . xmlesc($name) . '" sheetId="' . ($i + 1) . '" r:id="' . $rid . '"/>';
        $rels .= '<Relationship Id="' . $rid . '" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet' . ($i + 1) . '.xml"/>';
    }
    $rels .= '<Relationship Id="rId999" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/sharedStrings" Target="sharedStrings.xml"/>';
    $rels .= '<Relationship Id="rId1000" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';
    $rels .= '</Relationships>';

    $zip->addFromString('xl/workbook.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships">'
        . '<sheets>' . $sheetXml . '</sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);

    $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
        . '<styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
        . '<numFmts count="3">'
        . '<numFmt numFmtId="164" formatCode="yyyy-mm-dd"/>'
        . '<numFmt numFmtId="165" formatCode="yyyy-mm-dd hh:mm:ss"/>'
        . '<numFmt numFmtId="166" formatCode="hh:mm:ss"/>'
        . '</numFmts>'
        . '<cellXfs count="4">'
        . '<xf numFmtId="0"/>'
        . '<xf numFmtId="164"/>'
        . '<xf numFmtId="165"/>'
        . '<xf numFmtId="166"/>'
        . '</cellXfs>'
        . '</styleSheet>');

    foreach ($sheetNames as $i => $name) {
        $zip->addFromString('xl/worksheets/sheet' . ($i + 1) . '.xml', $sheetXmls[$name]);
    }

    $zip->addFromString('xl/sharedStrings.xml', write_shared_strings($shared));

    $zip->close();
    echo "generated: $path\n";
}

// ---- small.xlsx: two sheets with all cell types ----

$shared = [];
$idx = function (string $s) use (&$shared): int {
    $i = array_search($s, $shared, true);
    if ($i === false) {
        $i = count($shared);
        $shared[] = $s;
    }
    return $i;
};

$h = fn (string $v): array => ['t' => 'inline', 'v' => $v];

$dataRows = [
    // id, name, active, joined, note, clock
    [
        ['v' => '1'],
        ['t' => 's', 'v' => $idx('Alice')],
        ['t' => 'b', 'v' => true],
        ['t' => 'n', 'v' => '45292.5', 's' => 2],
        ['t' => 'inline', 'v' => 'hello'],
        ['t' => 'n', 'v' => '0.5', 's' => 3],
    ],
    [
        ['v' => '2'],
        ['t' => 's', 'v' => $idx('Bob "The Builder"')],
        ['t' => 'b', 'v' => false],
        ['t' => 'n', 'v' => '45293.25', 's' => 2],
        ['t' => 'inline', 'v' => ''],
        ['t' => 'n', 'v' => '0.25', 's' => 3],
    ],
    [
        ['v' => '3'],
        ['t' => 's', 'v' => $idx('')],
        ['t' => 'b', 'v' => true],
        ['t' => 'n', 'v' => '45294', 's' => 1],
        ['t' => 'blank'],
        ['t' => 'n', 'v' => '0.75', 's' => 3],
    ],
    [
        ['v' => '4'],
        ['t' => 's', 'v' => $idx('日本語')],
        ['t' => 'b', 'v' => true],
        ['t' => 'n', 'v' => '45295.75', 's' => 2],
        ['t' => 'inline', 'v' => 'escaped <tag> & \'quo\''],
        ['t' => 'n', 'v' => '0.125', 's' => 3],
    ],
    [
        ['v' => '5'],
        ['t' => 's', 'v' => $idx('Eve')],
        ['t' => 'b', 'v' => false],
        ['t' => 'n', 'v' => '45296.125', 's' => 2],
        ['t' => 'blank'],
        ['t' => 'n', 'v' => '0.3333333333', 's' => 3],
    ],
];

$secondRows = [
    [$h('a'), $h('b')],
    [['v' => '10'], $h('alpha')],
    [['v' => '20'], $h('beta')],
    [['v' => '30'], $h('gamma')],
];

build_workbook(
    "$dataDir/small.xlsx",
    ['Data', 'Second'],
    [
        'Data' => write_sheet_xml([array_map($h, ['id', 'name', 'active', 'joined', 'note', 'clock']), ...$dataRows], $shared),
        'Second' => write_sheet_xml($secondRows, $shared),
    ],
    $shared
);

// ---- large.xlsx: 100k rows for benchmarks ----

$shared = [];
$idx = function (string $s) use (&$shared): int {
    $i = array_search($s, $shared, true);
    if ($i === false) {
        $i = count($shared);
        $shared[] = $s;
    }
    return $i;
};

$names = ['Alice', 'Bob', 'Carol', 'Dave', 'Eve', 'Frank', 'Grace', 'Heidi', 'Ivan', 'Judy'];
$cities = ['Paris', 'Berlin', 'Tokyo', 'Jakarta', 'Sydney', 'Lagos', 'Lima', 'Oslo', 'Seoul', 'Cairo'];
$statuses = ['active', 'inactive', 'pending', 'banned', 'verified'];

$rows = [array_map($h, ['Index', 'Name', 'Value', 'Joined', 'City', 'Country', 'Status', 'Email'])];
$n = 100000;
for ($i = 1; $i <= $n; $i++) {
    $email = $names[$i % 10] . ($i % 97) . '@example.com';
    $rows[] = [
        ['v' => (string) $i],
        ['t' => 's', 'v' => $idx($names[$i % 10])],
        ['t' => 'n', 'v' => number_format($i / 7, 5, '.', '')],
        ['t' => 'n', 'v' => number_format(45292 + ($i % 365) + ($i % 24) / 24, 6, '.', '') . '', 's' => 2],
        ['t' => 's', 'v' => $idx($cities[$i % 10])],
        ['t' => 's', 'v' => $idx($i % 5 === 0 ? 'Unknown' : 'Country' . ($i % 23))],
        ['t' => 's', 'v' => $idx($statuses[$i % 5])],
        ['t' => 'inline', 'v' => $email],
    ];
}

build_workbook(
    "$dataDir/large.xlsx",
    ['Data'],
    ['Data' => write_sheet_xml($rows, $shared)],
    $shared
);

// ---- edge.xlsx: boundary cases for malformed-input tests ----

$bigCell = str_repeat('x', 1024 * 1024);
$bigSheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetData>'
    . '<row r="1">'
    . '<c r="A1" t="inlineStr"><is><t>' . xmlesc($bigCell) . '</t></is></c>'
    . '<c r="B1"><v>1e300</v></c>'
    . '</row>'
    . '<row r="2">'
    . '<c r="A2" t="inlineStr"><is><t>plain</t></is></c>'
    . '</row>'
    . '</sheetData></worksheet>';

// The xlsx_batch_reader crate requires the r attribute on <row>; a missing
// one must fail loudly instead of producing garbage.
$noRefSheet = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
    . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
    . '<sheetData>'
    . '<row>'
    . '<c t="inlineStr"><is><t>noref</t></is></c>'
    . '</row>'
    . '</sheetData></worksheet>';

build_workbook(
    "$dataDir/edge.xlsx",
    ['Empty', 'HeadersOnly', 'Big', 'NoRef'],
    [
        'Empty' => '<?xml version="1.0" encoding="UTF-8" standalone="yes"?>'
            . '<worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main">'
            . '<sheetData></sheetData></worksheet>',
        'HeadersOnly' => write_sheet_xml([array_map($h, ['id', 'name'])], []),
        'Big' => $bigSheet,
        'NoRef' => $noRefSheet,
    ],
    []
);

echo "done.\n";
