<?php

declare(strict_types=1);

// Generates test fixtures for the xml_streamer extension:
//   tests/data/small.xml  -- hand-crafted edge cases
//   tests/data/large.xml  -- 1M rows, ~250 MB, enough to make
//                            DOMDocument/simplexml hit memory limits (not committed)

$dataDir = __DIR__ . '/data';
if (!is_dir($dataDir)) {
    mkdir($dataDir, 0777, true);
}

// --- small.xml ------------------------------------------------------------
$rows = [
    [
        'id' => 1,
        'name' => 'Alice',
        'active' => true,
        'score' => 9.99,
        'note' => '',
        'tags' => ['a', 'b', 'c'],
        'nested' => ['x' => 1, 'y' => [2, 3]],
        'unicode' => '日本語のテキスト',
        'quotes' => 'she said "hello" & <escaped>',
    ],
    [
        'id' => 2,
        'name' => 'Bob O\'Brien',
        'active' => false,
        'score' => 1000,
        'note' => 'big number',
    ],
    [
        'id' => 3,
        'name' => 'Carol',
        'active' => true,
        'score' => 0,
        'note' => 'zero',
        'tags' => [],
        'nested' => [],
    ],
];

$attrRows = [
    ['id' => 10, 'country' => 'FR', 'lang' => 'fr'],
    ['id' => 11, 'country' => 'DE', 'lang' => 'de'],
    ['id' => 12, 'country' => 'JP', 'lang' => 'ja'],
];

