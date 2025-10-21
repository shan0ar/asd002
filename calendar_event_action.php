<?php
require_once 'includes/session_check.php';
require_once 'includes/db.php';
$db = getDb();

$data = json_decode(file_get_contents('php://input'), true);
if (!$data) die(json_encode(['success'=>false,'error'=>'Bad JSON']));

if ($data['action']==='add') {
    $dates = $data['selected_dates'] ?: [$data['event_date']];
    $res = [];
    foreach($dates as $date) {
        // Insertion de l'événement
        $stmt = $db->prepare("INSERT INTO calendar_events (event_date, start_time, end_time, event_type) VALUES (?, ?, ?, ?) RETURNING id");
        $stmt->execute([$date, $data['start_time'] ?: null, $data['end_time'] ?: null, $data['event_type']]);
        $event_id = $stmt->fetchColumn();
        // Lier les collaborateurs
        if (!empty($data['collaborators'])) {
            foreach($data['collaborators'] as $cid) {
                $db->prepare("INSERT INTO calendar_event_collaborators (event_id, collaborator_id) VALUES (?, ?)")->execute([$event_id, $cid]);
            }
        }
        $res[] = $event_id;
    }
    echo json_encode(['success'=>true, 'ids'=>$res]);
    exit;
}

if ($data['action'] === 'delete') {
    $stmt = $db->prepare("DELETE FROM calendar_events WHERE id = ?");
    $stmt->execute([$data['id']]);
    echo json_encode(['success'=>true]);
    exit;
}
echo json_encode(['success'=>false,'error'=>'Unknown action']);
