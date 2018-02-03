<?php

// API main functions
include_once("lib/apicore.php");

function getUserRole($chatid) {
    global $_SESSION, $db;
	
	$chat = $db->prepare("SELECT employer FROM chat WHERE (employer=? OR worker=?) AND id=?");
	$chat->execute(array($_SESSION['userid'], $_SESSION['userid'], $chatid));
	if ($chat->rowCount() === 0) {
		return 'none';
	} else {
		return $chat->fetchColumn() == $_SESSION['userid'] ? 'employer' : 'worker';
	}
}

function getOfferStatus($chatid, $getFullOffer=false) {
    global $db;
	
	$lastWorkerMessage = $db->prepare("SELECT id FROM chat_message WHERE chatid=? AND poster='system' AND msg LIKE 'worker%' ORDER BY id DESC LIMIT 1");
	$lastWorkerMessage->execute(array($chatid));
	$lastWorkerMessageId = $lastWorkerMessage->fetchColumn();
	if (!$lastWorkerMessageId) {
		$lastWorkerMessageId = 0;
	}
	
//	error_log($lastWorkerMessageId);
	
	$offerStm = $db->prepare("SELECT msg ".($getFullOffer ? ', id' : '')." FROM chat_message WHERE chatid=? AND poster='system' AND msg LIKE 'offer-%' AND msg NOT LIKE 'offer-declined-%' AND msg NOT LIKE 'offer-canceled-%' AND id>? ORDER BY timestamp ASC");
	$offerStm->execute(array($chatid, $lastWorkerMessageId));
	if ($offerStm->rowCount() > 0) {
		$offer = $offerStm->fetch(PDO::FETCH_ASSOC);
		$details = explode('-', $offer['msg']);

		if ($getFullOffer) {
			return array($details[1], $offer);
		} else {
			return $details[1];
		}
	} else {
		if ($getFullOffer) {
			return array('none', array());
		} else {
			return 'none';
		}
	}
}

function ensureUserRoleAndOfferStatus($chatid, $wantedUserRole, $wantedOfferStatus, $successFun) {
    global $result;
	
	// check if user is not blocked by other part
	if (isBlocked($chatid, $wantedUserRole)) {
		$result['message'] = 'blocked';
	} else {
		// check if chat exists & get userrole
		$userrole = getUserRole($chatid);
		if ($userrole !== $wantedUserRole) {
			$result['message'] = 'notexist';
		} else {
			// check offer status
			list($status, $offer) = getOfferStatus($chatid, true);
			if ($status !== $wantedOfferStatus) {
				$result['message'] = 'offerstatus';
			} else {
				$successFun($offer);
			}
		}
	}
}

