<?php
/**
 * Over-the-air updates from GitHub releases.
 *
 * Same flow as wp-toolkit: Plugin Update Checker (vendored, unmodified v5.6)
 * polls the repository's latest release and offers it in Dashboard → Updates.
 * `enableReleaseAssets()` makes it install the zip that `release.sh` attaches
 * to the release, not GitHub's auto-generated source archive, which would ship
 * ref/, src/, tests/ and docs/ and unpack into a folder named after the tag.
 *
 * The repository is public, so no access token is configured. If it ever goes
 * private, `setAuthentication()` needs a token, and that token must come from
 * wp-config.php or the environment, never from a file in this repository.
 */

namespace Zag;

use YahnisElsts\PluginUpdateChecker\v5\PucFactory;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once ZAG_PATH . 'plugin-update-checker/plugin-update-checker.php';

$zag_update_checker = PucFactory::buildUpdateChecker(
	'https://github.com/DylanDuff/zag-filters-for-bricks/',
	ZAG_FILE,
	'zag-filters-for-bricks'
);

$zag_update_checker->setBranch( 'main' );
$zag_update_checker->getVcsApi()->enableReleaseAssets();
