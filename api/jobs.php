<?php

// API main functions
include_once("lib/apicore.php");

function removeWorker($jobid, $workerid, $msg) {
	global $db;

	// delete worker from database
	$job = $db->prepare("UPDATE jobs SET worker=NULL WHERE id=?");
	$job->execute(array($jobid));

	// insert message into chat
	$chatStm = $db->prepare("SELECT id FROM chat WHERE jobid=? AND worker=?");
	$chatStm->execute(array($jobid, $workerid));
	$chatid = $chatStm->fetchColumn();

	$message = $db->prepare("INSERT INTO chat_message (chatid, poster, msg, timestamp) VALUES (?, ?, ?, ?)");
	$message->execute(array($chatid, 'system', $msg, time()));
	
	// push notification
	include_once('lib/pushnotif.php');
	sendChatPushNotif($chatid, null, $msg);
}

function react($action) {
    global $_SESSION, $db, $settings, $jsonPost, $result;

	onlyUsers();
    switch ($action) {
        case 'list':
            $result['result'] = true;

			// distinguish between jobs with filters (category, location & radius) or jobs from one specific user
			$asWorker = false;
			$asAdmin = false;
			if (isset($_GET['asAdmin']) && $_GET['asAdmin']=='true' && isAdmin()) {
				$isProfile = false;
				$asAdmin = true;

				$checkProps = array();
			} else if (isset($_GET['userrole']) && $_GET['userrole'] == 'worker') {
				$isProfile = true;
				$asWorker = true;

				$checkProps = array();
			} else if (isset($_GET['profileId']) || $asWorker) {
				$isProfile = true;

				$checkProps = array(
                	"profileId" => "/^\d+$/"
				);
			} else {
				$isProfile = false;
				
				$checkProps = array(
                	"locationCoords" => "/^-?\d{1,4}\.\d+,-?\d{1,4}\.\d+$/",
                	"radius" => "/^\d+$/",
                	"from" => "/^\d+$/"
				);
			}

            if (!$result['message'] = hasErrors($_GET, $checkProps)) {
				// if radius, check if in bounds
				if (!$asAdmin && !$isProfile && ($_GET['radius'] < $settings['bounds']['job']['locRadius'][0] || $_GET['radius'] > $settings['bounds']['job']['locRadius'][1])) {
					$result['message'] = 'radiusbounds';
				} else {
					// prepare db query
					if ($asAdmin)	{
						$jobs = $db->prepare("SELECT id, title, currency, price, img_preview AS img FROM jobs ORDER BY id DESC LIMIT ".$_GET['from'].", 4");
						$jobs->execute(array());
					} else if ($asWorker)	{
						$jobs = $db->prepare("SELECT id, title, currency, price, img_preview AS img FROM jobs WHERE worker=? AND status!='done' ORDER BY id DESC");
						$jobs->execute(array($_SESSION['userid']));
					} else if ($isProfile)	{		
						$jobs = $db->prepare("SELECT id, title, currency, price, img_preview AS img FROM jobs WHERE uid=? ORDER BY id DESC");
						$jobs->execute(array($_GET['profileId']));
					} else {
						// get lat / lng from location string
						$latLng = explode(',', $_GET['locationCoords']);
						$values = array($latLng[0], $latLng[1], $_GET['radius'], 111.045); // 69.0 for miles
						
						$whereAdd = '';
						if (isset($_GET['category']) && $_GET['category'] != '') {
							// check if category exists
							$category = $db->prepare("SELECT id FROM categories WHERE name=?");
							$category->execute(array($_GET['category']));
							if ($category->rowCount() == 0) {
								$result['message'] = 'category';
								return;
							}
							
							$whereAdd = 'AND category=?';
							$values[] = $_GET['category'];
						}
						
						// SQL query adapted from:
						// http://www.plumislandmedia.net/mysql/haversine-mysql-nearest-loc/
						$jobs = $db->prepare("SELECT id, title, currency, price, img, distance FROM (
												SELECT j.id, j.title, j.currency, j.price, j.img_preview AS img, j.category,
													p.radius,
													p.distance_unit
														* DEGREES(ACOS(COS(RADIANS(p.latpoint))
														* COS(RADIANS(j.locLat))
														* COS(RADIANS(p.longpoint - j.locLng))
														+ SIN(RADIANS(p.latpoint))
														* SIN(RADIANS(j.locLat))))
													AS distance
												FROM jobs AS j
												JOIN (
													SELECT ? AS latpoint, ? AS longpoint, ? AS radius, ? AS distance_unit
												) AS p
												WHERE j.locLat
													BETWEEN p.latpoint  - (p.radius / p.distance_unit)
													AND p.latpoint  + (p.radius / p.distance_unit)
												AND j.locLng
													BETWEEN p.longpoint - (p.radius / (p.distance_unit * COS(RADIANS(p.latpoint))))
													AND p.longpoint + (p.radius / (p.distance_unit * COS(RADIANS(p.latpoint))))
												AND j.worker IS NULL
											) AS d WHERE distance <= radius $whereAdd ORDER BY distance LIMIT ".$_GET['from'].", 4");
						$jobs->execute($values);
					}

					// for each job map the currency's short name
					$result['jobs'] = array_map(function ($job) {
						global $settings;

						$job['currency'] = array(
							'name' => $job['currency'],
							'short' => $settings['currencies'][$job['currency']]
						);

						return $job;
					}, $jobs->fetchAll(PDO::FETCH_ASSOC));
				}
			}
			
            break;
        case 'details':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($_GET, array(
                "id" => "/^\d+$/"
                    ))
            ) {
				$job = $db->prepare("SELECT *, CONCAT(locLat, ',', locLng) AS location FROM jobs WHERE id=?");
				$job->execute(array($_GET['id']));
				if ($job->rowCount() === 0) {
                    $result['message'] = 'notexist';
				} else {
					// return job details
					$result['job'] = $job->fetch(PDO::FETCH_ASSOC);
					if (!isset($_GET['shorten'])) {
						$result['job']['currency'] = array(
							'name' => $result['job']['currency'],
							'short' => $settings['currencies'][$result['job']['currency']]
						);
					}

					// return job images
					$result['job']['img'] = array();
					for ($i = 1; $i <= $settings['bounds']['job']['images'][1]; $i++) {
						if ($result['job']['img_' . $i] != '') {
							$result['job']['img'][] = $result['job']['img_' . $i];
						}
						unset($result['job']['img_' . $i]);
					}
					
					if ($result['job']['worker']) {
						if ($result['job']['uid'] == $_SESSION['userid']) {
							// return worker id
							$result['job']['workerId'] = $result['job']['worker'];
							// return worker name
							$worker = $db->prepare("SELECT name FROM user WHERE id=?");
							$worker->execute(array($result['job']['worker']));
							$result['job']['workerName'] = $worker->fetchColumn();
						} else if ($result['job']['worker'] == $_SESSION['userid']) {
							// return worker name
							$result['job']['isWorker'] = true;
						}
					}
					unset($result['job']['worker']);
					
					if (!isset($_GET['shorten'])) {					
						// return jobs poster details
						$poster = $db->prepare("SELECT id, name, img, rating FROM user WHERE id=?"); // , CONCAT(locLat, ',', locLng) AS location
						$poster->execute(array($result['job']['uid']));
						$result['job']['poster'] = $poster->fetch(PDO::FETCH_ASSOC);
					}
					unset($result['job']['uid']);
				}
            }
			
            break;
		case 'rapport':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/",
				"msg" => "/^.{".join(',', $settings['bounds']['rapport']['msg'])."}$/",
                    ))
            ) {
				$job = $db->prepare("SELECT * FROM jobs WHERE id=?");
				$job->execute(array($jsonPost['id']));
				if ($job->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// insert rapport into database
					$rapport = $db->prepare("INSERT INTO jobs_rapports (uid, jobid, msg, timestamp) VALUES (?, ?, ?, ?)");
					$rapport->execute(array($_SESSION['userid'], $jsonPost['id'], $jsonPost['msg'], time()));
				}
            }
			
			break;
        case 'create':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "title" => "/^.{".join(',', $settings['bounds']['job']['title'])."}$/",
