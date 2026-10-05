<?php
/** Endpoint kecil untuk cek bentrok jadwal secara langsung di formulir. */
require_once __DIR__ . '/../inc/bootstrap.php';
require_once __DIR__ . '/../inc/auth.php';
require_once __DIR__ . '/../inc/meeting.php';
requireLogin();

header('Content-Type: application/json');
$start = $_GET['start'] ?? '';
$dur   = max(15, (int) ($_GET['dur'] ?? 60));
$id    = (int) ($_GET['id'] ?? 0);

if (!strtotime($start)) { echo json_encode(['conflicts' => []]); exit; }

$s = date('Y-m-d H:i:s', strtotime($start));
$e = date('Y-m-d H:i:s', strtotime($start) + $dur * 60);

$out = array_map(fn($c) => [
    'id'    => (int) $c['id'],
    'label' => $c['client_name'] . ' (' . date('H.i', strtotime($c['start_at'])) . '–' . date('H.i', strtotime($c['end_at'])) . ')',
], meetingConflicts($s, $e, $id ?: null));

echo json_encode(['conflicts' => $out]);
