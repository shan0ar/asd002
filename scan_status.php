<?php
require_once 'includes/db.php';
require_once 'includes/session_check.php';
$db = getDb();
$scan_id = intval($_GET['scan_id']);
$stmt = $db->prepare("SELECT status FROM scans WHERE id=?");
$stmt->execute([$scan_id]);
$status = $stmt->fetchColumn();
header('Content-Type: application/json');
echo json_encode(['status' => $status ?: 'unknown']);
