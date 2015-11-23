<?php
/**
 * Cost utility functions
 */

// Don't load directly
if ( ! defined( 'ABSPATH' ) ) {
	die( '-1' );
}

class Tribe__Events__Cost_Utils {
	/**
	 * Static Singleton Factory Method
	 *
	 *@return Tribe__Events__Cost_Helpers
	 */
	public static function instance() {
		static $instance;

		if ( ! $instance ) {
			$instance = new self;
		}

		return $instance;
	}

	/**
	 * fetches all event costs from the database
	 *
	 * @return array
	 */
	public function get_all_costs() {
		global $wpdb;

		$costs = $wpdb->get_col( "
			SELECT
				DISTINCT meta_value
			FROM
				{$wpdb->postmeta}
			WHERE
				meta_key = '_EventCost'
				AND LENGTH( meta_value ) > 0;
		" );

		return $costs;
	}

	/**
	 * Fetch the possible separators
	 *
	 * @return array
	 */
	public function get_separators() {
		/**
		 * Allow users to create more possible separators, they must be only 1 char
		 * @var array
		 */
		return apply_filters( 'tribe_events_cost_separators', array( ',', '.' ) );
	}

	/**
	 * Check if a String is a valid cost
	 *
	 * @param  string  $cost String to be checked
	 * @return boolean
	 */
	public function is_valid_cost( $cost, $allow_negative = true ) {
		$price_regex = '(' . ( $allow_negative ? '-?' : '' ) . '[\d]+[\\' . implode( '\\', $this->get_separators() ) . ']?[\d]*)';

		return preg_match( $price_regex, trim( $cost ) );
	}

	/**
	 * fetches an event's cost values
	 *
	 * @param int|WP_Post $event The Event post object or event ID
	 *
	 * @return array
	 */
	public function get_event_costs( $event ) {
		$event = get_post( $event );

		if ( ! is_object( $event ) || ! $event instanceof WP_Post ) {
			return array();
		}

		if ( ! tribe_is_event( $event->ID ) ) {
			return array();
		}

		$costs = tribe_get_event_meta( $event->ID, '_EventCost', false );

		$parsed_costs = array();

		foreach ( $costs as $index => $value ) {
			if ( '' === $value ) {
				continue;
			}

			$parsed_costs += $this->parse_cost_range( $value );
		}

		return $parsed_costs;
	}//end get_event_costs

	/**
	 * Returns a formatted event cost
	 *
	 * @param int|WP_Post $event The Event post object or event ID
	 * @param bool $with_currency_symbol Include the currency symbol (optional)
	 *
	 * @return string
	 */
	public function get_formatted_event_cost( $event, $with_currency_symbol = false ) {
		$costs = $this->get_event_costs( $event );

		if ( ! $costs ) {
			return '';
		}

		$relevant_costs = array(
			'min' => $this->get_cost_by_func( $costs, 'min' ),
			'max' => $this->get_cost_by_func( $costs, 'max' ),
		);

		foreach ( $relevant_costs as &$cost ) {
			$cost = $this->maybe_replace_cost_with_free( $cost );

			if ( $with_currency_symbol ) {
				$cost = $this->maybe_format_with_currency( $cost );
			}

			$cost = esc_html( $cost );
		}

		if ( $relevant_costs['min'] == $relevant_costs['max'] ) {
			$formatted = $relevant_costs['min'];
		} else {
			$formatted = $relevant_costs['min'] . _x( ' - ', 'Cost range separator', 'the-events-calendar' ) . $relevant_costs['max'];
		}

		return $formatted;
	}//end get_formatted_event_cost

	/**
	 * If the cost is "0", call it "Free"
	 *
	 * @param int|float|string $cost Cost to analyze
	 *
	 * return int|float|string
	 */
	public function maybe_replace_cost_with_free( $cost ) {
		if ( '0' === (string) $cost ) {
			return esc_html__( 'Free', 'the-events-calendar' );
		}

		return $cost;
	}//end maybe_replace_cost_with_free

	/**
	 * Formats a cost with a currency symbol
	 *
	 * @param int|float|string $cost Cost to format
	 *
	 * return string
	 */
	public function maybe_format_with_currency( $cost ) {
		// check if the currency symbol is desired, and it's just a number in the field
		// be sure to account for european formats in decimals, and thousands separators
		if ( is_numeric( str_replace( $this->get_separators(), '', $cost ) ) ) {
			$cost = tribe_format_currency( $cost );
		}

		return $cost;
	}//end maybe_format_with_currency

	/**
	 * Returns a particular cost within an array of costs
	 *
	 * @param $costs mixed Cost(s) to review for max value
	 * @param $function string Function to use to determine which cost to return from range. Valid values: max, min
	 *
	 * @return float
	 */
	protected function get_cost_by_func( $costs = null, $function = 'max' ) {
		if ( ! is_array( $costs ) ) {
			if ( null === $costs ) {
				$costs = $this->get_all_costs();
			} else {
				$costs = (array) $costs;
			}

			$new_costs = array();
			foreach ( $costs as $index => $value ) {
				$values = $this->parse_cost_range( $value );
				foreach ( $values as $numeric => $val ) {
					$new_costs[ $numeric ] = $val;
				}
			}
			$costs = $new_costs;
		}

		if ( empty( $costs ) ) {
			return 0;
		}

		switch ( $function ) {
			case 'min':
				$cost = $costs[ min( array_keys( $costs ) ) ];
				break;
			case 'max':
			default:
				$cost = $costs[ max( array_keys( $costs ) ) ];
				break;
		}//end switch

		// Build the regular expression
		$price_regex = '(-?[\d]+[\\' . implode( '\\', $this->get_separators() ) . ']?[\d]*)';

		// use a regular expression instead of is_numeric
		if ( ! preg_match( $price_regex, $cost ) ) {
			return 0;
		}

		return $cost;
	}//end get_cost_by_func

	/**
	 * Returns a maximum cost in a list of costs. If an array of costs is not passed in, the array of costs is fetched via query.
	 *
	 * @param $costs mixed Cost(s) to review for max value
	 *
	 * @return float
	 */
	public function get_maximum_cost( $costs = null ) {
		return $this->get_cost_by_func( $costs, 'max' );
	}//end get_maximum_cost

	/**
	 * Returns a minimum cost in a list of costs. If an array of costs is not passed in, the array of costs is fetched via query.
	 *
	 * @param $costs mixed Cost(s) to review for min value
	 *
	 * @return float
	 */
	public function get_minimum_cost( $costs = null ) {
		return $this->get_cost_by_func( $costs, 'min' );
	}//end get_minimum_cost

	/**
	 * Parses an event cost into an array of ranges. If a range isn't provided, the resulting array will hold a single value.
	 *
	 * @param $cost string Cost for event.
	 *
	 * @return array
	 */
	public function parse_cost_range( $cost, $max_decimals = null ) {
		$separators = $this->get_separators();

		// Build the regular expression
		$price_regex = '((-?[\d]+)[\\' . implode( '\\', $separators ) . ']?([\d]*))';

		if ( ! is_string( $cost ) ){
			return $cost;
		}

		// try to find the lowest numerical value in a possible range
		if ( preg_match_all( '/' . $price_regex . '/', $cost, $matches ) ) {
			$cost = reset( $matches );
		}

		// Get the max number of decimals for the range
		if ( count( $matches ) === 4 ) {
			$decimals = max( array_map( 'strlen', end( $matches ) ) );

			// If we passed max decimals
			if ( ! is_null( $max_decimals ) ) {
				$decimals = max( $max_decimals, $decimals );
			}
		}

		$cost = (array) $cost;
		$ocost = array();

		// Keep the Costs in a organizeable array by keys with the "numeric" value
		foreach ( $cost as $key => $value ) {
			// Creates a Well Balanced Index that will perform good on a Key Sorting method
			$index = str_replace( '.', '', number_format( str_replace( $separators, '.', $value ), $decimals ) );
			$ocost[ $index ] = $value;
		}

		// Filter keeping the Keys
		asort( $ocost );

		return (array) $ocost;
	}//end parse_cost_range
}//end class
