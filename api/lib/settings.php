<?php

// advanced error reporting (for development & debugging only)
error_reporting(E_ALL | E_STRICT);
// set servers timezone
//date_default_timezone_set('Europe/Berlin');
date_default_timezone_set('Europe/Oslo');

$settings = array(
    // project
    'projekt_email'	=> 'system@jobyy.no',
    'projekt_name'	=> 'Jobyy.no App',
    'projekt_url'	=> 'jobyy.no',
	'projekt_info' => "Jobyy.no\nsupport@jobyy.no",
    // database
    'db_host' => 'localhost',
    'db_name' => 'jobapp',
    'db_user' => 'jobapp',
    'db_pass' => 'wuBywJZ8y45ETEzc',
	// FCM (Firebase Cloud Messaging)
	'fcm' => array(
		'API_key' => 'AIzaSyDdfSDs-c_GnZ88OvG6vQpQRhdkRIvSbBk',
		'package_name' => 'no.joby.app'
	),
	// others
	'currencies' => array(
		'NOK' => 'NOK',
		'EUR' => '€'
	),
	'bounds' => array(
		'job' => array(
			'title' 		=> array(10, 50),
			'description'	=> array(20, 700),
			'images'		=> array(1, 4),
			'locRadius'		=> array(1, 200)
		),
		'rapport' => array(
			'msg' => array(25, 500)
		),
		'profile' => array(
			'name'			=> array(2, 25),
			'description'	=> array(20, 250),
			'password'		=> array(8, 25)
		),
		'chatmsg' => array(1, 500),
		'rating' => array(10, 255)
	),
	'resize' => array(
		'preview' => array(
			'maxX' => 400,
			'maxY' => 400,
			'quality' => 75,
			'cropFactor' => 1.2
		),
		'normal' => array(
			'maxX' => 600,
			'maxY' => 1200,
			'quality' => 75
		),
		'profile' => array(
			'maxX' => 100,
			'maxY' => 100,
			'quality' => 75
		)
	)
);

?>
