<?php

declare(strict_types=1);

// Test suite for XmlStreamer. Run with the extension loaded:
//   php -d extension=target/release/libxml_streamer.so tests/test.php

$dataDir = __DIR__ . '/data';
if (!is_file("$dataDir/small.xml")) {
    fwrite(STDERR, "fixtures missing — run tests/generate_xml.php first\n");
    exit(2);
}

$pass = 0;
$fail = 0;

function check(string $label, bool $ok): void
{
    global $pass, $fail;
    if ($ok) {
        $pass++;
        echo "  PASS  $label\n";
    } else {
        $fail++;
        echo "  FAIL  $label\n";
    }
}

function expectException(string $label, callable $fn): void
{
    try {
        $fn();
        check($label, false);
    } catch (\Exception $e) {
        check($label, true);
    }
}

echo "== construction ==" . "\n";
expectException('missing file throws', fn() => new XmlStreamer("$dataDir/nope.xml"));
expectException('malformed element throws', function () use ($dataDir) {
    $p = "$dataDir/broken.xml";
    file_put_contents($p, "<rows><row><id>1</id></row><row><id>2</row></rows>");
    try {
        $s = new XmlStreamer($p);
        foreach ($s as $row) {
        }
        throw new \Exception('should have thrown');
    } finally {
        unlink($p);
    }
});
$s = new XmlStreamer("$dataDir/small.xml");
check('implements Iterator', (new ReflectionClass('XmlStreamer'))->implementsInterface(\Iterator::class));

echo "== iteration ==" . "\n";
$s->rewind();
$rows = [];
foreach ($s as $k => $row) {
    check("foreach key sequential ($k)", $k === count($rows));
    $rows[] = $row;
}
check('3 rows read', count($rows) === 3);

$row = $rows[0];
check('string id', $row['id'] === '1');
check('string name', $row['name'] === 'Alice');
check('int score with decimals kept as string', $row['score'] === '9.99');
check('empty note', $row['note'] === '');
check('repeated tags become list', $row['tags'] === ['a', 'b', 'c']);
check('nested structure', $row['x'] === ['v' => '1'] && $row['y'] === ['v' => ['2', '3']]);
check('unicode string', $row['unicode'] === '日本語のテキスト');
check('entities decoded', $row['quotes'] === 'she said "hello" & <escaped>');

$row = $rows[1];
check('apostrophe in string', $row['name'] === "Bob O'Brien");
check('false stays string', $row['active'] === 'false');
check('absent child key', !array_key_exists('nested', $row));

$row = $rows[2];
check('third row id', $row['id'] === '3');
check('empty list keys omitted', !array_key_exists('tags', $row) && !array_key_exists('nested', $row));

echo "== typed mode ==" . "\n";
$t = new XmlStreamer("$dataDir/small.xml", null, true);
$t->rewind();
$trows = iterator_to_array($t);
check('typed int', $trows[0]['id'] === 1);
check('typed float', $trows[0]['score'] === 9.99);
check('typed bool true', $trows[0]['active'] === true);
check('typed bool false', $trows[1]['active'] === false);
check('typed int with 0', $trows[2]['score'] === 0);
check('typed zero-length text stays string', $trows[0]['note'] === '');
check('typed string stays string', $trows[0]['name'] === 'Alice');

echo "== attributes ==" . "\n";
$s = new XmlStreamer("$dataDir/attrs.xml");
$s->rewind();
$attrs = iterator_to_array($s);
check('attribute row count', count($attrs) === 3);
check('attributes in @attributes', $attrs[0]['@attributes'] === ['id' => '10', 'country' => 'FR', 'lang' => 'fr']);
check('children alongside attributes', $attrs[0]['price'] === '12.5');
check('unicode attribute value', $attrs[2]['@attributes']['lang'] === 'ja');

