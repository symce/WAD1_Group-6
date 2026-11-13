<?php
/**
 * Shared helpers used by every API endpoint:
 * JSON output + error handling, secure sessions, CSRF, authentication/authorization
 * guards, validation, weave queries and image-upload handling.
 * Every SQL statement in this project is a PDO prepared statement.
 */
declare(strict_types=1);

require_once __DIR__ . '/../config/db.php';

const SESSION_NAME         = 'TWA_SESSION';
const REMEMBER_SECONDS     = 2592000;   // 30 days ("Remember me")
const IDLE_SECONDS         = 1800;      // 30 minutes idle timeout for normal sessions
const MAX_FAILED_LOGINS    = 5;
const LOGIN_WINDOW_SECONDS = 900;       // 15 minutes
const RESET_TTL_SECONDS    = 1800;      // reset links live 30 minutes
const MAX_UPLOAD_BYTES     = 3145728;   // 3 MB
// Valid bcrypt hash of an unrelated string: used only to equalise login timing for unknown accounts.
const DUMMY_HASH = '$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi';

/* ------------------------------------------------------------------ JSON / errors */

class ApiException extends RuntimeException
{
    public array $errors;

    public function __construct(string $message, int $status = 400, array $errors = [])
    {
        parent::__construct($message, $status);
        $this->errors = $errors;
    }
}

function json_out($data = null, string $message = '', int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
    echo json_encode(
        ['success' => $status < 400, 'message' => $message, 'data' => $data],
        JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_INVALID_UTF8_SUBSTITUTE
    );
    exit;
}

/** Abort the request with an error response (optionally with field-level messages). */
function fail(string $message, int $status = 400, array $errors = []): void
{
    throw new ApiException($message, $status, $errors);
}

function register_error_handlers(): void
{
    ini_set('display_errors', '0');
    set_error_handler(function (int $severity, string $message, string $file, int $line) {
        if (!(error_reporting() & $severity)) {
            return false;
        }
        throw new ErrorException($message, 0, $severity, $file, $line);
    });
    set_exception_handler(function (Throwable $e) {
        if ($e instanceof ApiException) {
            json_out($e->errors ? ['errors' => $e->errors] : null, $e->getMessage(), $e->getCode() ?: 400);
        }
        error_log('[TWA] ' . get_class($e) . ': ' . $e->getMessage() . ' @ ' . $e->getFile() . ':' . $e->getLine());
        json_out(null, 'Something went wrong on our side. Please try again in a moment.', 500);
    });
}

function require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        fail('Method not allowed.', 405);
    }
}

/* ------------------------------------------------------------------ request input */

function request_input(): array
{
    static $cache = null;
    if ($cache !== null) {
        return $cache;
    }
    $type = $_SERVER['CONTENT_TYPE'] ?? '';
    if (stripos($type, 'application/json') !== false) {
        $data = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($data)) {
            fail('Invalid JSON body.', 400);
        }
        return $cache = $data;
    }
    return $cache = $_POST;
}

/** Trimmed string input ('' when missing or not a string). */
function input_str(array $in, string $key): string
{
    $v = $in[$key] ?? '';
    return is_string($v) ? trim($v) : '';
}

/** Raw (untrimmed) string input: used for passwords. */
function input_raw(array $in, string $key): string
{
    $v = $in[$key] ?? '';
    return is_string($v) ? $v : '';
}

function text_len(string $s): int
{
    return function_exists('mb_strlen') ? mb_strlen($s, 'UTF-8') : strlen($s);
}

function valid_id($value): ?int
{
    $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    return $id === false ? null : (int) $id;
}

/* ------------------------------------------------------------------ environment */

function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https');
}

/** URL path the app is mounted at ('/' or e.g. '/twa' under XAMPP). */
function app_base_path(): string
{
    $dir = str_replace('\\', '/', dirname(dirname($_SERVER['SCRIPT_NAME'] ?? '/api/x.php')));
    return ($dir === '/' || $dir === '.' || $dir === '') ? '/' : rtrim($dir, '/');
}

function base_url(): string
{
    $env = getenv('TWA_APP_URL');
    if ($env) {
        return rtrim($env, '/');
    }
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    if (!preg_match('/^[A-Za-z0-9.\-:\[\]]+$/', $host)) {
        $host = 'localhost';
    }
    $base = app_base_path();
    return (is_https() ? 'https' : 'http') . '://' . $host . ($base === '/' ? '' : $base);
}

