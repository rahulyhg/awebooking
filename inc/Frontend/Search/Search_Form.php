<?php
namespace AweBooking\Frontend\Search;

use WPLibs\Form\Html_Form;
use AweBooking\Availability\Search\Search_Form as Form;

class Search_Form extends Form {
	/**
	 * The form builder instance.
	 *
	 * @var Html_Form
	 */
	protected $builder;

	/**
	 * //
	 *
	 * @var array
	 */
	protected $atts = [
		'layout'          => 'default', // Default vertical layout.
		'alignment'       => '',
		'hotel_location'  => true,
		'occupancy'       => true,
		'only_room'       => null,
		'container_class' => '',
	];

	/**
	 * Constructor.
	 *
	 * @param array $atts
	 */
	public function __construct( $atts = [] ) {
		$this->builder = new Form_Builder;
		$this->atts = $atts;
	}

	/**
	 * {@inheritdoc}
	 */
	public function render() {
		$this->builder->set_model( $this->request );

		if ( $this->http_request ) {
			$this->builder->set_request( $this->http_request );
		}

		return abrs_get_template_content( 'search-form.php', $this->get_template_data() );
	}

	/**
	 * Return search form ID.
	 *
	 * @param  string|null $name Optional. Name suffix.
	 * @return string
	 */
	public function id( $name = null ) {
		return 'awebooking_searchbox_' . $this->request->get_instance_number() . ( $name ? '_' . $name : '' );
	}

	/**
	 * Return the search URL.
	 *
	 * @return string
	 */
	public function action() {
		return apply_filters( 'abrs_search_form_action', abrs_get_page_permalink( 'search_results' ) );
	}

	/**
	 * Load default components.
	 *
	 * @return void
	 */
	public function components() {
		foreach ( [ 'hotel', 'dates', 'occupancy', 'button' ] as $component ) {
			$this->component( $component );
		}
	}

	/**
	 * Load a single component.
	 *
	 * @param string $name The template name without .php suffix.
	 */
	public function component( $name ) {
		$name = rtrim( $name, '.php' ) . '.php';

		$template = "search-form/{$this->atts['layout']}/{$name}";

		if ( ! file_exists( abrs_locate_template( $template ) ) ) {
			$template = 'search-form/default/' . $name;
		}

		abrs_get_template( $template, $this->get_template_data() );
	}

	/**
	 * Returns data for the template.
	 *
	 * @return array
	 */
	protected function get_template_data() {
		return apply_filters( 'abrs_search_form_data', [
			'search_form'  => $this,
			'res_request'  => $this->get_request(),
			'http_request' => $this->get_http_request(),
			'atts'         => $this->atts,
		], $this );
	}

	/**
	 * Print hidden inputs.
	 *
	 * @return void
	 */
	public function hiddens() {
		$inputs = [];

		if ( ! get_option( 'permalink_structure' ) ) {
			$inputs[] = $this->builder->hidden( 'p', abrs_get_page_id( 'check_availability' ) );
		}

		if ( abrs_running_on_multilanguage() ) {
			$inputs[] = $this->builder->hidden( 'lang', $this->parameter( 'lang' ) ?: abrs_multilingual()->get_current_language() );
		}

		if ( abrs_is_room_type() ) {
			// Resolve current hotel ID.
			$inputs[] = $this->builder->hidden( 'hotel', '' );
		}

		// only...
		// ...

		if ( count( $inputs ) > 0 ) {
			print implode( "\n", $inputs ); // @WPCS: XSS OK.
		}
	}

	/**
	 * Print the "hotels" select.
	 *
	 * @param array $attributes
	 */
	public function hotels( $attributes = [] ) {
		$attributes = $this->prepare_attributes( 'hotel', $attributes );

		// TODO: Improve this in next version.
		$list = abrs_list_hotels()->pluck( 'name', 'id' );

		print $this->builder->select( 'hotel', $list, $this->parameter( 'hotel' ), $attributes ); // @WPCS: XSS OK.
	}

