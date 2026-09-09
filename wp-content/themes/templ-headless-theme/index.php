<?php
/**
 * Intentionally empty.
 *
 * WordPress refuses to recognise a directory as a theme without this file, but
 * it is never reached: functions.php redirects on `template_redirect`, which
 * runs before the template loader picks a file.
 *
 * @package Templ\Headless\Theme
 */

defined( 'ABSPATH' ) || exit;
