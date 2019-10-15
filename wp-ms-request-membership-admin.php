<?php
/**
 * Admin page class.
 */
class WP_MS_Request_Membership_Admin  {
	/**
	 * @var array Settings holder.
	 */
	protected static $settings = array();

	/**
	 * Registers our menu and various admin hooks.
	 */
	public static function menu() {
		self::$settings = WP_MS_Request_Membership::get_settings();

		$settings_page = add_submenu_page(
			'options-general.php',
			__( 'Request Membership Settings', 'wp-ms-request' ),
			__( 'Request Membership', 'wp-ms-request' ),
			'promote_users',
			'ms-request-membership',
			array( __CLASS__, 'settings_screen' )
		);
		add_action( "load-{$settings_page}", array( __CLASS__, 'settings_screen_validate' ) );

		if ( empty( self::$settings['auto-join'] ) ) {
			$pending_page = add_users_page(
				__( 'Pending Requests', 'wp-ms-request' ),
				__( 'Pending Requests', 'wp-ms-request' ),
				'promote_users',
				'ms-pending-requests',
				array( __CLASS__, 'pending_screen' )
			);

			add_action( "load-{$pending_page}", array( __CLASS__, 'pending_screen_validate' ) );
			add_action( "load-{$pending_page}", 'wp_ms_request_membership_pending_screen_loader' );
		}
	}

	/**
	 * "Settings > Request Membership" screen.
	 */
	public static function settings_screen() {
		// do not show the 'administrator' role
		add_filter( 'editable_roles', array( __CLASS__, 'remove_administrator' ) );
	?>
		<div class="wrap">
			<h2><?php _e( 'Request Membership Settings', 'wp-ms-request' ); ?></h2>

			<form method="POST" action="">

			<table class="form-table">
			<tbody>
				<tr>
					<th scope="row"><label for="default-role"><?php _e( 'Default Role', 'wp-ms-request' ); ?></label></th>
					<td>
						<select name="settings[default-role]" id="default-role">
							<?php wp_dropdown_roles( self::$settings['default-role'] ); ?>
						</select>

						<p class="description"><?php _e( 'Select the default role that users should have when they join this site.', 'wp-ms-request' ); ?></p>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e( 'Auto-join', 'wp-ms-request' ); ?></th>
					<td>
						<fieldset><legend class="screen-reader-text"><span><?php _e( 'Auto-join', 'wp-ms-request' ); ?></span></legend>
						<label for="auto-join">
							<input type="checkbox" name="settings[auto-join]" id="auto-join" value="1" <?php checked( self::$settings['auto-join'], 1 ); ?> />
							<?php _e( 'Allow users who request membership to immediately auto-join the site.', 'wp-ms-request' ); ?>
						</label>
						</fieldset>

						<p class="description"><?php _e( 'If unchecked, site admins will need to approve the request under "Users > Pending Requests" before the user can join the site.', 'wp-ms-request' ); ?>
					</td>
				</tr>

				<tr>
					<th scope="row"><?php _e( 'Email', 'wp-ms-request' ); ?></th>
					<td>
						<fieldset><legend class="screen-reader-text"><span><?php _e( 'Email', 'wp-ms-request' ); ?></span></legend>
							<label for="email-admin">
								<input type="checkbox" name="settings[email-admin]" id="email-admin" value="1" <?php checked( self::$settings['email-admin'], 1 ); ?> />
								<?php _e( 'Send an email notification to the administrator when a new user either auto-joins or is requesting membership to the site.', 'wp-ms-request' ); ?>
							</label>

							<label for="email-requestee">
								<input type="checkbox" name="settings[email-requestee]" id="email-requestee" value="1" <?php checked( self::$settings['email-requestee'], 1 ); ?> />
								<?php _e( "Send an email notification to the requestee when the administrator approves the user's request.", 'wp-ms-request' ); ?>
							</label>
						</fieldset>
					</td>
				</tr>
			</tbody>
			</table>

			<input type="hidden" name="page" value="ms-request-membership" />
			<?php wp_nonce_field( 'wp-ms-request-membership-settings', 'wp-ms-request-nonce' ); ?>

			<?php submit_button(); ?>

			</form>
		</div>

	<?php
	}

