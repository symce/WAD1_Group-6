<?php
/**
 * Authentication API.   auth.php?action=me | login | register | logout | forgot | reset_check | reset
 *                                        | change_password | update_profile
 *
 * How it works (for the group presentation):
 *  - Passwords are stored ONLY as password_hash() (salted bcrypt); verified with password_verify().
 *  - Sessions use strict mode, HttpOnly + SameSite=Lax cookies, and a new id on login/register/password change.
 *  - Every non-GET request must carry the X-CSRF-Token header (checked in bootstrap_api()).
 *  - 5 failed logins per identifier in 15 minutes locks login for that identifier (HTTP 429).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();

switch ((string) ($_GET['action'] ?? '')) {
    case 'me':              action_me(); break;
    case 'login':           action_login(); break;
    case 'register':        action_register(); break;
    case 'logout':          action_logout(); break;
    case 'forgot':          action_forgot(); break;
    case 'reset_check':     action_reset_check(); break;
    case 'reset':           action_reset(); break;
    case 'change_password': action_change_password(); break;
    case 'update_profile':  action_update_profile(); break;
    default:                fail('Unknown action.', 404);
}

/* ------------------------------------------------------------------ helpers */

function valid_email_address(string $email): bool
{
    return $email !== '' && text_len($email) <= 150 && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
}

/** Minutes the identifier is still locked out for (0 = not locked). */
function login_lockout_minutes(string $key): int
{
    $times = run_query(
        'SELECT attempted_at FROM login_attempts
         WHERE identifier = :i AND success = 0 AND attempted_at > :since ORDER BY attempted_at ASC',
        ['i' => $key, 'since' => time() - LOGIN_WINDOW_SECONDS]
    )->fetchAll(PDO::FETCH_COLUMN);
    if (count($times) < MAX_FAILED_LOGINS) {
        return 0;
    }
    // Locked until enough old failures age out of the window to leave fewer than MAX.
    $unlockAt = (int) $times[count($times) - MAX_FAILED_LOGINS] + LOGIN_WINDOW_SECONDS;
    return max(1, (int) ceil(($unlockAt - time()) / 60));
}

function record_login_attempt(string $key, bool $success): void
{
    run_query(
        'INSERT INTO login_attempts (identifier, ip, attempted_at, success) VALUES (:i, :ip, :t, :s)',
        ['i' => $key, 'ip' => client_ip(), 't' => time(), 's' => $success ? 1 : 0]
    );
}

function find_user_row_by_id(int $id): array
{
    $row = run_query('SELECT * FROM users WHERE user_id = :id', ['id' => $id])->fetch();
    if (!$row) {
        fail('Account not found.', 404);
    }
    return $row;
}

/* ------------------------------------------------------------------ actions */

function action_me(): void
{
    require_method('GET');
    json_out(['user' => current_user(), 'csrf_token' => csrf_token()], 'OK');
}

function action_login(): void
{
    require_method('POST');
    $in = request_input();
    $identifier = input_str($in, 'identifier');
    $password = input_raw($in, 'password');
    $remember = !empty($in['remember']);
    if ($identifier === '' || $password === '') {
        fail('Enter your username or email and your password.', 422);
    }

    $key = strtolower($identifier);
    $wait = login_lockout_minutes($key);
    if ($wait > 0) {
        fail('Too many failed attempts. Please try again in ' . $wait . ' minute' . ($wait === 1 ? '' : 's') . '.', 429);
    }

    // username / email columns are NOCASE, so this lookup is case-insensitive.
    $user = run_query(
        'SELECT * FROM users WHERE username = :a OR email = :b LIMIT 1',
        ['a' => $identifier, 'b' => $identifier]
    )->fetch();

    // Always run one password_verify so unknown accounts cost the same time as wrong passwords.
    $valid = password_verify($password, $user ? $user['password_hash'] : DUMMY_HASH);
    if (!$user || !$valid) {
        record_login_attempt($key, false);
        fail('Invalid username/email or password.', 401);
    }

    // Success: clear throttling, upgrade the hash if the algorithm/cost changed, start a fresh session.
    run_query(
        'DELETE FROM login_attempts WHERE success = 0 AND identifier IN (:a, :b)',
        ['a' => $key, 'b' => strtolower($user['username'])]
    );
    run_query('DELETE FROM login_attempts WHERE attempted_at < :old', ['old' => time() - 86400]);
    record_login_attempt($key, true);
    if (password_needs_rehash($user['password_hash'], PASSWORD_DEFAULT)) {
        run_query('UPDATE users SET password_hash = :h WHERE user_id = :id',
            ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => (int) $user['user_id']]);
    }
    run_query('UPDATE users SET last_login_at = :t WHERE user_id = :id', ['t' => now_utc(), 'id' => (int) $user['user_id']]);

    login_user((int) $user['user_id'], $remember);
    $user = find_user_row_by_id((int) $user['user_id']);
    json_out(['user' => public_user($user), 'csrf_token' => csrf_token()], 'Login successful.');
}