function sendChatDetails($chatid) {
    global $_SESSION, $db, $result;
	
	// check if chat exists & get basic info
	$chat = $db->prepare("SELECT A.id, A.employer, A.jobid, A.employerDeleted, A.workerDeleted, B.id AS contactId, B.name AS contactName, B.img AS contactImg,"
		. " C.title AS jobTitle, C.img_preview AS jobImg, C.currency AS jobCurrency, C.price AS jobPrice, C.worker, D.timestamp AS hasBlocked, E.timestamp AS wasBlocked FROM chat AS A"
		. " LEFT JOIN user AS B ON (A.employer!=? AND B.id=A.employer) OR (A.worker!=? AND B.id=A.worker)"
		. " LEFT JOIN jobs AS C ON C.id=A.jobid"
		. " LEFT JOIN chat_blocked AS D ON D.uid=? AND D.blocked=B.id"
		. " LEFT JOIN chat_blocked AS E ON E.uid=B.id AND E.blocked=?"
		. " WHERE A.id=? AND (A.employer=? OR A.worker=?)");
	$chat->execute(array($_SESSION['userid'], $_SESSION['userid'], $_SESSION['userid'], $_SESSION['userid'], $chatid, $_SESSION['userid'], $_SESSION['userid']));
	if ($chat->rowCount() === 0) {
		$result['message'] = 'notexist';
	} else {
		// return chat details
		$result['chat'] = $chat->fetch(PDO::FETCH_ASSOC);

		$result['chat']['isEmployer'] = $isEmployer = $result['chat']['employer'] == $_SESSION['userid'];

		$result['chat']['hasWorker'] = $result['chat']['worker'] != '';
		unset($result['chat']['worker']);
		
		$result['chat']['offerStatus'] = getOfferStatus($chatid);
		
		// set chat messages read & find min messageid to send
		if ($isEmployer) {
			$updateMessages = $db->prepare("UPDATE chat_message SET status='read' WHERE chatid=? AND (poster='worker' OR (poster='system' AND msg NOT LIKE 'ratingWorker-%' AND msg NOT LIKE 'offer-declined-%' AND msg!='jobassigned'))");
			$minMsgId = isset($result['chat']['employerDeleted']) ? $result['chat']['employerDeleted'] : 0;
		} else {
			$updateMessages = $db->prepare("UPDATE chat_message SET status='read' WHERE chatid=? AND (poster='employer' OR (poster='system' AND msg NOT LIKE 'ratingEmployer-%'))");
			$minMsgId = isset($result['chat']['workerDeleted']) ? $result['chat']['workerDeleted'] : 0;
		}
		$updateMessages->execute(array($chatid));
		unset($result['chat']['employerDeleted']);
		unset($result['chat']['workerDeleted']);

		// return chat messages
		$lastDate = '';
		$messages = $db->prepare("SELECT msg, poster, timestamp FROM chat_message WHERE chatid=? AND id>? ORDER BY timestamp ASC");
		$messages->execute(array($chatid, $minMsgId));
		$result['chat']['messages'] = array();
		foreach ($messages->fetchAll(PDO::FETCH_ASSOC) as $message) {
			if ($message['poster'] == 'system') {
				$details = explode('-', $message['msg']);
				$message['type'] = $details[0];
				
				// dont list rating message for counterpart
				if (($isEmployer && $message['type'] == 'ratingWorker') || (!$isEmployer && $message['type'] == 'ratingEmployer')) {
					continue;
				}
				
				if (isset($details[1])) {
					$message['status'] = $details[1];
				}
				if (isset($details[2])) {
					$message['priceCurrency'] = $details[2];
					$message['priceValue'] = $details[3];
				}
				
				// change job price & currency if offer was accepted
				if ($message['type'] == 'offer' && $message['status'] == 'accepted') {
					$result['chat']['jobCurrency'] = $message['priceCurrency'];
					$result['chat']['jobPrice'] = $message['priceValue'];
				}
			} else if (($isEmployer && $message['poster'] == 'employer') || (!$isEmployer && $message['poster'] == 'worker')) {
				$message['poster'] = 'self';
			} else {
				$message['poster'] = 'other';
			}
			
			if ($message['poster'] != 'system' || ($message['type'] != 'offer' && $message['type'] != 'ratingEmployer' && $message['type'] != 'ratingWorker')) {
				$date = date('d/m/Y', $message['timestamp']);
				$time = date('H:i', $message['timestamp']);
				$message['timestamp'] = $date != $lastDate
					? $date .'&nbsp;-&nbsp;'
					: '';
				$message['timestamp'] .= $time;

				$lastDate = $date;			
			}

			$result['chat']['messages'][] = $message;
		}
	}
}

// check if user is not blocked by other part
function isBlocked($chatid, $userrole) {
    global $_SESSION, $db;

	$blocked = $db->prepare("SELECT B.timestamp FROM chat AS A LEFT JOIN chat_blocked AS B ON B.uid=A.".($userrole == 'employer' ? 'worker' : 'employer')." AND B.blocked=? WHERE A.id=?");
	$blocked->execute(array($_SESSION['userid'], $chatid));

	return $blocked->fetchColumn() > 0;
}

