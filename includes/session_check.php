<?php
session_start();

// Vérifie la présence du cookie de session et de la variable de session
if (!(isset($_COOKIE['PHPSESSID']) && isset($_SESSION['user_id']))) {
    header('Location: login.php');
    exit;
}