function action_register(): void
{
    require_method('POST');
    $in = request_input();          // any "role" sent by the client is ignored on purpose
    $fullName = input_str($in, 'full_name');
    $username = input_str($in, 'username');
    $email = input_str($in, 'email');
    $password = input_raw($in, 'password');
    $confirm = input_raw($in, 'confirm');

    $errors = [];
    if (text_len($fullName) < 2 || text_len($fullName) > 100) {
        $errors['full_name'] = 'Enter your full name (2-100 characters).';
    }
    if (!preg_match('/^[A-Za-z0-9_]{3,30}$/', $username)) {
        $errors['username'] = 'Username must be 3-30 characters: letters, numbers and underscores only.';
    }
    if (!valid_email_address($email)) {
        $errors['email'] = 'Enter a valid email address.';
    }
    if (($msg = password_policy_error($password)) !== null) {
        $errors['password'] = $msg;
    }
    if ($password !== $confirm) {
        $errors['confirm'] = 'Passwords do not match.';
    }
    if ($errors) {
        fail('Please fix the highlighted fields.', 422, $errors);
    }

    $dupes = [];
    if (run_query('SELECT 1 FROM users WHERE username = :u', ['u' => $username])->fetchColumn()) {
        $dupes['username'] = 'That username is already taken.';
    }
    if (run_query('SELECT 1 FROM users WHERE email = :e', ['e' => $email])->fetchColumn()) {
        $dupes['email'] = 'That email is already registered.';
    }
    if ($dupes) {
        fail('Username or email is already registered.', 409, $dupes);
    }

    try {
        run_query(
            "INSERT INTO users (full_name, username, email, password_hash, role, created_at)
             VALUES (:f, :u, :e, :h, 'MEMBER', :c)",
            ['f' => $fullName, 'u' => $username, 'e' => $email, 'h' => password_hash($password, PASSWORD_DEFAULT), 'c' => now_utc()]
        );
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {   // lost a race against another registration
            fail('Username or email is already registered.', 409);
        }
        throw $e;
    }
    $id = (int) db()->lastInsertId();
    login_user($id, false);
    json_out(['user' => public_user(find_user_row_by_id($id)), 'csrf_token' => csrf_token()], 'Account created. Welcome!', 201);
}

function action_logout(): void
{
    require_method('POST');
    logout_user();
    json_out(null, 'You have been logged out.');
}

/** Step 1 of password reset: always answers with the same generic message. */
function action_forgot(): void
{
    require_method('POST');
    $email = input_str(request_input(), 'email');
    if (!valid_email_address($email)) {
        fail('Enter a valid email address.', 422, ['email' => 'Enter a valid email address.']);
    }
    $generic = 'If that email is registered, a reset link has been sent.';

    $user = run_query('SELECT user_id, full_name, email FROM users WHERE email = :e', ['e' => $email])->fetch();
    if ($user) {
        $recent = (int) run_query(
            'SELECT COUNT(*) FROM password_resets WHERE user_id = :id AND created_at > :t',
            ['id' => (int) $user['user_id'], 't' => time() - 3600]
        )->fetchColumn();
        if ($recent < 5) {     // simple abuse limit: at most 5 links per hour per account
            $token = bin2hex(random_bytes(32));
            run_query(
                'INSERT INTO password_resets (user_id, token_hash, expires_at, created_at) VALUES (:u, :h, :x, :c)',
                ['u' => (int) $user['user_id'], 'h' => hash('sha256', $token), 'x' => time() + RESET_TTL_SECONDS, 'c' => time()]
            );
            $link = base_url() . '/#/reset?token=' . $token;
            write_mail_outbox($user['email'], 'Reset your Tribal Weave Archive password',
                "Hello {$user['full_name']},\n\nUse this single-use link within 30 minutes to choose a new password:\n$link\n\n"
                . "If you did not ask for this, you can ignore this message.");
        }
    }
    json_out(null, $generic);
}

function find_valid_reset(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
        return null;
    }
    $row = run_query(
        'SELECT reset_id, user_id FROM password_resets WHERE token_hash = :h AND used_at IS NULL AND expires_at > :now',
        ['h' => hash('sha256', $token), 'now' => time()]
    )->fetch();
    return $row ?: null;
}

