<?php

// API main functions
include_once("lib/apicore.php");

function react($action) {
    global $_SESSION, $db, $settings, $jsonPost, $result;

	onlyAdmin();
    switch ($action) {
		case 'categories-add': 
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "name" => false
                    ))
            ) {
				// add category
				$category = $db->prepare("INSERT INTO categories SET name=?");
				$category->execute(array($jsonPost['name']));
            }
			
			break;
		case 'categories-edit': 
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/",
                "name" => false
                    ))
            ) {
				// check if category exists
				$category = $db->prepare("SELECT name FROM categories WHERE id=?");
				$category->execute(array($jsonPost['id']));
				if ($category->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					$oldName = $category->fetchColumn();
					
					// edit category name
					$category = $db->prepare("UPDATE categories SET name=? WHERE id=?");
					$category->execute(array($jsonPost['name'], $jsonPost['id']));

					// update jobs
					$jobs = $db->prepare("UPDATE jobs SET category=? WHERE category=?");
					$jobs->execute(array($jsonPost['name'], $oldName));
				}
            }
			
			break;
		case 'categories-delete':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "id" => "/^\d+$/",
                    ))
            ) {
				// check if job exists & job owner is actual user
				$category = $db->prepare("SELECT name FROM categories WHERE id=?");
				$category->execute(array($jsonPost['id']));
				if ($category->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					$oldName = $category->fetchColumn();
					
					// delete category from database
					$category = $db->prepare("DELETE FROM categories WHERE id=?");
					$category->execute(array($jsonPost['id']));
				}
            }
			
			break;			
		case 'user-search':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "input" => false
                    ))
            ) {
				$users = $db->prepare("SELECT id, name, email, img, blockComment FROM user WHERE ".(isset($jsonPost['onlyBlocked']) ? "status='blocked' AND" : '')." (LOWER(name) LIKE LOWER(?) OR LOWER(email) LIKE LOWER(?)) ORDER BY LOWER(name)");
				$users->execute(array('%'.$jsonPost['input'].'%', '%'.$jsonPost['input'].'%'));
				
				$result['users'] = $users->fetchAll(PDO::FETCH_ASSOC);
            }
			
			break;
		case 'user-block':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "userid" => "/^\d+$/",
                "comment" => false
                    ))
            ) {
				$user = $db->prepare("SELECT * FROM user WHERE id=?");
				$user->execute(array($jsonPost['userid']));
				if ($user->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// change user status to blocked
					$block = $db->prepare("UPDATE user SET img=NULL, description=NULL, status='blocked', blockComment=? WHERE id=?");
					$block->execute(array($jsonPost['comment'], $jsonPost['userid']));
				}
            }
			
			break;			
		case 'user-unblock':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "userid" => "/^\d+$/"
                    ))
            ) {
				$user = $db->prepare("SELECT * FROM user WHERE id=?");
				$user->execute(array($jsonPost['userid']));
				if ($user->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					// change user status to active
					$block = $db->prepare("UPDATE user SET status='active' WHERE id=?");
					$block->execute(array($jsonPost['userid']));
				}
            }
			
			break;
        case 'user-delete':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "userid" => "/^\d+$/"
                    ))
            ) {
				$user = $db->prepare("SELECT * FROM user WHERE id=?");
				$user->execute(array($jsonPost['userid']));
				if ($user->rowCount() === 0) {
					$result['message'] = 'notexist';
				} else {
					deleteUser($jsonPost['userid']);
				}
            }
			
            break;			
		case 'rapports-list':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($_GET, array(
                "type" => "/^user|jobs$/",
                "status" => "/^active|deleted$/"
                    ))
            ) {
				if ($_GET['type'] == 'user') {
					$rapports = $db->prepare(
						"SELECT A.id AS rapportid, A.rapportedUid AS id, A.msg, A.uid AS rapporterId, B.name, B.img, C.name AS rapporterName FROM user_rapports AS A"
						. " LEFT JOIN user AS B ON B.id=A.rapportedUid"
						. " LEFT JOIN user AS C ON C.id=A.uid"
						. " WHERE A.status=?"
						. " ORDER BY A.timestamp ASC"
					);
				} else {
					$rapports = $db->prepare(
						"SELECT A.id AS rapportid, A.jobid AS id, A.msg, A.uid AS rapporterId, B.title, B.img_preview AS img, C.name AS rapporterName FROM jobs_rapports AS A"
						. " LEFT JOIN jobs AS B ON B.id=A.jobid"
						. " LEFT JOIN user AS C ON C.id=A.uid"
						. " WHERE A.status=?"
						. " ORDER BY A.timestamp ASC"
					);
				}
				$rapports->execute(array($_GET['status']));
				
				$result['rapports'] = $rapports->fetchAll(PDO::FETCH_ASSOC);
            }
			
			break;			
		case 'rapports-delete':
            $result['result'] = true;
			
            if (!$result['message'] = hasErrors($jsonPost, array(
                "type" => "/^user|job$/",
                "status" => "/^active|deleted$/",
				"id" => "/^\d+$/"
                    ))
            ) {
				if ($jsonPost['status'] == 'active') {
					if ($jsonPost['type'] == 'user') {
						$rapports = $db->prepare("UPDATE user_rapports SET status='deleted' WHERE id=?");
					} else {
						$rapports = $db->prepare("UPDATE jobs_rapports SET status='deleted' WHERE id=?");
					}
				} else {
					if ($jsonPost['type'] == 'user') {
						$rapports = $db->prepare("DELETE FROM user_rapports WHERE id=?");
					} else {
						$rapports = $db->prepare("DELETE FROM jobs_rapports WHERE id=?");
					}
				}
				$rapports->execute(array($jsonPost['id']));
			}
			
			break;	
        default:
            $result['message'] = 'unknown action';
    }
}

?>
