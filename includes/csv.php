<?php
/**
 * CSV import/export helpers (1.5.10). Pure functions, no WordPress calls, so tests/run-tests.php covers them.
 *
 * Import file: one rule per row, columns source,destination,method,name (a header row with those names
 * is optional; without one the columns are read in that order). method defaults to 301; a 410 row needs
 * no destination. Rows whose source already exists on the site, or earlier in the file, are skipped.
 */

if (!defined('ABSPATH')) {
    exit;
}

/** Redirect methods a CSV row may name. */
function devdredi_csv_methods()
{
    return array('301', '302', '307', '308', '410', 'js', 'meta');
}

/**
 * Parse CSV text into rows of trimmed cells. Blank lines are dropped. A UTF-8 byte order mark is removed.
 *
 * @return array<int, array<int, string>> rows, each a list of cells.
 */
function devdredi_csv_rows($text)
{
    $text = (string) $text;
    if (substr($text, 0, 3) === "\xEF\xBB\xBF") {
        $text = substr($text, 3);
    }
    $rows = array();
    foreach (preg_split('/\r\n|\r|\n/', $text) as $line) {
        if (trim($line) === '') {
            continue;
        }
        $cells = str_getcsv($line, ',', '"', '');
        $rows[] = array_map(function ($c) { return trim((string) $c); }, (array) $cells);
    }
    return $rows;
}

/**
 * Lower-cased source without a trailing slash, for duplicate detection only (the rule keeps the text as typed).
 */
function devdredi_csv_source_key($source)
{
    $k = strtolower(trim((string) $source));
    return (strlen($k) > 1) ? rtrim($k, '/') : $k;
}

/**
 * Decide what each row becomes: add (a new rule), skip (duplicate source) or invalid (with the reason).
 *
 * @param array $rows             From devdredi_csv_rows().
 * @param array $existing_sources Sources already on the site (one per entry).
 * @return array{add: array<int, array{line:int, source:string, destination:string, method:string, name:string}>, skipped: int[], invalid: array<int, array{line:int, why:string}>}
 */
function devdredi_csv_import_plan($rows, $existing_sources)
{
    $seen = array();
    foreach ((array) $existing_sources as $s) {
        $seen[devdredi_csv_source_key($s)] = true;
    }
    $map  = array('source' => 0, 'destination' => 1, 'method' => 2, 'name' => 3);
    $plan = array('add' => array(), 'skipped' => array(), 'invalid' => array());
    $line = 0;
    foreach ($rows as $i => $cells) {
        $line = $i + 1;
        if ($i === 0) {
            $head = array_map('strtolower', $cells);
            $alias = array('from' => 'source', 'url' => 'source', 'to' => 'destination', 'target' => 'destination', 'code' => 'method', 'type' => 'method', 'status' => 'method', 'title' => 'name', 'nickname' => 'name');
            $found = array();
            foreach ($head as $pos => $h) {
                $h = isset($alias[$h]) ? $alias[$h] : $h;
                if (isset($map[$h]) && !isset($found[$h])) {
                    $found[$h] = $pos;
                }
            }
            if (isset($found['source'])) {
                $map = $found + array('destination' => -1, 'method' => -1, 'name' => -1);
                continue; // a header row, not a rule
            }
        }
        $cell = function ($key) use ($cells, $map) {
            $pos = isset($map[$key]) ? $map[$key] : -1;
            return ($pos >= 0 && isset($cells[$pos])) ? (string) $cells[$pos] : '';
        };
        $source      = $cell('source');
        $destination = $cell('destination');
        $method      = strtolower($cell('method'));
        $name        = $cell('name');
        if ($method === '') {
            $method = '301';
        }
        if ($source === '' || strlen($source) > 2000) {
            $plan['invalid'][] = array('line' => $line, 'why' => 'source is empty or longer than 2000 characters');
            continue;
        }
        if (!in_array($method, devdredi_csv_methods(), true)) {
            $plan['invalid'][] = array('line' => $line, 'why' => 'method must be 301, 302, 307, 308, 410, js or meta');
            continue;
        }
        if ($method !== '410') {
            $scheme = strtolower((string) wp_parse_url($destination, PHP_URL_SCHEME));
            if ($destination === '' || !in_array($scheme, array('http', 'https'), true) || !wp_parse_url($destination, PHP_URL_HOST)) {
                $plan['invalid'][] = array('line' => $line, 'why' => 'destination must be an http or https URL');
                continue;
            }
        } else {
            $destination = '';
        }
        $key = devdredi_csv_source_key($source);
        if (isset($seen[$key])) {
            $plan['skipped'][] = $line;
            continue;
        }
        $seen[$key] = true;
        $plan['add'][] = array('line' => $line, 'source' => $source, 'destination' => $destination, 'method' => $method, 'name' => ($name !== '' ? $name : $source));
    }
    return $plan;
}

/** One CSV line from cells, RFC 4180 quoting, "\n" terminated. */
function devdredi_csv_line($cells)
{
    $out = array();
    foreach ((array) $cells as $c) {
        $c = (string) $c;
        // A leading formula character is neutralised so a spreadsheet never runs an exported value.
        if ($c !== '' && strpbrk($c[0], "=+-@\t\r") !== false) {
            $c = "'" . $c;
        }
        $out[] = '"' . str_replace('"', '""', $c) . '"';
    }
    return implode(',', $out) . "\n";
}