function action_reset_check(): void
{
    require_method('GET');
    if (!find_valid_reset((string) ($_GET['token'] ?? ''))) {
        fail('This reset link is invalid or has expired. Please request a new one.', 400);
    }
    json_out(null, 'Token is valid.');
}

function action_reset(): void
{
    require_method('POST');
    $in = request_input();
    $reset = find_valid_reset(input_str($in, 'token'));
    if (!$reset) {
        fail('This reset link is invalid or has expired. Please request a new one.', 400);
    }
    $password = input_raw($in, 'password');
    $errors = [];
    if (($msg = password_policy_error($password)) !== null) {
        $errors['password'] = $msg;
    }
    if ($password !== input_raw($in, 'confirm')) {
        $errors['confirm'] = 'Passwords do not match.';
    }
    if ($errors) {
        fail('Please fix the highlighted fields.', 422, $errors);
    }

    $pdo = db();
    $pdo->beginTransaction();
    try {
        $userId = (int) $reset['user_id'];
        run_query('UPDATE users SET password_hash = :h WHERE user_id = :id',
            ['h' => password_hash($password, PASSWORD_DEFAULT), 'id' => $userId]);
        // Single use: burn this token and every other pending token of the user.
        run_query('DELETE FROM password_resets WHERE user_id = :id', ['id' => $userId]);
        $u = find_user_row_by_id($userId);
        run_query('DELETE FROM login_attempts WHERE success = 0 AND identifier IN (:a, :b)',
            ['a' => strtolower($u['username']), 'b' => strtolower($u['email'])]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    session_regenerate_id(true);
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    json_out(['csrf_token' => csrf_token()], 'Your password has been updated. You can now log in.');
}

function action_change_password(): void
{
    require_method('POST');
    $user = require_login();
    $in = request_input();
    $current = input_raw($in, 'current_password');
    $new = input_raw($in, 'new_password');

    $row = find_user_row_by_id($user['user_id']);
    if ($current === '' || !password_verify($current, $row['password_hash'])) {
        fail('Please fix the highlighted fields.', 422, ['current_password' => 'Your current password is incorrect.']);
    }
    $errors = [];
    if (($msg = password_policy_error($new)) !== null) {
        $errors['new_password'] = $msg;
    } elseif ($new === $current) {
        $errors['new_password'] = 'Choose a password different from your current one.';
    }
    if ($new !== input_raw($in, 'confirm_password')) {
        $errors['confirm_password'] = 'Passwords do not match.';
    }
    if ($errors) {
        fail('Please fix the highlighted fields.', 422, $errors);
    }

    run_query('UPDATE users SET password_hash = :h WHERE user_id = :id',
        ['h' => password_hash($new, PASSWORD_DEFAULT), 'id' => $user['user_id']]);
    run_query('DELETE FROM password_resets WHERE user_id = :id', ['id' => $user['user_id']]);
    session_regenerate_id(true);
    if (!empty($_SESSION['remember'])) {
        send_session_cookie(true);
    }
    $_SESSION['csrf'] = bin2hex(random_bytes(32));
    json_out(['csrf_token' => csrf_token()], 'Password changed successfully.');
}

function action_update_profile(): void
{
    require_method('POST');
    $user = require_login();
    $in = request_input();
    $fullName = input_str($in, 'full_name');
    $email = input_str($in, 'email');

    $errors = [];
    if (text_len($fullName) < 2 || text_len($fullName) > 100) {
        $errors['full_name'] = 'Enter your full name (2-100 characters).';
    }
    if (!valid_email_address($email)) {
        $errors['email'] = 'Enter a valid email address.';
    } elseif (run_query('SELECT 1 FROM users WHERE email = :e AND user_id <> :id', ['e' => $email, 'id' => $user['user_id']])->fetchColumn()) {
        $errors['email'] = 'That email is already registered to another account.';
    }
    if ($errors) {
        fail('Please fix the highlighted fields.', isset($errors['email']) && str_contains($errors['email'], 'already') ? 409 : 422, $errors);
    }
    try {
        run_query('UPDATE users SET full_name = :f, email = :e WHERE user_id = :id',
            ['f' => $fullName, 'e' => $email, 'id' => $user['user_id']]);
    } catch (PDOException $e) {
        if ($e->getCode() === '23000') {
            fail('That email is already registered to another account.', 409, ['email' => 'That email is already registered to another account.']);
        }
        throw $e;
    }
    json_out(['user' => current_user(true)], 'Profile updated.');
}
