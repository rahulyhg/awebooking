<?php

use AweBooking\Constants;
use AweBooking\Model\Hotel;

/**
 * Retrieves the hotel object.
 *
 * @param  mixed $hotel The hotel ID.
 * @return \AweBooking\Model\Hotel|false|null
 */
function abrs_get_hotel( $hotel = 0 ) {
	if ( 0 == $hotel ) {
		return abrs_get_primary_hotel();
	}

	return abrs_rescue( function() use ( $hotel ) {
		$hotel = new Hotel( $hotel );

		return $hotel->exists() ? $hotel : null;
	}, false );
}

/**
 * Returns the default hotel.
 *
 * @return \AweBooking\Model\Hotel
 */
function abrs_get_primary_hotel() {
	if ( ! awebooking()->bound( 'default_hotel' ) ) {
		awebooking()->singleton( 'default_hotel', function () {
			return new Hotel( 'default' );
		});
	}

	return awebooking()->make( 'default_hotel' );
}

/**
 * Gets all hotels.
 *
 * @param array $args         Optional, the WP_Query args.
 * @param bool  $with_primary Return with primary hotel?.
 * @return \AweBooking\Support\Collection
 */
function abrs_list_hotels( $args = [], $with_primary = false ) {
	$args = wp_parse_args( $args, apply_filters( 'abrs_query_hotels_args', [
		'post_type'      => Constants::HOTEL_LOCATION,
		'post_status'    => 'publish',
		'posts_per_page' => 500, // Limit max 500.
		'order'          => 'ASC',
		'orderby'        => 'menu_order',
	] ) );

	$wp_query = new WP_Query( $args );

	$hotels = abrs_collect( $wp_query->posts )
		->map_into( Hotel::class );

	if ( $with_primary ) {
		$hotels = $hotels->prepend( abrs_get_primary_hotel() );
	}

	return $hotels;
}