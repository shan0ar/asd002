<?php
require_once 'includes/session_check.php';
// Connexion à la base PostgreSQL (modifie $dbpass si besoin)
$dbhost = 'localhost';
$dbuser = 'thomas';
$dbpass = ''; // Mets ton mot de passe ici si besoin
$dbname = 'osintapp';

try {
    $pdo = new PDO("pgsql:host=$dbhost;dbname=$dbname", $dbuser, $dbpass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
    ]);
    $clients = $pdo->query("SELECT id, name FROM clients ORDER BY LOWER(name) ASC")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {
    $clients = [];
}
// Détection du client courant pour mettre en bleu si sur client.php
$current_client_id = isset($_GET['id']) ? intval($_GET['id']) : null;
$current_page = basename($_SERVER['PHP_SELF']);
?>
<style>
:root {
  --sidebar-width: 260px;
  --sidebar-collapsed-width: 54px;
  --sidebar-bg: #222a30;
  --sidebar-fg: #fff;
  --sidebar-accent: #08a6c3;
  --sidebar-link-hover: #188dc7;
  --sidebar-btn-green: #23b26d;
}

/* RESET pour la sidebar */
.sidebar-asd * { box-sizing: border-box; }

.sidebar-asd {
  position: fixed;
  top: 0;
  left: 0;
  height: 100vh;
  width: var(--sidebar-width);
  background: var(--sidebar-bg);
  color: var(--sidebar-fg);
  display: flex;
  flex-direction: column;
  z-index: 1200;
  transition: width 0.22s cubic-bezier(.77,0,.175,1);
  font-family: 'Segoe UI', Arial, sans-serif;
  border-right: 1px solid #232f37;
}
.sidebar-asd-collapsed {
  width: var(--sidebar-collapsed-width) !important;
}
.sidebar-asd .sidebar-toggle-btn {
  position: absolute;
  top: 22px;
  right: -18px;
  width: 36px;
  height: 36px;
  border-radius: 50%;
  border: none;
  background: #fff;
  color: #222a30;
  font-size: 1.15em;
  box-shadow: 0 2px 8px #0001;
  cursor: pointer;
  display: flex;
  align-items: center;
  justify-content: center;
  z-index: 1220;
  transition: background 0.17s, color 0.17s;
}
.sidebar-asd .sidebar-toggle-btn:hover {
  background: #08a6c3;
  color: #fff;
}
.sidebar-asd-collapsed .sidebar-toggle-btn { right: -18px; }
.sidebar-asd-header {
  font-size: 2em;
  font-weight: 700;
  letter-spacing: 0.02em;
  padding: 32px 0 18px 28px;
  color: #fff;
  user-select: none;
  transition: opacity 0.18s;
}
.sidebar-asd-collapsed .sidebar-asd-header { opacity: 0; pointer-events: none; }
.sidebar-asd-menu {
  margin-bottom: 12px;
}
.sidebar-asd-menu a {
  display: block;
  padding: 11px 0 11px 28px;
  text-decoration: none;
  color: #fff;
  background: transparent;
  font-weight: 500;
  transition: background 0.18s, color 0.18s;
}
.sidebar-asd-menu a.active,
.sidebar-asd-menu a:hover {
  background: var(--sidebar-accent);
  color: #fff;
}

.sidebar-asd-accordion-title {
  width: 100%;
  text-align: left;
  background: none;
  border: none;
  color: #e1e4e8;
  font-weight: 600;
  font-size: 1.05em;
  padding: 9px 0 9px 28px;
  cursor: pointer;
  outline: none;
  position: relative;
  user-select: none;
  transition: background 0.15s;
  margin: 0;
}
.sidebar-asd-accordion-title .arrow {
  display: inline-block;
  margin-right: 8px;
  transition: transform 0.19s;
}
.sidebar-asd-accordion-title.expanded .arrow {
  transform: rotate(90deg);
}
.sidebar-asd-accordion-content {
  max-height: 777px;
  overflow: hidden;
  transition: max-height 0.23s cubic-bezier(.4,0,.2,1);
  padding-bottom: 3px;
}
.sidebar-asd-accordion-content.collapsed {
  max-height: 0;
  padding: 0;
  overflow: hidden;
  transition: max-height 0.19s cubic-bezier(.4,0,.2,1);
}