	/**
	 * Validate submitted form settings from "Settings > Request Membership".
	 */
	public static function settings_screen_validate() {
		if ( empty( $_POST['settings'] ) ) {
			return;
		}

		// nonce check
		check_admin_referer( 'wp-ms-request-membership-settings', 'wp-ms-request-nonce' );

		$settings = $_POST['settings'];

		if ( ! isset( $settings['auto-join'] ) ) {
			$settings['auto-join'] = 0;
		}

		if ( ! isset( $settings['email-admin'] ) ) {
			$settings['email-admin'] = 0;
		}

		if ( ! isset( $settings['email-requestee'] ) ) {
			$settings['email-requestee'] = 0;
		}

		// update DB option
		update_option( WP_MS_Request_Membership::$settings_key, $settings );

		// update internal settings marker
		self::$settings = $settings;

		// add notice
		self::add_notice( '<p>' . __( 'Settings updated.', 'wp-ms-request' ) . '</p>' );
	}

	/**
	 * "Users > Pending Requests" screen.
	 */
	public static function pending_screen() {
		global $wp_ms_request_list_table, $usersearch;

		$usersearch = ! empty( $_REQUEST['s'] ) ? stripslashes( $_REQUEST['s'] ) : '';

		$form_url = add_query_arg(
			array(
				'page' => 'ms-pending-requests',
			),
			admin_url( 'users.php' )
		);

		$search_form_url = remove_query_arg(
			array(
				'action',
				'error',
				'updated',
				'action2',
				'_wpnonce',
			), $_SERVER['REQUEST_URI']
		);

		$wp_list_table = &$wp_ms_request_list_table;
		$wp_list_table->prepare_items();
	?>

		<div class="wrap">
			<h2><?php _e( 'Users', 'wp-ms-request' ); ?>
			<?php if ( current_user_can( 'create_users' ) ) { ?>
				<a href="user-new.php" class="add-new-h2"><?php echo esc_html_x( 'Add New', 'user' ); ?></a>
			<?php } elseif ( is_multisite() && current_user_can( 'promote_users' ) ) { ?>
				<a href="user-new.php" class="add-new-h2"><?php echo esc_html_x( 'Add Existing', 'user' ); ?></a>
			<?php }

			if ( $usersearch )
				printf( '<span class="subtitle">' . __('Search results for &#8220;%s&#8221;') . '</span>', esc_html( $usersearch ) ); ?>
			</h2>

			<?php $wp_list_table->views(); ?>

			<form id="ms-request-search" action="<?php echo $search_form_url; ?>">
				<input type="hidden" name="page" value="ms-pending-requests" />
				<?php $wp_list_table->search_box( __( 'Search Users' ), 'ms-requests' ); ?>
			</form>

			<form id="ms-pending-requests" action="<?php echo esc_url( $form_url );?>" method="post">
				<?php $wp_list_table->display(); ?>
			</form>
		</div>
	<?php
	}

	/**
	 * Validate single action links from "Users > Pending Requests".
	 *
	 * When an admin clicks on the "Approve" or "Decline" links, this method
	 * handles validation.
	 */
	public static function pending_screen_validate() {
		$user_id = ! empty( $_REQUEST['user_id'] ) ? (int) $_REQUEST['user_id'] : 0;
		if ( empty( $user_id ) ) {
			return;
		}

		if ( empty( $_REQUEST['_wpnonce'] ) ) {
			return;
		}

		check_admin_referer( $_REQUEST['action'] );

		switch ( $_REQUEST['action'] ) {
			case 'ms-req-approve' :
				$add = WP_MS_Request_Membership::add_user_to_blog( $user_id, true );

				if ( true === $add ) {
					self::add_notice( sprintf(
						'<p>' . __( '%s was added to the site.', 'wp-ms-request' ) . '</p>',
						get_user_by( 'id', $user_id )->user_nicename
					) );
				}
				break;

			case 'ms-req-decline' :
				$remove = WP_MS_Request_Membership::set_pending_user( $user_id, 'remove' );

				if ( true === $remove ) {
					self::add_notice( sprintf(
						'<p>' . __( "%s's request was successfully rejected.", 'wp-ms-request' ) . '</p>',
						get_user_by( 'id', $user_id )->user_nicename
					) );
				}
				break;
		}
	}

