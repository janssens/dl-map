<?php

declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/settings.php';

function jobs_root(): string {
    return __DIR__ . '/../var/jobs';
}

function jobs_job_dir(string $jobId): string {
    return jobs_root() . '/' . $jobId;
}

function jobs_is_valid_job_id(string $jobId): bool {
    return (bool)preg_match('/^[a-f0-9]{16,64}$/', $jobId);
}

/**
 * @return array{id:string,user_id:int,settings_slug:string|null,source_job_id:string|null,deleted_at:string|null,deleted_by_user_id:int|null,created_at:string}
 */
function jobs_load_row(string $jobId): array {
    $stmt = db()->prepare('SELECT * FROM jobs WHERE id = ? LIMIT 1');
    $stmt->execute([$jobId]);
    $row = $stmt->fetch();
    if (!is_array($row)){
        throw new RuntimeException('Job introuvable');
    }
    /** @var array $row */
    return $row;
}

/**
 * @return array{id:string,user_id:int,settings_slug:string|null,source_job_id:string|null,deleted_at:string|null,deleted_by_user_id:int|null,created_at:string}
 */
function jobs_require_access(string $jobId, array $user): array {
    if (!jobs_is_valid_job_id($jobId)){
        http_response_code(400);
        echo "Invalid job id";
        exit;
    }
    try {
        $row = jobs_load_row($jobId);
    } catch (Throwable $e){
        http_response_code(404);
        echo "Not found";
        exit;
    }
    if (!empty($row['deleted_at'])){
        http_response_code(404);
        echo "Not found";
        exit;
    }
    $uid = (int)($user['id'] ?? 0);
    if (!auth_is_admin($user) && (int)($row['user_id'] ?? 0) !== $uid){
        http_response_code(403);
        echo "Forbidden";
        exit;
    }
    return $row;
}

function jobs_user_job_limit(?array $user): ?int {
    if (!is_array($user)){
        return null;
    }
    $tier = (string)($user['tier'] ?? 'free');
    if ($tier === 'free'){
        return 5;
    }
    return null;
}

function jobs_prune_missing_dirs_for_user(int $userId): void {
    $stmt = db()->prepare('SELECT id FROM jobs WHERE user_id = ? AND deleted_at IS NULL');
    $stmt->execute([$userId]);
    $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
    if (!is_array($ids) || count($ids) === 0){
        return;
    }
    $toMark = [];
    foreach ($ids as $id){
        $jid = (string)$id;
        if ($jid === ''){
            continue;
        }
        if (!is_dir(jobs_job_dir($jid))){
            $toMark[] = $jid;
        }
    }
    if (count($toMark) === 0){
        return;
    }
    $now = db_now();
    $stmtUpd = db()->prepare('UPDATE jobs SET deleted_at = ?, deleted_by_user_id = NULL WHERE id = ? AND deleted_at IS NULL');
    foreach ($toMark as $jid){
        $stmtUpd->execute([$now, $jid]);
    }
}

function jobs_count_active_for_user(array $user): int {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0){
        return 0;
    }
    jobs_prune_missing_dirs_for_user($uid);
    $stmt = db()->prepare('SELECT COUNT(*) FROM jobs WHERE user_id = ? AND deleted_at IS NULL');
    $stmt->execute([$uid]);
    return (int)$stmt->fetchColumn();
}

function jobs_insert(string $jobId, array $user, string $settingsSlug, ?string $sourceJobId = null, ?string $createdAt = null): void {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0){
        throw new RuntimeException('Invalid user');
    }
    if (!jobs_is_valid_job_id($jobId)){
        throw new RuntimeException('Invalid job id');
    }
    $settingsSlug = normalize_settings_id($settingsSlug);
    if ($settingsSlug === ''){
        $settingsSlug = null;
    }
    if ($createdAt === null || trim($createdAt) === ''){
        $createdAt = db_now();
    }
    $stmt = db()->prepare('INSERT INTO jobs(id,user_id,settings_slug,source_job_id,deleted_at,deleted_by_user_id,created_at) VALUES(?,?,?,?,NULL,NULL,?)');
    $stmt->execute([$jobId, $uid, $settingsSlug, $sourceJobId, $createdAt]);
}

/**
 * @return array<int, array>
 */
function jobs_list_for_user(array $user, int $limit = 200): array {
    $uid = (int)($user['id'] ?? 0);
    if ($uid <= 0){
        return [];
    }
    $limit = max(1, min(500, $limit));
    $stmt = db()->prepare('SELECT * FROM jobs WHERE user_id = ? AND deleted_at IS NULL ORDER BY created_at DESC LIMIT ' . (int)$limit);
    $stmt->execute([$uid]);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

/**
 * @return array<int, array>
 */
function jobs_admin_list_all(?string $userFilter = null, int $limit = 500): array {
    $limit = max(1, min(1000, $limit));
    $params = [];
    $where = ['j.deleted_at IS NULL'];
    $userFilter = $userFilter !== null ? trim($userFilter) : null;
    if ($userFilter !== null && $userFilter !== ''){
        if (ctype_digit($userFilter)){
            $where[] = '(u.id = ? OR u.email LIKE ?)';
            $params[] = (int)$userFilter;
            $params[] = '%' . $userFilter . '%';
        } else {
            $where[] = '(u.email LIKE ?)';
            $params[] = '%' . $userFilter . '%';
        }
    }
    $sql = 'SELECT j.*, u.email AS user_email, u.first_name AS user_first_name, u.last_name AS user_last_name, u.tier AS user_tier
            FROM jobs j
            JOIN users u ON u.id = j.user_id
            WHERE ' . implode(' AND ', $where) . '
            ORDER BY j.created_at DESC
            LIMIT ' . (int)$limit;
    $stmt = db()->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    return is_array($rows) ? $rows : [];
}

function jobs_read_status(string $jobId): array {
    $path = jobs_job_dir($jobId) . '/status.json';
    if (!is_file($path)){
        return ['state' => 'starting', 'message' => 'Initialisation…'];
    }
    $raw = file_get_contents($path);
    if ($raw === false){
        return ['state' => 'error', 'error' => 'Cannot read status'];
    }
    $json = json_decode($raw, true);
    return is_array($json) ? $json : ['state' => 'error', 'error' => 'Invalid status json'];
}

function jobs_delete_dir(string $dir): void {
    $dir = rtrim($dir, '/');
    $root = rtrim(jobs_root(), '/');
    if ($dir === '' || $root === '' || !str_starts_with($dir, $root . '/')){
        throw new RuntimeException('Refusing to delete outside jobs root');
    }
    if (!is_dir($dir)){
        return;
    }
    $it = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST
    );
    foreach ($it as $p){
        $path = $p->getPathname();
        if (is_dir($path)){
            @rmdir($path);
        } else {
            @unlink($path);
        }
    }
    @rmdir($dir);
}

function jobs_delete_job(string $jobId, array $actor): void {
    $row = jobs_require_access($jobId, $actor);
    $dir = jobs_job_dir($jobId);
    jobs_delete_dir($dir);
    $stmt = db()->prepare('UPDATE jobs SET deleted_at = ?, deleted_by_user_id = ? WHERE id = ? AND deleted_at IS NULL');
    $stmt->execute([db_now(), (int)($actor['id'] ?? 0), (string)$row['id']]);
}
