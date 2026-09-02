<?php
/**
 * Reads one page from each Digitalis endpoint and prints its real shape:
 * field names, types, and a sample value. We use this to build a MySQL
 * schema that matches the API exactly instead of guessing from the docs.
 *
 * Target: PHP 7.4 — array_is_list() (8.1) and get_debug_type() (8.0) are
 * replaced with the local helpers at the bottom.
 *
 * Run:  C:\xampp\php\php.exe tools\probe_api.php
 */

require __DIR__ . '/../config.php';
require __DIR__ . '/../lib/DigitalisApi.php';

$api = new DigitalisApi(config('digitalis_base_url'), config('digitalis_token'));

$endpoints = [
    '/products'             => ['page' => 1],
    '/brands'               => [],
    '/supercategory'        => [],
    '/category'             => [],
    '/category/subcategory' => [],
    '/category/hierarchy'   => [],
];

foreach ($endpoints as $path => $query) {
    echo str_repeat('=', 70), "\n";
    echo "GET {$path}", $query ? ' ' . json_encode($query) : '', "\n";
    echo str_repeat('=', 70), "\n";

    try {
        $envelope = $api->getEnvelope($path, $query);
    } catch (Throwable $e) {
        echo "  FAILED: {$e->getMessage()}\n\n";
        continue;
    }

    // Anything alongside `data` is usually pagination metadata — worth seeing.
    $meta = array_diff_key($envelope, ['data' => null]);
    if ($meta !== []) {
        echo "  envelope: ", json_encode($meta, JSON_UNESCAPED_UNICODE), "\n";
    }

    $data = isset($envelope['data']) ? $envelope['data'] : null;

    if (is_array($data) && is_list_array($data)) {
        echo "  data: list of ", count($data), " item(s)\n";
        if (isset($data[0])) {
            describe($data[0], '    ');
        }
    } elseif (is_array($data)) {
        echo "  data: object\n";
        describe($data, '    ');
    } else {
        echo "  data: ", var_export($data, true), "\n";
    }

    echo "\n";
}

/**
 * Print each field of an item as: name (type) = sample value
 *
 * @param mixed  $item
 * @param string $indent
 */
function describe($item, $indent)
{
    if (!is_array($item)) {
        echo $indent, debug_type($item), ' = ', truncate(var_export($item, true)), "\n";
        return;
    }

    foreach ($item as $field => $value) {
        if (is_array($value)) {
            $count = count($value);
            echo $indent, $field, " (array[{$count}])\n";
            // Show the shape of nested structures too (e.g. hierarchy "sub").
            if ($count > 0) {
                describe(reset($value), $indent . '  ');
            }
            continue;
        }

        echo $indent, $field, ' (', debug_type($value), ') = ',
             truncate(json_encode($value, JSON_UNESCAPED_UNICODE)), "\n";
    }
}

/**
 * PHP 7.4 stand-in for array_is_list() (8.1).
 *
 * @param array $array
 * @return bool
 */
function is_list_array(array $array)
{
    if ($array === []) {
        return true;
    }
    return array_keys($array) === range(0, count($array) - 1);
}

/**
 * PHP 7.4 stand-in for get_debug_type() (8.0).
 *
 * @param mixed $value
 * @return string
 */
function debug_type($value)
{
    if ($value === null) {
        return 'null';
    }
    if (is_bool($value)) {
        return 'bool';
    }
    if (is_int($value)) {
        return 'int';
    }
    if (is_float($value)) {
        return 'float';
    }
    if (is_string($value)) {
        return 'string';
    }
    if (is_array($value)) {
        return 'array';
    }
    if (is_object($value)) {
        return get_class($value);
    }
    return gettype($value);
}

/**
 * @param string $s
 * @param int    $max
 * @return string
 */
function truncate($s, $max = 80)
{
    return mb_strlen($s) > $max ? mb_substr($s, 0, $max) . '…' : $s;
}