	/**
	 * Validate bulk actions from "Users > Pending Requests".
	 */
	public static function pending_screen_bulk_validate( $action = '' ) {
		$user_ids = ! empty( $_POST['users'] ) ? wp_parse_id_list( $_POST['users'] ) : false;

		if ( false === $user_ids ) {
			return;
		}

		$parsed_ids = array();
		$message = '';

		switch ( $action ) {
			case 'approve' :
				foreach ( $user_ids as $user_id ) {
					$add = WP_MS_Request_Membership::add_user_to_blog( $user_id, true );

					if ( true === $add ) {
						$parsed_ids[] = $user_id;
					}
				}

				if ( ! empty( $parsed_ids ) ) {
					$message = '<p>' . __( 'The following users were successfully added to the site:', 'wp-ms-request' ) . '</p>';
				}
				break;

			case 'decline' :
				foreach ( $user_ids as $user_id ) {
					$remove = WP_MS_Request_Membership::set_pending_user( $user_id, 'remove' );

					if ( true === $remove ) {
						$parsed_ids[] = $user_id;
					}
				}

				if ( ! empty( $parsed_ids ) ) {
					$message = '<p>' . __( 'The requests for the following users were successfully removed:', 'wp-ms-request' ) . '</p>';
				}
				break;
		}

		if ( ! empty( $parsed_ids ) ) {
			$message .= '<ul>';

			foreach ( $parsed_ids as $parsed_id ) {
				$message .= '&bull; ' . get_user_by( 'id', $user_id )->user_nicename . '<br />';
			}

			$message .= '</ul>';

			self::add_notice( $message );
		}
	}

	/**
	 * Handy method to add an admin notice.
	 *
	 * @param string $message The notice you want to add.
	 * @param string $type The type of message. Either 'success' or 'error'.
	 */
	protected static function add_notice( $message = '', $type = 'success' ) {
		global $current_screen;

		// stuff our admin notice in the $current_screen global
		$current_screen->admin_notice = array(
			'message' => $message,
			'type'    => $type,
		);

		// add success message
		add_action( 'admin_notices', array( __CLASS__, 'output_notice' ) );
	}

	/**
	 * Method to output an admin notice.
	 *
	 * @see WP_MS_Request_Membership_Admin::output_notice()
	 */
	public static function output_notice() {
		global $current_screen;

		if ( empty( $current_screen->admin_notice ) ) {
			return;
		}

		$class = ( 'success'  === $current_screen->admin_notice['type'] ) ? 'updated' : esc_attr( $type );
	?>

		<div id="message" class="<?php echo $class; ?>">
			<?php echo strip_tags( $current_screen->admin_notice['message'], '<p><ul><li>') ; ?>
		</div>

	<?php
	}

	/**
	 * Filter to remove the "Administrator" role from the dropdown users menu.
	 */
	public static function remove_administrator( $retval ) {
		unset( $retval['administrator'] );
		return $retval;
	}
}

/**
 * Initialize custom code on the "Users > Pending Requests" page.
 */