// get existing chat or create new one
// and call function on success
function getChat($chatid, $successFun) {
    global $_SESSION, $db, $result;
	
	if ($chatid > 0) {
		// check if chat exists & get userrole
		$userrole = getUserRole($chatid);
		if ($userrole === 'none') {
			$result['message'] = 'notexist';
		} else {
			// check if user is not blocked by other part
			if (isBlocked($chatid, $userrole)) {
				$result['message'] = 'blocked';
			} else {
				// call continue function
				$successFun($chatid, $userrole);
			}
		}
	} else {
		// create a new chat
		$jobid = $chatid * -1;

		// check if job exists & get employer info
		$job = $db->prepare("SELECT uid FROM jobs WHERE id=?");
		$job->execute(array($jobid));
		if ($job->rowCount() === 0) {
			$result['message'] = 'notexist';
		} else {
			$employer = $job->fetchColumn();
			
			// check if user is not blocked by employer
			$blocked = $db->prepare("SELECT id FROM chat_blocked WHERE uid=? AND blocked=?");
			$blocked->execute(array($employer, $_SESSION['userid']));
			if ($blocked->rowCount() > 0) {
				$result['message'] = 'blocked';
			} else {
				// insert chat into database
				$chat = $db->prepare("INSERT INTO chat (employer, worker, jobid) VALUES (?, ?, ?)");
				$chat->execute(array($employer, $_SESSION['userid'], $jobid));
				$result['chatid'] = $chatid = $db->lastInsertId();

				// call continue function
				$successFun($chatid, 'worker');
			}
		}
	}
}

function declineOffer($offer) {
	global $db;

	// update offer status
	$details = explode('-', $offer['msg']);
	$newDetails = 'offer-declined-' . $details[2] . '-' . $details[3];

	$offerStm = $db->prepare("UPDATE chat_message SET msg=? WHERE id=?");
	$offerStm->execute(array($newDetails, $offer['id']));
}

