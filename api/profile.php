<?php

// API main functions
include_once("lib/apicore.php");

function react($action) {
    global $_SESSION, $db, $settings, $jsonPost, $result;

	onlyUsers();
    switch ($action) {
        case 'details':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($_GET, array(
                "id" => "/^\d+$/"
                    ))
            ) {
				// check if user exists
				$user = $db->prepare("SELECT id, status, name, img, description, CONCAT(locLat, ',', locLng) AS location, rating FROM user WHERE id=?");
				$user->execute(array($_GET['id']));
				if ($user->rowCount() === 0) {
                    $result['message'] = 'notexist';
				} else {
					// return user details
					$result['user'] = $user->fetch(PDO::FETCH_ASSOC);
					
//					// return users posted job count
//					$jobs = $db->prepare("SELECT COUNT(*) AS rowcount FROM jobs WHERE uid=?");
//					$jobs->execute(array($_GET['id']));
//					$result['user']['jobs'] = $jobs->fetchColumn();
	
					if (isset($_GET['includeRatingDetails'])) {
						// return users rating details
						$ratings = $db->prepare("SELECT A.rating, A.description, B.id, B.name, B.img FROM ratings AS A LEFT JOIN user AS B ON B.id=A.poster WHERE A.uid=? ORDER BY A.id DESC");
						$ratings->execute(array($_GET['id']));
						$result['user']['ratings'] = $ratings->fetchAll(PDO::FETCH_ASSOC);
						
						$blocked = $db->prepare("SELECT id FROM chat_blocked WHERE (uid=? AND blocked=?) OR (uid=? AND blocked=?)");
						$blocked->execute(array($_SESSION['userid'], $_GET['id'], $_GET['id'], $_SESSION['userid']));
						$result['user']['hasBlocked'] = $blocked->fetchColumn();
					}
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
				$job = $db->prepare("SELECT * FROM user WHERE id=?");
				$job->execute(array($jsonPost['id']));
				if ($job->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// insert rapport into database
					$rapport = $db->prepare("INSERT INTO user_rapports (uid, rapportedUid, msg, timestamp) VALUES (?, ?, ?, ?)");
					$rapport->execute(array($_SESSION['userid'], $jsonPost['id'], $jsonPost['msg'], time()));
				}
            }
			
			break;			
        case 'owndetails':
            $result['result'] = true;

			// get details of own profile
			$user = $db->prepare("SELECT name, email, img, description, locName, CONCAT(locLat, ',', locLng) AS location, pushnotif FROM user WHERE id=?");
			$user->execute(array($_SESSION['userid']));
			
			// return user details
			$result['user'] = $user->fetch(PDO::FETCH_ASSOC);
			
            break;
        case 'img':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "img" => false
                    ))
            ) {
				// image functions
				include_once("lib/image.php");
				
				// update profile image
				$user = $db->prepare("UPDATE user SET img=? WHERE id=?");
				$user->execute(array(
					resizeImg($jsonPost['img'], $settings['resize']['profile']),
					$_SESSION['userid']
				));
            }
			
            break;
        case 'name':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "name" => "/^.{".join(',', $settings['bounds']['profile']['name'])."}$/",
                    ))
            ) {
//				// check if another user already uses this name
//				$user = $db->prepare("SELECT id FROM user WHERE name=? AND id!=?");
//				$user->execute(array($jsonPost['name'], $_SESSION['userid']));
//				if ($user->rowCount() > 0) {
//                    $result['message'] = 'nameexist';
//				} else {
					// update profile name
					$user = $db->prepare("UPDATE user SET name=? WHERE id=?");
					$user->execute(array($jsonPost['name'], $_SESSION['userid']));
//				}
            }
			
            break;
        case 'description':
            $result['result'] = true;

			if (!isset($jsonPost['description'])) {
				$jsonPost['description'] = '';
			}
			if (strlen($jsonPost['description']) > $settings['bounds']['profile']['description'][1]) {
				$result['message'] = 'invalid description';
			} else {
//			if (!$result['message'] = hasErrors($jsonPost, array(
//                "description" => "/^.{".join(',', $settings['bounds']['profile']['description'])."}$/",
//                    ))
//            ) {
				// update profile description
				$user = $db->prepare("UPDATE user SET description=? WHERE id=?");
				$user->execute(array($jsonPost['description'], $_SESSION['userid']));
            }
			
            break;
        case 'email':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "email" => "/^[a-z0-9][a-z0-9_\.-]+@[a-z0-9][a-z0-9_\.-]+\.[a-z]{2,4}$/i",
                    ))
            ) {
				// check if another user already uses this email
				$user = $db->prepare("SELECT id FROM user WHERE email=? AND id!=?");
				$user->execute(array($jsonPost['email'], $_SESSION['userid']));
				if ($user->rowCount() > 0) {
                    $result['message'] = 'emailexist';
				} else {
					// update profile email
					$user = $db->prepare("UPDATE user SET email=? WHERE id=?");
					$user->execute(array($jsonPost['email'], $_SESSION['userid']));
				}
            }
			
            break;
        case 'location':
            $result['result'] = true;

			$jsonPost = $jsonPost['location'];			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "location" => "/^-?\d{1,4}\.\d+,-?\d{1,4}\.\d+$/",
				"locName" => false
                    ))
            ) {
				// get lat / lng from location string
				$latLng = explode(',', $jsonPost['location']);
				
				// update profile location
				$user = $db->prepare("UPDATE user SET locName=?, locLat=?, locLng=? WHERE id=?");
				$user->execute(array($jsonPost['locName'], $latLng[0], $latLng[1], $_SESSION['userid']));
            }
			
            break;
        case 'password':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
                "password" => "/^.{".join(',', $settings['bounds']['profile']['password'])."}$/",
                    ))
            ) {
				// update profile password
				$user = $db->prepare("UPDATE user SET password=? WHERE id=?");
				$user->execute(array(md5($jsonPost['password']), $_SESSION['userid']));
            }
			
            break;
        case 'pushnotif':
            $result['result'] = true;

			// update profile pushnotif
			$user = $db->prepare("UPDATE user SET pushnotif=? WHERE id=?");
			$user->execute(array($jsonPost['pushnotif'] == 1 ? 1 : 0, $_SESSION['userid']));
			
            break;
        case 'deleteAccount':
            $result['result'] = true;
			
			deleteUser($_SESSION['userid']);
			
            break;
        case 'filters':
            $result['result'] = true;

            if (!$result['message'] = hasErrors($jsonPost, array(
				"location" => false,
                "locationCoords" => "/^-?\d{1,4}\.\d+,-?\d{1,4}\.\d+$/",
				"radius" => "/^\d+$/",
                    ))
            ) {
				if (!isset($jsonPost['category'])) {
					$jsonPost['category'] = '';
				}
				$jsonPost['asAdmin'] = isset($jsonPost['asAdmin']) && $jsonPost['asAdmin'] ? 1 : 0;
				
				// update filters
				$user = $db->prepare("UPDATE filters SET category=?, location=?, locationCoords=?, radius=?, asAdmin=? WHERE uid=?");
				$user->execute(array($jsonPost['category'], $jsonPost['location'], $jsonPost['locationCoords'], $jsonPost['radius'], $jsonPost['asAdmin'], $_SESSION['userid']));
				
				// update user location (if not set)
				$locStm = $db->prepare("SELECT locName FROM user WHERE id=?");
				$locStm->execute(array($_SESSION['userid']));
				if ($locStm->fetchColumn() == '') {				
					// get lat / lng from location string
					$latLng = explode(',', $jsonPost['locationCoords']);

					// update profile location
					$user = $db->prepare("UPDATE user SET locName=?, locLat=?, locLng=? WHERE id=?");
					$user->execute(array($jsonPost['location'], $latLng[0], $latLng[1], $_SESSION['userid']));
				}
            }
			
            break;
        default:
            $result['message'] = 'unknown action';
    }
}

?>
