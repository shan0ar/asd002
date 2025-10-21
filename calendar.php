<?php
session_start();
require_once 'includes/db.php';
$db = getDb();

// Récupération des clients existants
$clients = $db->query("SELECT name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// -- GESTION AJAX (insert, delete, fetch, clients) --
if (isset($_GET['action'])) {
    header('Content-Type: application/json');
    if ($_GET['action'] === 'fetch') {
        $stmt = $db->prepare("SELECT e.id, e.event_date, e.event_type, e.start_time, e.end_time, e.client,
            array_agg(c.name) AS collaborators
            FROM calendar_events e
            LEFT JOIN calendar_event_collaborators ec ON ec.event_id = e.id
            LEFT JOIN collaborators c ON c.id = ec.collaborator_id
            WHERE e.event_date BETWEEN :start AND :end
            GROUP BY e.id
        ");
        $stmt->execute([
            ':start' => $_GET['start'] ?? date('Y-m-01'),
            ':end' => $_GET['end'] ?? date('Y-m-t')
        ]);
        $events = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $collabs = array_filter(explode(',', trim($row['collaborators'], '{}')));
            $title = $row['event_type'];
            if (!empty($collabs)) $title .= " (" . implode(', ', $collabs) . ")";
            if ($row['client']) $title .= " [" . $row['client'] . "]";
            if ($row['start_time']) {
                $start = $row['event_date'] . 'T' . $row['start_time'];
                $allDay = false;
            } else {
                $start = $row['event_date'];
                $allDay = true;
            }
            $event = [
                'id' => $row['id'],
                'title' => $title,
                'start' => $start,
                'allDay' => (bool)$allDay,
                'event_type' => $row['event_type'],
                'collaborators' => $collabs,
                'start_time' => $row['start_time'],
                'end_time' => $row['end_time'],
                'event_date' => $row['event_date'],
                'client' => $row['client'],
            ];
            if (!$allDay && $row['end_time']) {
                $event['end'] = $row['event_date'] . 'T' . $row['end_time'];
            }
            $events[] = $event;
        }
        echo json_encode($events); exit;
    }
    elseif ($_GET['action'] === 'add') {
        $data = json_decode(file_get_contents('php://input'), true);
        $event_type = $data['event_type'];
        $collaborators = $data['collaborators'] ?? [];
        $client = isset($data['client']) && $data['client'] !== '' ? $data['client'] : null;
        // Ajout du client à la volée si non existant
        if ($client) {
            $client_exists = $db->query("SELECT 1 FROM clients WHERE name=" . $db->quote($client))->fetchColumn();
            if (!$client_exists) {
                $db->prepare("INSERT INTO clients(name) VALUES (?)")->execute([$client]);
            }
        }
        $start_time = !empty($data['start_time']) ? $data['start_time'] : null;
        $end_time = !empty($data['end_time']) ? $data['end_time'] : null;
        $dates = $data['dates'] ?? [];

        $ids = [];
        foreach ($dates as $date) {
            $stmt = $db->prepare("INSERT INTO calendar_events(event_date, event_type, start_time, end_time, client) VALUES (?, ?, ?, ?, ?) RETURNING id");
            $stmt->execute([$date, $event_type, $start_time, $end_time, $client]);
            $event_id = $stmt->fetchColumn();
            if ($collaborators) {
                foreach ($collaborators as $cname) {
                    $cid = $db->query("SELECT id FROM collaborators WHERE name=" . $db->quote($cname))->fetchColumn();
                    if ($cid) {
                        $db->prepare("INSERT INTO calendar_event_collaborators(event_id, collaborator_id) VALUES (?, ?)")->execute([$event_id, $cid]);
                    }
                }
            }
            $ids[] = $event_id;
        }
        echo json_encode(['success'=>true, 'ids'=>$ids]); exit;
    }
    elseif ($_GET['action'] === 'delete') {
        $data = json_decode(file_get_contents('php://input'), true);
        if (!empty($data['id'])) {
            $stmt = $db->prepare("DELETE FROM calendar_events WHERE id=?");
            $stmt->execute([$data['id']]);
            echo json_encode(['success'=>true]); exit;
        }
        echo json_encode(['success'=>false]); exit;
    }
    elseif ($_GET['action'] === 'clients') {
        $clis = $db->query("SELECT name FROM clients ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode($clis); exit;
    }
    exit;
}

$collaborators = $db->query("SELECT name FROM collaborators ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
$event_types = [
    "Test d'intrusion", "Audit darkweb", "Audit d'exposition", "Audit Office365", "Audit Active Directory"
];
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Calendrier / Agenda</title>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.css">
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f4f7fa; }
        #calendar { max-width: 1200px; margin: 40px auto; background: #fff; box-shadow:0 2px 16px #b1b6c9b8; border-radius:16px; padding:28px; }
        .fc-toolbar-title { font-size: 2.1rem; color: #23396a; font-weight: 600; }
        .fc-button, .fc-button-primary { background: #2957a4; border: none; border-radius: 6px; color: #fff; transition: background 0.1s; }
        .fc-button:hover, .fc-button-primary:hover { background: #19386a; }
        .fc-day-today { background: #d8eafe !important; }
        .fc-event, .event-block { background: linear-gradient(90deg, #6f99ea 0%, #4ed6e2 100%) !important; color:#fff; border: none; border-radius:6px; font-weight:500; padding: .1em .3em;}
        .fc-daygrid-event-dot { border-color: #2957a4 !important; }
        .fc-daygrid-event:hover, .fc-timegrid-event:hover, .event-block:hover { filter: brightness(1.13); box-shadow:0 2px 8px #4ed6e244; }
        .modal-bg { display:none; position:fixed; top:0;left:0;right:0;bottom:0; z-index:10; background:rgba(27, 44, 73, 0.24);}
        .modal { display:none; position:fixed; top:10%;left:0;right:0;margin:auto; width:480px; background:#f8fafd; border-radius:14px; box-shadow:0 0 24px #23396a99; z-index:11; padding:34px; border: 1px solid #d6e0fa;}
        .modal.active, .modal-bg.active { display:block; }
        .modal h3 { margin-top:0; color: #2957a4;}
        .event-block { margin:7px 0; padding:8px 12px; font-size:1.06em;}
        .event-block span { font-size:0.96em; }
        .event-delete { float:right; color:#d93e61; cursor:pointer; font-weight:bold; font-size:1.3em;}
        .form-row { margin-bottom:14px;}
        .form-row label { display:block; font-size:1.07em; color:#23396a;}
        .form-row input[type="text"], .form-row select, .form-row input[type="time"] {
            width:100%; padding:7px 10px; border-radius:7px; border:1px solid #b1b6c9; font-size:1em; background:#fafdff; margin-top:4px; }
        .form-row-collabs label {margin-right:10px; display:inline-block;}
        button { padding:10px 22px; border:none; border-radius:6px; background: linear-gradient(90deg, #2957a4 0%, #4ed6e2 100%); color:#fff; font-weight:600; cursor:pointer; font-size:1em;}
        button:disabled { background:#888;}
        #client-select { display:flex; gap:6px; }
        #client { flex:1; }
        #add-client-btn { background:#4ed6e2; color:#2957a4; border:none; border-radius:5px; font-weight:600; padding:0 13px; font-size:1em; cursor:pointer; transition:background 0.15s;}
        #add-client-btn:hover { background: #2957a4; color:#fff; }
        #client-new { flex:1; }
        @media (max-width: 600px) {.modal {width:96vw;}}
    </style>
</head>
<body>
    <div id="calendar"></div>
    <div class="modal-bg" id="modal-bg"></div>
    <div class="modal" id="modal">
        <h3 id="modal-title">Évènements du <span id="modal-date"></span></h3>
        <div id="modal-events"></div>
        <form id="add-event-form" style="margin-top:18px;" autocomplete="off">
            <div class="form-row">
                <label for="event-type">Type d'évènement:</label>
                <select id="event-type" required>
                    <?php foreach($event_types as $t): ?>
                        <option value="<?=htmlspecialchars($t)?>"><?=$t?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-row">
                <label for="client">Client (optionnel) :</label>
                <div id="client-select">
                    <select id="client" name="client">
                        <option value="">— Aucun —</option>
                        <?php foreach($clients as $cli): ?>
                            <option value="<?=htmlspecialchars($cli)?>"><?=$cli?></option>
                        <?php endforeach; ?>
                    </select>
                    <input type="text" id="client-new" placeholder="Ajouter client..." style="display:none;flex:1;">
                    <button type="button" id="add-client-btn" title="Ajouter un nouveau client">+</button>
                </div>
            </div>
            <div class="form-row form-row-collabs">
                <label>Collaborateurs : </label>
                <?php foreach($collaborators as $c): ?>
                    <label><input type="checkbox" name="collaborators[]" value="<?=htmlspecialchars($c)?>"> <?=$c?></label>
                <?php endforeach; ?>
            </div>
            <div class="form-row">
                <label for="start-time">Heure début (optionnel):</label>
                <input type="time" id="start-time" name="start-time">
            </div>
            <div class="form-row">
                <label for="end-time">Heure fin (optionnel):</label>
                <input type="time" id="end-time" name="end-time">
            </div>
            <input type="hidden" id="selected-dates" name="selected-dates">
            <button type="submit" id="submit-btn">Ajouter l'événement</button>
        </form>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/fullcalendar@5.11.3/main.min.js"></script>
    <script>
    let calendar;
    let selectedDates = [];
    let lastClickedDate = null;

    function padTime(n) {
        return n.toString().padStart(2, '0');
    }

    function setTimeInputsFromDates(startDateObj, endDateObj) {
        let startH = padTime(startDateObj.getHours()) + ':' + padTime(startDateObj.getMinutes());
        let endH = padTime(endDateObj.getHours()) + ':' + padTime(endDateObj.getMinutes());
        document.getElementById('start-time').value = startH;
        document.getElementById('end-time').value = endH;
    }

    function getDateArrayInclusive(startStr, endStr) {
        let arr = [];
        let current = new Date(startStr);
        let end = new Date(endStr);
        while (current <= end) {
            arr.push(current.toISOString().slice(0, 10));
            current.setDate(current.getDate() + 1);
        }
        return arr;
    }

    document.addEventListener('DOMContentLoaded', function() {
        calendar = new FullCalendar.Calendar(document.getElementById('calendar'), {
            locale: 'fr',
            firstDay: 1,
            initialView: 'dayGridMonth',
            selectable: true,
            selectMirror: true,
            selectLongPressDelay: 50,
            slotDuration: '00:30:00',
            headerToolbar: {
                left: 'prev,next today',
                center: 'title',
                right: 'dayGridMonth,timeGridWeek'
            },
            events: 'calendar.php?action=fetch',
            select: function(info) {
                selectedDates = [];
                let isTimed = !!info.startStr.match(/T/);
                if (isTimed) {
                    let startDateObj = new Date(info.startStr);
                    let endDateObj = new Date(info.endStr);
                    let startDate = info.startStr.slice(0,10);
                    selectedDates = [startDate];
                    setTimeout(function() {
                        setTimeInputsFromDates(startDateObj, endDateObj);
                    }, 0);
                } else {
                    let startDate = info.startStr;
                    let endDateObj = new Date(info.endStr);
                    endDateObj.setDate(endDateObj.getDate() - 1);
                    let endDate = endDateObj.toISOString().slice(0,10);
                    selectedDates = getDateArrayInclusive(startDate, endDate);
                    setTimeout(function() {
                        document.getElementById('start-time').value = "08:30";
                        document.getElementById('end-time').value = "18:00";
                    }, 0);
                }
                showModal(selectedDates);
            },
            dateClick: function(info) {
                selectedDates = [info.dateStr];
                setTimeout(function() {
                    document.getElementById('start-time').value = "08:30";
                    document.getElementById('end-time').value = "18:00";
                }, 0);
                showModal(selectedDates);
            },
            eventClick: function(info) {
                if(info.event.start) {
                    selectedDates = [info.event.startStr.slice(0,10)];
                    setTimeout(function() {
                        document.getElementById('start-time').value = "";
                        document.getElementById('end-time').value = "";
                    }, 0);
                    showModal(selectedDates);
                }
            },
            editable: false,
            eventResizableFromStart: false,
            navLinks: true,
            dayMaxEvents: true
        });
        calendar.render();

        // ---- Client select logic ----
        const clientSelect = document.getElementById('client');
        const addClientBtn = document.getElementById('add-client-btn');
        const clientNewInput = document.getElementById('client-new');

        addClientBtn.onclick = function() {
            if (clientNewInput.style.display === "none" || !clientNewInput.style.display) {
                clientNewInput.style.display = "block";
                clientNewInput.value = "";
                clientNewInput.focus();
                clientSelect.style.display = "none";
                addClientBtn.textContent = "✔";
            } else {
                let newCli = clientNewInput.value.trim();
                if (newCli.length) {
                    let opt = document.createElement('option');
                    opt.value = newCli;
                    opt.textContent = newCli;
                    opt.selected = true;
                    clientSelect.appendChild(opt);
                    clientSelect.value = newCli;
                    clientSelect.style.display = "block";
                    clientNewInput.style.display = "none";
                    addClientBtn.textContent = "+";
                }
            }
        };
        clientNewInput.addEventListener('blur', function() {
            setTimeout(function(){
                if(document.activeElement !== addClientBtn) {
                    clientNewInput.style.display = "none";
                    addClientBtn.textContent = "+";
                    clientSelect.style.display = "block";
                }
            }, 150);
        });

        // Correction : Empêche le submit par défaut si bouton désactivé
        document.getElementById('add-event-form').addEventListener('submit', function(e){
            var btn = document.getElementById('submit-btn');
            if(btn && btn.disabled) e.preventDefault();
        });
    });

    function showModal(dates) {
        lastClickedDate = dates[0];
        document.getElementById('modal-bg').classList.add('active');
        document.getElementById('modal').classList.add('active');
        document.getElementById('modal-date').textContent = (dates.length > 1)
            ? dates[0] + " à " + dates[dates.length-1]
            : dates[0];
        document.getElementById('selected-dates').value = JSON.stringify(dates);
        showDayEvents(dates[0]);
        document.getElementById('add-event-form').reset();
        document.getElementById('client').style.display = "block";
        document.getElementById('client-new').style.display = "none";
        document.getElementById('add-client-btn').textContent = "+";
    }
    document.getElementById('modal-bg').onclick = closeModal;
    function closeModal() {
        document.getElementById('modal-bg').classList.remove('active');
        document.getElementById('modal').classList.remove('active');
        if(calendar) calendar.refetchEvents();
    }

    function showDayEvents(day) {
        fetch(`calendar.php?action=fetch&start=${day}&end=${day}`)
        .then(r=>r.json())
        .then(events => {
            let blocks = '';
            for (const ev of events) {
                blocks += `<div class="event-block" data-id="${ev.id}">
                    <b>${ev.title}</b>
                    <span class="event-collab"></span>
                    <span class="event-delete" title="Supprimer" onclick="deleteEvent(${ev.id});">&times;</span>
                </div>`;
            }
            document.getElementById('modal-events').innerHTML = blocks || "<em>Aucun évènement ce jour.</em>";
        });
    }

    document.getElementById('add-event-form').onsubmit = function(e) {
        e.preventDefault();
        var btn = document.getElementById('submit-btn');
        if(btn) btn.disabled = true;
        let client = document.getElementById('client').style.display !== "none"
            ? document.getElementById('client').value
            : document.getElementById('client-new').value.trim();
        let data = {
            event_type: document.getElementById('event-type').value,
            collaborators: Array.from(document.querySelectorAll('input[name=\"collaborators[]\"]:checked')).map(cb=>cb.value),
            client: client,
            start_time: document.getElementById('start-time').value,
            end_time: document.getElementById('end-time').value,
            dates: JSON.parse(document.getElementById('selected-dates').value)
        };
        fetch('calendar.php?action=add', {
            method:'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify(data)
        }).then(r=>r.json())
        .then(resp=>{
            if(btn) btn.disabled = false;
            if(resp && resp.success) {
                if(calendar) calendar.refetchEvents();
                closeModal();
            } else {
                alert('Erreur lors de l\'ajout !');
            }
        }).catch(function(){
            if(btn) btn.disabled = false;
            alert('Erreur lors de l\'ajout !');
        });
    };

    function deleteEvent(id) {
        if (!confirm("Supprimer cet évènement ?")) return;
        fetch('calendar.php?action=delete', {
            method:'POST',
            headers: {'Content-Type':'application/json'},
            body: JSON.stringify({id:id})
        }).then(r=>r.json()).then(resp=>{
            if(resp.success) {
                showDayEvents(lastClickedDate);
                if(calendar) calendar.refetchEvents();
            }
        });
    }
    </script>
</body>
</html>
