<?php

// API main functions
include_once("lib/apicore.php");

function getUserFilters($userid) {
	global $db;

	$filtersStm = $db->prepare("SELECT * FROM filters WHERE uid=?");
	$filtersStm->execute(array($userid));
	if ($filtersStm->rowCount() === 0) {
		$filtersStm = $db->prepare("INSERT INTO filters SET uid=?");
		$filtersStm->execute(array($userid));
		$filters = array();
	} else {
		$filters = $filtersStm->fetch(PDO::FETCH_ASSOC);
		$filters['asAdmin'] = $filters['asAdmin'] ? true : false;
	}
	
	return $filters;
}

function doLogin($userid) {
	global $_SESSION, $db, $jsonPost, $result;
	
	$FCMtoken = isset($jsonPost['FCMtoken']) ? $jsonPost['FCMtoken'] : '';
	$platform = isset($jsonPost['platform']) ? $jsonPost['platform'] : '';
	
	// create distinct session id
	$sessionid = session_id();
	do {
		$user = $db->prepare("SELECT id FROM user WHERE sessionid=?");
		$user->execute(array($sessionid));

		$isDistinct = $user->rowCount() === 0;
		if (!$isDistinct) {
			session_regenerate_id(true);
			$sessionid = session_id();
		}
	} while (!$isDistinct);

	// write session id to database for lifelong sessions
	$user = $db->prepare("UPDATE user SET sessionid=?, FCMtoken=?, platform=? WHERE id=?");
	$user->execute(array($sessionid, $FCMtoken, $platform, $userid));

	// return session id & set session
	$result['sid'] = $sessionid;
	$_SESSION['userid'] = $userid;

	// return user data
	$result['user'] = array(
		'id' => $userid,
		'isLoggedin' => true,
		'isAdmin' => isAdmin(),
		'filters' => getUserFilters($userid)
	);
	$result['newMessageCount'] = getNewMessageCount($userid);
}

