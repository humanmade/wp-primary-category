<?php
/**
 * Plugin Name: HM Primary Category
 * Plugin URI: https://github.com/humanmade/wp-primary-category
 * Description: Lets an editor mark one term per taxonomy as a post's primary one, and makes WordPress return it first.
 * Version: 0.1.0
 * Author: Human Made Limited
 * Author URI: https://humanmade.com
 * Text Domain: hm-primary-category
 * Requires at least: 6.6
 * Requires PHP: 8.1
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 *
 * @package HM\Primary_Term
 */

namespace HM\Primary_Term;

// `require_once` so this is a no-op when Composer's `files` autoload has
// already loaded them, which it will have in a Composer-managed install.
require_once __DIR__ . '/inc/api.php';
require_once __DIR__ . '/inc/cli.php';
require_once __DIR__ . '/inc/editor.php';
require_once __DIR__ . '/inc/migrate.php';
require_once __DIR__ . '/inc/namespace.php';

bootstrap();
