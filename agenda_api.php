<?php
require_once 'includes/session_check.php';
require_once 'includes/db.php';
$db = getDb();

$data = json_decode(file_get_contents('php://input'), true);
if(!isset($data['action'])) exit;

if($data['action'] === 'add') {
    $dates = $data['dates'] ?? [];
    $event_type = $data['event_type'] ?? '';
    $collaborators = $data['collaborators'] ?? [];

    foreach($dates as $date) {
        $db->beginTransaction();
        $stmt = $db->prepare("INSERT INTO calendar_events(event_date, event_type) VALUES (?, ?) RETURNING id");
        $stmt->execute([$date, $event_type]);
        $event_id = $stmt->fetchColumn();
        foreach($collaborators as $cid) {
            $db->prepare("INSERT INTO calendar_event_collaborators(event_id, collaborator_id) VALUES (?, ?)")->execute([$event_id, $cid]);
        }
        $db->commit();
    }
    http_response_code(204); // No Content
    exit;
}

if($data['action'] === 'delete') {
    $event_id = $data['event_id'] ?? 0;
    $db->prepare("DELETE FROM calendar_events WHERE id=?")->execute([$event_id]);
    http_response_code(204);
    exit;
}