function esc(string $s): string
{
    return htmlspecialchars($s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
}

$xml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$xml .= "<catalog>\n";
$xml .= "  <!-- a comment before the first row -->\n";
foreach ($rows as $r) {
    $xml .= "  <row>\n";
    $xml .= "    <id>{$r['id']}</id>\n";
    $xml .= "    <name>" . esc($r['name']) . "</name>\n";
    $xml .= "    <active>" . ($r['active'] ? 'true' : 'false') . "</active>\n";
    $xml .= "    <score>{$r['score']}</score>\n";
    $xml .= "    <note>" . esc($r['note']) . "</note>\n";
    if (isset($r['tags'])) {
        foreach ($r['tags'] as $t) {
            $xml .= "    <tags>" . esc($t) . "</tags>\n";
        }
    }
    if (isset($r['nested'])) {
        foreach ($r['nested'] as $k => $v) {
            $xml .= "    <" . esc((string) $k) . ">\n";
            if (is_array($v)) {
                foreach ($v as $vv) {
                    $xml .= "      <v>" . esc((string) $vv) . "</v>\n";
                }
            } else {
                $xml .= "      <v>" . esc((string) $v) . "</v>\n";
            }
            $xml .= "    </" . esc((string) $k) . ">\n";
        }
    }
    $xml .= "    <unicode>" . esc($r['unicode'] ?? '') . "</unicode>\n";
    $xml .= "    <quotes>" . esc($r['quotes'] ?? '') . "</quotes>\n";
    $xml .= "  </row>\n";
}
$xml .= "</catalog>\n";

file_put_contents("$dataDir/small.xml", $xml);

// --- attrs.xml: attribute-heavy rows ---------------------------------------
$attrXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<items>\n";
foreach ($attrRows as $r) {
    $attrXml .= "  <row id=\"{$r['id']}\" country=\"{$r['country']}\" lang=\"{$r['lang']}\">\n";
    $attrXml .= "    <price>12.5</price>\n";
    $attrXml .= "  </row>\n";
}
$attrXml .= "</items>\n";
file_put_contents("$dataDir/attrs.xml", $attrXml);

// --- namespaces.xml: prefixed rows and elements ---------------------------
$nsXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<r:root xmlns:r=\"urn:data\" xmlns:o=\"urn:other\">\n";
$nsXml .= "  <r:row r:id=\"7\">\n";
$nsXml .= "    <r:name>Prefixed</r:name>\n";
$nsXml .= "    <o:unrelated>skipped namespace child</o:unrelated>\n";
$nsXml .= "  </r:row>\n";
$nsXml .= "</r:root>\n";
file_put_contents("$dataDir/namespaces.xml", $nsXml);

// --- nested.xml: deeply nested children and self-closing tags -------------
$nestedXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<data>\n";
$nestedXml .= "  <row>\n";
$nestedXml .= "    <user>\n";
$nestedXml .= "      <profile>\n";
$nestedXml .= "        <settings>\n";
$nestedXml .= "          <theme>dark</theme>\n";
$nestedXml .= "          <notifications enabled=\"false\"/>\n";
$nestedXml .= "        </settings>\n";
$nestedXml .= "      </profile>\n";
$nestedXml .= "      <name>Deeply Nested</name>\n";
$nestedXml .= "    </user>\n";
$nestedXml .= "    <empty/>\n";
$nestedXml .= "    <mixed>text before <b>bold</b> text after</mixed>\n";
$nestedXml .= "  </row>\n";
$nestedXml .= "</data>\n";
file_put_contents("$dataDir/nested.xml", $nestedXml);

// --- rows.xml: no root wrapper, trailing comments --------------------------
$rowsXml = "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n";
$rowsXml .= "<row><id>1</id><v>first</v></row>\n";
$rowsXml .= "<!-- between rows -->\n";
$rowsXml .= "<row><id>2</id><v>second</v></row>\n";
file_put_contents("$dataDir/rows.xml", $rowsXml);

// --- large.xml -------------------------------------------------------------
$rows = 1_000_000;
$fh = fopen("$dataDir/large.xml", 'w');
fwrite($fh, "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<rows>\n");
for ($i = 0; $i < $rows; $i++) {
    $name = "Customer Number $i";
    $email = "user$i@example.com";
    $active = $i % 2 === 0 ? 'true' : 'false';
    $score = round(($i % 1000) / 7.0, 2);
    $balance = $i * 13;
    $note = $i % 100 === 0 ? '' : "note $i";
    $tags = $i % 3 === 0 ? "<tags>vip</tags>\n      <tags>repeat</tags>" : "<tags>regular</tags>";
    $joined = sprintf('%04d-%02d-%02d', 2020 + $i % 6, 1 + $i % 12, 1 + $i % 28);
    fwrite($fh, "  <row>\n");
    fwrite($fh, "    <id>$i</id>\n");
    fwrite($fh, "    <name>$name</name>\n");
    fwrite($fh, "    <email>$email</email>\n");
    fwrite($fh, "    <active>$active</active>\n");
    fwrite($fh, "    <score>$score</score>\n");
    fwrite($fh, "    <balance>$balance</balance>\n");
    fwrite($fh, "    <note>$note</note>\n");
    fwrite($fh, "    <joined>$joined</joined>\n");
    fwrite($fh, "    $tags\n");
    fwrite($fh, "  </row>\n");
}
fwrite($fh, "</rows>\n");
fclose($fh);

printf("small.xml: %s (%.1f KB)\n", filesize("$dataDir/small.xml"), filesize("$dataDir/small.xml") / 1024);
printf("attrs.xml: %s (%.1f KB)\n", filesize("$dataDir/attrs.xml"), filesize("$dataDir/attrs.xml") / 1024);
printf("namespaces.xml: %s (%.1f KB)\n", filesize("$dataDir/namespaces.xml"), filesize("$dataDir/namespaces.xml") / 1024);
printf("nested.xml: %s (%.1f KB)\n", filesize("$dataDir/nested.xml"), filesize("$dataDir/nested.xml") / 1024);
printf("rows.xml: %s (%.1f KB)\n", filesize("$dataDir/rows.xml"), filesize("$dataDir/rows.xml") / 1024);
printf("large.xml: %s (%.1f MB)\n", filesize("$dataDir/large.xml"), filesize("$dataDir/large.xml") / 1024 / 1024);