echo "== namespaces ==" . "\n";
$s = new XmlStreamer("$dataDir/namespaces.xml");
$s->rewind();
$ns = iterator_to_array($s);
check('prefixed row matched by local name', count($ns) === 1);
check('prefixed children stripped', $ns[0]['name'] === 'Prefixed');
check('prefixed attribute key stripped', $ns[0]['@attributes'] === ['id' => '7']);
check('foreign-namespace child kept with local name', $ns[0]['unrelated'] === 'skipped namespace child');

echo "== nested & self-closing ==" . "\n";
$s = new XmlStreamer("$dataDir/nested.xml");
$s->rewind();
$nested = iterator_to_array($s);
$n = $nested[0];
check('deep nesting', $n['user']['profile']['settings']['theme'] === 'dark');
check('self-closing with attr', $n['user']['profile']['settings']['notifications'] === ['@attributes' => ['enabled' => 'false']]);
check('self-closing empty is empty string', $n['empty'] === '');
check('mixed content text', $n['mixed']['b'] === 'bold');
check('mixed content @value normalized', $n['mixed']['@value'] === 'text before text after');

echo "== rows without root wrapper ==" . "\n";
$s = new XmlStreamer("$dataDir/rows.xml");
$s->rewind();
$r = iterator_to_array($s);
check('two rows', count($r) === 2 && $r[0]['id'] === '1' && $r[1]['id'] === '2');
check('second row value', $r[1]['v'] === 'second');

echo "== protocol ==" . "\n";
$s = new XmlStreamer("$dataDir/small.xml");
$s->rewind();
check('key starts at 0', $s->key() === 0);
check('valid before iteration', $s->valid() === true);
check('current is first row after valid', $s->current()['id'] === '1');
$s->rewind();
check('nextRow returns first row', $s->nextRow()['id'] === '1');
check('nextRow returns second row', $s->nextRow()['id'] === '2');
check('nextRow returns third row', $s->nextRow()['id'] === '3');
check('nextRow returns null at EOF', $s->nextRow() === null);
check('current null after EOF', $s->current() === null);
check('valid false after EOF', $s->valid() === false);

echo "== batch reads ==" . "\n";
$s = new XmlStreamer("$dataDir/small.xml");
$s->rewind();
$batch = $s->nextRows(2);
check('nextRows(2) returns 2', is_array($batch) && count($batch) === 2);
check('batch order', $batch[0]['id'] === '1' && $batch[1]['id'] === '2');
$batch = $s->nextRows(2);
check('nextRows(2) returns 1 at tail', is_array($batch) && count($batch) === 1 && $batch[0]['id'] === '3');
check('nextRows returns null at EOF', $s->nextRows(2) === null);

echo "== rewind ==" . "\n";
$s = new XmlStreamer("$dataDir/small.xml");
$s->rewind();
$first = [];
foreach ($s as $row) {
    $first[] = $row['id'];
}
check('second pass identical', $first === ['1', '2', '3']);

echo "== malformed & boundary ==" . "\n";
$tmp = "$dataDir/tmp_malformed.xml";
$expectThrow = function (string $label, string $content, bool $duringIteration = false) use ($tmp) {
    file_put_contents($tmp, $content);
    try {
        $s = new XmlStreamer($tmp);
        if ($duringIteration) {
            foreach ($s as $row) {
            }
        }
        check($label, false);
    } catch (\Exception $e) {
        check($label, true);
    } finally {
        unlink($tmp);
    }
};

$expectThrow('unterminated element throws', '<rows><row><id>1</id></rows>', true);
$expectThrow('mismatched end tag throws', '<rows><row><id>1</x></row></rows>', true);
$expectThrow('unterminated tag throws', '<rows><row><id>1</row></rows>', true);
$expectThrow('bad entity throws', '<rows><row><v>&bogus;</v></row></rows>', true);
$expectThrow('unclosed quote throws', '<rows><row a="1><v>x</v></row></rows>', true);

