<?php
/**
 * Flags PHP 8.0+ syntax that would crash on dstore's PHP 7.4 server.
 *
 * This exists because your local PHP is 8.2, so `php -l` accepts code the
 * production server cannot parse. A PHP 8 parse error is fatal and total:
 * the file does not half-work, it returns a blank 500 — exactly the failure
 * we saw from the Digitalis API.
 *
 * Uses the tokenizer rather than plain text search, so occurrences inside
 * comments and strings are correctly ignored.
 *
 * Run:  C:\xampp\php\php.exe tools\check_php74.php
 */

$root = dirname(__DIR__);

// Functions introduced after 7.4, with the version that added them.
$newFunctions = [
    'str_contains'      => '8.0',
    'str_starts_with'   => '8.0',
    'str_ends_with'     => '8.0',
    'get_debug_type'    => '8.0',
    'array_is_list'     => '8.1',
    'enum_exists'       => '8.1',
    'array_find'        => '8.4',
    'array_any'         => '8.4',
    'array_all'         => '8.4',
];

// Reserved words only valid as types in 8.0+.
$newTypes = [
    'mixed'  => '8.0',
    'never'  => '8.1',
    'false'  => '8.0',
    'null'   => '8.0',
];

$files = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($f) {
            return $f->getFilename() !== 'vendor' && $f->getFilename() !== '.git';
        }
    )
);

$findings = 0;
$checked  = 0;

