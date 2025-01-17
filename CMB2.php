<?php

/**
 * Helper function to provide directory path to CMB
 * @since  2.0.0
 * @param  string  $path Path to append
 * @return string        Directory with optional path appended
 */
function cmb2_dir( $path = '' ) {
	return trailingslashit( dirname( __FILE__ ) ) . $path;
}

require_once cmb2_dir( 'includes/helper-functions.php' );

$meta_boxes_config = apply_filters( 'cmb2_meta_boxes', array() );
foreach ( (array) $meta_boxes_config as $meta_box ) {
	$cmb = new CMB2( $meta_box );
	if ( $cmb->prop( 'hookup' ) ) {
		$hookup = new CMB2_hookup( $cmb );
	}
}

/**
 * Create meta boxes
 */
class CMB2 {

	/**
	 * Current field's ID
	 * @var   string
	 * @since 2.0.0
	 */
	protected $cmb_id = '';

	/**
	 * Metabox Config array
	 * @var   array
	 * @since 0.9.0
	 */
	protected $meta_box;

	/**
	 * Object ID for metabox meta retrieving/saving
	 * @var   int
	 * @since 1.0.0
	 */
	protected $object_id = 0;

	/**
	 * Type of object being saved. (e.g., post, user, or comment)
	 * @var   string
	 * @since 1.0.0
	 */
	protected $object_type = 'post';

	/**
	 * Type of object registered for metabox. (e.g., post, user, or comment)
	 * @var   string
	 * @since 1.0.0
	 */
	protected $mb_object_type = null;

	/**
	 * List of fields that are changed/updated on save
	 * @var   array
	 * @since 1.1.0
	 */
	protected $updated = array();

	/**
	 * Metabox Defaults
	 * @var   array
	 * @since 1.0.1
	 */
	protected $mb_defaults = array(
		'id'         => '',
		'title'      => '',
		'type'       => '',
		'pages'      => array(), // Post type
		'context'    => 'normal',
		'priority'   => 'high',
		'show_names' => true, // Show field names on the left
		'show_on'    => array( 'key' => false, 'value' => false ), // Specific post IDs or page templates to display this metabox
		'cmb_styles' => true, // Include cmb bundled stylesheet
		'fields'     => array(),
		'hookup'     => true,
		'new_user_section' => 'add-new-user', // or 'add-existing-user'
	);

	/**
	 * Get started
	 */
	function __construct( $meta_box, $object_id = 0 ) {

		if ( empty( $meta_box['id'] ) ) {
			wp_die( __( 'Metabox configuration is required to have an ID parameter', 'cmb2' ) );
		}

		$this->meta_box = wp_parse_args( $meta_box, $this->mb_defaults );
		$this->object_id( $object_id );
		$this->mb_object_type();
		$this->cmb_id = $meta_box['id'];

		CMB2_Boxes::add( $this );
	}

	/**
	 * Loops through and displays fields
	 * @since  1.0.0
	 * @param  int    $object_id   Object ID
	 * @param  string $object_type Type of object being saved. (e.g., post, user, or comment)
	 */
	public function show_form( $object_id = 0, $object_type = '' ) {
		$object_type = $this->object_type( $object_type );
		$object_id = $this->object_id( $object_id );

		$this->nonce_field();

		echo "\n<!-- Begin CMB Fields -->\n";
		/**
		 * Hook before form table begins
		 *
		 * @param array  $cmb_id      The current box ID
		 * @param int    $object_id   The ID of the current object
		 * @param string $object_type The type of object you are working with.
		 *	                           Usually `post` (this applies to all post-types).
		 *	                           Could also be `comment`, `user` or `options-page`.
		 * @param array  $cmb         This CMB2 object
		 */
		do_action( 'cmb2_before_form', $this->cmb_id, $object_id, $object_type, $this );

		echo '<div class="cmb2_wrap form-table"><ul id="cmb2_metabox_'. sanitize_html_class( $this->cmb_id ) .'" class="cmb2_metabox">';

		foreach ( $this->prop( 'fields' ) as $field_args ) {

			$field_args['context'] = $this->prop( 'context' );

			if ( 'group' == $field_args['type'] ) {

				if ( ! isset( $field_args['show_names'] ) ) {
					$field_args['show_names'] = $this->prop( 'show_names' );
				}
				$this->render_group( $field_args );
			} else {

				$field_args['show_names'] = $this->prop( 'show_names' );

				// Render default fields
				$field = new CMB2_Field( array(
					'field_args'  => $field_args,
					'object_type' => $this->object_type(),
					'object_id'   => $this->object_id(),
				) );
				$field->render_field();
			}
		}

		echo '</ul></div>';

		/**
		 * Hook after form form has been rendered
		 *
		 * @param array  $cmb_id      The current box ID
		 * @param int    $object_id   The ID of the current object
		 * @param string $object_type The type of object you are working with.
		 *	                           Usually `post` (this applies to all post-types).
		 *	                           Could also be `comment`, `user` or `options-page`.
		 * @param array  $cmb         This CMB2 object
		 */
		do_action( 'cmb2_after_form', $this->cmb_id, $object_id, $object_type, $this );
		echo "\n<!-- End CMB Fields -->\n";

	}

