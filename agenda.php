<?php
session_start();
// Inclure session_check si besoin : require_once 'includes/session_check.php';
require_once 'includes/db.php';
$db = getDb();

$event_types = [
    "Test d'intrusion",
    "Audit darkweb",
    "Audit d'exposition",
    "Audit Office365",
    "Audit Active Directory"
];

// --- Récupération collaborateurs
$collaborators = $db->query("SELECT id, name FROM collaborators ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// --- Actions AJAX (add, delete, fetch events)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    header('Content-Type: application/json');
    if ($_POST['action'] === 'add_event') {
        $event_date = $_POST['date'];
        $event_type = $_POST['event_type'];
        $start_time = $_POST['start_time'];
        $end_time = $_POST['end_time'];
        $collabs = $_POST['collaborators'] ?? [];
        if (!$event_date || !$event_type || !$start_time || !$end_time || empty($collabs)) {
            echo json_encode(['success'=>false, 'msg'=>'Champs obligatoires manquants']);
            exit;
        }
        $stmt = $db->prepare("INSERT INTO calendar_events (event_date, event_type, start_time, end_time) VALUES (?, ?, ?, ?) RETURNING id");
        $stmt->execute([$event_date, $event_type, $start_time, $end_time]);
        $event_id = $stmt->fetchColumn();
        foreach ($collabs as $cid) {
            $db->prepare("INSERT INTO calendar_event_collaborators (event_id, collaborator_id) VALUES (?, ?)")->execute([$event_id, $cid]);
        }
        echo json_encode(['success'=>true]);
        exit;
    }
    if ($_POST['action'] === 'delete_event') {
        $id = intval($_POST['id']);
        $db->prepare("DELETE FROM calendar_events WHERE id=?")->execute([$id]);
        echo json_encode(['success'=>true]);
        exit;
    }
    if ($_POST['action'] === 'fetch_events') {
        $start = $_POST['start'];
        $end = $_POST['end'];
        $stmt = $db->prepare("SELECT e.*, array_agg(c.name) as collaborators
            FROM calendar_events e
            LEFT JOIN calendar_event_collaborators ec ON e.id=ec.event_id
            LEFT JOIN collaborators c ON ec.collaborator_id=c.id
            WHERE e.event_date BETWEEN ? AND ?
            GROUP BY e.id");
        $stmt->execute([$start, $end]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true, 'events'=>$events]);
        exit;
    }
    if ($_POST['action'] === 'fetch_day') {
        $date = $_POST['date'];
        $stmt = $db->prepare("SELECT e.*, array_agg(c.name) as collaborators
            FROM calendar_events e
            LEFT JOIN calendar_event_collaborators ec ON e.id=ec.event_id
            LEFT JOIN collaborators c ON ec.collaborator_id=c.id
            WHERE e.event_date=?
            GROUP BY e.id");
        $stmt->execute([$date]);
        $events = $stmt->fetchAll(PDO::FETCH_ASSOC);
        echo json_encode(['success'=>true, 'events'=>$events]);
        exit;
    }
    exit;
}

// --- HTML + JS ---
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Agenda / Calendrier</title>
    <style>
        body { font-family: 'Segoe UI', Arial, sans-serif; margin: 0; background: #f7f7fa; }
        .header { background: #2f3542; color: #fff; padding: 14px 0; text-align:center; font-size: 2em; }
        .calendar-controls {margin: 20px auto; text-align: center;}
        .calendar-controls select, .calendar-controls button {font-size:1em; padding: 6px 12px; margin: 0 5px;}
        #calendar {display: grid; grid-template-columns: repeat(7,1fr); gap: 6px; max-width: 1100px; margin: 0 auto; }
        .day, .empty {background: #fff; min-height: 80px; border-radius: 6px; box-shadow:0 2px 4px #0001; position: relative; cursor: pointer;}
        .day.selected, .day.range {outline: 2px solid #1e90ff;}
        .day.today {border: 2px solid #ffa502;}
        .day .daynum {position: absolute; top: 6px; left: 8px; font-weight: bold; color: #666;}
        .event-chip {display: inline-block; background: #1e90ff; color: #fff; border-radius: 4px; padding: 2px 7px; font-size: .9em; margin: 2px 2px 0 0;}
        .event-chip:hover {background: #3742fa;}
        .day.enlarged { z-index: 20; position: relative; box-shadow:0 8px 32px #0006; min-height: 220px; min-width: 200px; }
        .day .enlarged-content { background:#f1f2f6; border-radius:10px; padding:13px; margin:25px 2px 3px 2px; }
        .event-detail {background:#f1f2f6;padding:8px 10px;border-radius:7px;margin-bottom:7px;display:flex;justify-content:space-between;align-items:center;}
        .event-detail .del-btn {color:#e74c3c;cursor:pointer;margin-left:12px;}
        #addEventForm label {display:block; margin: 8px 0 2px;}
        #addEventForm input, #addEventForm select {padding:5px 6px; width: 100%; margin-bottom:8px;}
        #addEventForm .collabs-list {display:flex;gap:8px;}
        #addEventForm .collabs-list label {display:inline-block;}
        .calendar-head { display: contents; }
        @media (max-width:650px) {
            #calendar {max-width:98vw;}
            .day.enlarged {min-width:120px;}
        }
    </style>
</head>
<body>
<div class="header">Agenda / Calendrier</div>
<div class="calendar-controls">
    <button onclick="prevPeriod()">&lt;</button>
    <span id="periodLabel"></span>
    <button onclick="nextPeriod()">&gt;</button>
    <select id="viewMode" onchange="changeView()">
        <option value="month">Mois</option>
        <option value="week">Semaine</option>
        <option value="quarter">Trimestre</option>
    </select>
</div>
<div id="calendar"></div>

<!-- Modal d'ajout -->
<div id="addEventModal" style="display:none; position:fixed; z-index:1000; left:0;top:0;width:100vw;height:100vh; background:rgba(20,20,20,.55)">
    <div style="background:#fff;max-width:370px;width:97vw;margin:5vw auto 0 auto;padding:24px 20px;border-radius:13px;box-shadow:0 8px 30px #0005;position:relative;">
        <span onclick="closeAddEventModal()" style="position:absolute;right:13px;top:10px;font-size:1.5em;cursor:pointer;color:#aaa;">&times;</span>
        <div id="addEventModalContent"></div>
    </div>
</div>

<script>
let today = new Date();
let state = {
    viewMode: 'month',
    periodStart: new Date(today.getFullYear(), today.getMonth(), 1),
    selectedDates: [],
    events: {},
};
let enlargedDay = null;

function pad(n){return n<10?'0'+n:n;}
function dateISO(d){return d.getFullYear()+"-"+pad(d.getMonth()+1)+"-"+pad(d.getDate());}

// Période actuelle (début/fin)
function getPeriodRange() {
    let d = new Date(state.periodStart);
    if (state.viewMode==='month') {
        let start = new Date(d.getFullYear(), d.getMonth(), 1);
        let end = new Date(d.getFullYear(), d.getMonth()+1, 0);
        return {start, end};
    }
    if (state.viewMode==='week') {
        let day = d.getDay()==0?6:d.getDay()-1; // lundi=0
        let start = new Date(d); start.setDate(d.getDate()-day);
        let end = new Date(start); end.setDate(start.getDate()+6);
        return {start, end};
    }
    if (state.viewMode==='quarter') {
        let q = Math.floor(d.getMonth()/3);
        let start = new Date(d.getFullYear(), q*3, 1);
        let end = new Date(d.getFullYear(), q*3+3, 0);
        return {start, end};
    }
    return {};
}

// Affichage calendrier
function renderCalendar() {
    let cal = document.getElementById('calendar');
    cal.innerHTML = '';
    let {start, end} = getPeriodRange();
    let daylabels = ['Lun','Mar','Mer','Jeu','Ven','Sam','Dim'];
    // En-têtes
    daylabels.forEach(lbl => {
        let th = document.createElement('div');
        th.style.fontWeight='bold'; th.style.textAlign='center';
        th.textContent = lbl;
        cal.appendChild(th);
    });
    // Jours vides début
    let startDay = start.getDay()==0?6:start.getDay()-1;
    for(let i=0;i<startDay;i++) {
        let empty = document.createElement('div');
        empty.className = 'empty';
        cal.appendChild(empty);
    }
    // Jours
    let iter = new Date(start);
    let endplus = new Date(end); endplus.setDate(endplus.getDate()+1);
    while (iter < endplus) {
        let dISO = dateISO(iter);
        let dayDiv = document.createElement('div');
        dayDiv.className = 'day';
        if (today.toDateString()===iter.toDateString()) dayDiv.classList.add('today');
        if (state.selectedDates.includes(dISO)) dayDiv.classList.add('selected');
        if (enlargedDay === dISO) dayDiv.classList.add('enlarged');
        let daynum = document.createElement('span');
        daynum.className = 'daynum';
        daynum.textContent = iter.getDate();
        dayDiv.appendChild(daynum);
        // Events
        let events = state.events[dISO] || [];
        events.forEach(ev => {
            let chip = document.createElement('span');
            chip.className = 'event-chip';
            chip.textContent = ev.event_type + (ev.start_time ? " ("+ev.start_time.substr(0,5)+(ev.end_time?'–'+ev.end_time.substr(0,5):'')+")" : "");
            dayDiv.appendChild(chip);
        });
        // Click/drag
        dayDiv.onmousedown = e => {
            e.preventDefault();
            clearSelection();
            selectDay(dISO);
            dragSelection(dISO);
        };
        dayDiv.onmouseover = e => {
            if (mouseDown) dragSelection(dISO, true);
        };
        // click pour agrandir
        dayDiv.onclick = function(e) {
            if (enlargedDay === dISO) {
                enlargedDay = null;
                renderCalendar();
            } else {
                enlargedDay = dISO;
                renderCalendar();
                showDayInline(dISO, dayDiv);
            }
            e.stopPropagation();
        };
        cal.appendChild(dayDiv);
        iter.setDate(iter.getDate()+1);
    }
    // click hors calendrier désélectionne l'enlarged
    document.body.onclick = function(e) {
        if (enlargedDay !== null) {
            enlargedDay = null;
            renderCalendar();
        }
    };
    renderPeriodLabel();
}

// Sélection
let mouseDown = false;
document.addEventListener('mousedown',()=>{mouseDown=true;});
document.addEventListener('mouseup',()=>{mouseDown=false;});

function clearSelection() { state.selectedDates=[]; }
function selectDay(d) {state.selectedDates=[d];}
function dragSelection(to, keep=false) {
    let {start,end} = getPeriodRange();
    let dates = [];
    let fromDate = new Date(state.selectedDates[0]);
    let toDate = new Date(to);
    let min = fromDate<toDate?fromDate:toDate, max=fromDate>toDate?fromDate:toDate;
    min = new Date(min); max = new Date(max);
    while (min<=max) {dates.push(dateISO(min)); min.setDate(min.getDate()+1);}
    state.selectedDates=dates;
    renderCalendar();
}

// Navigation
function prevPeriod() {
    if (state.viewMode==='month') state.periodStart.setMonth(state.periodStart.getMonth()-1);
    if (state.viewMode==='week') state.periodStart.setDate(state.periodStart.getDate()-7);
    if (state.viewMode==='quarter') state.periodStart.setMonth(state.periodStart.getMonth()-3);
    state.selectedDates=[];
    enlargedDay = null;
    fetchEvents();
}
function nextPeriod() {
    if (state.viewMode==='month') state.periodStart.setMonth(state.periodStart.getMonth()+1);
    if (state.viewMode==='week') state.periodStart.setDate(state.periodStart.getDate()+7);
    if (state.viewMode==='quarter') state.periodStart.setMonth(state.periodStart.getMonth()+3);
    state.selectedDates=[];
    enlargedDay = null;
    fetchEvents();
}
function changeView() {
    state.viewMode=document.getElementById('viewMode').value;
    if (state.viewMode==='week') state.periodStart=new Date(today);
    state.selectedDates=[];
    enlargedDay = null;
    fetchEvents();
}

// Affichage période
function renderPeriodLabel() {
    let {start, end} = getPeriodRange();
    let opts = {month:'long', year:'numeric'};
    let label = '';
    if (state.viewMode==='month') label = start.toLocaleDateString('fr-FR',opts);
    if (state.viewMode==='week') label = "Semaine du "+start.toLocaleDateString('fr-FR')+" au "+end.toLocaleDateString('fr-FR');
    if (state.viewMode==='quarter') label = "Trimestre "+(Math.floor(start.getMonth()/3)+1)+" "+start.getFullYear();
    document.getElementById('periodLabel').textContent=label.charAt(0).toUpperCase()+label.slice(1);
}

// Récupère événements pour la période
function fetchEvents() {
    let {start,end} = getPeriodRange();
    fetch('',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=fetch_events&start='+dateISO(start)+'&end='+dateISO(end)
    }).then(r=>r.json()).then(data=>{
        state.events={};
        if (data.success) data.events.forEach(ev=>{
            if (!state.events[ev.event_date]) state.events[ev.event_date]=[];
            state.events[ev.event_date].push(ev);
        });
        renderCalendar();
    });
}
fetchEvents();

// Affichage inline du jour agrandi
function showDayInline(day, div) {
    fetch('', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'action=fetch_day&date=' + day
    }).then(r => r.json()).then(data => {
        let cont = document.createElement('div');
        cont.className = 'enlarged-content';
        cont.innerHTML = "<b>Évènements du jour :</b><br>";
        if (data.success) {
            if (data.events.length === 0) cont.innerHTML += "Aucun évènement";
            else data.events.forEach(ev => {
                let d = document.createElement('div');
                d.className = "event-detail";
                d.innerHTML = "<div><b>" + ev.event_type + "</b> (" + (ev.start_time ? ev.start_time.substr(0, 5) : '') + (ev.end_time ? "–" + ev.end_time.substr(0, 5) : '') + ")<br><small>Collab.: " + ev.collaborators.filter(x => x).join(', ') + "</small></div>"
                + "<span class='del-btn' onclick='deleteEvent("+ev.id+",\""+day+"\")' title='Supprimer'>&#128465;</span>";
                cont.appendChild(d);
            });
        }
        cont.innerHTML += `<hr><button onclick="showAddEventForm('${day}')">+ Ajouter un évènement</button>`;
        div.appendChild(cont);
    });
}

// Modal d'ajout
function showAddEventForm(day) {
    document.getElementById('addEventModal').style.display = 'block';
    let c = document.getElementById('addEventModalContent');
    c.innerHTML = `<h2>Ajouter un évènement le ${(new Date(day)).toLocaleDateString('fr-FR')}</h2>
        <form id="addEventForm" onsubmit="return submitAddEvent(event,'${day}')">
            <label>Type d'évènement</label>
            <select name="event_type" required>
                <?php foreach($event_types as $type): ?>
                <option value="<?=htmlspecialchars($type)?>"><?=htmlspecialchars($type)?></option>
                <?php endforeach; ?>
            </select>
            <label>Heure de début</label>
            <input type="time" name="start_time" required>
            <label>Heure de fin</label>
            <input type="time" name="end_time" required>
            <label>Collaborateurs concernés</label>
            <div class="collabs-list">
                <?php foreach($collaborators as $col): ?>
                <label><input type="checkbox" name="collaborators[]" value="<?=$col['id']?>"> <?=htmlspecialchars($col['name'])?></label>
                <?php endforeach; ?>
            </div>
            <button type="submit">Ajouter</button>
            <button type="button" onclick="closeAddEventModal()">Annuler</button>
        </form>`;
}
function closeAddEventModal() {
    document.getElementById('addEventModal').style.display = 'none';
}

// Ajout évènement
function submitAddEvent(ev,day) {
    ev.preventDefault();
    let f = document.getElementById('addEventForm');
    let data = new FormData(f);
    data.append('action','add_event');
    data.append('date',day);
    fetch('',{
        method:'POST',
        body:data
    }).then(r=>r.json()).then(d=>{
        if(d.success){
            closeAddEventModal();
            enlargedDay = day;
            fetchEvents();
        }
        else alert('Erreur: '+d.msg);
    });
    return false;
}

// Suppression
function deleteEvent(id,day) {
    if(!confirm("Supprimer cet évènement ?")) return;
    fetch('',{
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=delete_event&id='+id
    }).then(r=>r.json()).then(d=>{
        if(d.success){
            enlargedDay = day;
            fetchEvents();
        }
        else alert('Erreur suppression');
    });
}
</script>
</body>
</html>
