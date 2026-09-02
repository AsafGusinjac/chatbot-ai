<?php
/**
 * Catches the file-level problems that different editors introduce and that
 * are invisible on screen:
 *
 *   - UTF-8 BOM before <?php  → "headers already sent", broken JSON/sessions
 *   - non-UTF-8 bytes         → mojibake in Bosnian product names (č ć š ž đ)
 *   - trailing ?>             → stray whitespace after it does the same as a BOM
 *   - CRLF line endings       → noisy diffs between editors
 *
 * Run this before committing, and any time output looks corrupted.
 *
 * Target: PHP 7.4 — str_starts_with()/str_contains() (8.0) are avoided.
 *
 * Run:  C:\xampp\php\php.exe tools\check_files.php
 */

$root  = dirname(__DIR__);
$files = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        function ($f) {
            return $f->getFilename() !== 'vendor' && $f->getFilename() !== '.git';
        }
    )
);

$problems = 0;
$checked  = 0;

foreach ($files as $file) {
    if (!$file->isFile() || !in_array($file->getExtension(), ['php', 'js', 'css', 'html', 'txt', 'sql'], true)) {
        continue;
    }

    $path     = $file->getPathname();
    $relative = ltrim(str_replace($root, '', $path), '\\/');
    $content  = (string) file_get_contents($path);
    $checked++;

    $issues = [];

    if (substr($content, 0, 3) === "\xEF\xBB\xBF") {
        $issues[] = 'UTF-8 BOM at start — will break header() and session_start()';
    }
    if (!mb_check_encoding($content, 'UTF-8')) {
        $issues[] = 'not valid UTF-8 — likely saved as Windows-1250';
    }
    if (strpos($content, "\r\n") !== false) {
        $issues[] = 'CRLF line endings (cosmetic, but noisy in diffs)';
    }
    if ($file->getExtension() === 'php' && preg_match('/\?>\s*$/', $content)) {
        $issues[] = 'closing ?> at end of file — omit it in PHP-only files';
    }

    if ($issues !== []) {
        $problems++;
        echo "  {$relative}\n";
        foreach ($issues as $issue) {
            echo "      - {$issue}\n";
        }
    }
}

echo "\nChecked {$checked} file(s). ";
echo $problems === 0
    ? "All clean.\n"
    : "{$problems} file(s) need attention (see above).\n";

exit($problems === 0 ? 0 : 1);
