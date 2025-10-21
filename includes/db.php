<?php
function getDb() {
    static $db = null;
    if ($db === null) {
        $db = new PDO("pgsql:host=localhost;port=5432;dbname=osintapp", "thomas", "thomas");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    return $db;
}
?>