	/**
	 * Render a repeatable group
	 */
	public function render_group( $args ) {
		if ( ! isset( $args['id'], $args['fields'] ) || ! is_array( $args['fields'] ) ) {
			return;
		}

		$args['count']   = 0;
		$field_group     = new CMB2_Field( array(
			'field_args'  => $args,
			'object_type' => $this->object_type(),
			'object_id'   => $this->object_id(),
		) );
		$desc            = $field_group->args( 'description' );
		$label           = $field_group->args( 'name' );
		$sortable        = $field_group->options( 'sortable' ) ? ' sortable' : '';
		$group_val       = (array) $field_group->value();
		$nrows           = count( $group_val );
		$remove_disabled = $nrows <= 1 ? 'disabled="disabled" ' : '';

		echo '<li class="cmb-row cmb-repeat-group-wrap"><div class="cmb-td"><ul id="', $field_group->id(), '_repeat" class="cmb-nested repeatable-group'. $sortable .'" style="width:100%;">';
		if ( $desc || $label ) {
			$class = $desc ? ' cmb-group-description' : '';
			echo '<li class="cmb-row'. $class .'"><div class="cmb-th">';
				if ( $label )
					echo '<h2 class="cmb-group-name">'. $label .'</h2>';
				if ( $desc )
					echo '<p class="cmb2_metabox_description">'. $desc .'</p>';
			echo '</div></li>';
		}

		if ( ! empty( $group_val ) ) {

			foreach ( $group_val as $iterator => $field_id ) {
				$this->render_group_row( $field_group, $remove_disabled );
			}
		} else {
			$this->render_group_row( $field_group, $remove_disabled );
		}

		echo '<li class="cmb-row"><div class="cmb-td"><p class="add-row"><button data-selector="', $field_group->id() ,'_repeat" data-grouptitle="', $field_group->options( 'group_title' ) ,'" class="add-group-row button">'. $field_group->options( 'add_button' ) .'</button></p></div></li>';

		echo '</ul></div></li>';

	}

	public function render_group_row( $field_group, $remove_disabled ) {

		echo '
		<li class="cmb-row repeatable-grouping" data-iterator="'. $field_group->count() .'">
			<div class="cmb-td">
				<ul class="cmb-nested" style="width: 100%;">';
				if ( $field_group->options( 'group_title' ) ) {
					echo '
					<li class="cmb-row cmb-group-title">
						<div class="cmb-th">
							', sprintf( '<h4>%1$s</h4>', $field_group->replace_hash( $field_group->options( 'group_title' ) ) ), '
						</div>
					</li>
					';
				}
				// Render repeatable group fields
				foreach ( array_values( $field_group->args( 'fields' ) ) as $field_args ) {
					$field_args['show_names'] = $field_group->args( 'show_names' );
					$field_args['context'] = $field_group->args( 'context' );
					$field = new CMB2_Field( array(
						'field_args'  => $field_args,
						'group_field' => $field_group,
					) );
					$field->render_field();
				}
				echo '
					<li class="cmb-row remove-field-row">
						<div class="remove-row">
							<button '. $remove_disabled .'data-selector="'. $field_group->id() .'_repeat" class="button remove-group-row alignright">'. $field_group->options( 'remove_button' ) .'</button>
						</div>
					</li>
				</ul>
			</div>
		</li>
		';

		$field_group->args['count']++;
	}

