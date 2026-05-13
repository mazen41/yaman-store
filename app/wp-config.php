<?php
/**
 * The base configuration for WordPress
 *
 * The wp-config.php creation script uses this file during the installation.
 * You don't have to use the website, you can copy this file to "wp-config.php"
 * and fill in the values.
 *
 * This file contains the following configurations:
 *
 * * Database settings
 * * Secret keys
 * * Database table prefix
 * * ABSPATH
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/
 *
 * @package WordPress
 */

// ** Database settings - You can get this info from your web host ** //
/** The name of the database for WordPress */
define( 'DB_NAME', "u945462263_loopapp" );

/** Database username */
define( 'DB_USER', "u945462263_loopapp" );

/** Database password */
define( 'DB_PASSWORD', "GZgaarazuhair@gmail.com123" );

/** Database hostname */
define( 'DB_HOST', "localhost" );

/** Database charset to use in creating database tables. */
define( 'DB_CHARSET', 'utf8mb4' );

/** The database collate type. Don't change this if in doubt. */
define( 'DB_COLLATE', '' );

/**#@+
 * Authentication unique keys and salts.
 *
 * Change these to different unique phrases! You can generate these using
 * the {@link https://api.wordpress.org/secret-key/1.1/salt/ WordPress.org secret-key service}.
 *
 * You can change these at any point in time to invalidate all existing cookies.
 * This will force all users to have to log in again.
 *
 * @since 2.6.0
 */
define( 'AUTH_KEY',         '6]%a$oSZu:<Eand2C3AsoCLv1?$cyAn31z(E6Ab.p<~g-q]HtZ$Qn{k}x|<=d,]t' );
define( 'SECURE_AUTH_KEY',  '^NgCf]}<f)zwA)FkPs|9+3HkK}gipt[K=;B0OGudA4[m+qU*Gk1zr4aP=ZR<;rCp' );
define( 'LOGGED_IN_KEY',    'j?&zuCop/%U&NU!iaU)(JEZ/.LNno3Z%*^wOQ]WgkmKj4!T.$.xRq8LoXHyo}LR]' );
define( 'NONCE_KEY',        'CsIwP+_X^wnJrE]$Jp64~=b^NrU8+C?>tUvrKG$&3D81TiK!0e-@GUSmB._(mHo9' );
define( 'AUTH_SALT',        ')E;C1LKNz!)AuglkUfhW[!Bi]lxf ~V{9?S``7T+S)m;+`Rwoiw[by-T`+(4y*1V' );
define( 'SECURE_AUTH_SALT', '{X2Yy<;bUEbUeaYCJOgX/eim0NW,#13qp!CD`B<vWC;k(8EBgK+0T  Z/VGx*zw_' );
define( 'LOGGED_IN_SALT',   'nr}>w`:q6Gy]o$3 vCIA~|wSq;I[<b{-W~GIQ#DhR%_X|u4v$v.Eg N>{[19[k`>' );
define( 'NONCE_SALT',       'R(bk.[oX$T)ZYBz{?]0=VVEXI8W(z[?8D@G8!8w~,UP~mKy}.t!eed<c6>$K/OWa' );

/**#@-*/

/**
 * WordPress database table prefix.
 *
 * You can have multiple installations in one database if you give each
 * a unique prefix. Only numbers, letters, and underscores please!
 *
 * At the installation time, database tables are created with the specified prefix.
 * Changing this value after WordPress is installed will make your site think
 * it has not been installed.
 *
 * @link https://developer.wordpress.org/advanced-administration/wordpress/wp-config/#table-prefix
 */
$table_prefix = 'zuzu_';

/**
 * For developers: WordPress debugging mode.
 *
 * Change this to true to enable the display of notices during development.
 * It is strongly recommended that plugin and theme developers use WP_DEBUG
 * in their development environments.
 *
 * For information on other constants that can be used for debugging,
 * visit the documentation.
 *
 * @link https://developer.wordpress.org/advanced-administration/debug/debug-wordpress/
 */
define( 'WP_DEBUG', false );
define('WP_MAX_EXECUTION_TIME', 500); // Replace 300 with your desired time in seconds

/* Add any custom values between this line and the "stop editing" line. */



/* That's all, stop editing! Happy publishing. */

/** Absolute path to the WordPress directory. */
if ( ! defined( 'ABSPATH' ) ) {
	define( 'ABSPATH', __DIR__ . '/' );
}

/** Sets up WordPress vars and included files. */
require_once ABSPATH . 'wp-settings.php';
