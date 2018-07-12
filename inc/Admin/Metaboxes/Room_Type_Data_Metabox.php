<?php
namespace AweBooking\Admin\Metaboxes;

use AweBooking\Constants;
use AweBooking\Model\Room;
use AweBooking\Model\Room_Type;
use AweBooking\Component\Form\Form_Builder;
use Awethemes\Http\Request;

class Room_Type_Data_Metabox extends Abstract_Metabox {
	/**
	 * The form builder.
	 *
	 * @var \AweBooking\Component\Form\Form_Builder
	 */
	protected $form_builder;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->id      = 'awebooking-room-type-data';
		$this->title   = esc_html__( 'Room Type Data', 'awebooking' );
		$this->screen  = Constants::ROOM_TYPE;

		$this->form_builder = new Form_Builder( 'room-type' );
		$this->form_fields( $this->form_builder );
	}

	/**
	 * Output the metabox.
	 *
	 * @param \WP_Post $post The WP_Post object.
	 */
	public function output( $post ) {
		global $the_room_type;

		if ( is_null( $the_room_type ) ) {
			$the_room_type = abrs_get_room_type( $post->ID );
		}

		$is_translation = null;
		if ( abrs_running_on_multilanguage() ) {
			$is_translation = abrs_multilingual()->get_original_post( $post->ID ) != $post->ID;
		}

		// Prepare the Form.
		$form = $this->form_builder;

		// Add the tabs.
		foreach ( $this->get_tabs() as $key => $args ) {
			$form->add_section( $key, $args );
		}

		$form->object_id( $post->ID );
		$form->prepare_fields();

		foreach ( $form as $control ) {
			/* @var \AweBooking\Component\Form\Field_Proxy $control */
			if ( $is_translation && false === $control->prop( 'translatable' ) ) {
				$control->set_attribute( 'disabled', 'disabled' );
			}
		}

		// Print the core nonce field.
		wp_nonce_field( 'awebooking_save_data', '_awebooking_nonce' );

		// Show the form controls.
		include trailingslashit( __DIR__ ) . 'views/html-room-type-main.php';
	}

	/**
	 * Handle save the the metabox.
	 *
	 * @param \WP_Post                $post    The WP_Post object instance.
	 * @param \Awethemes\Http\Request $request The HTTP Request.
	 */
	public function save( $post, Request $request ) {
		// Create the new room-type instance.
		$room_type = new Room_Type( $post->ID );

		$is_translation = false;
		if ( abrs_running_on_multilanguage() ) {
			$is_translation = abrs_multilingual()->get_original_post( $post->ID ) != $post->ID;
		}

		// Get the sanitized values.
		$values = $this->form_builder->handle( $request );

		$rate_services = array_map( 'absint', $request->get( '_rate_services' ) ?: [] );

		// Fill the room type data.
		$room_type->fill([
			'beds'            => $values->get( '_beds', [] ),
			'view'            => $values->get( '_room_view', '' ),
			'area_size'       => $values->get( '_area_size', '' ),
			'rack_rate'       => $values->get( 'base_price', 0 ),
			'rate_inclusions' => $values->get( '_rate_inclusions', [] ),
			'rate_policies'   => $values->get( '_rate_policies', [] ),
			'rate_min_los'    => $values->get( 'minimum_night', 0 ),
			'rate_max_los'    => $values->get( '_rate_maximum_los', 0 ),
			'gallery_ids'     => $values->get( 'gallery', [] ),
			'tax_rate_id'     => $values->get( '_tax_rate_id', 0 ),
			'hotel_id'        => absint( $request->get( '_hotel_id' ) ),
			'rate_services'   => $rate_services,
		]);

		if ( ! $is_translation ) {
			// Correct the occupancy size.
			foreach ( [ 'number_adults', 'number_children', 'number_infants' ] as $key ) {
				if ( ! isset( $values[ $key ] ) ) {
					continue;
				}

				$max = (int) $values->get( '_maximum_occupancy', 0 );

				// Value cannot be greater than maximum occupancy.
				if ( (int) $values[ $key ] > $max ) {
					$values[ $key ] = $max;
				}
			}

			$room_type->fill([
				'maximum_occupancy'   => $values->get( '_maximum_occupancy', 0 ),
				'number_adults'       => $values->get( 'number_adults', 0 ),
				'number_children'     => $values->get( 'number_children', 0 ),
				'number_infants'      => $values->get( 'number_infants', 0 ),
				'calculation_infants' => $values->get( '_infants_in_calculations', 'off' ),
			]);
		}

		// Fire action before save.
		do_action( 'abrs_process_room_type_data', $room_type, $values, $request );

		// Save the data.
		$saved = $room_type->save();

		// Handle update rooms data.
		if ( false === $is_translation ) {
			if ( 0 === count( $room_type->get_rooms() ) ) {
				$this->perform_scaffold_rooms( $room_type, $request->input( '_rooms', [] ) );
			} elseif ( $request->filled( '_rooms' ) ) {
				$this->perform_update_rooms( $room_type, $request->input( '_rooms', [] ) );
			}
		}

		// Add successfully notice.
		if ( $saved ) {
			abrs_admin_notices( 'Successfully updated', 'success' )->dialog();
		}
	}

	/**
	 * Perform scaffold rooms.
	 *
	 * @param  \AweBooking\Model\Room_Type $room_type      The room type instance.
	 * @param  array                       $scaffold_rooms The rooms data.
	 * @return void
	 */
	protected function perform_scaffold_rooms( $room_type, $scaffold_rooms ) {
		$scaffold_rooms = array_filter( (array) $scaffold_rooms );

		if ( empty( $scaffold_rooms ) ) {
			return;
		}

		foreach ( array_values( $scaffold_rooms ) as $index => $data ) {
			if ( empty( $data['id'] ) || -1 != $data['id'] ) {
				continue;
			}

			$room = ( new Room )->fill([
				'order'     => $index,
				'name'      => ! empty( $data['name'] )
					? sanitize_text_field( wp_unslash( $data['name'] ) )
					/* translators: 1: Room type name, 2: Room item order */
					: sprintf( esc_html__( '%1$s - %2$d', 'awebooking' ), $room_type->get( 'title' ), ( $index + 1 ) ),
				'room_type' => $room_type->get_id(),
			]);

			$room->save();
		}
	}

	/**
	 * Perform update rooms.
	 *
	 * @param  \AweBooking\Model\Room_Type $room_type The room type instance.
	 * @param  array                       $rooms     The rooms data.
	 * @return void
	 */
	protected function perform_update_rooms( $room_type, $rooms ) {
		$index = 0;

		foreach ( (array) $rooms as $data ) {
			$name = ! empty( $data['name'] )
				? sanitize_text_field( wp_unslash( $data['name'] ) )
				/* translators: 1: Room type name, 2: Room item order */
				: sprintf( esc_html__( '%1$s - %2$d', 'awebooking' ), $room_type->get( 'title' ), ( $index + 1 ) );

			if ( $room = abrs_get_room( $data['id'] ) ) {
				$room->order = $index;
				$room->name  = $name;
			} else {
				$room = ( new Room )->fill([
					'name'      => $name,
					'order'     => $index,
					'room_type' => $room_type->get_id(),
				]);
			}

			$room->save();
			$index++;
		}
	}

	/**
	 * Register the fields on the form.
	 *
	 * @param  \AweBooking\Component\Form\Form_Builder $form The form builder.
	 * @return void
	 */
	protected function form_fields( $form ) {
		// General tab.
		$form->add_field([
			'id'              => '_beds',
			'type'            => 'include',
			'name'            => esc_html__( 'Beds', 'awebooking' ),
			'text'            => [ 'add_row_text' => esc_html__( 'Add More', 'awebooking' ) ],
			'include'         => trailingslashit( __DIR__ ) . 'views/html-room-type-bed.php',
			'repeatable'      => true,
			'sanitization_cb' => [ $this, 'sanitize_beds' ],
		]);

		$form->add_field([
			'id'          => '_room_view',
			'type'        => 'text_medium',
			'name'        => esc_html__( 'View', 'awebooking' ),
			'attributes'  => [ 'list' => 'view_datalist' ],
			'after'       => $this->datalist_view_callback(),
		]);

		$form->add_field([
			'name'       => esc_html__( 'Area size', 'awebooking' ),
			'id'         => '_area_size',
			'type'       => 'text_small',
			'validate'   => 'numeric|min:1',
			'before'     => '<div class="abrs-input-addon">',
			'after'      => '<label for="area_size">' . abrs_get_measure_unit_label() . '</label></div>',
		]);

		$form->add_field([
			'id'              => '_maximum_occupancy',
			'type'            => 'text_medium',
			'name'            => esc_html__( 'Maximum occupancy', 'awebooking' ),
			'default'         => 2,
			'after'           => $this->datalist_number_callback( 1, 20 ),
			'attributes'      => [ 'list' => '_maximum_occupancy_datalist' ],
			'sanitization_cb' => 'absint',
			'translatable'    => false,
		]);

		$form->add_field([
			'id'              => 'number_adults', // _number_adults
			'type'            => 'text',
			'name'            => esc_html__( 'Number Adults', 'awebooking' ),
			'default'         => 2,
			'attributes'      => [ 'list' => 'number_adults_datalist' ],
			'after'           => $this->datalist_number_callback( 1, 20 ),
			'sanitization_cb' => 'absint',
			'translatable'    => false,
		]);

		$form->add_field([
			'id'              => 'number_children', // _number_children
			'type'            => 'text',
			'name'            => esc_html__( 'Number Children', 'awebooking' ),
			'default'         => 0,
			'attributes'      => [ 'list' => 'number_children_datalist' ],
			'after'           => $this->datalist_number_callback( 1, 20 ),
			'sanitization_cb' => 'absint',
			'translatable'    => false,
		]);

		$form->add_field([
			'id'              => 'number_infants', // _number_infants
			'type'            => 'text',
			'name'            => esc_html__( 'Number Infants', 'awebooking' ),
			'default'         => 0,
			'attributes'      => [ 'list' => 'number_infants_datalist' ],
			'after'           => $this->datalist_number_callback( 1, 20 ),
			'sanitization_cb' => 'absint',
			'translatable'    => false,
		]);

		$form->add_field( [
			'id'              => '_infants_in_calculations',
			'type'            => 'abrs_toggle',
			'desc'            => esc_html__( 'Include infants in max calculations?', 'awebooking' ),
			'default'         => false,
			'show_names'      => false,
			'translatable'    => false,
		]);

		// Pricing.
		$form->add_field([
			'id'              => 'base_price', // _rack_rate
			'type'            => 'abrs_amount',
			'name'            => esc_html__( 'Rack Rate', 'awebooking' ),
			'append'          => abrs_currency_symbol(),
			'tooltip'         => esc_html__( 'Rack rate is the regular everyday rate.', 'awebooking' ),
		]);

		$form->add_field([
			'id'               => '_tax_rate_id',
			'type'             => 'select',
			'name'             => esc_html__( 'Tax', 'awebooking' ),
			'classes'          => 'with-selectize',
			'options_cb'       => 'abrs_get_tax_rates_for_dropdown',
			'show_option_none' => esc_html__( 'No Tax', 'awebooking' ),
			'show_on_cb'       => function () {
				return abrs_tax_enabled() && ( 'per_room' === abrs_get_tax_rate_model() );
			},
		]);

		$form->add_field([
			'id'          => '_rate_inclusions',
			'type'        => 'text',
			'name'        => esc_html__( 'Inclusions (for display)', 'awebooking' ),
			'desc'        => esc_html__( 'What does the package/service include? Ex. Breakfast, Shuttle, etc.', 'awebooking' ),
			'text'        => [ 'add_row_text' => esc_html__( 'Add More', 'awebooking' ) ],
			'repeatable'  => true,
			'tooltip'     => true,
		]);

		$form->add_field([
			'id'          => '_rate_policies',
			'type'        => 'text',
			'name'        => esc_html__( 'Policies (for display)', 'awebooking' ),
			'text'        => [ 'add_row_text' => esc_html__( 'Add More', 'awebooking' ) ],
			'desc'        => esc_html__( 'What does the policies apply for this room? Ex. Cancelable, Non-refundable., etc.', 'awebooking' ),
			'repeatable'  => true,
			'tooltip'     => true,
		]);

		$form->add_field([
			'id'              => 'minimum_night', // '_rate_min_los'
			'type'            => 'text_small',
			'name'            => esc_html__( 'Min LOS', 'awebooking' ),
			'desc'            => esc_html__( 'Minimum Length of Stay', 'awebooking' ),
			'default'         => 1,
			'tooltip'         => true,
			'sanitization_cb' => 'absint',
		]);

		$form->add_field([
			'id'              => '_rate_maximum_los',
			'type'            => 'text_small',
			'name'            => esc_html__( 'Max LOS', 'awebooking' ),
			'desc'            => esc_html__( 'Maximum Length of Stay', 'awebooking' ),
			'default'         => 0,
			'tooltip'         => true,
			'sanitization_cb' => 'absint',
		]);

		// Desc.
		$form->add_field([
			'id'           => 'gallery',
			'type'         => 'file_list',
			'name'         => esc_html__( 'Gallery', 'awebooking' ),
			'query_args'   => [ 'type' => 'image' ],
			'text'         => [ 'add_upload_files_text' => esc_html__( 'Select Images', 'awebooking' ) ],
			'preview_size' => 'medium',
		]);

		$form->add_field([
			'id'          => 'excerpt',
			'type'        => 'wysiwyg',
			'name'        => esc_html__( 'Short Description', 'awebooking' ),
			'save_field'  => false,
			'escape_cb'   => false,
			'options'     => [ 'textarea_rows' => 80 ],
			'default_cb'  => function() {
				return get_post_field( 'post_excerpt', get_the_ID() );
			},
		]);
	}

	/**
	 * Return array of tabs to show.
	 *
	 * @return array
	 */
	protected function get_tabs() {
		return apply_filters( 'abrs_room_type_data_tabs', [
			'general' => [
				'title'    => esc_html__( 'General', 'awebooking' ),
				'priority' => 10,
			],
			'pricing' => [
				'title'    => esc_html__( 'Pricing', 'awebooking' ),
				'priority' => 20,
			],
			'amenities' => [
				'title'    => esc_html__( 'Rooms', 'awebooking' ),
				'priority' => 40,
			],
			'description' => [
				'title'    => esc_html__( 'Description', 'awebooking' ),
				'priority' => 50,
			],
		]);
	}

	/**
	 * Output the sections.
	 *
	 * @param \AweBooking\Component\Form\Form_Builder $form The form builder.
	 * @access private
	 */
	protected function output_tabs( $form ) {
		include trailingslashit( __DIR__ ) . 'views/html-room-type-general.php';
		include trailingslashit( __DIR__ ) . 'views/html-room-type-pricing.php';
		include trailingslashit( __DIR__ ) . 'views/html-room-type-amenities.php';
		include trailingslashit( __DIR__ ) . 'views/html-room-type-description.php';
	}

	/**
	 * Generate datalist HTML callback.
	 *
	 * @param  int $min Min.
	 * @param  int $max Max.
	 * @return \Closure
	 */
	protected function datalist_number_callback( $min, $max ) {
		return function( $field_args, $field ) use ( $min, $max ) {
			echo '<datalist id="' . esc_attr( $field->id() ) . '_datalist">';

			for ( $i = $min; $i <= $max; $i++ ) {
				echo '<option value="' . esc_attr( $i ) . '">';
			}

			echo '</datalist>';
		};
	}

	/**
	 * Generate view datalist HTML callback.
	 *
	 * @return \Closure
	 */
	protected function datalist_view_callback() {
		return function() {
			$view_datalist = apply_filters( 'abrs_list_room_views', [
				esc_html__( 'Airport view', 'awebooking' ),
				esc_html__( 'Bay view', 'awebooking' ),
				esc_html__( 'City view', 'awebooking' ),
				esc_html__( 'Courtyard view', 'awebooking' ),
				esc_html__( 'Golf view', 'awebooking' ),
				esc_html__( 'Harbor view', 'awebooking' ),
				esc_html__( 'Intercoastal view', 'awebooking' ),
				esc_html__( 'Lake view', 'awebooking' ),
				esc_html__( 'Marina view', 'awebooking' ),
				esc_html__( 'Mountain view', 'awebooking' ),
				esc_html__( 'Ocean view', 'awebooking' ),
				esc_html__( 'Pool view', 'awebooking' ),
				esc_html__( 'River view', 'awebooking' ),
				esc_html__( 'Water view', 'awebooking' ),
				esc_html__( 'Beach view', 'awebooking' ),
				esc_html__( 'Garden view', 'awebooking' ),
				esc_html__( 'Park view', 'awebooking' ),
				esc_html__( 'Forest view', 'awebooking' ),
				esc_html__( 'Rain forest view', 'awebooking' ),
				esc_html__( 'Various views', 'awebooking' ),
				esc_html__( 'Limited view', 'awebooking' ),
				esc_html__( 'Slope view', 'awebooking' ),
				esc_html__( 'Strip view', 'awebooking' ),
				esc_html__( 'Countryside view', 'awebooking' ),
				esc_html__( 'Sea view', 'awebooking' ),
			]);

			echo '<datalist id="view_datalist">';

			foreach ( $view_datalist as $val ) {
				echo '<option value="' . esc_attr( $val ) . '">';
			}

			echo '</datalist>';
		};
	}

	/**
	 * Sanitize beds.
	 *
	 * TODO: ...
	 *
	 * @param  array $beds Input data.
	 * @return array
	 */
	public function sanitize_beds( $beds ) {
		$values = [];
		foreach ( (array) $beds as $key => $val ) {
			if ( ! isset( $val['type'] ) || ! $val['type'] ) {
				continue;
			}

			$values[ $key ] = $val;
		}

		return array_filter( $values );
	}
}