foreach ($files as $file) {
    if (!$file->isFile() || $file->getExtension() !== 'php') {
        continue;
    }

    $relative = ltrim(str_replace($root, '', $file->getPathname()), '\\/');
    $source   = (string) file_get_contents($file->getPathname());
    $tokens   = @token_get_all($source);
    $checked++;

    $issues = [];
    $inConstructorParams = false;
    $parenDepth = 0;

    foreach ($tokens as $i => $token) {
        // Track when we are inside __construct(...) to spot promoted properties.
        if (is_string($token)) {
            if ($inConstructorParams && $token === '(') {
                $parenDepth++;
            } elseif ($inConstructorParams && $token === ')') {
                $parenDepth--;
                if ($parenDepth <= 0) {
                    $inConstructorParams = false;
                }
            }
            continue;
        }

        [$id, $text, $line] = $token;

        if ($id === T_STRING && strtolower($text) === '__construct') {
            $inConstructorParams = true;
            $parenDepth = 0;
        }

        // Constructor property promotion (8.0)
        if ($inConstructorParams && in_array($id, [T_PUBLIC, T_PRIVATE, T_PROTECTED], true)) {
            $issues[] = [$line, '8.0', 'constructor property promotion — declare the property and assign in the body'];
        }

        // Functions that do not exist in 7.4
        if ($id === T_STRING && isset($newFunctions[strtolower($text)])) {
            $ver = $newFunctions[strtolower($text)];
            $issues[] = [$line, $ver, "{$text}() does not exist in 7.4"];
        }

        // Types that are not valid in 7.4 — only when genuinely used as a type.
        // A ':' alone is ambiguous: it introduces a return type, but it is also
        // the ternary separator in `$a ? $b : null`. Only a ':' that directly
        // follows ')' is a return type.
        if ($id === T_STRING && isset($newTypes[strtolower($text)])) {
            $prevIdx = prevMeaningfulIndex($tokens, $i);
            $prev    = $prevIdx === null ? null : tokenText($tokens[$prevIdx]);

            $isTypePosition = false;
            if ($prev === ':') {
                $isTypePosition = isReturnTypeColon($tokens, $prevIdx);
            } elseif ($prev === '|') {
                // Part of a union type such as string|null.
                $isTypePosition = true;
            } elseif ($prev === '(' || $prev === ',') {
                // Parameter type: only if a variable follows, e.g. (mixed $x).
                $nextIdx = nextMeaningfulIndex($tokens, $i);
                $isTypePosition = $nextIdx !== null
                    && !is_string($tokens[$nextIdx])
                    && $tokens[$nextIdx][0] === T_VARIABLE;
            }

            if ($isTypePosition) {
                $ver = $newTypes[strtolower($text)];
                $issues[] = [$line, $ver, "'{$text}' type declaration — drop the type or use a @return docblock"];
            }
        }

        // Union types (8.0) — 'A|B' in a type position.
        //
        // `FOO | BAR` is also plain bitwise OR between two constants, which is
        // valid in every PHP version. The two are only distinguishable by what
        // surrounds the chain: a union type either ends at a variable
        // (parameter) or follows the ')' + ':' of a return type.
        // Only start from the head of a chain, so 'A|B|C' reports once.
        if ($id === T_STRING
            && nextMeaningful($tokens, $i) === '|'
            && prevMeaningful($tokens, $i) !== '|'
        ) {
            $end = $i;
            while (true) {
                $pipeIdx = nextMeaningfulIndex($tokens, $end);
                if ($pipeIdx === null || tokenText($tokens[$pipeIdx]) !== '|') {
                    break;
                }
                $nameIdx = nextMeaningfulIndex($tokens, $pipeIdx);
                if ($nameIdx === null || is_string($tokens[$nameIdx]) || $tokens[$nameIdx][0] !== T_STRING) {
                    break;
                }
                $end = $nameIdx;
            }

            $afterIdx = nextMeaningfulIndex($tokens, $end);
            $endsAtVariable = $afterIdx !== null
                && !is_string($tokens[$afterIdx])
                && $tokens[$afterIdx][0] === T_VARIABLE;

            $isReturnType = false;
            $beforeIdx = prevMeaningfulIndex($tokens, $i);
            if ($beforeIdx !== null && tokenText($tokens[$beforeIdx]) === ':') {
                $isReturnType = isReturnTypeColon($tokens, $beforeIdx);
            }

            if ($endsAtVariable || $isReturnType) {
                $issues[] = [$line, '8.0', 'union type declaration'];
            }
        }

        // Tokens that only exist in PHP 8+
        if (defined('T_MATCH') && $id === T_MATCH) {
            $issues[] = [$line, '8.0', 'match expression — use switch instead'];
        }
        if (defined('T_NULLSAFE_OBJECT_OPERATOR') && $id === T_NULLSAFE_OBJECT_OPERATOR) {
            $issues[] = [$line, '8.0', 'nullsafe operator ?-> — use an isset() check'];
        }
        if (defined('T_ATTRIBUTE') && $id === T_ATTRIBUTE) {
            $issues[] = [$line, '8.0', 'attribute #[...] — use a docblock'];
        }
        if (defined('T_ENUM') && $id === T_ENUM) {
            $issues[] = [$line, '8.1', 'enum — use class constants'];
        }
        if (defined('T_READONLY') && $id === T_READONLY) {
            $issues[] = [$line, '8.1', 'readonly property'];
        }
    }

    // Trailing comma in a parameter list (8.0). Cheap regex; declarations only.
    if (preg_match_all('/function\s+\w*\s*\([^)]*,\s*\)/s', $source, $m, PREG_OFFSET_CAPTURE)) {
        foreach ($m[0] as $match) {
            $line = substr_count(substr($source, 0, $match[1]), "\n") + 1;
            $issues[] = [$line, '8.0', 'trailing comma in parameter list'];
        }
    }

    if ($issues !== []) {
        echo "\n  {$relative}\n";
        usort($issues, function ($a, $b) {
            return $a[0] <=> $b[0];
        });
        foreach ($issues as $issue) {
            printf("      line %-4d [PHP %s]  %s\n", $issue[0], $issue[1], $issue[2]);
            $findings++;
        }
    }
}

echo "\nScanned {$checked} PHP file(s). ";
if ($findings === 0) {
    echo "No PHP 8-only syntax found — safe for 7.4.\n";
} else {
    echo "{$findings} issue(s) would break on PHP 7.4.\n";
}

exit($findings === 0 ? 0 : 1);

/**
 * The previous token that is not whitespace or a comment, as a string.
 *
 * @param array $tokens
 * @param int   $i
 * @return string|null
 */
