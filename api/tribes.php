<?php
/**
 * Tribes API.   tribes.php?action=list[&all=1]  |  tribes.php?action=get&id=N
 * "list" returns the featured tribes (or every tribe with all=1, used by the submit-form datalist).
 * "get" returns one tribe plus its APPROVED weaves (JOIN) .
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/helpers.php';
bootstrap_api();

const TRIBE_SELECT = "SELECT t.tribe_id, t.tribe_name, t.location, t.description, t.history, t.cultural_background,
        t.traditional_practices, t.cultural_significance, t.image, t.is_featured,
        (SELECT COUNT(*) FROM weaves w WHERE w.tribe_id = t.tribe_id AND w.status = 'APPROVED') AS weave_count
    FROM tribes t";

function format_tribe(array $t): array
{
    $t['tribe_id'] = (int) $t['tribe_id'];
    $t['is_featured'] = (int) $t['is_featured'];
    $t['weave_count'] = (int) $t['weave_count'];
    return $t;
}

switch ((string) ($_GET['action'] ?? '')) {
    case 'list':
        require_method('GET');
        $sql = !empty($_GET['all'])
            ? TRIBE_SELECT . ' ORDER BY t.tribe_name COLLATE NOCASE'
            : TRIBE_SELECT . ' WHERE t.is_featured = 1 ORDER BY t.tribe_id';
        json_out(['tribes' => array_map('format_tribe', run_query($sql)->fetchAll())], 'OK');
        break;

    case 'get':
        require_method('GET');
        $id = valid_id($_GET['id'] ?? null);
        $row = $id === null ? false : run_query(TRIBE_SELECT . ' WHERE t.tribe_id = :id', ['id' => $id])->fetch();
        if (!$row) {
            fail('Tribe not found.', 404);
        }
        $weaves = query_weaves(["w.status = 'APPROVED'", 'w.tribe_id = :tid'], ['tid' => $id]);
        json_out(['tribe' => format_tribe($row), 'weaves' => $weaves], 'OK');
        break;

    default:
        fail('Unknown action.', 404);
}
