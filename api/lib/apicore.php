<?php

// settings
include_once(__DIR__ . "/settings.php");
// database connection
include_once(__DIR__ . "/dbase.php");

// session management
if (isset($_GET['sid']) && preg_match('/^[-,a-zA-Z0-9]{1,128}$/', $_GET['sid'])) {
	session_id($_GET['sid']);
	session_start();

	// if session is no longer set in php, reactivate it if possible
	if (!isset($_SESSION['userid'])) {
		$user = $db->prepare("SELECT id FROM user WHERE sessionid=?");
		$user->execute(array($_GET['sid']));
		if ($user->rowCount() === 0) {
			$_SESSION['userid'] = '';
		} else {
			// fetch user and set session
			$_SESSION['userid'] = $user->fetchColumn();
		}
	}
} else {
	session_start();
}

// CORS
if (isset($_SERVER['HTTP_ORIGIN'])) {
    header("Access-Control-Allow-Origin: {$_SERVER['HTTP_ORIGIN']}");
//    header('Access-Control-Max-Age: 86400');    // cache for 1 day
    header('Access-Control-Allow-Credentials: true');
}

// Access-Control headers are received during OPTIONS requests
if ($_SERVER['REQUEST_METHOD'] == 'OPTIONS') {
    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_METHOD'])) {
		header("Access-Control-Allow-Methods: GET, POST, OPTIONS");
	}

    if (isset($_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS'])) {
		header("Access-Control-Allow-Headers: {$_SERVER['HTTP_ACCESS_CONTROL_REQUEST_HEADERS']}");
	}

    exit(0);
}

// check maintenance mode
$configQuery = $db->prepare("SELECT * FROM config");
$configQuery->execute(array());
$config = $configQuery->fetch(PDO::FETCH_ASSOC);
if ($config['maintenance'] == 1) {
    print json_encode(array(
		'result' => false,
		'message' => 'maintenanceMode'
	));
    die();
}

// get JSON POST input
$jsonPost = json_decode(file_get_contents('php://input'), true);
if (is_array($_GET)) {
    // prepare return-array
    $result = array(
		'result' => false
	);
    // switch requested action
    react($_GET['action']);
    // output
    print json_encode($result);
} else {
    print json_encode(array(
		'result' => false,
		'message' => 'Method not supported.'
	));
}
die();

// check input for required fields & pattern errors
function hasErrors($request = array(), $required = array()) {
    $errors = array();
    foreach ($required as $var => $pat) {
        if (!isset($request[$var]) || strlen($request[$var]) == 0) {
            // not set or empty
            $errors[] = "missing " . $var;
        } else if ($pat && !preg_match($pat, $request[$var])) {
            // not pattern matching    
            $errors[] = "invalid " . $var;
        }
    }
    // return errors or if no errors return FALSE
    return (count($errors) > 0) ? $errors : false;
}

// check if user is logged in
function isUser() {
    global $_SESSION, $isUser, $db;

    if (!defined($isUser)) {
        $isUser = false;

        if (isset($_SESSION['userid']) && $_SESSION['userid'] != '') {
            $userQuery = $db->prepare("SELECT status FROM user WHERE id=?");
            $userQuery->execute(array($_SESSION['userid']));
            if ($userQuery->rowCount() === 0) {
                $_SESSION['userid'] = '';
            } else {
				// fetch user status
				$status = $userQuery->fetchColumn();
				if ($status == 'active') {
					$isUser = true;
				}
            }
        }
    }
    return $isUser;
}

// ensure that we have a logged in user, otherwise exit script
function onlyUsers() {
    global $_SESSION;
    if (!isUser()) {
        echo(json_encode(array(
            'result' => false,
            'message' => 'badAuth'
        )));
        die();
    }
}

// check if user is admin
function isAdmin() {
    global $_SESSION;
    return (isUser() && $_SESSION['userid'] <= 4);
}

// ensure that we have a logged in admin, otherwise exit script
function onlyAdmin() {
    global $_SESSION;
    if (!isAdmin()) {
        echo(json_encode(array(
            'result' => false,
            'message' => 'badAuth'
        )));
        die();
    }
}