//                "description" => "/^.{".join(',', $settings['bounds']['job']['description'])."}$/",
                "location" => "/^-?\d{1,4}\.\d+,-?\d{1,4}\.\d+$/",
				"locName" => false,
                "category" => false,
                "currency" => "/^".join('|', array_keys($settings['currencies']))."$/",
                "price" => "/^\d+(\.\d{1,2})?$/"
                    ))
            ) {
				if (!isset($jsonPost['description'])) {
					$jsonPost['description'] = '';
				}
				if (strlen($jsonPost['description']) > $settings['bounds']['job']['description'][1]) {
					$result['message'] = 'invalid description';
				} else {				
					// check if category exists
					$category = $db->prepare("SELECT id FROM categories WHERE name=?");
					$category->execute(array($jsonPost['category']));
					if ($category->rowCount() == 0) {
						$result['message'] = 'category';
					} else {
						// check if count of images is in bounds
						$imgCount = count($jsonPost['img']);
						if ($imgCount < $settings['bounds']['job']['images'][0] || $imgCount > $settings['bounds']['job']['images'][1]) {
							$result['message'] = 'imgcount';
						} else {
							// image functions
							include_once("lib/image.php");

							// get lat / lng from location string
							$latLng = explode(',', $jsonPost['location']);

							// check if user has not already posted a job with same title
							if (isset($jsonPost['id'])) {
								$job = $db->prepare("SELECT id FROM jobs WHERE uid=? AND title=? AND id!=?");
								$job->execute(array($_SESSION['userid'], $jsonPost['title'], $jsonPost['id']));
							} else {
								$job = $db->prepare("SELECT id FROM jobs WHERE uid=? AND title=?");
								$job->execute(array($_SESSION['userid'], $jsonPost['title']));
							}
							if ($job->rowCount() > 0) {
								$result['message'] = 'exist';
							} else {
								// check if job is new or edited
								if (isset($jsonPost['id'])) {
									if (!preg_match('/^\d+$/', $jsonPost['id'])) {
										$result['message'] = 'bad id';
									} else {
										// check if job belongs to user
										$job = $db->prepare("SELECT id FROM jobs WHERE uid=? AND id=? AND status!='done'");
										$job->execute(array($_SESSION['userid'], $jsonPost['id']));
										if ($job->rowCount() == 0) {
											$result['message'] = 'notbelong';
										} else {
											// prepare images
											$imgCol = array();
											$imgVal = array();
											$i = 1;
											foreach ($jsonPost['img'] as $imgCode) {
												$imgCol[] = 'img_' . $i . '=?';
												$imgVal[] = resizeImg($imgCode, $settings['resize']['normal']);
												$i++;
											}

											// prepare preview image
											$imgCol[] = 'img_preview=?';
											$imgVal[] = resizeImg($jsonPost['img'][0], $settings['resize']['preview']);

											$imgCol = join(',', $imgCol);

											// update jobs database
											$job = $db->prepare("UPDATE jobs SET title=?, description=?, category=?, currency=?, price=?, $imgCol, locName=?, locLat=?, locLng=? WHERE id=?");
											$job->execute(array_merge(
												array($jsonPost['title'], $jsonPost['description'], $jsonPost['category'], $jsonPost['currency'], $jsonPost['price']),
												$imgVal,
												array($jsonPost['locName'], $latLng[0], $latLng[1], $jsonPost['id'])
											));
										}							
									}
								} else {
									// prepare images
									$imgCol = array();
									$imgColPlaceholder = array();
									$imgVal = array();
									$i = 1;
									foreach ($jsonPost['img'] as $imgCode) {
										$imgCol[] = 'img_' . $i;
										$imgColPlaceholder[] = '?';
										$imgVal[] = resizeImg($imgCode, $settings['resize']['normal']);
										$i++;
									}

									// prepare preview image
									$imgCol[] = 'img_preview';
									$imgColPlaceholder[] = '?';
									$imgVal[] = resizeImg($jsonPost['img'][0], $settings['resize']['preview']);

									$imgCol = join(',', $imgCol);
									$imgColPlaceholder = join(',', $imgColPlaceholder);

									// insert into jobs database
									$job = $db->prepare("INSERT INTO jobs (uid, title, description, category, currency, price, $imgCol, locName, locLat, locLng) VALUES (?, ?, ?, ?, ?, ?, $imgColPlaceholder, ?, ?, ?)");
									$job->execute(array_merge(
										array($_SESSION['userid'], $jsonPost['title'], $jsonPost['description'], $jsonPost['category'], $jsonPost['currency'], $jsonPost['price']),
										$imgVal,
										array($jsonPost['locName'], $latLng[0], $latLng[1])
									));

									// return new job id
									$result['jobid'] = $db->lastInsertId();
								}
							}
						}
					}
				}
            }

            break;
		case 'delete':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/",
                    ))
            ) {
				// check if job exists & job owner is actual user or admin
				if (isAdmin()) {
					$job = $db->prepare("SELECT id FROM jobs WHERE id=?");
					$job->execute(array($jsonPost['id']));
				} else {
					$job = $db->prepare("SELECT id FROM jobs WHERE id=? AND uid=?");
					$job->execute(array($jsonPost['id'], $_SESSION['userid']));
				}
				if ($job->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {					
					// delete job from database
					$job = $db->prepare("DELETE FROM jobs WHERE id=?");
					$job->execute(array($jsonPost['id']));

					// delete job_rapports from database
					$job = $db->prepare("DELETE FROM jobs_rapports WHERE jobid=?");
					$job->execute(array($jsonPost['id']));
				}
            }
			
			break;			
		case 'leave':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/",
                    ))
            ) {
				// check if job exists & worker is actual user
				$job = $db->prepare("SELECT id FROM jobs WHERE id=? AND worker=? AND status!='done'");
				$job->execute(array($jsonPost['id'], $_SESSION['userid']));
				if ($job->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {					
					removeWorker($jsonPost['id'], $_SESSION['userid'], 'workerleft');
				}
            }
			
			break;			
		case 'removeWorker':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/",
                "workerId" => "/^\d+$/",
                    ))
            ) {
				// check if job exists & job owner is actual user
				$job = $db->prepare("SELECT id FROM jobs WHERE id=? AND uid=? AND status!='done'");
				$job->execute(array($jsonPost['id'], $_SESSION['userid']));
				if ($job->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					removeWorker($jsonPost['id'], $jsonPost['workerId'], 'workerremoved');
				}
            }
			
			break;			
		case 'markDone':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/"
                    ))
            ) {
				// check if job exists & job owner is actual user
				$job = $db->prepare("SELECT worker FROM jobs WHERE id=? AND uid=? AND status!='done'");
				$job->execute(array($jsonPost['id'], $_SESSION['userid']));
				if ($job->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// get workerid
					$workerid = $job->fetchColumn();
					
					// set job status -> done
					$job = $db->prepare("UPDATE jobs SET status='done' WHERE id=?");
					$job->execute(array($jsonPost['id']));
					
					// insert rating messages into chat
					$chatStm = $db->prepare("SELECT id FROM chat WHERE jobid=? AND worker=?");
					$chatStm->execute(array($jsonPost['id'], $workerid));
					$chatid = $chatStm->fetchColumn();

					$message = $db->prepare("INSERT INTO chat_message (chatid, poster, msg, timestamp) VALUES (?, ?, ?, ?)");
					$message->execute(array($chatid, 'system', 'ratingEmployer-open', time()));
					$message->execute(array($chatid, 'system', 'ratingWorker-open', time()));
					
					// push notification
					include_once('lib/pushnotif.php');
					sendChatPushNotif($chatid, null, 'markDone');
				}
            }
			
			break;			
		case 'rate':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/",
				"stars" => "/^[0-5]$/",
				"text" => "/^.{".join(',', $settings['bounds']['rating'])."}$/"
                    ))
            ) {
				// check if (done) job exists & job owner or worker is actual user
				$jobStm = $db->prepare("SELECT uid AS employer, worker FROM jobs WHERE id=? AND (uid=? OR worker=?) AND status='done'");
				$jobStm->execute(array($jsonPost['id'], $_SESSION['userid'], $_SESSION['userid']));
				if ($jobStm->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// get employer & worker
					$job = $jobStm->fetch(PDO::FETCH_ASSOC);
					
					$isEmployer = $job['employer'] == $_SESSION['userid'];
					$ratedUser = $isEmployer ? $job['worker'] : $job['employer'];
					
					// check that no rating exists
					$rating = $db->prepare("SELECT id FROM ratings WHERE uid=? AND poster=? AND jobid=?");
					$rating->execute(array($ratedUser, $_SESSION['userid'], $jsonPost['id']));
					if ($rating->rowCount() > 0) {
						$result['message'] = 'exist';
					} else {
						// insert rating into database
						$rating = $db->prepare("INSERT INTO ratings (uid, poster, jobid, rating, description, timestamp) VALUES (?, ?, ?, ?, ?, ?)");
						$rating->execute(array($ratedUser, $_SESSION['userid'], $jsonPost['id'], $jsonPost['stars'], $jsonPost['text'], time()));
						
						// update rating message in chat
						// get chatid
						$chatStm = $db->prepare("SELECT id FROM chat WHERE jobid=? AND worker=?");
						$chatStm->execute(array($jsonPost['id'], $job['worker']));
						$chatid = $chatStm->fetchColumn();
						
						// find existing rating msg in chat
						$ratingMsgStm = $db->prepare("SELECT id, msg FROM chat_message WHERE chatid=? AND poster='system' AND msg='rating" . ($isEmployer ? 'Employer' : 'Worker') . "-open'");
						$ratingMsgStm->execute(array($chatid));
						if ($ratingMsgStm->rowCount() == 0) {
							$result['message'] = 'notexist chatmsg';
						} else {
							$ratingMsg = $ratingMsgStm->fetch(PDO::FETCH_ASSOC);

							// construct new rating message
							$details = explode('-', $ratingMsg['msg']);
							$newDetails = $details[0] . '-rated';

							// update rating message
							$ratingMsgStm = $db->prepare("UPDATE chat_message SET msg=? WHERE id=?");
							$ratingMsgStm->execute(array($newDetails, $ratingMsg['id']));
							
							// update rated user average rating
							$userRating = $db->prepare("UPDATE user SET rating=(SELECT AVG(rating) FROM ratings WHERE uid=?) WHERE id=?");
							$userRating->execute(array($ratedUser, $ratedUser));

//							// push notification
//							include_once('lib/pushnotif.php');
//							sendChatPushNotif($chatid, null, 'rate');
						}
					}
				}
            }
			
			break;			
        default:
            $result['message'] = 'unknown action';
    }
}

?>
