<?php
/**
 * Plugin Name: Templ Headless
 * Description: Per-site API keys for the headless network. Owns authentication for every REST endpoint this repo ships.
 * Version: 0.1.0
 * Requires at least: 7.1
 * Requires PHP: 8.3
 * Author: Templ
 * Author URI: https://templ.io/
 * License: MIT
 * Text Domain: templ-headless
 *
 * Must-use on purpose. Every feature plugin on this network authenticates
 * through Templ\Headless\Keys, so the key store cannot be something a site
 * administrator is able to deactivate: doing so would not lock the endpoints
 * down, it would take them offline.
 *
 * @package Templ\Headless
 */

namespace Templ\Headless;

defined( 'ABSPATH' ) || exit;

const VERSION = '0.1.0';

define( 'TEMPL_HEADLESS_DIR', __DIR__ );

/*
 * Required explicitly rather than autoloaded. PSR-4 maps class names to files
 * and this codebase has no classes, only namespaced functions, so there is
 * nothing for an autoloader to trigger on. The list below is the load order.
 */
require_once TEMPL_HEADLESS_DIR . '/src/keys/hash.php';
require_once TEMPL_HEADLESS_DIR . '/src/keys/store.php';
require_once TEMPL_HEADLESS_DIR . '/src/keys.php';
require_once TEMPL_HEADLESS_DIR . '/src/rest.php';
require_once TEMPL_HEADLESS_DIR . '/src/admin/screen.php';
require_once TEMPL_HEADLESS_DIR . '/src/admin.php';
require_once TEMPL_HEADLESS_DIR . '/src/cli.php';

/**
 * Registers every hook the plugin owns, by handing off to each module.
 *
 * @return void
 */
function bootstrap(): void {
	Keys\bootstrap();
	Rest\bootstrap();
	Admin\bootstrap();
	Cli\bootstrap();
}

bootstrap();