function wp_ms_request_membership_pending_screen_loader() {
	global $wp_ms_request_list_table;

	// require the user list table
	require_once( ABSPATH . 'wp-admin/includes/class-wp-users-list-table.php' );

	/**
	 * List table class for the "Users > Pending Requests" page.
	 *
	 * Sorry that this is inline!  Didn't want to include another file.
	 */
	class WP_MS_Request_Membership_List_Table extends WP_Users_List_Table {
		/**
		 * @var int Pending requests count.
		 */
		public $count = 0;

		/**
		 * Constructor.
		 */
		public function __construct() {
			add_filter( 'manage_users_custom_column', array( $this, 'custom_columns' ), 10, 3 );
			add_filter( 'user_row_actions',           array( $this, 'custom_row_actions' ), 10, 2 );

			// Define singular and plural labels, as well as whether we support AJAX.
			parent::__construct( array(
				'ajax'     => false,
				'plural'   => 'requests',
				'singular' => 'request',
			) );
		}

		/**
		 * Override the parent prepare_items() method.
		 */
		public function prepare_items() {
			global $usersearch;

			$usersearch = isset( $_REQUEST['s'] ) ? $_REQUEST['s'] : '';
			$per_page   = $this->get_items_per_page( str_replace( '-', '_', "{$this->screen->id}_per_page" ) );
			$paged      = $this->get_pagenum();

			$pending_users = array_keys( get_option( WP_MS_Request_Membership::$pending_requests_key, array() ) );
			//$pending_users = array( 1, 4 );

			// add count
			if ( ! empty( $pending_users ) ) {
				$this->count = count( $pending_users );

			// if no users, make sure no users are returned
			} else {
				$pending_users[] = 0;
			}

			$args = array(
				'number'  => $per_page,
				'offset'  => ( $paged - 1 ) * $per_page,
				'search'  => "*{$usersearch}*",
				'orderby' => 'ID',
				'order'   => 'ASC',
				'include' => $pending_users,
				'blog_id' => 0,
			);

			if ( isset( $_REQUEST['orderby'] ) ) {
				$args['orderby'] = $_REQUEST['orderby'];
			}

			if ( isset( $_REQUEST['order'] ) ) {
				$args['order'] = $_REQUEST['order'];
			}

			// Query the user IDs for this page
			$wp_user_search = new WP_User_Query( $args );

			$this->items = $wp_user_search->get_results();

			$this->set_pagination_args( array(
				'total_items' => $wp_user_search->get_total(),
				'per_page'    => $per_page,
			) );
		}

		/**
		 * Get the views (the links above the WP List Table).
		 *
		 * @uses WP_Users_List_Table::get_views() to get the users views
		 */
		public function get_views() {
			$views = parent::get_views();

			// Remove the 'current' class from the 'All' link
			$views['all'] = str_replace( 'class="current"', '', $views['all'] );

			// Add our custom view
			$views['ms-requests'] = sprintf( '<a href="%1$s" class="current">%2$s</a>',
				add_query_arg( 'page', 'ms-pending-requests', admin_url( 'users.php' ) ),
				sprintf( __( 'Pending %s', 'wp-ms-request' ), '<span class="count">(' . number_format_i18n( $this->count ) . ')</span>' ) );

			return $views;
		}

		/**
		 * Override the parent display_rows() method.
		 */
		public function display_rows() {
			$style = '';
			foreach ( $this->items as $userid => $user_object ) {
				$style = ( ' class="alternate"' == $style ) ? '' : ' class="alternate"';
				echo "\n\t" . $this->single_row( $user_object, $style );
			}
		}

		/**
		 * Override the parent get_bulk_actions() method.
		 */
		public function get_bulk_actions() {
			$actions = array(
				'approve' => __( 'Approve', 'wp-ms-request' ),
				'decline' => __( 'Decline', 'wp-ms-request' ),
			);

			// Commenting this out for now
			if ( current_user_can( 'delete_users' ) ) {
				//$actions['delete'] = __( 'Delete', 'buddypress' );
			}

			return $actions;
		}

		/**
		 * Do not show "Change role to..." dropdown menu.
		 */
		public function extra_tablenav( $which ) {}

		/**
		 * The text shown when no items are found.
		 */
		public function no_items() {
			esc_html_e( 'No pending requests found.', 'wp-ms-request' );
		}

		/**
		 * Add custom "ID" column to our list table.
		 */
		public function custom_columns( $retval, $column_name, $user_id ) {
			if ( 'id' === $column_name ) {
				return $user_id;
			}
		}

		/**
		 * Add custom row actions - "Approve" and "Decline".
		 */
		public function custom_row_actions( $retval, $user ) {
			unset( $retval['edit-profile'], $retval['remove'], $retval['spam'] );

			$approve_link = wp_nonce_url(
				add_query_arg( array(
					'action' => 'ms-req-approve',
					'user_id' => $user->ID,
				) ),
				'ms-req-approve'
			);

			$decline_link = wp_nonce_url(
				add_query_arg( array(
					'action' => 'ms-req-decline',
					'user_id' => $user->ID,
				) ),
				'ms-req-decline'
			);

			$new_actions = array();
			$new_actions['ms-req-approve'] = '<a href="' . $approve_link . '">' . __( 'Approve', 'wp-ms-request' ) .'</a>';
			$new_actions['ms-req-decline'] = '<a href="' . $decline_link . '">' . __( 'Decline', 'wp-ms-request' ) .'</a>';

			return array_merge( $new_actions, $retval );
		}

		/**
		 * Set our columns for our list table.
		 */
		public static function set_columns() {
			return array(
				'cb'       => '<input type="checkbox" />',
				'id'       => __( 'ID',       'wp-ms-request' ),
				'username' => __( 'Username', 'wp-ms-request' ),
				'name'     => __( 'Name',     'wp-ms-request' ),
				'email'    => __( 'E-mail',   'wp-ms-request' ),
			);
		}

		/**
		 * Add additional sortable columns for our list table.
		 */
		public static function set_sortable_columns( $retval ) {
			$retval['id'] = 'id';

			return $retval;
		}

		/**
		 * Custom inline CSS.
		 */
		public static function css() {
		?>

			<style type="text/css">
			th.column-id, td.column-id {width:58px;}
			.ms-req-decline a {color:#a00;}
			</style>

		<?php
		}
	}

	// load our list table
	$wp_ms_request_list_table = new WP_MS_Request_Membership_List_Table;

	// list table mods
	add_action( 'admin_head', array( 'WP_MS_Request_Membership_List_Table', 'css' ) );
	add_filter( 'manage_users_page_ms-pending-requests_columns', array( 'WP_MS_Request_Membership_List_Table', 'set_columns' ) );
	add_filter( 'manage_users_page_ms-pending-requests_sortable_columns', array( 'WP_MS_Request_Membership_List_Table', 'set_sortable_columns' ) );

	// validation - approve / decline
	WP_MS_Request_Membership_Admin::pending_screen_bulk_validate( $wp_ms_request_list_table->current_action() );

}