	/**
	 * Print the "check_in" input.
	 *
	 * @param array $attributes
	 * @param array $alt_attributes
	 */
	public function check_in( $attributes = [], $alt_attributes = [] ) {
		$value = $this->parameter( 'check_in' );

		print $this->builder->hidden( 'check_in', $value, $attributes ); // @WPCS: XSS OK.

		$this->input( 'text', 'check_in_alt', array_merge( $alt_attributes, [
			'name'          => false, // Remove "name" attribute.
			'value'         => $value ? abrs_format_date( $value ) : '',
			'placeholder'   => abrs_get_date_format(),
			'autocomplete'  => 'off',
			'aria-haspopup' => 'true',
		] ) );
	}

	/**
	 * Print the "check_out" input.
	 *
	 * @param array $attributes
	 * @param array $alt_attributes
	 */
	public function check_out( $attributes = [], $alt_attributes = [] ) {
		$value = $this->parameter( 'check_out' );

		print $this->builder->hidden( 'check_out', $value, $attributes ); // @WPCS: XSS OK.

		$this->input( 'text', 'check_out_alt', array_merge( $alt_attributes, [
			'name'          => false, // Remove "name" attribute.
			'value'         => $value ? abrs_format_date( $value ) : '',
			'placeholder'   => abrs_get_date_format(),
			'autocomplete'  => 'off',
			'aria-haspopup' => 'true',
		] ) );
	}

	/**
	 * Print adults input.
	 *
	 * @param array $attributes
	 */
	public function adults( $attributes = [] ) {
		// $attributes = $this->prepare_attributes( 'adults', $attributes );
		//
		// echo $this->builder->select_range( 'adults', 1, absint( abrs_get_option( 'search_form_max_adults', 6 ) ), null, $attributes );
		// return;
		$this->input( 'number', 'adults', wp_parse_args( $attributes, [
			'step' => 1,
			'min'  => 1,
			'max'  => absint( abrs_get_option( 'search_form_max_adults', 6 ) ),
		] ) );
	}

	/**
	 * Print children input.
	 *
	 * @param array $attributes
	 */
	public function children( $attributes = [] ) {
		$this->input( 'number', 'children', wp_parse_args( $attributes, [
			'step' => 1,
			'min'  => 0,
			'max'  => absint( abrs_get_option( 'search_form_max_children', 6 ) ),
		] ) );
	}

	/**
	 * Print infants input.
	 *
	 * @param array $attributes
	 */
	public function infants( $attributes = [] ) {
		$this->input( 'number', 'infants', wp_parse_args( $attributes, [
			'step' => 1,
			'min'  => 0,
			'max'  => absint( abrs_get_option( 'search_form_max_infants', 6 ) ),
		] ) );
	}

	/**
	 * Print a input.
	 *
	 * @param string $type
	 * @param string $name
	 * @param array  $attributes
	 */
	protected function input( $type, $name, $attributes ) {
		$attributes = $this->prepare_attributes( $name, $attributes );

		if ( isset( $attributes['type'] ) ) {
			$type = $attributes['type'];
		}

		$value = isset( $attributes['value'] )
			? $attributes['value']
			: $this->parameter( $name );

		print $this->builder->input( $type, $name, $value, $attributes ); // @WPCS: XSS OK.
	}

	/**
	 * Prepare some attributes by name.
	 *
	 * @param  string $name
	 * @param  array  $defaults
	 * @return array
	 */
	protected function prepare_attributes( $name, $defaults = [] ) {
		$attributes          = [];
		$attributes['id']    = $this->id( $name );
		$attributes['class'] = 'form-input searchbox__input searchbox__input--' . $name;

		if ( $this->request->is_locked( $name ) ) {
			$attributes['readonly'] = 'readonly';
		}

		return wp_parse_args( $defaults, $attributes );
	}
}