.sidebar-asd-search {
  padding: 0 15px 0 28px;
  margin-top: 6px;
}
.sidebar-asd-search input[type="text"] {
  width: 100%;
  padding: 7px 10px;
  border-radius: 3px;
  border: none;
  background: #fff;
  color: #111;
  font-size: 1em;
  outline: none;
  transition: box-shadow 0.18s;
  margin-bottom: 7px;
}
.sidebar-asd-clients {
  flex: 1 1 auto;
  overflow-y: auto;
  padding-left: 0;
  margin-top: 3px;
}
.sidebar-asd-client {
  display: block;
  padding: 9px 0 9px 28px;
  color: #fff;
  text-decoration: none;
  font-weight: 500;
  border: none;
  background: none;
  cursor: pointer;
  transition: background 0.13s, color 0.13s;
  border-radius: 0;
}
.sidebar-asd-client:hover,
.sidebar-asd-client.active {
  background: #27313a;
  color: #fff;
}
.sidebar-asd-create-btn {
  margin: 0 0 10px 28px;
  padding: 8px 21px;
  border: none;
  border-radius: 4px;
  background: var(--sidebar-btn-green);
  color: #fff;
  font-weight: 600;
  font-size: 1em;
  cursor: pointer;
  transition: background 0.16s;
  display: block;
  text-align: left;
  font-family: inherit;
}
.sidebar-asd-create-btn:hover {
  background: #188c50;
}
.sidebar-asd-logout {
  color: #fff;
  text-decoration: none;
  padding: 10px 0 10px 28px;
  margin: 22px 0 0 0;
  display: block;
  font-size: 1.07em;
  font-weight: 400;
  background: none;
  border: none;
  cursor: pointer;
  transition: background 0.17s;
}
.sidebar-asd-logout:hover {
  background: #232f37;
}

/* Hide text in collapsed mode, only show icons/buttons (if you add any) */
.sidebar-asd-collapsed .sidebar-asd-header,
.sidebar-asd-collapsed .sidebar-asd-menu a,
.sidebar-asd-collapsed .sidebar-asd-accordion-title,
.sidebar-asd-collapsed .sidebar-asd-search,
.sidebar-asd-collapsed .sidebar-asd-client,
.sidebar-asd-collapsed .sidebar-asd-create-btn,
.sidebar-asd-collapsed .sidebar-asd-plan-btn,
.sidebar-asd-collapsed .sidebar-asd-logout {
  opacity: 0;
  pointer-events: none;
  height: 0;
  padding: 0;
  margin: 0;
  font-size: 0;
  border: none;
  overflow: hidden;
  transition: opacity 0.2s, height 0.2s;
}
.sidebar-asd-collapsed .sidebar-toggle-btn {
  /* always visible */
}

.sidebar-asd-client.active-client {
  background: #4361ee !important;
  color: #fff !important;
}

@media (max-width: 900px) {
  .sidebar-asd { width: 100vw; max-width: 100vw; min-width: 0; }
  .sidebar-asd-collapsed { width: 54px; }
  .sidebar-toggle-btn { right: 0; top: 10px; }
}
</style>

