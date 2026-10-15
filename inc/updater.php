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
 * The repository is private, so GitHub answers every unauthenticated request
 * with a 404 and the checker sees no updates. Each site therefore needs a
 * read-only token: define `ZAG_GITHUB_TOKEN` in wp-config.php, or set it in the
 * environment. It must never be committed to this repository. Without one the
 * checker is still registered, it just never finds a release.
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

$zag_github_token = defined( 'ZAG_GITHUB_TOKEN' ) ? ZAG_GITHUB_TOKEN : getenv( 'ZAG_GITHUB_TOKEN' );

if ( $zag_github_token ) {
	$zag_update_checker->setAuthentication( $zag_github_token );
}