	/**
	 * Loops through and saves field data
	 * @since  1.0.0
	 * @param  int    $object_id   Object ID
	 * @param  string $object_type Type of object being saved. (e.g., post, user, or comment)
	 */
	public function save_fields( $object_id = 0, $object_type = '', $_post ) {

		$object_id = $this->object_id( $object_id );
		$object_type = $this->object_type( $object_type );

		$this->prop( 'show_on', array( 'key' => false, 'value' => false ) );

		// save field ids of those that are updated
		$this->updated = array();

		foreach ( $this->prop( 'fields' ) as $field_args ) {

			if ( 'group' == $field_args['type'] ) {
				$this->save_group( $field_args );
			} elseif ( 'title' == $field_args['type'] ) {
				// Don't process title fields
				continue;
			} else {
				// Save default fields
				$field = new CMB2_Field( array(
					'field_args'  => $field_args,
					'object_type' => $object_type,
					'object_id'   => $object_id,
				) );

				$field->save_field( $_post );
			}

		}

		// If options page, save the updated options
		if ( $object_type == 'options-page' ) {
			cmb2_options( $object_id )->set();
		}

		/**
		 * Fires after all fields have been saved.
		 *
		 * The dynamic portion of the hook name, $object_type, refers to the metabox/form's object type
		 * 	Usually `post` (this applies to all post-types).
		 *  	Could also be `comment`, `user` or `options-page`.
		 *
		 * @param int    $object_id   The ID of the current object
		 * @param array  $cmb_id      The current box ID
		 * @param string $updated     All fields that were updated.
		 *                            Will only include fields that had values change.
		 * @param array  $cmb         This CMB2 object
		 */
		do_action( "cmb2_save_{$object_type}_fields", $object_id, $this->cmb_id, $this->updated, $this );

	}

	/**
	 * Save a repeatable group
	 */
	public function save_group( $args ) {

		if ( ! isset( $args['id'], $args['fields'], $_POST[ $args['id'] ] ) || ! is_array( $args['fields'] ) )
			return;

		$field_group        = new CMB2_Field( array(
			'field_args'  => $args,
			'object_type' => $this->object_type(),
			'object_id'   => $this->object_id(),
		) );
		$base_id            = $field_group->id();
		$old                = $field_group->get_data();
		$group_vals         = $_POST[ $base_id ];
		$saved              = array();
		$is_updated         = false;
		$field_group->index = 0;

		// $group_vals[0]['color'] = '333';
		foreach ( array_values( $field_group->fields() ) as $field_args ) {
			$field = new CMB2_Field( array(
				'field_args'  => $field_args,
				'group_field' => $field_group,
			) );
			$sub_id = $field->id( true );

			foreach ( (array) $group_vals as $field_group->index => $post_vals ) {

				// Get value
				$new_val = isset( $group_vals[ $field_group->index ][ $sub_id ] )
					? $group_vals[ $field_group->index ][ $sub_id ]
					: false;

				// Sanitize
				$new_val = $field->sanitization_cb( $new_val );

				if ( 'file' == $field->type() && is_array( $new_val ) ) {
					// Add image ID to the array stack
					$saved[ $field_group->index ][ $new_val['field_id'] ] = $new_val['attach_id'];
					// Reset var to url string
					$new_val = $new_val['url'];
				}

				// Get old value
				$old_val = is_array( $old ) && isset( $old[ $field_group->index ][ $sub_id ] )
					? $old[ $field_group->index ][ $sub_id ]
					: false;

				$is_updated = ( ! empty( $new_val ) && $new_val != $old_val );
				$is_removed = ( empty( $new_val ) && ! empty( $old_val ) );
				// Compare values and add to `$updated` array
				if ( $is_updated || $is_removed )
					$this->updated[] = $base_id .'::'. $field_group->index .'::'. $sub_id;

				// Add to `$saved` array
				$saved[ $field_group->index ][ $sub_id ] = $new_val;

			}
			$saved[ $field_group->index ] = array_filter( $saved[ $field_group->index ] );
		}
		$saved = array_filter( $saved );

		$field_group->update_data( $saved, true );
	}

	/**
	 * Get object id from global space if no id is provided
	 * @since  1.0.0
	 * @param  integer $object_id Object ID
	 * @return integer $object_id Object ID
	 */
	public function object_id( $object_id = 0 ) {

		if ( $object_id ) {
			$this->object_id = $object_id;
			return $this->object_id;
		}

		if ( $this->object_id ) {
			return $this->object_id;
		}

		// Try to get our object ID from the global space
		switch ( $this->object_type() ) {
			case 'user':
				if ( ! isset( $this->new_user_page ) ) {
					$object_id = isset( $GLOBALS['user_ID'] ) ? $GLOBALS['user_ID'] : $object_id;
				}
				$object_id = isset( $_REQUEST['user_id'] ) ? $_REQUEST['user_id'] : $object_id;
				break;

			default:
				$object_id = isset( $GLOBALS['post']->ID ) ? $GLOBALS['post']->ID : $object_id;
				$object_id = isset( $_REQUEST['post'] ) ? $_REQUEST['post'] : $object_id;
				break;
		}

		// reset to id or 0
		$this->object_id = $object_id ? $object_id : 0;

		return $this->object_id;
	}

