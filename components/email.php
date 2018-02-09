<?php
/**
 * Manage BuddyPress Email Post Types.
 *
 * @since 1.6.0
 */
class BPCLI_Email extends BPCLI_Component {
	/**
	 * Create a new email post connected to an email type.
	 *
	 * ## OPTIONS
	 *
	 * [--type]
	 * : Required. Email type for the email (should be unique identifier, sanitized like a post slug).
	 *
	 * [--type_description]
	 * : Email type description.
	 *
	 * [--subject]
	 * : Required. Email subject line. Email tokens allowed. View https://codex.buddypress.org/emails/email-tokens/ for more info.
	 *
	 * [--content]
	 * : Required. Email content. Email tokens allowed. View https://codex.buddypress.org/emails/email-tokens/ for more info.
	 *
	 * [--plain_text_content]
	 * : Plain-text email content. Email tokens allowed. View https://codex.buddypress.org/emails/email-tokens/ for more info.
	 *
	 * ## EXAMPLES
	 *
	 *     $ wp bp email create --type=new-event --type_description="Send an email when a new event is created" --subject="[{{{site.name}}}] A new event was created" --content="<a href='{{{some.custom-token-url}}}'></a>A new event</a> was created" --plain_text_content="A new event was created"
	 *     Success: Email post created for type "new-event".
	 *
	 * @alias add
	 */
	public function create( $args, $assoc_args ) {
		$switched = false;

		if ( false === bp_is_root_blog() ) {
			$switched = true;
			switch_to_blog( bp_get_root_blog_id() );
		}

		// 'type' is required.
		if ( empty( $assoc_args['type'] ) ) {
			if ( true === $switched ) {
				restore_current_blog();
			}

			WP_CLI::error( "The 'type' field must be filled in." );
			return;
		}

		$term = term_exists( $assoc_args['type'], bp_get_email_tax_type() );

		// Term already exists so don't do anything.
		if ( $term !== 0 && $term !== null ) {
			if ( true === $switched ) {
				restore_current_blog();
			}

			WP_CLI::error( "Email type '{$assoc_args['type']}' already exists." );
			return;
		}

		// 'subject' is required.
		if ( empty( $assoc_args['subject'] ) ) {
			if ( true === $switched ) {
				restore_current_blog();
			}

			WP_CLI::error( "The 'subject' field must be filled in." );
			return;
		}

		if ( ! empty( $args[0] ) ) {
			$assoc_args['content'] = $this->read_from_file_or_stdin( $args[0] );
		}
		if ( \WP_CLI\Utils\get_flag_value( $assoc_args, 'edit' ) ) {
			$input = \WP_CLI\Utils\get_flag_value( $assoc_args, 'content', '' );
			if ( $output = $this->_edit( $input, 'WP-CLI: New BP Email Content' ) ) {
				$assoc_args['content'] = $output;
			} else {
				$assoc_args['content'] = $input;
			}
		}

		// 'content' is required.
		if ( empty( $assoc_args['content'] ) ) {
			if ( true === $switched ) {
				restore_current_blog();
			}

			WP_CLI::error( "The 'content' field must be filled in." );
			return;
		}

		$id = $assoc_args['type'];

		$defaults = array(
			'post_status' => 'publish',
			'post_type'   => bp_get_email_post_type(),
		);

		$email = array(
			'post_title'   => $assoc_args['subject'],
			'post_content' => $assoc_args['content'],
			'post_excerpt' => ! empty( $assoc_args['plaintext_content'] ) ? $assoc_args['plaintext_content'] :'',
		);

		// Email post content.
		$post_id = wp_insert_post( bp_parse_args( $email, $defaults, 'install_email_' . $id ) );

		// Save the situation.
		if ( ! is_wp_error( $post_id ) ) {
			$tt_ids = wp_set_object_terms( $post_id, $id, bp_get_email_tax_type() );

			// Situation description.
			if ( ! is_wp_error( $tt_ids ) && ! empty( $assoc_args['type_description'] ) ) {
				$term = get_term_by( 'term_taxonomy_id', (int) $tt_ids[0], bp_get_email_tax_type() );
				wp_update_term( (int) $term->term_id, bp_get_email_tax_type(), array(
					'description' => $assoc_args['type_description'],
				) );
			}

			WP_CLI::success( "Email post created for type '{$assoc_args['type']}'." );
		} else {
			WP_CLI::error( "There was a problem creating the email post for type '{$assoc_args['type']}'." );
		}

		if ( true === $switched ) {
			restore_current_blog();
		}
	}

	/**
	 * Get details for a post connected to an email type.
	 *
	 * ## OPTIONS
	 *
	 * <type>
	 * : The email type to fetch the post details for.
	 *
	 * [--field=<field>]
	 * : Instead of returning the whole post, returns the value of a single field.
	 *
	 * [--fields=<fields>]
	 * : Limit the output to specific fields. Defaults to all fields.
	 *
	 * [--format=<format>]
	 * : Render output in a particular format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - csv
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * ## EXAMPLE
	 *
	 *     # Output the post ID for the 'activity-at-message' email type
	 *     $ wp bp email get activity-at-message --fields=ID
	 *
	 * @subcommand get-post
	 */
	public function get_post( $args, $assoc_args ) {
		$email = bp_get_email( $args[0] );

		if ( is_wp_error( $email ) ) {
			WP_CLI::error( "Email post for type '{$args[0]}' does not exist." );
			return;
		}

		$post_arr = get_object_vars( $email->get_post_object() );
		unset( $post_arr['filter'] );
		if ( empty( $assoc_args['fields'] ) ) {
			$assoc_args['fields'] = array_keys( $post_arr );
		}

		$formatter = $this->get_formatter( $assoc_args );
		$formatter->display_item( $email->get_post_object() );
	}

	/**
	 * Reinstall BuddyPress default emails.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Answer yes to the confirmation message.
	 *
	 * ## EXAMPLE
	 *
	 *     $ wp bp email reinstall --yes
	 *     Success: Emails have been successfully reinstalled.
	 */
	public function reinstall( $args, $assoc_args ) {
		WP_CLI::confirm( 'Are you sure you want to reinstall BuddyPress emails?', $assoc_args );

		require_once buddypress()->plugin_dir . 'bp-core/admin/bp-core-admin-tools.php';

		$result = bp_admin_reinstall_emails();

		if ( 0 === $result[0] ) {
			WP_CLI::success( $result[1] );
		} else {
			WP_CLI::error( $result[1] );
		}
	}
}

WP_CLI::add_command( 'bp email', 'BPCLI_EMail' );