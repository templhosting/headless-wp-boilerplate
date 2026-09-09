# Extending

Three walkthroughs, each using a hook the boilerplate ships but does not itself use.
None of them requires forking a plugin.
The full hook list is in `AGENTS.md` > Extension points.

Put the code in a small must-use plugin of your own, or in a feature plugin's module if you are already editing one.
Everything below assumes a file that runs on the network, the same way the shipped plugins do.

## 1. Add a field to the contact form

Say every submission should carry a phone number.

The `templ_contact_form_validate` filter receives the sanitised fields and the raw input.
Validate the extra value, add it to the returned array, and store it from the `templ_contact_form_submission_created` action.

```php
add_filter(
	'templ_contact_form_validate',
	function ( $clean, array $input ) {
		// An earlier handler may have already returned an error; respect it.
		if ( is_wp_error( $clean ) ) {
			return $clean;
		}

		$phone = isset( $input['phone'] ) ? sanitize_text_field( (string) $input['phone'] ) : '';

		if ( '' !== $phone && strlen( $phone ) > 30 ) {
			return new WP_Error(
				'my_phone_too_long',
				__( 'That phone number is too long.', 'my-extension' ),
				[ 'status' => 400 ]
			);
		}

		$clean['phone'] = $phone;

		return $clean;
	},
	10,
	2
);

add_action(
	'templ_contact_form_submission_created',
	function ( int $submission_id ) {
		// The validated request is not passed to this action, so read the
		// phone from the request the same way the endpoint read the rest.
		$request = rest_get_server()->get_raw_data();
		$data    = json_decode( $request, true );

		if ( ! empty( $data['phone'] ) ) {
			update_post_meta( $submission_id, '_my_phone', sanitize_text_field( $data['phone'] ) );
		}
	}
);
```

The stored value is now on the submission post; add a column to the admin screen if a human needs to see it.

## 2. Add double opt-in to the newsletter

The boilerplate is single opt-in and sends no mail.
To require confirmation, send an email on `templ_newsletter_subscribed` and hold the subscriber in a pending state until they click.

```php
add_action(
	'templ_newsletter_subscribed',
	function ( int $subscriber_id ) {
		$email = get_post( $subscriber_id )->post_title;
		$token = get_post_meta( $subscriber_id, '_templ_nl_token', true );

		// Your own confirm route, on your frontend, that calls back to
		// WordPress to flip a meta flag once the link is opened.
		$confirm_url = add_query_arg(
			[ 'token' => $token ],
			'https://frontend.example.com/newsletter/confirm'
		);

		wp_mail(
			$email,
			__( 'Confirm your subscription', 'my-extension' ),
			sprintf( "Confirm here: %s", $confirm_url )
		);

		update_post_meta( $subscriber_id, '_my_pending', '1' );
	}
);
```

The unsubscribe token is reused here deliberately: it already identifies exactly one subscriber and is safe to put in a link.
Treat a subscriber with `_my_pending` set as not-yet-confirmed in whatever your frontend reads.

## 3. Add a whole plugin that reuses the key auth

A new endpoint on a new plugin authenticates the same way every shipped one does: it hands `Templ\Headless\Keys\permission_callback` to `register_rest_route`.
Nothing else is involved; that one function is the entire auth contract.

Copy the shape of `templ-contact-form`:

1. A main file with the MU-plugin dependency guard, so a missing must-use plugin degrades to an admin notice rather than a fatal.
2. `src/` modules, each a single namespace with one `bootstrap()`.
3. A versioned REST namespace whose paths are built from a constant.

The minimum:

```php
namespace My\Feature\Rest;

function bootstrap(): void {
	add_action( 'rest_api_init', __NAMESPACE__ . '\\register_routes' );
}

function register_routes(): void {
	register_rest_route(
		'my-feature/v1',
		'/things',
		[
			'methods'             => 'GET',
			'callback'            => __NAMESPACE__ . '\\index',
			// The whole of it. Per-site keys, the header parsing, the revoked
			// check: all already done.
			'permission_callback' => 'Templ\\Headless\\Keys\\permission_callback',
		]
	);
}
```

Then wire the plugin into the stack, so dev and every test container see it:

- Add its directory to the bind mounts in `compose.yaml`: the `x-wp-content` anchor, the `wordpress` service, the `init` service, and the `tests` and `tests-multisite` services.
- Add it to the activation loop in `tools/init.sh`.
- Register its prefixes and text domain in `phpcs.xml`.

Because the MU plugin runs on every subsite, the new plugin's routes are per-site the moment it is network-activated, with no extra work.

**If your new route is unauthenticated,** it needs `permission_callback => '__return_true'` and a note in `AGENTS.md` saying why, next to the existing note about the unsubscribe route.
"Authenticated by default" is the property that makes this repo safe to hand to a frontend, and an undocumented open route erodes it silently.
