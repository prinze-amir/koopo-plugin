<?php
// koopo/includes/dokan/koopo-dokan-upgrade.php
if ( ! defined( 'ABSPATH' ) ) {
    exit;
}

require_once __DIR__ . '/class-koopo-dokan-upgrade.php';

add_action( 'plugins_loaded', function() {
    Koopo_Dokan_Upgrade::instance();
} );