function client_ip(): string
{
    return substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

function now_utc(): string
{
    return gmdate('Y-m-d H:i:s');
}

/* ------------------------------------------------------------------ sessions + CSRF */

function cookie_opts(int $expires): array
{
    return [
        'expires'  => $expires,
        'path'     => app_base_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ];
}

function send_session_cookie(bool $remember): void
{
    setcookie(SESSION_NAME, session_id(), cookie_opts($remember ? time() + REMEMBER_SECONDS : 0));
}

function init_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    // Own session folder: keeps "remember me" sessions from being purged by the server's 24-minute GC.
    $dir = STORAGE_PATH . '/sessions';
    if (!is_dir($dir)) {
        @mkdir($dir, 0700, true);
    }
    if (is_dir($dir) && is_writable($dir)) {
        session_save_path($dir);
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', (string) REMEMBER_SECONDS);
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => app_base_path(),
        'secure'   => is_https(),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();

    $now = time();
    if (!empty($_SESSION['user_id'])) {
        $remember = !empty($_SESSION['remember']);
        if (!$remember && $now - (int) ($_SESSION['last_activity'] ?? $now) > IDLE_SECONDS) {
            // Idle too long: forget the user, keep a fresh anonymous session.
            $_SESSION = [];
            session_regenerate_id(true);
        } elseif ($remember) {
            send_session_cookie(true);   // sliding 30-day cookie
        }
    }
    $_SESSION['last_activity'] = $now;
}

/** Log a user into the current session (new session id prevents session fixation). */
function login_user(int $userId, bool $remember): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
    $_SESSION['remember'] = $remember;
    $_SESSION['last_activity'] = time();
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    if ($remember) {
        send_session_cookie(true);
    }
}

/** Destroy session data AND expire the cookie. */
function logout_user(): void
{
    $_SESSION = [];
    if (session_status() === PHP_SESSION_ACTIVE) {
        session_destroy();
    }
    setcookie(SESSION_NAME, '', cookie_opts(time() - 42000));
}