file_put_contents($tmp, '<rows><row><v>garbage text without tags</v></row></rows>');
check('text before first row ignored', count(iterator_to_array(new XmlStreamer($tmp))) === 1);
unlink($tmp);

file_put_contents($tmp, "<rows>\n</rows>");
$s = new XmlStreamer($tmp);
check('empty file yields nothing', iterator_count($s) === 0);
unlink($tmp);

file_put_contents($tmp, "<rows><row/></rows>");
$s = new XmlStreamer($tmp);
$rows = iterator_to_array($s);
check('self-closing row yields empty @value', count($rows) === 1 && $rows[0] === ['@value' => '']);
unlink($tmp);

file_put_contents($tmp, "<rows><v>x</v></rows>");
check('no rows tag match yields nothing', iterator_count(new XmlStreamer($tmp)) === 0);
unlink($tmp);

file_put_contents($tmp, "<rows><row><v>x</v></row><row><v>y</v></row></rows>");
check('row tag renamed via constructor', count(iterator_to_array(new XmlStreamer($tmp, 'row'))) === 2);
check('custom row tag via constructor', count(iterator_to_array(new XmlStreamer($tmp, 'rows'))) === 1);
unlink($tmp);

file_put_contents($tmp, '<rows><row><v>1 &lt; 2 &amp;&amp; 3 &gt; 2</v></row></rows>');
$rows = iterator_to_array(new XmlStreamer($tmp));
check('entities in text decoded', $rows[0]['v'] === '1 < 2 && 3 > 2');
unlink($tmp);

file_put_contents($tmp, '<rows><row><v>a<![CDATA[ b < c & d ]]>e</v></row></rows>');
$rows = iterator_to_array(new XmlStreamer($tmp));
check('CDATA appended to text', $rows[0]['v'] === 'a b < c & d e');
unlink($tmp);

file_put_contents($tmp, "<rows><row id=\"1\">5</row></rows>");
$rows = iterator_to_array(new XmlStreamer($tmp, null, true));
check('typed @value with attributes', $rows[0] === ['@attributes' => ['id' => '1'], '@value' => 5]);
unlink($tmp);

file_put_contents($tmp, "<rows><row>5</row></rows>");
$rows = iterator_to_array(new XmlStreamer($tmp, null, true));
check('typed leaf row wrapped in @value', $rows[0] === ['@value' => 5]);
unlink($tmp);

file_put_contents($tmp, "<rows><row>5</row></rows>");
$rows = iterator_to_array(new XmlStreamer($tmp));
check('untyped leaf row wrapped in @value', $rows[0] === ['@value' => '5']);
unlink($tmp);

// Large single text node (1 MB) round-trips
$big = str_repeat('y', 1024 * 1024);
file_put_contents($tmp, "<rows><row><v>$big</v></row></rows>");
$rows = iterator_to_array(new XmlStreamer($tmp));
check('1 MB text node round-trips', $rows[0]['v'] === $big);
unlink($tmp);

echo "== large file (1M rows, ~240 MB) ==" . "\n";
$t0 = hrtime(true);
$n = 0;
$sum = 0;
foreach (new XmlStreamer("$dataDir/large.xml") as $row) {
    $sum += (int) $row['id'];
    $n++;
}
$ms = (hrtime(true) - $t0) / 1e6;
$peak = memory_get_peak_usage(true) / 1024 / 1024;
check('1,000,000 rows', $n === 1_000_000);
check('checksum correct', $sum === 499999500000);
check('peak memory under 32 MB', $peak < 32.0);
printf("  INFO  large.xml: %.0f ms (%.0f rows/sec), peak %.1f MB\n", $ms, $n / ($ms / 1000), $peak);

echo "\n" . ($fail === 0 ? "ALL TESTS PASSED ($pass passed)" : "FAILURES: $fail (pass: $pass)") . "\n";
exit($fail === 0 ? 0 : 1);
