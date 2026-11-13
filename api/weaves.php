<?php
/**
 * Weaves API.   weaves.php?action=list | get | create | update | delete | approve | reject
 *
 * Workflow: MEMBER creates -> PENDING -> ADMIN approves (public) or rejects with feedback ->
 * MEMBER revises & resubmits -> PENDING again.  ADMIN-created records are APPROVED immediately.
 * All writes are POST (multipart/form-data when an image is attached) and need the CSRF header.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();

switch ((string) ($_GET['action'] ?? '')) {
    case 'list':    action_list(); break;
    case 'get':     action_get(); break;
    case 'create':  action_create(); break;
    case 'update':  action_update(); break;
    case 'delete':  action_delete(); break;
    case 'approve': action_approve(); break;
    case 'reject':  action_reject(); break;
    default:        fail('Unknown action.', 404);
}

function weave_id_from_query(): int
{
    $id = valid_id($_GET['id'] ?? null);
    if ($id === null) {
        fail('Weaving record not found.', 404);
    }
    return $id;
}

function load_weave_or_404(): array
{
    $weave = fetch_weave(weave_id_from_query());
    if (!$weave) {
        fail('Weaving record not found.', 404);
    }
    return $weave;
}

function can_view_weave(array $w, ?array $user): bool
{
    return $w['status'] === 'APPROVED'
        || ($user !== null && ($user['role'] === 'ADMIN' || $w['submitted_by'] === $user['user_id']));
}

function action_list(): void
{
    require_method('GET');
    $user = current_user();
    $isAdmin = $user !== null && $user['role'] === 'ADMIN';
    $where = [];
    $params = [];

    $mine = !empty($_GET['mine']);
    if ($mine) {
        $user = require_login();
        $where[] = 'w.submitted_by = :mine';
        $params['mine'] = $user['user_id'];
    }

    // Visibility: everybody sees APPROVED. Own records (mine=1) and admins may ask for other statuses.
    $status = strtoupper(is_string($_GET['status'] ?? null) ? trim($_GET['status']) : '');
    if ($status === '') {
        $status = $mine ? 'ALL' : 'APPROVED';
    }
    if (!in_array($status, ['ALL', 'PENDING', 'APPROVED', 'REJECTED'], true)) {
        fail('Invalid status filter.', 422);
    }
    if ($status !== 'APPROVED' && !$mine) {
        require_role('ADMIN');
    }
    if ($status !== 'ALL') {
        $where[] = 'w.status = :status';
        $params['status'] = $status;
    }

    $q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
    if ($q !== '') {
        $q = function_exists('mb_substr') ? mb_substr($q, 0, 100) : substr($q, 0, 100);
        $like = '%' . str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $q) . '%';
        $where[] = "(w.name LIKE :q1 ESCAPE '!' OR t.tribe_name LIKE :q2 ESCAPE '!' OR r.region_name LIKE :q3 ESCAPE '!' OR w.location LIKE :q4 ESCAPE '!')";
        foreach (['q1', 'q2', 'q3', 'q4'] as $k) {
            $params[$k] = $like;
        }
    }
    foreach (['region' => 'r.region_name', 'medium' => 'm.medium_name'] as $key => $column) {
        $val = is_string($_GET[$key] ?? null) ? trim($_GET[$key]) : '';
        if ($val !== '' && $val !== 'All') {
            $where[] = $column . ' = :' . $key;
            $params[$key] = $val;
        }
    }
    if (isset($_GET['tribe_id']) && $_GET['tribe_id'] !== '') {
        $tid = valid_id($_GET['tribe_id']);
        if ($tid === null) {
            fail('Invalid tribe filter.', 422);
        }
        $where[] = 'w.tribe_id = :tribe_id';
        $params['tribe_id'] = $tid;
    }

    $limit = isset($_GET['limit']) ? max(1, min(200, (int) $_GET['limit'])) : null;
    $newest = ($_GET['sort'] ?? '') === 'newest';
    json_out([
        'weaves' => query_weaves($where, $params, $newest, $limit),
        'total'  => count_weaves($where, $params),
    ], 'OK');
}

function action_get(): void
{
    require_method('GET');
    $weave = load_weave_or_404();
    if (!can_view_weave($weave, current_user())) {
        fail('Weaving record not found.', 404);   // do not reveal that a hidden record exists
    }
    json_out(['weave' => $weave], 'OK');
}

function action_create(): void
{
    require_method('POST');
    $user = require_login();
    [$v, $errors] = validate_weave_input(request_input());
    if (($imgError = upload_error('image')) !== null) {
        $errors['image'] = $imgError;
    }
    if ($errors) {
        fail('Please fix the highlighted fields.', 422, $errors);
    }

    $isAdmin = $user['role'] === 'ADMIN';
    $status = $isAdmin ? 'APPROVED' : 'PENDING';     // admin-added records are published directly
    $image = save_uploaded_image('image');
    $pdo = db();

    try {
        $weaveId = 0;
        for ($attempt = 0; $attempt < 3; $attempt++) {   // retry if two people got the same catalog code
            $pdo->beginTransaction();
            try {
                $tribeId = find_or_create_tribe($v['tribe'], $v['loc'], $image ?? DEFAULT_IMAGE);
                run_query(
                    'INSERT INTO weaves (catalog_code, tribe_id, region_id, medium_id, level_id, name, location, fiber, dyes, loom,
                        cultural_significance, motifs, doc_source, fpic, image, status, admin_feedback, submitted_by, created_at, updated_at)
                     VALUES (:code, :tribe, :region, :medium, :level, :name, :loc, :fiber, :dyes, :loom,
                        :culture, :motifs, :doc, :fpic, :image, :status, :feedback, :by, :now, :now2)',
                    [
                        'code' => next_catalog_code(), 'tribe' => $tribeId, 'region' => $v['region_id'], 'medium' => $v['medium_id'],
                        'level' => $v['vuln_id'], 'name' => $v['name'], 'loc' => $v['loc'], 'fiber' => $v['fiber'], 'dyes' => $v['dyes'],
                        'loom' => $v['loom'], 'culture' => $v['culture'], 'motifs' => $v['motifs'], 'doc' => $v['docSource'],
                        'fpic' => $v['fpic'], 'image' => $image ?? DEFAULT_IMAGE, 'status' => $status,
                        'feedback' => $isAdmin ? 'Added directly by an administrator.' : '', 'by' => $user['user_id'],
                        'now' => now_utc(), 'now2' => now_utc(),
                    ]
                );
                $weaveId = (int) $pdo->lastInsertId();
                if ($isAdmin) {
                    run_query("INSERT INTO review_logs (weave_id, admin_id, action, feedback) VALUES (:w, :a, 'APPROVE', :f)",
                        ['w' => $weaveId, 'a' => $user['user_id'], 'f' => 'Added directly by an administrator.']);
                }
                $pdo->commit();
                break;
            } catch (PDOException $e) {
                $pdo->rollBack();
                if ($e->getCode() !== '23000' || $attempt === 2) {
                    throw $e;
                }
            }
        }
    } catch (Throwable $e) {
        delete_uploaded_image($image);
        throw $e;
    }
    json_out(['weave' => fetch_weave($weaveId)],
        $isAdmin ? 'Record added and published to the archive.' : 'Record saved with PENDING status and sent for admin review.', 201);
}

function action_update(): void
{
    require_method('POST');
    $user = require_login();
    $weave = load_weave_or_404();
    $isAdmin = $user['role'] === 'ADMIN';

    if (!$isAdmin) {
        if ($weave['submitted_by'] !== $user['user_id']) {
            fail('You can only edit your own submissions.', 403);
        }
        if ($weave['status'] === 'APPROVED') {
            fail('Approved records can no longer be edited. Please contact an administrator.', 403);
        }
    }

    [$v, $errors] = validate_weave_input(request_input());
    if (($imgError = upload_error('image')) !== null) {
        $errors['image'] = $imgError;
    }
    if ($errors) {
        fail('Please fix the highlighted fields.', 422, $errors);
    }

    $newImage = save_uploaded_image('image');
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $tribeId = find_or_create_tribe($v['tribe'], $v['loc'], $newImage ?? $weave['image']);
        // Members: editing sends the record back to PENDING (old feedback is cleared; history stays in review_logs).
        $status = $isAdmin ? $weave['status'] : 'PENDING';
        $feedback = $isAdmin ? $weave['admin_feedback'] : '';
        run_query(
            'UPDATE weaves SET tribe_id = :tribe, region_id = :region, medium_id = :medium, level_id = :level, name = :name,
                location = :loc, fiber = :fiber, dyes = :dyes, loom = :loom, cultural_significance = :culture, motifs = :motifs,
                doc_source = :doc, fpic = :fpic, image = :image, status = :status, admin_feedback = :feedback, updated_at = :now
             WHERE weave_id = :id',
            [
                'tribe' => $tribeId, 'region' => $v['region_id'], 'medium' => $v['medium_id'], 'level' => $v['vuln_id'],
                'name' => $v['name'], 'loc' => $v['loc'], 'fiber' => $v['fiber'], 'dyes' => $v['dyes'], 'loom' => $v['loom'],
                'culture' => $v['culture'], 'motifs' => $v['motifs'], 'doc' => $v['docSource'], 'fpic' => $v['fpic'],
                'image' => $newImage ?? $weave['image'], 'status' => $status, 'feedback' => $feedback,
                'now' => now_utc(), 'id' => $weave['weave_id'],
            ]
        );
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        delete_uploaded_image($newImage);
        throw $e;
    }
    if ($newImage !== null) {
        delete_uploaded_image($weave['image']);
    }
    json_out(['weave' => fetch_weave($weave['weave_id'])],
        $isAdmin ? 'Record updated.' : 'Submission revised and sent to the admin with PENDING status.');
}

function action_delete(): void
{
    require_method('POST');
    $user = require_login();
    $weave = load_weave_or_404();
    if ($user['role'] !== 'ADMIN') {
        if ($weave['submitted_by'] !== $user['user_id']) {
            fail('You can only delete your own submissions.', 403);
        }
        if ($weave['status'] === 'APPROVED') {
            fail('Approved records can only be deleted by an administrator.', 403);
        }
    }
    run_query('DELETE FROM weaves WHERE weave_id = :id', ['id' => $weave['weave_id']]);   // review_logs cascade
    delete_uploaded_image($weave['image']);
    json_out(null, 'Record deleted.');
}

function review_weave(string $decision, ?string $feedback): array
{
    $admin = require_role('ADMIN');
    $weave = load_weave_or_404();
    if ($weave['status'] !== 'PENDING') {
        fail('Only pending records can be reviewed.', 409);
    }
    $newStatus = $decision === 'APPROVE' ? 'APPROVED' : 'REJECTED';
    $pdo = db();
    $pdo->beginTransaction();
    try {
        run_query('UPDATE weaves SET status = :s, admin_feedback = :f, updated_at = :now WHERE weave_id = :id',
            ['s' => $newStatus, 'f' => $feedback, 'now' => now_utc(), 'id' => $weave['weave_id']]);
        run_query('INSERT INTO review_logs (weave_id, admin_id, action, feedback) VALUES (:w, :a, :act, :f)',
            ['w' => $weave['weave_id'], 'a' => $admin['user_id'], 'act' => $decision, 'f' => $feedback]);
        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }
    return fetch_weave($weave['weave_id']);
}

function action_approve(): void
{
    require_method('POST');
    $w = review_weave('APPROVE', 'Verified and approved for public archival display.');
    json_out(['weave' => $w], 'Record approved and published.');
}

function action_reject(): void
{
    require_method('POST');
    require_role('ADMIN');
    $feedback = input_str(request_input(), 'feedback');
    if ($feedback === '') {
        fail('Please provide rejection feedback so the Member can revise their submission.', 422,
            ['feedback' => 'Feedback is required when rejecting a record.']);
    }
    if (text_len($feedback) > 1000) {
        fail('Feedback is too long.', 422, ['feedback' => 'Feedback must be at most 1000 characters.']);
    }
    $w = review_weave('REJECT', $feedback);
    json_out(['weave' => $w], 'Record rejected and feedback sent to the member.');
}