function prevMeaningful(array $tokens, $i)
{
    $idx = prevMeaningfulIndex($tokens, $i);
    return $idx === null ? null : tokenText($tokens[$idx]);
}

/**
 * Index of the previous token that is not whitespace or a comment.
 *
 * @param array $tokens
 * @param int   $i
 * @return int|null
 */
function prevMeaningfulIndex(array $tokens, $i)
{
    for ($j = $i - 1; $j >= 0; $j--) {
        $t = $tokens[$j];
        if (is_string($t)) {
            return $j;
        }
        if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $j;
        }
    }
    return null;
}

/**
 * A token's text, whether it is a plain string or a [id, text, line] array.
 *
 * @param string|array $token
 * @return string
 */
function tokenText($token)
{
    return is_string($token) ? $token : $token[1];
}

/**
 * Is the ':' at $colonIdx introducing a return type?
 *
 * Both of these put ')' immediately before a ':':
 *
 *     function f(): string     <- return type
 *     $x = cond() ? g($y) : null;   <- ternary
 *
 * They are told apart by walking back to the '(' that the ')' closes and
 * checking whether it opened a function declaration's parameter list.
 *
 * @param array $tokens
 * @param int   $colonIdx
 * @return bool
 */
function isReturnTypeColon(array $tokens, $colonIdx)
{
    $closeIdx = prevMeaningfulIndex($tokens, $colonIdx);
    if ($closeIdx === null || tokenText($tokens[$closeIdx]) !== ')') {
        return false;
    }

    $openIdx = matchingOpenParen($tokens, $closeIdx);
    if ($openIdx === null) {
        return false;
    }

    $beforeIdx = prevMeaningfulIndex($tokens, $openIdx);
    if ($beforeIdx === null) {
        return false;
    }
    $before = $tokens[$beforeIdx];

    // function (): T   /   fn (): T
    if (!is_string($before) && in_array($before[0], [T_FUNCTION, T_FN], true)) {
        return true;
    }

    // function name(): T
    if (!is_string($before) && $before[0] === T_STRING) {
        $twoBackIdx = prevMeaningfulIndex($tokens, $beforeIdx);
        if ($twoBackIdx !== null
            && !is_string($tokens[$twoBackIdx])
            && $tokens[$twoBackIdx][0] === T_FUNCTION
        ) {
            return true;
        }
    }

    // function () use ($x): T  — step back over the use(...) clause.
    if (!is_string($before) && $before[0] === T_USE) {
        $useOpenIdx = prevMeaningfulIndex($tokens, $beforeIdx);
        if ($useOpenIdx !== null && tokenText($tokens[$useOpenIdx]) === ')') {
            return isReturnTypeColon($tokens, $beforeIdx);
        }
    }

    return false;
}

/**
 * Index of the '(' matching the ')' at $closeIdx.
 *
 * @param array $tokens
 * @param int   $closeIdx
 * @return int|null
 */
function matchingOpenParen(array $tokens, $closeIdx)
{
    $depth = 0;
    for ($j = $closeIdx; $j >= 0; $j--) {
        $text = tokenText($tokens[$j]);
        if ($text === ')') {
            $depth++;
        } elseif ($text === '(') {
            $depth--;
            if ($depth === 0) {
                return $j;
            }
        }
    }
    return null;
}

/**
 * The next token that is not whitespace or a comment, as a string.
 *
 * @param array $tokens
 * @param int   $i
 * @return string|null
 */
function nextMeaningful(array $tokens, $i)
{
    $idx = nextMeaningfulIndex($tokens, $i);
    return $idx === null ? null : tokenText($tokens[$idx]);
}

/**
 * Index of the next token that is not whitespace or a comment.
 *
 * @param array $tokens
 * @param int   $i
 * @return int|null
 */
function nextMeaningfulIndex(array $tokens, $i)
{
    $count = count($tokens);
    for ($j = $i + 1; $j < $count; $j++) {
        $t = $tokens[$j];
        if (is_string($t)) {
            return $j;
        }
        if (!in_array($t[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
            return $j;
        }
    }
    return null;
}