	/**
	 * Sets the $object_type based on metabox settings
	 * @since  1.0.0
	 * @param  array|string $meta_box Metabox config array or explicit setting
	 * @return string       Object type
	 */
	public function mb_object_type() {

		if ( null !== $this->mb_object_type ) {
			return $this->mb_object_type;
		}

		if ( $this->is_options_page_mb() ) {
			$this->mb_object_type = 'options-page';
			return $this->mb_object_type;
		}

		if ( ! $this->prop( 'pages' ) ) {
			$this->mb_object_type = 'post';
			return $this->mb_object_type;
		}

		$type = false;
		// check if 'pages' is a string
		if ( is_string( $this->prop( 'pages' ) ) ) {
			$type = $this->prop( 'pages' );
		}
		// if it's an array of one, extract it
		elseif ( is_array( $this->prop( 'pages' ) ) && count( $this->prop( 'pages' ) === 1 ) ) {
			$cpts = $this->prop( 'pages' );
			$type = is_string( end( $cpts ) )
				? end( $cpts )
				: false;
		}

		if ( ! $type ) {
			$this->mb_object_type = 'post';
			return $this->mb_object_type;
		}

		// Get our object type
		if ( 'user' == $type )
			$this->mb_object_type = 'user';
		elseif ( 'comment' == $type )
			$this->mb_object_type = 'comment';
		else
			$this->mb_object_type = 'post';

		return $this->mb_object_type;
	}

	/**
	 * Determines if metabox is for an options page
	 * @since  1.0.1
	 * @return boolean True/False
	 */
	public function is_options_page_mb() {
		return ( isset( $this->meta_box['show_on']['key'] ) && 'options-page' === $this->meta_box['show_on']['key'] );
	}

	/**
	 * Returns the object type
	 * @since  1.0.0
	 * @return string Object type
	 */
	public function object_type( $object_type = '' ) {
		if ( $object_type ) {
			$this->object_type = $object_type;
			return $this->object_type;
		}

		if ( $this->object_type ) {
			return $this->object_type;
		}

		global $pagenow;

		if ( in_array( $pagenow, array( 'user-edit.php', 'profile.php', 'user-new.php' ), true ) ) {
			$this->object_type = 'user';

		} elseif ( in_array( $pagenow, array( 'edit-comments.php', 'comment.php' ), true ) ) {
			$this->object_type = 'comment';

		} else {
			$this->object_type = 'post';
		}

		return $this->object_type;
	}

	/**
	 * Get metabox property and optionally set a fallback
	 * @since  2.0.0
	 * @param  string $property Metabox config property to retrieve
	 * @param  mixex  $fallback Fallback value to set if no value found
	 * @return mixed            Metabox config property value or false
	 */
	public function prop( $property, $fallback = null ) {
		if ( array_key_exists( $property, $this->meta_box ) ) {
			return $this->meta_box[ $property ];
		} elseif ( $fallback ) {
			return $this->meta_box[ $property ] = $fallback;
		}
	}

	/**
	 * Generate a unique nonce field for each registered meta_box
	 * @since  2.0.0
	 * @return string unique nonce hidden input
	 */
	public function nonce_field() {
		wp_nonce_field( $this->nonce(), $this->nonce(), false, true );
	}

	/**
	 * Generate a unique nonce for each registered meta_box
	 * @since  2.0.0
	 * @return string unique nonce string
	 */
	public function nonce() {
		if ( isset( $this->generated_nonce ) ) {
			return $this->generated_nonce;
		}
		$this->generated_nonce = sanitize_html_class( 'nonce_'. basename( __FILE__ ) . $this->cmb_id );
		return $this->generated_nonce;
	}

	/**
	 * Magic getter for our object.
	 * @param string $field
	 * @throws Exception Throws an exception if the field is invalid.
	 * @return mixed
	 */
	public function __get( $field ) {
		switch( $field ) {
			case 'cmb_id':
			case 'meta_box':
				return $this->{$field};
			default:
				throw new Exception( 'Invalid '. __CLASS__ .' property: ' . $field );
		}
	}

}

/**
 * Stores each CMB2 instance
 */
class CMB2_Boxes {

	/**
	 * Array of all metabox objects
	 * @var   array
	 * @since 2.0.0
	 */
	protected static $meta_boxes = array();

	public static function get( $cmb_id ) {
		if ( empty( self::$meta_boxes ) || empty( self::$meta_boxes[ $cmb_id ] ) ) {
			return false;
		}

		return self::$meta_boxes[ $cmb_id ];
	}

	public static function add( $meta_box ) {
		self::$meta_boxes[ $meta_box->cmb_id ] = $meta_box;
	}

}

// End. That's it, folks! //
