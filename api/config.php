<?php

// API main functions
include_once("lib/apicore.php");

function react($action) {
    global $_SESSION, $db, $jsonPost, $result;

    switch ($action) {
        case 'categories':
            $result['result'] = true;
			
			// get all categories from API
			$categories = $db->prepare("SELECT id, name FROM categories ORDER BY name ASC");
			$categories->execute(array());
			
			// return array of categories
			$result['categories'] = $categories->fetchAll(PDO::FETCH_ASSOC);
			
            break;
        default:
            $result['message'] = 'unknown action';
    }
}

?>
