<?php
/**
 * Router for PHP's built-in server:   php -S localhost:8000 router.php
 * Blocks direct web access to private folders and sensitive file types (the Apache
 * equivalent is .htaccess), then lets the built-in server serve everything else.
 */
$path = rawurldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$path = '/' . ltrim(str_replace(['\\', "\0"], ['/', ''], $path), '/');

$blocked = preg_match('#(^|/)\.\.?(/|$)#', $path)                          // path traversal
    || preg_match('#^/(database|config|includes|storage)(/|$)#i', $path)  // private folders
    || preg_match('#/\.[^/]*$#', $path)                                    // dotfiles (.htaccess, .git ...)
    || preg_match('#\.(sql|log|sqlite|sqlite3|db|md)$#i', $path)           // sensitive file types
    || preg_match('#^/uploads/.*\.(php|phtml|phar)$#i', $path)             // never execute uploads
    || strcasecmp($path, '/router.php') === 0;

if ($blocked) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Forbidden';
    return true;
}
return false;   // serve the requested file / index.html normally