function deleteChat($chatid, $userid) {
	global $db;

	$chatStm = $db->prepare("SELECT employer, employerDeleted, workerDeleted FROM chat WHERE id=? AND (employer=? OR worker=?)");
	$chatStm->execute(array($chatid, $userid, $userid));
	if ($chatStm->rowCount() === 0) {
		return 'notexist';
	} else {
		$chat = $chatStm->fetch(PDO::FETCH_ASSOC);
		$isEmployer = $chat['employer'] == $userid;

		// get last msg id
		$lastMessage = $db->prepare("SELECT id FROM chat_message WHERE chatid=? ORDER BY timestamp DESC LIMIT 1");
		$lastMessage->execute(array($chatid));
		$lastMessageId = $lastMessage->fetchColumn();

		// set new employer/worker deleted id (for further processing)
		if ($isEmployer) {
			$chat['employerDeleted'] = $lastMessageId;
			$chat['workerDeleted'] = isset($chat['workerDeleted']) ? $chat['workerDeleted'] : 0;
		} else {
			$chat['workerDeleted'] = $lastMessageId;
			$chat['employerDeleted'] = isset($chat['employerDeleted']) ? $chat['employerDeleted'] : 0;
		}

		// check if both users deleted complete chat
		if ($chat['employerDeleted'] == $chat['workerDeleted']) {
			// delete chat
			$chat = $db->prepare("DELETE FROM chat WHERE id=?");
			$chat->execute(array($chatid));

			// delete all messages
			$chatMsg = $db->prepare("DELETE FROM chat_message WHERE chatid=?");
			$chatMsg->execute(array($chatid));
		} else {
			// update deleted msg id
			$chatUpdate = $db->prepare("UPDATE chat SET ".($isEmployer ? 'employer' : 'worker')."Deleted=? WHERE id=?");
			$chatUpdate->execute(array($lastMessageId, $chatid));

			// delete messages both users deleted
			$chatMsg = $db->prepare("DELETE FROM chat_message WHERE chatid=? AND id<=?");
			$chatMsg->execute(array(
				$chatid,
				min($chat['employerDeleted'], $chat['workerDeleted'])
			));
		}
	}
	return false;
}

function deleteUser($userid) {
    global $db;
		
	// delete job_rapports from database
	$jobRapportsQuery = $db->prepare("DELETE FROM jobs_rapports WHERE jobid=?");
	$jobsQuery = $db->prepare("SELECT id FROM jobs WHERE uid=?");
	$jobsQuery->execute(array($userid));
	$jobs = $jobsQuery->fetchAll(PDO::FETCH_ASSOC);
	foreach ($jobs as $job) {
		$jobRapportsQuery->execute(array($job['id']));
	}
	
	// delete jobs from database
	$job = $db->prepare("DELETE FROM jobs WHERE uid=?");
	$job->execute(array($userid));	
	
	// update (open) jobs where user is worker
	$job = $db->prepare("UPDATE jobs SET worker=NULL WHERE worker=? AND status='open'");
	$job->execute(array($userid));
	

	// delete chats
	$chatStm = $db->prepare("SELECT id FROM chat WHERE (employer=? OR worker=?)");
	$chatStm->execute(array($userid, $userid));
	$chats = $chatStm->fetchAll(PDO::FETCH_ASSOC);
	foreach ($chats as $chat) {
		deleteChat($chat['id'], $userid);
	}
	
	// delete user_rapports from database
	$job = $db->prepare("DELETE FROM user_rapports WHERE rapportedUid=?");
	$job->execute(array($userid));
	
	// delete user
	$job = $db->prepare("DELETE FROM user WHERE id=?");
	$job->execute(array($userid));
}


function getNewMessageCount($userid) {
	global $db;

	$messages = $db->prepare("SELECT COUNT(B.id) FROM chat AS A LEFT JOIN chat_message AS B ON B.chatid=A.id WHERE"
		."(   (A.employer=? AND B.poster='worker')"
		." OR (A.employer=? AND B.poster='system' AND B.msg NOT LIKE 'ratingWorker-%' AND msg NOT LIKE 'offer-declined-%' AND msg!='jobassigned')"
		." OR (A.worker=? AND B.poster='employer')"
		." OR (A.worker=? AND B.poster='system' AND B.msg NOT LIKE 'ratingEmployer-%')"
		.") AND B.status='unread'"
	);	
	$messages->execute(array($userid, $userid, $userid, $userid));
	return $messages->fetchColumn();
}

?>
