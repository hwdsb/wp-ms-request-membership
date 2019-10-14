<?php
/*
Plugin Name: WP Multisite Request Membership
Description: Adds a widget allowing current users to add themselves to a sub-site.  Sub-site admins can allow users to auto-add themselves or request membership.
ersion: 0.1
Author: r-a-y
Author URI: http://profiles.wordpress.org/r-a-y
*/

add_action( 'plugins_loaded', array( 'WP_MS_Request_Membership', 'init' ) );

add_action( 'widgets_init',   array( 'WP_MS_Request_Membership', 'register_widget' ) );

/**
 * Core class.
 */
class WP_MS_Request_Membership {
	/**
	 * @var string Key for our settings DB option
	 */
	public static $settings_key = 'wp_ms_request_membership';

	/**
	 * @var string Key for our pending requests DB option
	 */
	public static $pending_requests_key = 'wp_ms_pending_requests';

	/**
	 * Static init method.
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Constructor.
	 */
	public function __construct() {
		if ( ! is_multisite() ) {
			return;
		}

		// admin stuff
		if ( defined( 'WP_NETWORK_ADMIN' ) ) {
			require dirname( __FILE__ ) . '/wp-ms-request-membership-admin.php';

			add_action( 'admin_menu', array( 'WP_MS_Request_Membership_Admin', 'menu' ) );

		// frontend stuff
		} else {
			add_action( 'login_form_wp-ms-request', array( $this, 'login_listener' ) );
			add_action( 'template_redirect',        array( $this, 'validate_autoadd_submission' ), 0 );
		}
	}

	/**
	 * Get our settings.
	 */
	public static function get_settings() {
		$settings = get_option( self::$settings_key );

		if ( empty( $settings['default-role'] ) ) {
			$settings['default-role'] = 'subscriber';
		}

		if ( ! isset( $settings['auto-join'] ) ) {
			$settings['auto-join'] = 0;
		}

		if ( ! isset( $settings['email-admin'] ) ) {
			$settings['email-admin'] = 0;
		}

		if ( ! isset( $settings['email-requestee'] ) ) {
			$settings['email-requestee'] = 0;
		}

		return $settings;
	}

	/**
	 * Get pending users.
	 *
	 * @return array Array where the user ID is set as the key.
	 */
	public static function get_pending_users() {
		return get_option( 'wp_ms_pending_requests', array() );
	}

	/**
	 * Register our widget.
	 */
	public static function register_widget() {
		if ( ! is_multisite() ) {
			return;
		}

		register_widget( 'WP_MS_Request_Membership_Widget' );
	}

	/**
	 * Login listener.
	 */
	public function login_listener() {
		add_filter( 'login_redirect', array( $this, 'validate_login_submission' ), 10, 3 );
	}

	/**
	 * Validates login form submission from our frontend widget.
	 */
	public function validate_login_submission( $redirect_to, $requested_redirect_to, $user ) {
		global $error;

		if ( is_wp_error( $user ) ) {
			return $redirect_to;
		}

		add_action( 'login_footer', array( $this, 'add_login_footer_link' ) );

		// check if user is already a member of the blog
		if ( is_user_member_of_blog( $user->ID ) ) {
			$error = __( 'You are already a member of this site.', 'wp-ms-request' );
			add_filter( 'login_message', '__return_false' );

		// not a member, so add them
		} else {
			self::add_user_to_blog( $user->ID );

			// alter message on login screen
			add_filter( 'login_message', array( $this, 'set_confirmation_message' ) );
		}

		return $redirect_to;
	}

	/**
	 * Validates auto-add submissions from our frontend widget.
	 */
	public function validate_autoadd_submission() {
		// auto-add nonce link
		if ( empty( $_REQUEST['wp-ms-add'] ) ) {
			return;
		}

		if ( ! wp_verify_nonce( $_REQUEST['ms-add-nonce'], 'wp-ms-add' ) ) {
			wp_die( __( "Oops! You shouldn't be here!", 'wp-ms-request' ) );
		}

		// add the user to the blog
		$add = self::add_user_to_blog();
		if ( true === $add ) {
			$message = $this->set_confirmation_message();
		} else {
			$message = __( 'Something went wrong when attempting to add you to the site.  Please notify the administrator.', 'wp-ms-request' );
		}

		$html = "<p>{$message}</p>";
		$html .= '<p id="backtoblog"><a href="' . esc_url( home_url( '/' ) ) . '">' . sprintf( __( '&larr; Back to %s', 'wp-ms-request' ), get_bloginfo( 'title', 'display' ) ) . '</a></p></div>';

		wp_die( $html );
	}