function csrf_token(): string
{
    if (empty($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf'];
}

function require_csrf(): void
{
    $sent = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    $real = $_SESSION['csrf'] ?? '';
    if (!is_string($sent) || $sent === '' || !is_string($real) || $real === '' || !hash_equals($real, $sent)) {
        fail('Security token missing or invalid. Please refresh the page and try again.', 403);
    }
}

/** Call first in every API file: error handlers, session, and CSRF check for non-GET requests. */
function bootstrap_api(): void
{
    register_error_handlers();
    header_remove('X-Powered-By');
    init_session();
    if (!in_array($_SERVER['REQUEST_METHOD'] ?? 'GET', ['GET', 'HEAD'], true)) {
        require_csrf();
    }
}

/* ------------------------------------------------------------------ database helper */

/** Prepare + bind + execute. Integers are bound as integers, everything else as strings. */
function run_query(string $sql, array $params = []): PDOStatement
{
    $st = db()->prepare($sql);
    foreach ($params as $key => $value) {
        $name = ':' . ltrim((string) $key, ':');
        if (is_bool($value)) {
            $value = (int) $value;
        }
        if ($value === null) {
            $st->bindValue($name, null, PDO::PARAM_NULL);
        } elseif (is_int($value)) {
            $st->bindValue($name, $value, PDO::PARAM_INT);
        } else {
            $st->bindValue($name, (string) $value, PDO::PARAM_STR);
        }
    }
    $st->execute();
    return $st;
}

/* ------------------------------------------------------------------ users / authorization */

/** Safe user shape: never contains the password hash. */
function public_user(array $row): array
{
    return [
        'user_id'       => (int) $row['user_id'],
        'full_name'     => $row['full_name'],
        'username'      => $row['username'],
        'email'         => $row['email'],
        'role'          => $row['role'],
        'created_at'    => $row['created_at'],
        'last_login_at' => $row['last_login_at'] ?? null,
    ];
}

function current_user(bool $refresh = false): ?array
{
    static $cache = false;
    if ($cache !== false && !$refresh) {
        return $cache;
    }
    $cache = null;
    $id = $_SESSION['user_id'] ?? null;
    if ($id) {
        $row = run_query(
            'SELECT user_id, full_name, username, email, role, created_at, last_login_at FROM users WHERE user_id = :id',
            ['id' => (int) $id]
        )->fetch();
        if ($row) {
            $cache = public_user($row);
        } else {
            unset($_SESSION['user_id']);   // account was deleted
        }
    }
    return $cache;
}

function require_login(): array
{
    $user = current_user();
    if (!$user) {
        fail('Please log in to continue.', 401);
    }
    return $user;
}

function require_role(string $role): array
{
    $user = require_login();
    if ($user['role'] !== $role) {
        fail('You do not have permission to do that.', 403);
    }
    return $user;
}

/** Password policy (same rule is enforced in assets/js/app.js). Returns an error message or null. */
function password_policy_error(string $pw): ?string
{
    if (strlen($pw) < 8 || !preg_match('/[A-Za-z]/', $pw) || !preg_match('/\d/', $pw)) {
        return 'Password must be at least 8 characters and contain a letter and a number.';
    }
    if (strlen($pw) > 72) {
        return 'Password must be at most 72 characters.';
    }
    return null;
}

/* ------------------------------------------------------------------ lookups + weaves */

/** Resolve a human-readable lookup value (region / medium / level) to its id, or null. */
function lookup_id(string $kind, string $name): ?int
{
    static $sql = [
        'region' => 'SELECT region_id FROM regions WHERE region_name = :n',
        'medium' => 'SELECT medium_id FROM mediums WHERE medium_name = :n',
        'level'  => 'SELECT level_id FROM vulnerability_levels WHERE level_name = :n',
    ];
    if ($name === '' || !isset($sql[$kind])) {
        return null;
    }
    $id = run_query($sql[$kind], ['n' => $name])->fetchColumn();
    return $id === false ? null : (int) $id;
}

const WEAVE_FROM = 'FROM weaves w
    JOIN tribes t ON t.tribe_id = w.tribe_id
    JOIN regions r ON r.region_id = w.region_id
    JOIN mediums m ON m.medium_id = w.medium_id
    JOIN vulnerability_levels l ON l.level_id = w.level_id
    LEFT JOIN users u ON u.user_id = w.submitted_by';

const WEAVE_SELECT = 'SELECT w.weave_id, w.catalog_code AS id, w.tribe_id, t.tribe_name AS tribe,
    r.region_name AS region, m.medium_name AS medium, l.level_name AS vuln, w.name, w.location AS loc,
    w.fiber, w.dyes, w.loom, w.cultural_significance AS culture, w.motifs, w.doc_source AS docSource,
    w.fpic, w.image, w.status, w.submitted_by, u.full_name AS submitter_name, w.admin_feedback, w.created_at '
    . WEAVE_FROM;

function format_weave(array $r): array
{
    $r['weave_id']     = (int) $r['weave_id'];
    $r['tribe_id']     = (int) $r['tribe_id'];
    $r['submitted_by'] = $r['submitted_by'] === null ? null : (int) $r['submitted_by'];
    return $r;
}

/** $where holds fixed SQL fragments only; every value travels through $params. */
function query_weaves(array $where, array $params, bool $newestFirst = false, ?int $limit = null): array
{
    $sql = WEAVE_SELECT . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
         . ($newestFirst ? ' ORDER BY w.weave_id DESC' : ' ORDER BY w.weave_id ASC');
    if ($limit !== null) {
        $sql .= ' LIMIT :row_limit';
        $params['row_limit'] = $limit;
    }
    return array_map('format_weave', run_query($sql, $params)->fetchAll());
}

function count_weaves(array $where, array $params): int
{
    $sql = 'SELECT COUNT(*) ' . WEAVE_FROM . ($where ? ' WHERE ' . implode(' AND ', $where) : '');
    return (int) run_query($sql, $params)->fetchColumn();
}

function fetch_weave(int $id): ?array
{
    $rows = query_weaves(['w.weave_id = :id'], ['id' => $id]);
    return $rows[0] ?? null;
}

/** Validate the submit/edit form. Returns [normalised values, field errors]. */
function validate_weave_input(array $in): array
{
    $errors = [];
    $v = [];
    $rules = [
        'tribe'     => [1, 100,  'Tribe'],
        'name'      => [1, 150,  'Weave name'],
        'loc'       => [1, 150,  'Geographic location'],
        'fiber'     => [1, 200,  'Fiber / material'],
        'dyes'      => [1, 200,  'Natural dyes'],
        'loom'      => [1, 200,  'Loom / weaving technique'],
        'culture'   => [1, 2000, 'Cultural significance'],
        'docSource' => [1, 300,  'Documentation source'],
        'fpic'      => [1, 300,  'Community consent / FPIC documentation'],
    ];
    foreach ($rules as $key => [$min, $max, $label]) {
        $val = input_str($in, $key);
        $len = text_len($val);
        if ($len < $min) {
            $errors[$key] = $label . ' is required.';
        } elseif ($len > $max) {
            $errors[$key] = $label . ' must be at most ' . $max . ' characters.';
        }
        $v[$key] = $val;
    }
    $v['motifs'] = input_str($in, 'motifs');
    if (text_len($v['motifs']) > 2000) {
        $errors['motifs'] = 'Motifs and patterns must be at most 2000 characters.';
    }
    $lookups = ['region' => ['region', 'Region'], 'medium' => ['medium', 'Craft medium'], 'vuln' => ['level', 'Vulnerability status']];
    foreach ($lookups as $key => [$kind, $label]) {
        $id = lookup_id($kind, input_str($in, $key));
        if ($id === null) {
            $errors[$key] = 'Choose a valid ' . strtolower($label) . '.';
        }
        $v[$key . '_id'] = $id;
    }
    return [$v, $errors];
}

/** Find a tribe by name (case-insensitive) or create a non-featured one. */
function find_or_create_tribe(string $name, string $location, string $image): int
{
    $id = run_query('SELECT tribe_id FROM tribes WHERE tribe_name = :n', ['n' => $name])->fetchColumn();
    if ($id !== false) {
        return (int) $id;
    }
    run_query(
        'INSERT INTO tribes (tribe_name, location, image, is_featured) VALUES (:n, :l, :i, 0)',
        ['n' => $name, 'l' => $location, 'i' => $image]
    );
    return (int) db()->lastInsertId();
}

function next_catalog_code(): string
{
    $max = (int) db()->query(
        "SELECT COALESCE(MAX(CAST(SUBSTR(catalog_code, 9) AS INTEGER)), 0) FROM weaves WHERE catalog_code LIKE 'ATWKP-M-%'"
    )->fetchColumn();
    return sprintf('ATWKP-M-%04d', $max + 1);
}

/* ------------------------------------------------------------------ image upload */

const IMAGE_TYPES = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp', 'image/gif' => 'gif'];

/** Returns an error message for the uploaded file, or null when there is none / it is fine. */
function upload_error(string $field = 'image'): ?string
{
    if (empty($_FILES[$field]) || !is_array($_FILES[$field])) {
        return null;
    }
    $f = $_FILES[$field];
    if (is_array($f['error'])) {
        return 'Upload a single image file.';
    }
    if ($f['error'] === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    if ($f['error'] === UPLOAD_ERR_INI_SIZE || $f['error'] === UPLOAD_ERR_FORM_SIZE || $f['size'] > MAX_UPLOAD_BYTES) {
        return 'The image is larger than 3 MB.';
    }
    if ($f['error'] !== UPLOAD_ERR_OK || !is_uploaded_file($f['tmp_name'])) {
        return 'The image upload failed. Please try again.';
    }
    // Real MIME sniffing: the file extension / client-sent type are never trusted.
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($f['tmp_name']);
    if (!isset(IMAGE_TYPES[$mime]) || @getimagesize($f['tmp_name']) === false) {
        return 'Only JPG, PNG, WEBP or GIF images are allowed.';
    }
    return null;
}

/** Move a validated upload into /uploads and return its web path ('uploads/xxxx.ext'), or null. */
function save_uploaded_image(string $field = 'image'): ?string
{
    if (empty($_FILES[$field]) || ($_FILES[$field]['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) {
        return null;
    }
    $error = upload_error($field);
    if ($error !== null) {
        fail('Please fix the highlighted fields.', 422, ['image' => $error]);
    }
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($_FILES[$field]['tmp_name']);
    if (!is_dir(UPLOADS_PATH)) {
        @mkdir(UPLOADS_PATH, 0755, true);
    }
    $name = bin2hex(random_bytes(16)) . '.' . IMAGE_TYPES[$mime];
    if (!move_uploaded_file($_FILES[$field]['tmp_name'], UPLOADS_PATH . '/' . $name)) {
        throw new RuntimeException('Could not store the uploaded image.');
    }
    return 'uploads/' . $name;
}

/** Delete a file we created in uploads/. The strict pattern guarantees bundled seed images are never touched. */
function delete_uploaded_image(?string $path): void
{
    if ($path !== null && preg_match('#^uploads/[a-f0-9]{32}\.(jpg|png|webp|gif)$#', $path)) {
        $file = UPLOADS_PATH . '/' . basename($path);
        if (is_file($file)) {
            @unlink($file);
        }
    }
}

/* ------------------------------------------------------------------ simulated e-mail */

function write_mail_outbox(string $to, string $subject, string $body): void
{
    if (!is_dir(STORAGE_PATH)) {
        @mkdir(STORAGE_PATH, 0755, true);
    }
    $entry = '[' . now_utc() . " UTC]\nTo: $to\nSubject: $subject\n\n$body\n" . str_repeat('-', 70) . "\n";
    file_put_contents(STORAGE_PATH . '/mail_outbox.log', $entry, FILE_APPEND | LOCK_EX);
}
