<?php
/**
 * Koopo: Stories Feature (Shim)
 *
 * This file is intentionally kept for backward compatibility with koopo.php,
 * but all logic now lives under includes/stories/.
 */
if ( ! defined('ABSPATH') ) exit;

if ( ! defined('KOOPO_STORIES_VER') ) {
    define('KOOPO_STORIES_VER', '1.0.0');
}

require_once KOOPO_PATH . 'includes/stories/class-stories-module.php';

/**
 * Back-compat class name used by koopo.php.
 */
class Koopo_Stories_Feature {
    public function init() : void {
        Koopo_Stories_Module::instance()->init();
    }
}
