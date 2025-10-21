<?php
session_start();
require_once 'includes/db.php';
$db = getDb();

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';

    // Récupérer l'utilisateur
    $stmt = $db->prepare("SELECT * FROM users WHERE username = ?");
    $stmt->execute([$username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($user && password_verify($password, $user['password_hash'])) {
        // OK, login réussi
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['username'] = $user['username'];
        header('Location: index.php');
        exit;
    } else {
        $error = "Identifiants incorrects.";
    }
}
?>

<!DOCTYPE html>
<html lang="fr">
<head>
    <meta charset="UTF-8">
    <title>Connexion</title>
    <style>
        body { font-family: sans-serif; padding: 40px; }
        form { max-width: 300px; margin: 0 auto; }
        label { display: block; margin-top: 18px; }
        input[type="text"], input[type="password"] { width: 100%; padding: 8px; }
        button { margin-top: 18px; width: 100%; padding: 10px; }
        .error { color: red; text-align: center; margin-bottom: 18px; }
    </style>
</head>
<body>
    <h2>Connexion</h2>
    <?php if ($error): ?>
        <div class="error"><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>
    <form method="post" autocomplete="off">
        <label for="username">Nom d'utilisateur :</label>
        <input type="text" name="username" id="username" required autofocus>

        <label for="password">Mot de passe :</label>
        <input type="password" name="password" id="password" required>

        <button type="submit">Se connecter</button>
    </form>
</body>
</html>
