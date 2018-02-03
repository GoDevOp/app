<?php

function sendPushNotif($title, $body, $to, $platform, $msgData) {
	global $settings;
	
	// create POST data for FCM POST
	if ($platform == 'iOS') {
		$postData = array(
			'notification' => array(
				'title' => $title,
				'body' => $body
			),
			'data' => array(
				'msgData' => $msgData
			)
		);
	} else {
		$postData = array(
			'data' => array(
				'title' => $title,
				'body' => $body,
//				'image' => 'www/img/ben.png',
				'msgData' => $msgData,
			)
		);
	}
	
	$postData = json_encode(array_merge(
		$postData,
		array(
			'to' => $to,
			'priority' => 'high',
			'restricted_package_name' => $settings['fcm']['package_name']
		)
	));

	// POST to FCM
	$ch = curl_init('https://fcm.googleapis.com/fcm/send');
	curl_setopt($ch, CURLOPT_CUSTOMREQUEST, "POST");
	curl_setopt($ch, CURLOPT_POSTFIELDS, $postData);
	curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
	curl_setopt($ch, CURLOPT_HTTPHEADER, array(
		'Content-Type: application/json',
		'Authorization: key=' . $settings['fcm']['API_key'],
		'Content-Length: ' . strlen($postData)
	));

//	$POSTresult = curl_exec($ch);
//	error_log(json_encode($POSTresult));
	curl_exec($ch);
}

function sendChatPushNotif($chatid, $msg, $msg2translate=null) {
	global $_SESSION, $db;
	
	$userStm = $db->prepare("SELECT B.language, B.FCMtoken, B.pushnotif, B.platform FROM chat AS A LEFT JOIN user AS B ON (A.employer=? AND B.id=A.worker) OR (A.worker=? AND B.id=A.employer) WHERE A.id=?");
	$userStm->execute(array($_SESSION['userid'], $_SESSION['userid'], $chatid));
	$userToNotify = $userStm->fetch(PDO::FETCH_ASSOC);

	if ($userToNotify['FCMtoken']) {// && $userToNotify['pushnotif']==1
		$userStm = $db->prepare("SELECT name FROM user WHERE id=?");
		$userStm->execute(array($_SESSION['userid']));
		$actUserName = $userStm->fetchColumn();

		if (isset($msg2translate)) {
			$translationsJSON = implode("", (file('txt/trans_' . $userToNotify['language'] . '.json')));
			$translations = json_decode($translationsJSON, true);
			$msg = $translations['pushnotif'][$msg2translate]
				? $translations['pushnotif'][$msg2translate]
				: $msg2translate;
		}

		sendPushNotif($actUserName, $msg, $userToNotify['FCMtoken'], $userToNotify['platform'], array(
			'type' => 'chat',
			'chatid' => $chatid
		));
	}
}

?>