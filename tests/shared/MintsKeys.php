<?php
/**
 * @package Templ\Headless\Tests
 */

namespace Templ\Headless\Tests\Shared;

use Templ\Headless\Keys\Store;

/**
 * Mints API keys on a given site and cleans them up afterwards.
 *
 * Keys are created in-process with switch_to_blog(), because minting a key is
 * the very operation the CLI and admin wrap and is not itself the subject of
 * the REST tests: those need a working key, not a test of how it was made.
 */
trait MintsKeys {

	/**
	 * Key post IDs to delete in teardown, as [ blog_id => [ post_id, ... ] ].
	 *
	 * @var array<int, int[]>
	 */
	private array $minted_keys = [];

	/**
	 * Mints a key on a site and returns its plaintext.
	 *
	 * @param int    $blog_id Site to mint on.
	 * @param string $label   Key label.
	 * @return string The plaintext key.
	 */
	protected function mint_key( int $blog_id, string $label = 'test' ): string {
		switch_to_blog( $blog_id );

		$key = Store\create( $label );

		$this->minted_keys[ $blog_id ][] = (int) $key['id'];

		restore_current_blog();

		return $key['plaintext'];
	}

	/**
	 * Revokes a key by its plaintext, on a site.
	 *
	 * @param int    $blog_id   Site the key lives on.
	 * @param string $plaintext The plaintext key.
	 * @return void
	 */
	protected function revoke_key( int $blog_id, string $plaintext ): void {
		switch_to_blog( $blog_id );

		$post = \Templ\Headless\Keys\find( $plaintext );
		if ( null !== $post ) {
			Store\revoke( (int) $post->ID );
		}

		restore_current_blog();
	}

	/**
	 * Deletes every key this trait minted.
	 *
	 * @return void
	 */
	protected function clean_up_keys(): void {
		foreach ( $this->minted_keys as $blog_id => $ids ) {
			switch_to_blog( $blog_id );
			foreach ( $ids as $id ) {
				Store\delete( $id );
			}
			restore_current_blog();
		}

		$this->minted_keys = [];
	}
}
