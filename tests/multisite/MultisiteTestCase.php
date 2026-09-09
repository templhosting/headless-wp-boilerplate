<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Multisite;

use PHPUnit\Framework\TestCase;

/**
 * Base for the multisite suite: resolves the main site and the sample subsite,
 * and creates a scratch second subsite where a test needs two tenants.
 */
abstract class MultisiteTestCase extends TestCase {

	/**
	 * The network's main site blog ID.
	 *
	 * @var int
	 */
	protected int $main_id;

	/**
	 * The main site's home URL.
	 *
	 * @var string
	 */
	protected string $main_url;

	/**
	 * The sample subsite's blog ID.
	 *
	 * @var int
	 */
	protected int $subsite_id;

	/**
	 * The sample subsite's home URL.
	 *
	 * @var string
	 */
	protected string $subsite_url;

	protected function setUp(): void {
		parent::setUp();

		$this->main_id  = (int) get_main_site_id();
		$this->main_url = trailingslashit( get_home_url( $this->main_id ) );

		$slug = getenv( 'TEMPL_SAMPLE_SITE_SLUG' ) ?: 'customer-one';
		$site = self::find_site_by_path( '/' . $slug . '/' );

		$this->assertNotNull( $site, "The sample subsite '{$slug}' must exist. Reprovision with composer dev:reset." );

		$this->subsite_id  = (int) $site->blog_id;
		$this->subsite_url = trailingslashit( get_home_url( $this->subsite_id ) );
	}

	/**
	 * Finds a site by its path.
	 *
	 * A path lookup rather than get_site_by_path(), because the dev network's
	 * domain carries a port that wp_parse_url(PHP_URL_HOST) strips, so the
	 * domain-plus-path lookup never matches on this stack.
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