function react($action) {
    global $_SESSION, $_GET, $db, $settings, $jsonPost, $result;

	onlyUsers();
    switch ($action) {
		case 'newMessageCount':
            $result['result'] = true;
			
			$result['newMessageCount'] = getNewMessageCount($_SESSION['userid']);
			
			break;
        case 'list':
            $result['result'] = true;

			// get chats
			$chats = $db->prepare("SELECT A.id, A.employer, A.jobid, A.employerDeleted, A.workerDeleted, B.name AS contactName, B.img AS contactImg, C.title AS jobTitle FROM chat AS A"
				. " LEFT JOIN user AS B ON (A.employer!=? AND B.id=A.employer) OR (A.worker!=? AND B.id=A.worker)"
			  	. " LEFT JOIN jobs AS C ON C.id=A.jobid"								  
				. " WHERE A.employer=? OR A.worker=?");
			$chats->execute(array($_SESSION['userid'], $_SESSION['userid'], $_SESSION['userid'], $_SESSION['userid']));
			
			// get last message & newMessageCount for every chat
			$lastMessageWorker = $db->prepare("SELECT msg, timestamp, poster FROM chat_message WHERE chatid=? AND (poster!='system' OR (poster='system' AND msg NOT LIKE 'ratingEmployer-%')) AND id>? ORDER BY timestamp DESC LIMIT 1");
			$lastMessageEmployer = $db->prepare("SELECT msg, timestamp, poster FROM chat_message WHERE chatid=? AND (poster!='system' OR (poster='system' AND msg NOT LIKE 'ratingWorker-%' AND msg NOT LIKE 'offer-declined-%' AND msg!='jobassigned')) AND id>? ORDER BY timestamp DESC LIMIT 1");
			$newMessageCountWorker = $db->prepare("SELECT COUNT(id) FROM chat_message WHERE chatid=? AND (poster='employer' OR (poster='system' AND msg NOT LIKE 'ratingEmployer-%')) AND status='unread'");
			$newMessageCountEmployer = $db->prepare("SELECT COUNT(id) FROM chat_message WHERE chatid=? AND (poster='worker' OR (poster='system' AND msg NOT LIKE 'ratingWorker-%' AND msg NOT LIKE 'offer-declined-%' AND msg!='jobassigned')) AND status='unread'");

			$result['chats'] = array();
			foreach($chats->fetchAll(PDO::FETCH_ASSOC) as $chat) {
				$isEmployer = $chat['employer'] == $_SESSION['userid'];
				if ($isEmployer) {
					$newMessageCountEmployer->execute(array($chat['id']));
					$chat['newMessageCount'] = $newMessageCountEmployer->fetchColumn();
				} else {
					$newMessageCountWorker->execute(array($chat['id']));
					$chat['newMessageCount'] = $newMessageCountWorker->fetchColumn();
				}
				unset($chat['employer']);
				
				// find min messageid to send
				if ($isEmployer) {
					$minMsgId = isset($chat['employerDeleted']) ? $chat['employerDeleted'] : 0;
				} else {
					$minMsgId = isset($chat['workerDeleted']) ? $chat['workerDeleted'] : 0;
				}
				unset($chat['employerDeleted']);
				unset($chat['workerDeleted']);
				
				$lastMessage = $isEmployer
					? $lastMessageEmployer
					: $lastMessageWorker;
				$lastMessage->execute(array($chat['id'], $minMsgId));
				if ($lastMessage->rowCount() > 0) {
					$chat['lastMessage'] = $lastMessage->fetch(PDO::FETCH_ASSOC);
					
					//$chat['lastMessage']['timestamp'] = $date = date('d/m/Y', $chat['lastMessage']['timestamp']); //  H:i:s

					if ($chat['lastMessage']['poster'] == 'system') {
						$details = explode('-', $chat['lastMessage']['msg']);
						$chat['lastMessage']['typeStatus'] = $details[0] . '-' .
							(isset($details[1]) ? $details[1] . '-' : '') .
							($isEmployer ? 'employer' : 'worker');
					}


					$result['chats'][] = $chat;
				}
			}
			
            break;
        case 'details':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($_GET, array(
                "id" => "/^\d+$/"
                    ))
            ) {
				sendChatDetails($_GET['id']);
            }
			
            break;
        case 'deleteChat':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/"
                    ))
            ) {
				$result['message'] = deleteChat($jsonPost['id'], $_SESSION['userid']);
            }
			
            break;			
		case 'rapport':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/",
				"msg" => "/^.{".join(',', $settings['bounds']['rapport']['msg'])."}$/",
                    ))
            ) {
				$chat = $db->prepare("SELECT * FROM chat WHERE id=?");
				$chat->execute(array($jsonPost['id']));
				if ($chat->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// insert rapport into database
					$rapport = $db->prepare("INSERT INTO chat_rapports (uid, chatid, msg, timestamp) VALUES (?, ?, ?, ?)");
					$rapport->execute(array($_SESSION['userid'], $jsonPost['id'], $jsonPost['msg'], time()));
				}
            }
			
			break;			
		case 'blockUser':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "userid" => "/^\d+$/"
                    ))
            ) {
				$job = $db->prepare("SELECT * FROM user WHERE id=?");
				$job->execute(array($jsonPost['userid']));
				if ($job->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// insert user into blocked list for actual user
					$block = $db->prepare("INSERT INTO chat_blocked (uid, blocked, timestamp) VALUES (?, ?, ?)");
					$block->execute(array($_SESSION['userid'], $jsonPost['userid'], time()));
				}
            }
			
			break;			
		case 'unblockUser':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "userid" => "/^\d+$/"
                    ))
            ) {
				$job = $db->prepare("SELECT * FROM user WHERE id=?");
				$job->execute(array($jsonPost['userid']));
				if ($job->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// delete from blocked list for actual user
					$block = $db->prepare("DELETE FROM chat_blocked WHERE uid=? AND blocked=?");
					$block->execute(array($_SESSION['userid'], $jsonPost['userid']));
				}
            }
			
			break;			
        case 'start':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($_GET, array(
                "jobid" => "/^\d+$/"
                    ))
            ) {
				// check if chat for that job already exists
				$chat = $db->prepare("SELECT id FROM chat WHERE jobid=? AND (employer=? OR worker=?)");
				$chat->execute(array($_GET['jobid'], $_SESSION['userid'], $_SESSION['userid']));
				if ($chat->rowCount() > 0) {
					// chat already exists -> open it
					$chatid = $chat->fetchColumn();
					sendChatDetails($chatid);
				} else {
					// check if job exists & get employer info
					$job = $db->prepare("SELECT B.id AS contactId, B.name AS contactName, B.img AS contactImg, C.title AS jobTitle, C.img_preview AS jobImg, C.currency AS jobCurrency, C.price AS jobPrice, IF (C.worker IS NULL, 0, 1) AS hasWorker FROM jobs AS C"
						. " LEFT JOIN user AS B ON B.id=C.uid"
						. " WHERE C.id=?");
					$job->execute(array($_GET['jobid']));
					if ($job->rowCount() === 0) {
						$result['message'] = 'notexist';
					} else {
						// return chat details
						$result['chat'] = $job->fetch(PDO::FETCH_ASSOC);
						$result['chat']['id'] = $_GET['jobid'] * -1;
						$result['chat']['jobid'] = $_GET['jobid'];
						$result['chat']['messages'] = array();
						
						$result['chat']['hasWorker'] = $result['chat']['hasWorker'] == 1;
						$result['chat']['isEmployer'] = false;
						$result['chat']['offerStatus'] = 'none';
					}
				}
            }
			
            break;
		case 'send':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^-*\d+$/",				
                "msg" => "/^.{".join(',', $settings['bounds']['chatmsg'])."}$/"
                    ))
            ) {
				getChat($jsonPost['id'], function ($chatid, $userrole) {
					global $db, $jsonPost;

					// insert message into database
					$message = $db->prepare("INSERT INTO chat_message (chatid, poster, msg, timestamp) VALUES (?, ?, ?, ?)");
					$message->execute(array($chatid, $userrole, $jsonPost['msg'], time()));
					
					$shortMsg = strlen($jsonPost['msg']) > 25
						? substr($jsonPost['msg'], 0, 22) . '...'
						: $jsonPost['msg'];
					
					// push notification
					include_once('lib/pushnotif.php');
					sendChatPushNotif($chatid, $shortMsg);
				});
            }
			
			break;
		case 'makeOffer':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^-*\d+$/",				
                "currency" => "/^".join('|', array_keys($settings['currencies']))."$/",
                "price" => "/^\d+(\.\d{1,2})?$/"
                    ))
            ) {
				// find according jobid
				if ($jsonPost['id'] > 0) {
					$chat = $db->prepare("SELECT jobid FROM chat WHERE id=?");
					$chat->execute(array($jsonPost['id']));
					$jobid = $chat->fetchColumn();				
				} else {
					$jobid = $jsonPost['id'] * -1;
				}
				
				// ensure that job has no worker
				$job = $db->prepare("SELECT worker FROM jobs WHERE id=?");
				$job->execute(array($jobid));
				$worker = $job->fetchColumn();
				if ($worker) {
					$result['message'] = 'has worker';
				} else {
					getChat($jsonPost['id'], function ($chatid, $userrole) {
						global $db, $jsonPost;
						
						// check userrole
						if ($userrole !== 'worker') {
							$result['message'] = 'notexist';
						} else {
							// check offer status
							if (getOfferStatus($chatid) !== 'none') {
								$result['message'] = 'offerstatus';
							} else {
								// insert offer into database
								$message = $db->prepare("INSERT INTO chat_message (chatid, poster, msg, timestamp) VALUES (?, ?, ?, ?)");
								$message->execute(array($chatid, 'system', 'offer-open-' . $jsonPost['currency'] . '-' . $jsonPost['price'], time()));
								
								// push notification
								include_once('lib/pushnotif.php');
								sendChatPushNotif($chatid, null, 'makeOffer');
							}
						}						
					});
				}
            }
			
			break;
		case 'cancelOffer':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/"
                    ))
            ) {	
				// ensure user role and offer status
				ensureUserRoleAndOfferStatus($jsonPost['id'], 'worker', 'open', function ($offer) {
					global $db, $jsonPost;

					// check if user is not blocked by other part
					if (isBlocked($jsonPost['id'], 'worker')) {
						$result['message'] = 'blocked';
					} else {
						// update offer status
						$details = explode('-', $offer['msg']);
						$newDetails = 'offer-canceled-' . $details[2] . '-' . $details[3];

						$offerStm = $db->prepare("UPDATE chat_message SET msg=? WHERE id=?");
						$offerStm->execute(array($newDetails, $offer['id']));

						// push notification
						include_once('lib/pushnotif.php');
						sendChatPushNotif($jsonPost['id'], null, 'cancelOffer');
					}
				});
            }
			
			break;
		case 'acceptOffer':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/"
                    ))
            ) {
				// ensure user role and offer status
				ensureUserRoleAndOfferStatus($jsonPost['id'], 'employer', 'open', function ($offer) {
					global $db, $jsonPost;

					// get worker & jobid
					$chatStm = $db->prepare("SELECT worker, jobid FROM chat WHERE id=?");
					$chatStm->execute(array($jsonPost['id']));
					$chat = $chatStm->fetch(PDO::FETCH_ASSOC);

					// update job -> set worker
					$job = $db->prepare("UPDATE jobs SET worker=? WHERE id=?");
					$job->execute(array($chat['worker'], $chat['jobid']));

					// update offer status
					$details = explode('-', $offer['msg']);
					$newDetails = 'offer-accepted-' . $details[2] . '-' . $details[3];

					$offerStm = $db->prepare("UPDATE chat_message SET msg=? WHERE id=?");
					$offerStm->execute(array($newDetails, $offer['id']));

					// decline all other offers
					$chatStm = $db->prepare("SELECT id FROM chat WHERE jobid=? AND id!=?");
					$chatStm->execute(array($chat['jobid'], $jsonPost['id']));
					$chats = $chatStm->fetchAll(PDO::FETCH_ASSOC);

					foreach ($chats as $chat) {
						$offerStm = $db->prepare("SELECT id, msg FROM chat_message WHERE chatid=? AND poster='system' AND msg LIKE 'offer-open-%'");
						$offerStm->execute(array($chat['id']));
						$offer = $offerStm->fetch(PDO::FETCH_ASSOC);

						declineOffer($offer);

						// insert jobassigned message into chat
						$message = $db->prepare("INSERT INTO chat_message (chatid, poster, msg, timestamp) VALUES (?, ?, ?, ?)");
						$message->execute(array($chat['id'], 'system', 'jobassigned', time()));
					}

					// push notification
					include_once('lib/pushnotif.php');
					sendChatPushNotif($jsonPost['id'], null, 'acceptOffer');
				});
            }
			
			break;
		case 'declineOffer':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/"
                    ))
            ) {
				// ensure user role and offer status
				ensureUserRoleAndOfferStatus($jsonPost['id'], 'employer', 'open', function ($offer) {
					global $jsonPost;

					declineOffer($offer);

					// push notification
					include_once('lib/pushnotif.php');
					sendChatPushNotif($jsonPost['id'], null, 'declineOffer');
				});
            }
			
			break;
        default:
            $result['message'] = 'unknown action';
    }
}

?>
