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
define('WP_CACHE', true);
define( 'WPCACHEHOME', '/var/www/html/wp-content/plugins/wp-super-cache/' );
define( 'DB_NAME', 'alphawolf' );

/** MySQL database username */
define( 'DB_USER', 'alpha' );

/** MySQL database password */
define( 'DB_PASSWORD', '' );

/** MySQL hostname */
define( 'DB_HOST', 'wordpress-mysql' );

/** Database Charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8' );

/** The Database Collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication Unique Keys and Salts.
 *
 * Change these to different unique phrases!
 * You can generate these using the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}
 * You can change these at any point in time to invalidate all existing cookies. This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define('AUTH_KEY',         ']J5i)TE>AdLg4Zr#8hImP>h?]&p!|_/di3ZX9G]9@VS^|2xEKAqmpjb<`1N-U8R1');
define('SECURE_AUTH_KEY',  'Y)w+$XzsEd-Dp@FPc$_NP]A:+F:pl3iaYXm.DH$e`H7!] |@ZnA(1}SP9T&oRabI');
define('LOGGED_IN_KEY',    '{QM|$vq_!RAxM&12x>pRHvq<*+YIk^RI5xp-v+v,x0|UU@-K=U@S47j7:~_{~!|t');
define('NONCE_KEY',        '_n#{pNAs@p7vbsp}b!c}]3N+Azq/&c7,Y`e)mf$R_0)9*J/_b<T5,E]6&bzOIq)Z');
define('AUTH_SALT',        'fvwfC37f4g9yi1~nq|Zkl1lZOn BaasAXZL`0>3>m;]T/ajKAo&si(|t{BJnIr-)');
define('SECURE_AUTH_SALT', '`Q?A;FJW*{plKSkr+%<RR095?$woAH4p-ng8fq[={7u,aS|{|%gR|p5.AF_7=( G');
define('LOGGED_IN_SALT',   'EopP(X8|~o]AF*8~CA(hW-9Tgbi+uGy9]u{4_f|Y+_A>+t-/s8qU0Lrac*:!rI;)');
define('NONCE_SALT',       '|_iVKxZaPmm-/+W8YCgx5UUDp|y.;u9Tf!.H,W!Ix(c~Mv|B/vwKbpie&gSV-PMF');

/**#@-*/

/**
 * WordPress Database Table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 */
$table_prefix = 'wp_';

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
define( 'WP_DEBUG', false );

/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', dirname( __FILE__ ) . '/' );
}

/** Sets up WordPress vars and included files. */
require_once( ABSPATH . 'wp-settings.php' );