<nav class="sidebar-asd" id="asd-sidebar">
  <button class="sidebar-toggle-btn" id="sidebar-toggle-btn" title="Ouvrir/fermer le menu">
    <span id="sidebar-arrow">&lt;</span>
  </button>
  <div class="sidebar-asd-header">ASD</div>
  <div class="sidebar-asd-menu">
    <a href="index.php" class="<?=basename($_SERVER['PHP_SELF']) === 'index.php' ? 'active' : ''?>">Accueil</a>
  </div>

  <!-- Rapports Accordion -->
  <button class="sidebar-asd-accordion-title" id="rapports-title" type="button">
    <span class="arrow">&#8250;</span> Rapports
  </button>
  <div class="sidebar-asd-accordion-content" id="rapports-content">
    <div class="sidebar-asd-search">
      <input type="text" id="sidebar-search-input" placeholder="Rechercher un client..." autocomplete="off" />
    </div>
    <div class="sidebar-asd-clients" id="sidebar-clients">
      <?php foreach($clients as $client):
        $is_active = ($current_page === 'client.php' && $current_client_id == $client['id']);
      ?>
        <a href="client.php?id=<?=$client['id']?>"
           class="sidebar-asd-client<?= $is_active ? ' active-client' : '' ?>"
           data-client="<?=strtolower($client['name'])?>">
          <?=htmlspecialchars($client['name'])?>
        </a>
      <?php endforeach; ?>
      <?php if(empty($clients)): ?>
        <span style="color:#bbb;font-size:0.97em; padding-left:28px;">Aucun client</span>
      <?php endif; ?>
    </div>
  </div>

  <!-- Gestion Accordion -->
  <button class="sidebar-asd-accordion-title" id="gestion-title" type="button">
    <span class="arrow">&#8250;</span> Gestion
  </button>
  <div class="sidebar-asd-accordion-content" id="gestion-content">
    <a href="create_client.php" class="sidebar-asd-client"
       style="margin-bottom:10px; font-weight:600;">Créer un client</a>
    <a href="planification.php" class="sidebar-asd-client"
       style="margin-bottom:10px; font-weight:600;">Planification</a>
    <!-- Ajoute d'autres liens de gestion ici -->
  </div>

  <a href="logout.php" class="sidebar-asd-logout">Déconnexion</a>
</nav>

<script>
(function() {
  // Sidebar toggle
  const sidebar = document.getElementById('asd-sidebar');
  const toggleBtn = document.getElementById('sidebar-toggle-btn');
  const arrow = document.getElementById('sidebar-arrow');
  let collapsed = false;

  function setSidebarState(state) {
    collapsed = state;
    sidebar.classList.toggle('sidebar-asd-collapsed', collapsed);
    arrow.innerHTML = collapsed ? "&#8250;" : "&lt;";
    toggleBtn.title = collapsed ? "Déplier le menu" : "Réduire le menu";
    var main = document.querySelector('.main');
    if(main) {
      main.style.marginLeft = collapsed ? '54px' : '260px';
    }
  }
  setSidebarState(false);

  toggleBtn.onclick = function() {
    setSidebarState(!collapsed);
  };

  // Accordion logic
  function accordionToggle(title, content) {
    title.addEventListener('click', function() {
      const expanded = !content.classList.contains('collapsed');
      document.querySelectorAll('.sidebar-asd-accordion-title').forEach(btn => btn.classList.remove('expanded'));
      document.querySelectorAll('.sidebar-asd-accordion-content').forEach(div => div.classList.add('collapsed'));
      if (expanded) {
        // collapse this section
        content.classList.add('collapsed');
        title.classList.remove('expanded');
      } else {
        // expand this section
        content.classList.remove('collapsed');
        title.classList.add('expanded');
      }
    });
  }

  const rapportsTitle = document.getElementById('rapports-title');
  const rapportsContent = document.getElementById('rapports-content');
  const gestionTitle = document.getElementById('gestion-title');
  const gestionContent = document.getElementById('gestion-content');
  // Start with rapports ouvert, gestion fermé
  rapportsTitle.classList.add('expanded');
  rapportsContent.classList.remove('collapsed');
  gestionTitle.classList.remove('expanded');
  gestionContent.classList.add('collapsed');
  accordionToggle(rapportsTitle, rapportsContent);
  accordionToggle(gestionTitle, gestionContent);

  // Barre de recherche : filtre les clients
  const searchInput = document.getElementById('sidebar-search-input');
  const clientLinks = document.querySelectorAll('.sidebar-asd-client');
  if (searchInput) {
    searchInput.addEventListener('input', function() {
      const val = this.value.trim().toLowerCase();
      clientLinks.forEach(function(link) {
        link.style.display = (!val || link.dataset.client.includes(val)) ? "" : "none";
      });
    });
  }
})();
</script>