	/**
	 * Helper method to add a user to a blog.
	 *
	 * @param int $user_id The user ID
	 * @param bool $force_autojoin Whether to auto-join the user or add the user
	 *        to the pending requests list.  Default: false.
	 */
	public static function add_user_to_blog( $user_id = 0, $force_autojoin = false ) {
		if ( 0 === (int) $user_id ) {
			$user_id = get_current_user_id();
		}

		$settings = self::get_settings();
		$role = $settings['default-role'];

		// auto-join
		if( ! empty( $settings['auto-join'] ) || ( true === (bool) $force_autojoin ) ) {
			$add_user = add_existing_user_to_blog( array(
				'user_id' => $user_id,
				'role'    => $role,
			) );

			// remove user from pending requests just in case
			if ( true === $add_user ) {
				self::set_pending_user( $user_id, 'remove' );
			}

			// send an email to the admin
			if ( false === (bool) $force_autojoin  && ! empty( $settings['email-admin'] ) ) {
				wp_mail(
					get_bloginfo( 'admin_email' ),
					sprintf( __( '[%s] A new member has auto-joined your site', 'wp-ms-request' ), get_bloginfo ( 'name' ) ),
					sprintf( __( "Hi,

A new member, %1$s, has auto-joined your site.

View this user's profile in the admin dashboard if desired:
%2$s", 'wp-ms-request' ),
	get_user_by( 'id', $user_id )->user_nicename,
	admin_url( 'users.php' )
					)
				);
			}

			// send an email to the user if we are approving the user
			if ( true === (bool) $force_autojoin && ! empty( $settings['email-requestee'] ) ) {
				wp_mail(
					get_user_by( 'id', $user_id )->user_email,
					sprintf( __( '[%s] Your membership request was approved', 'wp-ms-request' ), get_bloginfo ( 'name' ) ),
					sprintf( __( 'Hi %1$s,

Your membership request to join the site, %2$s, with the role of "%3$s" was approved.

%4$s', 'wp-ms-request' ),
	get_user_by( 'id', $user_id )->user_nicename,
	get_bloginfo( 'name' ),
	self::get_translatable_role(),
	home_url( '/' )
					)
				);
			}

			return $add_user;

		// request membership
		} else {
			return self::set_pending_user( $user_id );
		}
	}

	/**
	 * Helper method to add / remove a user to / from the pending requests list.
	 *
	 * @param int $user_id The user ID
	 * @param string $mode. Either 'add' or 'remove'. Default: 'add'.
	 * @return bool
	 */
	public static function set_pending_user( $user_id = 0, $mode = 'add' ) {
		// get our pending users DB option
		$pending_users = self::get_pending_users();

		// remove user from pending requests
		if ( 'remove' === $mode ) {
			unset( $pending_users[$user_id] );

		// add user to pending requests
		} else {
			// add the user as a key
			$pending_users[$user_id] = 1;

			// get our settings
			$settings = self::get_settings();

			// send an email to the admin
			if ( ! empty( $settings['email-admin'] ) ) {
				wp_mail(
					get_bloginfo( 'admin_email' ),
					sprintf( __( '[%s] A new member has requested membership to your site', 'wp-ms-request' ), get_bloginfo ( 'name' ) ),
					sprintf( __( 'Hi,

A new member, %1$s, has requested membership to your site.

To approve or decline the request, login to the admin dashboard:
%2$s', 'wp-ms-request' ),
	get_user_by( 'id', $user_id )->user_nicename,
	admin_url( 'users.php?page=ms-pending-requests' )
					)
				);
			}
		}

		// update the DB option
		return update_option( self::$pending_requests_key, $pending_users );
	}

	/**
	 * Show the "Back to" site link on the login page.
	 *
	 * Had to duplicate some element IDs without adding new CSS.  No biggie!
	 */
	public function add_login_footer_link() {
	?>

		<div id="login">
			<p id="backtoblog" style="padding:0;"><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php printf( __( '&larr; Back to %s', 'wp-ms-request' ), get_bloginfo( 'title', 'display' ) ); ?></a></p>
		</div>

	<?php
	}

	/**
	 * Alter login message when submitting from the widget.
	 *
	 * @return string.
	 */
	public function set_confirmation_message( $retval = '' ) {
		$settings = self::get_settings();

		// auto-join
		if( ! empty( $settings['auto-join'] ) ) {
			$message = sprintf( __( 'You have successfully joined this site with the role of "%s"', 'wp-ms-request' ), self::get_translatable_role() );

		// request membership
		} else {
			$message = __( 'You have requested membership to this site.', 'wp-ms-request' );
			$message .= '<br /><br />';
			$message .= __( 'Please wait until an administrator has approved your request.', 'wp-ms-request' );
		}

		return "<p class='message'>{$message}</p>";
	}

	/**
	 * Get the translatable default role for WP MS Request Membership.
	 *
	 * @return string
	 */
	public static function get_translatable_role() {
		global $wp_roles;

		// @todo check if the role is valid...
		$settings = self::get_settings();
		$role = $settings['default-role'];

		return translate_user_role( $wp_roles->roles[$role]['name'] );
	}

	/**
	 * Return the join button.
	 *
	 * @param string $button_text Text for the button.
	 */
	public static function get_button( $button_text = '' ) {
		if ( empty( $button_text ) ) {
			$button_text = __( 'Join this site!', 'wp-ms-request' );
		}

		return sprintf(
			'<form id="wp-ms-autoadd-form" method="POST" action="%1$s">
				<input type="hidden" name="wp-ms-add" value="1" />%2$s
				<input type="submit" value="%3$s" class="button" />
			</form>',
				home_url( '/' ),
				wp_nonce_field( 'wp-ms-add', 'ms-add-nonce', true, false ),
				esc_attr( $button_text )
		);
	}
}

/**
 * Widget class for WP MS Request Membership.
 */
class WP_MS_Request_Membership_Widget extends WP_Widget {

	/**
	 * Constructor.
	 */
	function __construct() {
		parent::__construct(
			false,
			__( 'Request Membership', 'wp-ms-request' ),
			array(
				'description' => __( 'Adds a form allowing existing users to request membership to this sub-site.', 'wp-ms-request' ),
			)
		);

		add_filter( 'wp_ms_request_widget_text', 'wpautop' );
	}

	/**
	 * Front-end display of widget.
	 *
	 * @see WP_Widget::widget()
	 *
	 * @param array $args     Widget arguments.
	 * @param array $instance Saved values from database.
	 */
	public function widget( $args, $instance ) {
		$settings = WP_MS_Request_Membership::get_settings();

		// logged-in user checks
		if ( is_user_logged_in() ) {
			// if user is already a member of the blog, stop!
			if ( is_user_member_of_blog() ) {
				return;
			}

			// check if auto-join is off and check if user has already requested access
			if ( 0 === (int) $settings['auto-join'] ) {
				$current_user_id = get_current_user_id();
				$pending_users   = WP_MS_Request_Membership::get_pending_users();

				// user has already requested access before, so stop!
				if ( isset( $pending_users[$current_user_id] ) ) {
					return;
				}
			}
		}

		// piggyback off WP's 'widget_title' filter
		$title = apply_filters( 'widget_title', $instance['title'] );
		$loggedin_text  = apply_filters( 'widget_title', $instance['loggedin_text'] );
		$loggedout_text = apply_filters( 'widget_title', $instance['loggedout_text'] );
		$autoadd_text   = apply_filters( 'widget_title', $instance['autoadd_text'] );

		// add custom filters for widget text for ambitious plugin devs!
		$loggedin_text  = apply_filters( 'wp_ms_request_widget_text', $instance['loggedin_text'] );
		$loggedout_text = apply_filters( 'wp_ms_request_widget_text', $instance['loggedout_text'] );

		echo $args['before_widget'];
		if ( ! empty( $title ) ) {
			echo $args['before_title'] . $title . $args['after_title'];
		}

		if ( is_user_logged_in() ) {
			echo $loggedin_text;

			// Output button.
			echo WP_MS_Request_Membership::get_button( $autoadd_text );

		} else {
			echo $loggedout_text;

			add_filter( 'login_form_bottom', array( $this, 'add_login_form_fields' ) );

			wp_login_form( array(
				'remember' => false,
			) );
			remove_filter( 'login_form_bottom', array( $this, 'add_login_form_fields' ) );
		}

		echo $args['after_widget'];
	}

	/**
	 * Back-end widget form.
	 *
	 * @see WP_Widget::form()
	 *
	 * @param array $instance Previously saved values from database.
	 */
	public function form( $instance ) {
		$settings = WP_MS_Request_Membership::get_settings();

		$title = isset( $instance[ 'title' ] ) ?
			$instance[ 'title' ] :
			__( 'Request Membership To This Site', 'wp-ms-request' );

		$default_loggedin_text = ! empty( $settings['auto-join'] ) ?
			__( 'Click on the button below to join this site.', 'wp-ms-request' ) :
			__( 'Click on the button below to request membership to this site.' , 'wp-ms-request' );


		$loggedin_text = isset( $instance['loggedin_text'] ) ?
			$instance['loggedin_text'] :
			$default_loggedin_text;

		$default_loggedout_text = ! empty( $settings['auto-join'] ) ?
			__( 'Login to join this site.', 'wp-ms-request' ) :
			__( 'Login to request membership to this site.' , 'wp-ms-request' );

		$loggedout_text = isset( $instance['loggedout_text'] ) ?
			$instance['loggedout_text'] :
			$default_loggedout_text;

		$autoadd_text = isset( $instance[ 'autoadd_text' ] ) ?
			$instance[ 'autoadd_text' ] :
			__( 'Join this site!', 'wp-ms-request' );

		$autojoin_text = $settings['auto-join'] ? __( 'ON', 'wp-ms-request' ) : __( 'OFF', 'wp-ms-request' );
	?>
		<p><?php printf( __( 'Auto-join is %s', 'wp-ms-request' ), "<strong>{$autojoin_text}</strong>" ); ?>.

		(<?php printf( __( '<a href="%s">Change settings</a>', 'wp-ms-request' ), admin_url( 'options-general.php?page=ms-request-membership' ) ); ?>)
		</p>

		<p>
		<label for="<?php echo $this->get_field_id( 'title' ); ?>"><?php _e( 'Title:', 'wp-ms-request' ); ?></label>
		<input class="widefat" id="<?php echo $this->get_field_id( 'title' ); ?>" name="<?php echo $this->get_field_name( 'title' ); ?>" type="text" value="<?php echo esc_attr( $title ); ?>">
		</p>

		<p>
		<label for="<?php echo $this->get_field_id( 'loggedin_text' ); ?>"><?php _e( 'Logged-in Text:', 'wp-ms-request' ); ?></label>
		<textarea class="large-text" id="<?php echo $this->get_field_id( 'loggedin_text' ); ?>" name="<?php echo $this->get_field_name( 'loggedin_text' ); ?>"><?php echo esc_attr( $loggedin_text ); ?></textarea>
		</p>

		<p>
		<label for="<?php echo $this->get_field_id( 'loggedout_text' ); ?>"><?php _e( 'Logged-out Text:', 'wp-ms-request' ); ?></label>
		<textarea class="large-text" id="<?php echo $this->get_field_id( 'loggedout_text' ); ?>" name="<?php echo $this->get_field_name( 'loggedout_text' ); ?>"><?php echo esc_attr( $loggedout_text ); ?></textarea>
		</p>

		<p>
		<label for="<?php echo $this->get_field_id( 'autoadd_text' ); ?>"><?php _e( 'Button Text:', 'wp-ms-request' ); ?></label>
		<input class="widefat" id="<?php echo $this->get_field_id( 'autoadd_text' ); ?>" name="<?php echo $this->get_field_name( 'autoadd_text' ); ?>" type="text" value="<?php echo esc_attr( $autoadd_text ); ?>"><br />
		<small><?php _e( 'The button is only shown if a user is logged-in and is not a member of the site while viewing the widget.', 'wp-ms-request' ); ?></small>
		</p>

		<?php
	}

	/**
	 * Sanitize widget form values as they are saved.
	 *
	 * @see WP_Widget::update()
	 *
	 * @param array $new_instance Values just sent to be saved.
	 * @param array $old_instance Previously saved values from database.
	 *
	 * @return array Updated safe values to be saved.
	 */
	public function update( $new_instance, $old_instance ) {
		$instance = array();
		$instance['title'] = ( ! empty( $new_instance['title'] ) ) ? strip_tags( $new_instance['title'] ) : '';
		$instance['loggedin_text']  = ( ! empty( $new_instance['loggedin_text'] ) ) ? wp_kses_post( $new_instance['loggedin_text'] ): '';
		$instance['loggedout_text'] = ( ! empty( $new_instance['loggedout_text'] ) ) ? wp_kses_post( $new_instance['loggedout_text'] ) : '';
		$instance['autoadd_text'] = ( ! empty( $new_instance['autoadd_text'] ) ) ? strip_tags( $new_instance['autoadd_text'] ) : '';

		return $instance;
	}

	/**
	 * Custom login fields for use with our widget.
	 *
	 * @param string $retval
	 * @return string
	 */
	public function add_login_form_fields( $retval ) {
		$retval .= '<input type="hidden" name="action" value="wp-ms-request" />';
		$retval .= '<input type="hidden" name="interim-login" value="1" />';

		return $retval;
	}
}
