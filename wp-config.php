<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the
 * installation. You don't have to use the web site, you can
 * copy this file to "wp-config.php" and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * MySQL settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://codex.wordpress.org/Editing_wp-config.php
 *
 * @package WordPress
 */

// ** MySQL settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define('DB_NAME', 'wordpress');

/** MySQL database username */
define('DB_USER', 'wordpress');

/** MySQL database password */
define('DB_PASSWORD', 'IRrnYEnjtXoPA0kG');

/** MySQL hostname */
define('DB_HOST', 'localhost');

/** Database Charset to use in creating database tables. */
define('DB_CHARSET', 'utf8');

/** The Database Collate type. Don't change this if in doubt. */
define('DB_COLLATE', '');

define('FS_METHOD', 'direct');


/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         'Ug+(cKe-N`6@/[p+{i-MZo|#yT$+j{F;$34xx8P:LFH%QIJK9^OA/wmfxW#+JO[u');
define('SECURE_AUTH_KEY',  '?75P0RNvEq9sR6#c-*=.{weg!A{GXKiJb}}W i|n`7e<P6oNmvS+p+G$/+p8~ufj');
define('LOGGED_IN_KEY',    '.S-<)9]/[-aObHgx{,G+^(tW{qA{r)qd~+Vb-(njJb5u0yX<ZA;0w]V,;y`kiMVC');
define('NONCE_KEY',        '<:q?p&$W,4@uTHj?XQVtAVaiEiNvPSkXFt)`9`4>Csl|V)!8oo,J,9~8qbVo.DRj');
define('AUTH_SALT',        '::3xZwxc[9,>xr]_@S|k+)<}xvt-72i0LR hn#=R3@VrT&1jBk N?IR5,}#]1;<#');
define('SECURE_AUTH_SALT', 'reUuutW?}p/&V?`Rmbu{`NjMsy-_lQ_p[bPtKD yR0Wo^9%F_2}8jjfF3e_HNt1-');
define('LOGGED_IN_SALT',   'bl+:#Rt8fkeh;u6Iof)GFJU-O(ms9%ut>,@_>0Q-[%4i=.i=}R$HYyNFH4H.KSE^');
define('NONCE_SALT',       'd;31OR,~69/SqhD+j7HxCtM#x)zH<(UpE-S$cC*N7_lOs|z)$)n-4Ej35,<KZE:M');
/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix  = 'wp_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the Codex.
 *
 * @link https://codex.wordpress.org/Debugging_in_WordPress
 */
define('WP_DEBUG', false);

/* That's all, stop editing! Happy blogging. */

/** Absolute path to the WordPress directory. */
if ( !defined('ABSPATH') )
	define('ABSPATH', dirname(__FILE__) . '/');

/** Sets up WordPress vars and included files. */
require_once(ABSPATH . 'wp-settings.php');
