<?php
declare(strict_types=1);
/**
 * file.php — authenticated streamer for uploaded request letters.
 *
 * Uploads are stored outside direct web access (uploads/.htaccess denies all);
 * this controller authorizes the requester with the same rules as ticket.php
 * before streaming the file. Accepts ?ticket_id= (submitted tickets) or
 * ?draft_id= (own private drafts).
 */
require_once __DIR__ . '/config/config.php';
Auth::start();

if (!Auth::refresh($pdo)) {
    http_response_code(401);
    exit('Not authenticated.');
}
$u = Auth::user();

$ticketId = (int) ($_GET['ticket_id'] ?? 0);
$draftId  = (int) ($_GET['draft_id'] ?? 0);

$path = null;
if ($ticketId > 0) {
    $st = $pdo->prepare('SELECT created_by, driver_id, attachment_path FROM trip_tickets WHERE ticket_id = ? AND is_active = 1');
    $st->execute([$ticketId]);
    $t = $st->fetch();
    if (!$t) {
        http_response_code(404);
        exit('Not found.');
    }
    $role = (int) $u['role_id'];
    $allowed = in_array($role, [1, 2, 3, 5], true)
        || ($role === 4 && (int) $t['driver_id'] === (int) $u['id'])
        || ($role === 6 && (int) $t['created_by'] === (int) $u['id']);
    if (!$allowed) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $path = $t['attachment_path'];
} elseif ($draftId > 0) {
    $st = $pdo->prepare('SELECT created_by, attachment_path FROM trip_drafts WHERE draft_id = ?');
    $st->execute([$draftId]);
    $d = $st->fetch();
    if (!$d) {
        http_response_code(404);
        exit('Not found.');
    }
    if ((int) $d['created_by'] !== (int) $u['id']) {
        http_response_code(403);
        exit('Forbidden.');
    }
    $path = $d['attachment_path'];
} else {
    http_response_code(400);
    exit('Missing ticket_id or draft_id.');
}

if (empty($path)) {
    http_response_code(404);
    exit('No file attached.');
}

// Resolve and confine to the uploads directory (path traversal guard).
$uploadsRoot = realpath(APP_ROOT . '/uploads');
$full = realpath(APP_ROOT . '/' . ltrim((string) $path, '/'));
if ($uploadsRoot === false || $full === false
    || !str_starts_with($full, $uploadsRoot . DIRECTORY_SEPARATOR) || !is_file($full)) {
    http_response_code(404);
    exit('File unavailable.');
}

$ext = strtolower((string) pathinfo($full, PATHINFO_EXTENSION));
$types = [
    'pdf'  => 'application/pdf',
    'png'  => 'image/png',
    'jpg'  => 'image/jpeg',
    'jpeg' => 'image/jpeg',
    'doc'  => 'application/msword',
    'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
];
$mime = $types[$ext] ?? 'application/octet-stream';
$name = basename($full);

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($full));
header('Content-Disposition: inline; filename="' . rawurlencode($name) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, no-store, max-age=0');

readfile($full);