function react($action) {
    global $_SESSION, $db, $settings, $jsonPost, $result;

    switch ($action) {
        case 'log_status':
            $result['result'] = true;
			
			// return user data
            $result['user'] = array(
				'isLoggedin' => isUser(),
				'isAdmin' => isAdmin(),
				'id' => 0
			);
            if ($result['user']['isLoggedin']) {
				$result['user']['id'] = $_SESSION['userid'];
				$result['user']['filters'] = getUserFilters($_SESSION['userid']);
			}
			
			if ($result['user']['isLoggedin']) {
				$FCMtoken = $_GET['FCMtoken'] ? $_GET['FCMtoken'] : '';
				$platform = $_GET['platform'] ? $_GET['platform'] : '';

				$user = $db->prepare("UPDATE user SET FCMtoken=?, platform=? WHERE id=?");
				$user->execute(array($FCMtoken, $platform, $result['user']['id']));
				
				$result['newMessageCount'] = getNewMessageCount($result['user']['id']);
			}

            break;
        case 'logout':
			onlyUsers();

            $result['result'] = true;
			
			// remove session id from database
			$user = $db->prepare("UPDATE user SET sessionid='', FCMtoken=NULL WHERE id=?");
			$user->execute(array($_SESSION['userid']));			

            $_SESSION['userid'] = '';
			
            break;
        case 'login':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "email" => "/^[a-z0-9][a-z0-9_\.-]+@[a-z0-9][a-z0-9_\.-]+[a-z]{2,4}$/i",
                "password" => false,
                    ))
            ) {
                // verify login credentials
				$userQuery = $db->prepare("SELECT id, status FROM user WHERE email=? AND password=? AND facebookID IS NULL");
				$userQuery->execute(array($jsonPost['email'], md5($jsonPost['password'])));
                if ($userQuery->rowCount() === 0) {
                    // not existing
                    $result['message'] = 'notexist';
                    $_SESSION['userid'] = '';
                } else {
					// fetch userid & status
					$user = $userQuery->fetch(PDO::FETCH_ASSOC);
					if ($user['status'] == 'blocked') {
						$result['message'] = 'blocked';
						$_SESSION['userid'] = '';
					} else {
						doLogin($user['id']);
					}
                }
            }
            break;
		case 'signup':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
				"name" => "/^.{".join(',', $settings['bounds']['profile']['name'])."}$/",
                "email" => "/^[a-z0-9][a-z0-9_\.-]+@[a-z0-9][a-z0-9_\.-]+\.[a-z]{2,4}$/i",
                "password" => "/^.{".join(',', $settings['bounds']['profile']['password'])."}$/",
                "password2" => false,
                    ))
            ) {
                if ($jsonPost['password'] != $jsonPost['password2']) {
                    // ^ verify that both entered passwords are same
                    $result['message'] = 'badverify password';
                } else {
//                    // check if another user already uses this name
//                    $user = $db->prepare("SELECT id FROM user WHERE name=?");
//					$user->execute(array($jsonPost['name']));
//					if ($user->rowCount() > 0) {
//						$result['message'] = 'nameexist';
//					} else {
						// check if another user already uses this email
						$user = $db->prepare("SELECT id FROM user WHERE email=? AND facebookID IS NULL");
						$user->execute(array($jsonPost['email']));
						if ($user->rowCount() > 0) {
							$result['message'] = 'emailexist';
						} else {
                            // create new user
                            $creationDate = date('Y-m-d');
							
                            $newuser = $db->prepare("INSERT INTO user (name, email, password, dateOfCreation) VALUES (?,?,?,?)");
                            $newuser->execute(array($jsonPost['name'], $jsonPost['email'], md5($jsonPost['password']), $creationDate));

							// login user
                            $newuserid = $db->lastInsertId();
							doLogin($newuserid);
                        }
//                    }
                }
            }

            break;
        case 'facebook':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "token" => false
                    ))
            ) {
				// connect to facebook API per PHP SDK
				require "lib/Facebook/autoload.php";
				
				$fbAPI = new \Facebook\Facebook([
				  'app_id' => '835287049956899',
				  'app_secret' => '03f0139236c9cc72305c59ba8ccefa24',
				  'default_graph_version' => 'v2.9',
				  'default_access_token' => $jsonPost['token']
				]);

				try {
				  // Get the \Facebook\GraphNodes\GraphUser object for the current user.
				  $fbAPIresponse = $fbAPI->get('/me?fields=id,name,email');
				} catch(\Facebook\Exceptions\FacebookResponseException $e) {
				  // When Graph returns an error
				  $result['message'] = 'Graph returned an error: ' . $e->getMessage();
				} catch(\Facebook\Exceptions\FacebookSDKException $e) {
				  // When validation fails or other local issues
				  $result['message'] = 'Facebook SDK returned an error: ' . $e->getMessage();
				}
				
				if (!$result['message']) {
					// get facebook user details
					$fbAPIUser = $fbAPIresponse->getGraphUser();
					$fbUser = array(
						'id' => $fbAPIUser->getID(),
						'name' => $fbAPIUser->getName(),
						'email' => $fbAPIUser->getEmail()
					);				

					// check if already registered
					$user = $db->prepare("SELECT id FROM user WHERE facebookID=?");
					$user->execute(array($fbUser['id']));
					if ($user->rowCount() > 0) {
						// fetch userid
						$userid = $user->fetchColumn();

						doLogin($userid);
					} else {
						// signup
						// permutate name while another user already uses this name
						$newName = $fbUser['name'];
//						$user = $db->prepare("SELECT id FROM user WHERE name=?");
//						$user->execute(array($newName));
//						$no = 1;
//						while ($user->rowCount() > 0) {
//							$newName = $fbUser['name'] . '_' . ++$no;
//							$user = $db->prepare("SELECT id FROM user WHERE name=?");
//							$user->execute(array($newName));
//						};

						// create new user
						$creationDate = date('Y-m-d');

						// create user image from facebook image
						$image = file_get_contents('http://graph.facebook.com/' . $fbUser['id'] . '/picture?width=' . $settings['resize']['profile']['maxX'] . '&height=' . $settings['resize']['profile']['maxY']);
						if ($image) {
							// image functions
							include_once("lib/image.php");
							$imgCode = resizeImg('data:image/jpg;base64,'.base64_encode($image), $settings['resize']['profile']);

							$newuser = $db->prepare("INSERT INTO user (name, email, img, facebookID, dateOfCreation) VALUES (?, ?, ?, ?, ?)");
							$newuser->execute(array($newName, $fbUser['email'], $imgCode, $fbUser['id'], $creationDate));
						} else {
							$newuser = $db->prepare("INSERT INTO user (name, email, facebookID, dateOfCreation) VALUES (?, ?, ?, ?)");
							$newuser->execute(array($newName, $fbUser['email'], $fbUser['id'], $creationDate));
						}

						// login user
						$newuserid = $db->lastInsertId();
						doLogin($newuserid);
					}
				}
            }
            break;
        case "forgotpw":
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "email" => "/^[a-z0-9][a-z0-9_\.-]+@[a-z0-9][a-z0-9_\.-]+\.[a-z]{2,4}$/i",
                    ))
            ) {
                // get user from database
                $userQuery = $db->prepare("SELECT id, name, email FROM user WHERE email=? AND facebookID IS NULL");
                $userQuery->execute(array($jsonPost['email']));
                if ($userQuery->rowCount() > 0) {
                    $user = $userQuery->fetch(PDO::FETCH_ASSOC);

                    // create new password
                    $newpw = bin2hex(openssl_random_pseudo_bytes(5));

                    // update user
                    $updateQuery = $db->prepare("UPDATE user SET password=? WHERE id=?");
                    $updateQuery->execute(array(md5($newpw), $user['id']));

                    // send email
                    include_once('lib/email.php');
                    $emailReplace = array_merge($user, $settings);
                    $emailReplace['newpw'] = $newpw;
                    $email_txt = preg_split('/--BW--\n/', template('txt/email_lostpw_en.txt', $emailReplace));
                    sendmail($user['email'], $email_txt[0], $email_txt[1], $settings['projekt_email'], $settings['projekt_name']);
                } else {
                    $result['message'] = 'notexist';
                }
            }

            break;			
        default:
            $result['message'] = 'unknown action';
    }
}

?>
