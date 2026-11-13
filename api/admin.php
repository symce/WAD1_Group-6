<?php
/**
 * Admin API (ADMIN only).   admin.php?action=stats  |  admin.php?action=members
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();
require_role('ADMIN');   // 401 for guests, 403 for members

switch ((string) ($_GET['action'] ?? '')) {
    case 'stats':
        require_method('GET');
        $w = run_query("SELECT COUNT(*) AS total,
                COALESCE(SUM(status = 'PENDING'), 0)  AS pending,
                COALESCE(SUM(status = 'APPROVED'), 0) AS approved,
                COALESCE(SUM(status = 'REJECTED'), 0) AS rejected
            FROM weaves")->fetch();
        $members = (int) run_query("SELECT COUNT(*) FROM users WHERE role = 'MEMBER'")->fetchColumn();
        json_out([
            'total'    => (int) $w['total'],
            'pending'  => (int) $w['pending'],
            'approved' => (int) $w['approved'],
            'rejected' => (int) $w['rejected'],
            'members'  => $members,
        ], 'OK');
        break;

    case 'members':
        require_method('GET');
        $rows = run_query("SELECT u.user_id, u.full_name, u.username, u.email, u.created_at,
                COUNT(w.weave_id) AS total_contributions,
                COALESCE(SUM(w.status = 'APPROVED'), 0) AS approved_contributions
            FROM users u
            LEFT JOIN weaves w ON w.submitted_by = u.user_id
            WHERE u.role = 'MEMBER'
            GROUP BY u.user_id
            ORDER BY u.user_id DESC")->fetchAll();
        foreach ($rows as &$r) {
            $r['user_id'] = (int) $r['user_id'];
            $r['total_contributions'] = (int) $r['total_contributions'];
            $r['approved_contributions'] = (int) $r['approved_contributions'];
        }
        unset($r);
        json_out(['members' => $rows], 'OK');
        break;

    default:
        fail('Unknown action.', 404);
}
