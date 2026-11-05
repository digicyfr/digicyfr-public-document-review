<?php
/**
 * Plugin Name: Digicyfr Public Document Review
 * Description: A private inventory of published PDF links with attachment review dates and owners.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Digicyfr
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html
 * Text Domain: digicyfr-public-document-review
 * Domain Path: /languages
 * Update URI: https://digicyfr.com/plugins/digicyfr-public-document-review/
 *
 * @package Digicyfr_Public_Document_Review
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'DIGICYFR_PUBLIC_DOCUMENT_REVIEW_VERSION', '0.1.0' );
define( 'DIGICYFR_PUBLIC_DOCUMENT_REVIEW_PATH', plugin_dir_path( __FILE__ ) );
define( 'DIGICYFR_PUBLIC_DOCUMENT_REVIEW_URL', plugin_dir_url( __FILE__ ) );

require_once DIGICYFR_PUBLIC_DOCUMENT_REVIEW_PATH . 'includes/class-dpdr-storage.php';
require_once DIGICYFR_PUBLIC_DOCUMENT_REVIEW_PATH . 'includes/class-dpdr-scanner.php';
require_once DIGICYFR_PUBLIC_DOCUMENT_REVIEW_PATH . 'includes/class-dpdr-run.php';
require_once DIGICYFR_PUBLIC_DOCUMENT_REVIEW_PATH . 'includes/class-dpdr-review.php';
require_once DIGICYFR_PUBLIC_DOCUMENT_REVIEW_PATH . 'includes/class-dpdr-admin.php';

register_activation_hook( __FILE__, array( 'DPDR_Storage', 'install' ) );
DPDR_Admin::boot();
