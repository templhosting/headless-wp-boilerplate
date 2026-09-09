<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Multisite;

use Templ\Headless\Tests\Shared\MakesRequests;
use Templ\Headless\Tests\Shared\MintsKeys;

/**
 * A site created from the network must be fully headless the moment it exists,
 * with no activation step: the MU plugin runs everywhere, the feature plugins
 * are network-activated, and the default theme is the headless one. This proves
 * the zero-touch onboarding the agency model depends on.
 */
final class NewSiteTest extends MultisiteTestCase {

	use MakesRequests;
	use MintsKeys;

	/**
	 * The scratch site's blog ID, or 0 when none was created.
	 *
	 * @var int
	 */
	private int $new_site_id = 0;

	protected function tearDown(): void {
		$this->clean_up_keys();

		if ( $this->new_site_id > 0 ) {
			wp_delete_site( $this->new_site_id );
			$this->new_site_id = 0;
		}

		parent::tearDown();
	}

	public function test_a_freshly_created_site_serves_the_api_without_activation(): void {
		$slug   = 'scratch-' . wp_generate_password( 6, false, false );
		$domain = get_site( $this->main_id )->domain;

		// wp_insert_site() runs the new site's install, which populates its
		// home/siteurl options from $_SERVER['HTTP_HOST']. In CLI there is no
		// request, so it is set here to the network domain, matching what the
		// web server would have provided.
		$_SERVER['HTTP_HOST'] = $domain;

		// The domain has to be the network's registered domain, port and all,
		// so the new site is reachable on the same host as the rest.
		$site = wp_insert_site(
			[
				'domain' => $domain,
				'path'   => '/' . $slug . '/',
				'title'  => 'Scratch',
			]
		);

		$this->assertNotInstanceOf( \WP_Error::class, $site, 'Could not create a scratch site.' );

		$this->new_site_id = (int) $site;
		$site_url          = trailingslashit( get_home_url( $this->new_site_id ) );

		// The post types have to exist on the new site with no activation, so a
		// key can be minted straight away.
		$key = $this->mint_key( $this->new_site_id );

		$ping = $this->get( $site_url, '/templ-headless/v1/ping', $key );
		$this->assertSame( 200, $ping['status'] );
		$this->assertSame( $this->new_site_id, $ping['body']['site_id'] );

		// The feature routes have to answer too.
		$submission = $this->post_json(
			$site_url,
			'/templ-contact-form/v1/submissions',
			[
				'name'    => 'Ada',
				'email'   => 'ada@example.com',
				'message' => 'On a brand new site.',
			],
			$key
		);
		$this->assertSame( 201, $submission['status'] );

		$subscribe = $this->post_json(
			$site_url,
			'/templ-newsletter/v1/subscribers',
			[ 'email' => 'sub@example.com' ],
			$key
		);
		$this->assertSame( 201, $subscribe['status'] );

		// And the theme has to be the headless one, so the frontend is closed.
		$this->assertSame( 'templ-headless-theme', get_blog_option( $this->new_site_id, 'stylesheet' ) );
	}
}
