<?php
// require_once 'includes/session_check.php'; // Protection par session
require_once 'includes/db.php';
$db = getDb();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    if ($username === '' || $password === '') {
        $error = 'Nom d\'utilisateur et mot de passe obligatoires.';
    } else {
        // Vérifie unicité du username
        $stmt = $db->prepare("SELECT id FROM users WHERE username = ?");
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = 'Ce nom d\'utilisateur existe déjà.';
        } else {
            // Hash du mot de passe
            $password_hash = password_hash($password, PASSWORD_DEFAULT);

            // Ajout en base
            $stmt = $db->prepare("INSERT INTO users (username, password_hash, created_at) VALUES (?, ?, NOW())");
            $stmt->execute([$username, $password_hash]);
            $success = "Utilisateur <b>" . htmlspecialchars($username) . "</b> créé avec succès !";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Créer un utilisateur</title>
    <style>
        body { font-family: sans-serif; padding: 40px; }
        form { max-width: 350px; margin: 0 auto; }
        label { display: block; margin-top: 18px; }
        input[type="text"], input[type="password"] { width: 100%; padding: 8px; }
        button { margin-top: 18px; width: 100%; padding: 10px; }
        .error { color: red; text-align: center; margin-bottom: 18px; }
        .success { color: green; text-align: center; margin-bottom: 18px; }
    </style>
</head>
<body>
    <h2>Créer un nouvel utilisateur</h2>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <?php if ($success): ?>
        <div class="success"><?= $success ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <label for="username">Nom d'utilisateur :</label>
        <input type="text" name="username" id="username" required autofocus>

        <label for="password">Mot de passe :</label>
        <input type="password" name="password" id="password" required>

        <button type="submit">Créer l'utilisateur</button>
    </form>
    <p style="text-align:center;margin-top:20px;">
        <a href="client.php">Retour</a>
    </p>
</body>
</html>
