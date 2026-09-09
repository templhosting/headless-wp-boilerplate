<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Integration;

use PHPUnit\Framework\TestCase;

/**
 * Base for the integration suite: resolves the site under test to its blog ID
 * and URL, so a test can act on it without caring about the network shape.
 *
 * Single-site is the default, so the site under test is the one and only site.
 * Under multisite (TEMPL_HEADLESS_MULTISITE=1) the same tests run against the
 * sample subsite, exercising the subdirectory URL and a non-main blog ID. The
 * property names keep the `subsite_` prefix in both modes so the test bodies
 * read identically; on single-site it simply names the only site there is.
 */
abstract class IntegrationTestCase extends TestCase {

	/**
	 * The blog ID of the site under test, discovered once.
	 *
	 * @var int
	 */
	protected int $subsite_id;

	/**
	 * The home URL of the site under test.
	 *
	 * @var string
	 */
	protected string $subsite_url;

	protected function setUp(): void {
		parent::setUp();

		if ( is_multisite() ) {
			$slug = getenv( 'TEMPL_SAMPLE_SITE_SLUG' ) ?: 'customer-one';
			$site = self::find_site_by_path( '/' . $slug . '/' );

			$this->assertNotNull( $site, "The sample subsite '{$slug}' must exist. Reprovision with composer dev:reset:multisite." );

			$this->subsite_id  = (int) $site->blog_id;
			$this->subsite_url = trailingslashit( get_home_url( $this->subsite_id ) );

			return;
		}

		$this->subsite_id  = get_current_blog_id();
		$this->subsite_url = trailingslashit( get_home_url() );
	}

	/**
	 * Finds a site by its path.
	 *
	 * A path lookup rather than get_site_by_path(), because the dev network's
	 * domain carries a port (localhost:8090) while wp_parse_url(PHP_URL_HOST)
	 * strips it, so the domain-plus-path lookup never matches on this stack.
	 *
	 * @param string $path Site path, e.g. /customer-one/.
	 * @return \WP_Site|null
	 */
	protected static function find_site_by_path( string $path ): ?\WP_Site {
		$sites = get_sites(
			[
				'path'   => $path,
				'number' => 1,
			]
		);

		return $sites ? $sites[0] : null;
	}
}
