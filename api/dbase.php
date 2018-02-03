<?php

// settings
include_once(__DIR__ . "/settings.php");

// connect to database
try {
    $db = new PDO('mysql:host='.$settings['db_host'].';dbname='.$settings['db_name'], $settings['db_user'], $settings['db_pass']);
} catch (PDOException $e) {
    echo(json_encode(array(
        'result' => false,
        'message' => $e->getMessage()
    )));
    die();
}

// set UTF8 as standard
$db->query("SET NAMES 'utf8mb4'");
// activate exceptions on erros
$db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

?>