<?php
/**
 * Loader for the Templ Headless must-use plugin.
 *
 * WordPress loads must-use plugins by scanning wp-content/mu-plugins for PHP
 * files at the top level only: it never descends into subdirectories. A
 * must-use plugin that is more than one file therefore needs a stub like this
 * one beside the directory it loads.
 *
 * Nothing else belongs in here.
 *
 * @package Templ\Headless
 */

defined( 'ABSPATH' ) || exit;

require_once __DIR__ . '/templ-headless/plugin.php';
