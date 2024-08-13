<?php
/*
Plugin Name: Paid Memberships Pro - Subscription Delays Add On
Plugin URI: https://www.paidmembershipspro.com/add-ons/subscription-delays/
Description: Adds a field to delay the start of a subscription for membership levels and discount codes for variable-length trials.
Version: 0.5.7
Author: Paid Memberships Pro
Author URI: https://www.paidmembershipspro.com
Text Domain: pmpro-subscription-delays
Domain Path: /languages
*/

function pmprosd_pmpro_load_plugin_text_domain() {
	load_plugin_textdomain( 'pmpro-subscription-delays', false, basename( dirname( __FILE__ ) ) . '/languages' ); 
}
add_action( 'init', 'pmprosd_pmpro_load_plugin_text_domain');



// add subscription delay field to level price settings
function pmprosd_pmpro_membership_level_after_other_settings() {
	$level_id = intval( $_REQUEST['edit'] );
	$delay    = get_option( 'pmpro_subscription_delay_' . $level_id, '' );
	?>
	<table>
		<tbody class="form-table">
		<tr>
			<td>
		<tr>
			<th scope="row" valign="top"><label for="subscription_delay"><?php esc_html_e('Subscription Delay:', 'pmpro-subscription-delays'); ?></label></th>
			<td><input name="subscription_delay" type="text" size="20" value="<?php echo esc_attr( $delay ); ?>" /> <small><?php esc_html_e('# of days to delay the start of the subscription. If set, this will override any trial/etc defined above.', 'pmpro-subscription-delays'); ?></small></td>
		</tr>
		</td>
		</tr>
		</tbody>
	</table>
	<?php
}
add_action( 'pmpro_membership_level_after_other_settings', 'pmprosd_pmpro_membership_level_after_other_settings' );

// save subscription delays for the code when the code is saved/added
function pmprosd_pmpro_save_membership_level( $level_id ) {
	$subscription_delay = $_REQUEST['subscription_delay'];  // subscription delays for levels checked
	update_option( 'pmpro_subscription_delay_' . $level_id, $subscription_delay );
}
add_action( 'pmpro_save_membership_level', 'pmprosd_pmpro_save_membership_level' );

// add subscription delay field to level price settings
function pmprosd_pmpro_discount_code_after_level_settings( $code_id, $level ) {
	$delays = pmpro_getDCSDs( $code_id );
	if ( ! empty( $delays[ $level->id ] ) ) {
		$delay = $delays[ $level->id ];
	} else {
		$delay = '';
	}
	?>
	<table>
		<tbody class="form-table">
		<tr>
			<td>
		<tr>
			<th scope="row" valign="top"><label for="subscription_delay"><?php esc_html_e('Subscription Delay:', 'pmpro-subscription-delays'); ?></label></th>
			<td><input name="subscription_delay[]" type="text" size="20" value="<?php echo esc_attr( $delay ); ?>" /> <small><?php esc_html_e('# of days to delay the start of the subscription. If set, this will override any trial/etc defined above.', 'pmpro-subscription-delays'); ?></small></td>
		</tr>
		</td>
		</tr>
		</tbody>
	</table>
	<?php
}
add_action( 'pmpro_discount_code_after_level_settings', 'pmprosd_pmpro_discount_code_after_level_settings', 10, 2 );

// save subscription delays for the code when the code is saved/added
function pmprosd_pmpro_save_discount_code_level( $code_id, $level_id ) {
	$all_levels_a         = $_REQUEST['all_levels'];                            // array of level ids checked for this code
	$subscription_delay_a = $_REQUEST['subscription_delay'];    // subscription delays for levels checked

	if ( ! empty( $all_levels_a ) ) {
		$key                 = array_search( $level_id, $all_levels_a );              // which level is it in the list?
		$delays              = pmpro_getDCSDs( $code_id );                     // get delays for this code
		$delays[ $level_id ] = $subscription_delay_a[ $key ];           // add delay for this level
		pmpro_saveDCSDs( $code_id, $delays );                     // save delays
	}
}
add_action( 'pmpro_save_discount_code_level', 'pmprosd_pmpro_save_discount_code_level', 10, 2 );

// update subscription start date based on the discount code used or levels subscription start date
function pmprosd_pmpro_profile_start_date( $start_date, $order ) {
	$subscription_delay = null;

	// if a discount code is used, we default to the setting there
	if ( ! empty( $order->discount_code_id ) ) {
		// The order has already been saved with a discount code.
		$delays = pmpro_getDCSDs( $order->discount_code_id );
		if ( ! empty( $delays[ $order->membership_id ] ) ) {
			$subscription_delay = $delays[ $order->membership_id ];
		}
	} elseif( pmpro_is_checkout() && ! empty( $order->getMembershipLevelAtCheckout()->discount_code ) ) {
		// We are at checekout and the discount code use has not been added to the order yet.
		$discount_code = $order->getMembershipLevelAtCheckout()->discount_code;
		$code_obj = new PMPro_Discount_Code( $discount_code );
		if ( ! empty( $code_obj->id ) ) {
			$delays = pmpro_getDCSDs( $code_obj->id );
			if ( ! empty( $delays[ $order->membership_id ] ) ) {
				$subscription_delay = $delays[ $order->membership_id ];
			}
		}
	} else {
		// No discount code, so we default to the setting on the level.
		$subscription_delay = get_option( 'pmpro_subscription_delay_' . $order->membership_id, '' );
	}

	if ( empty( $subscription_delay ) ) {
	    return $start_date;
    }
    
	if ( ! is_numeric( $subscription_delay ) ) {
		$start_date = pmprosd_convert_date( $subscription_delay );
	} else {
		$start_date = date( 'Y-m-d', strtotime( '+ ' . intval( $subscription_delay ) . ' Days', current_time( 'timestamp' ) ) ) . 'T0:0:0';
	}
	
	$today = date( 'Y-m-d\T0:0:0', current_time( 'timestamp' ) );
	
	// Stripe does strange things if the profile start is before the current date!
	if ( $start_date < $today ) {
		$start_date = $today;
	}

	$start_date = apply_filters( 'pmprosd_modify_start_date', $start_date, $order, $subscription_delay );
	return $start_date;
}
add_filter( 'pmpro_profile_start_date', 'pmprosd_pmpro_profile_start_date', 10, 2 );

/**
 * Save a "pmprosd_trialing_until" user meta after checkout.
 *
 * @since .4
 */
function pmprosd_pmpro_after_checkout( $user_id ) {
	// If the PMPro_Subscription class exists, subs table will track the next payment date and we don't need to do anything here.
	if ( class_exists( 'PMPro_Subscription' ) ) {
		// We can also clear existing user meta from pre-3.0.
		delete_user_meta( $user_id, 'pmprosd_trialing_until' );
		return;
	}

	$level = pmpro_getMembershipLevelForUser( $user_id );

	if ( ! empty( $level ) ) {
		$subscription_delay = get_option( 'pmpro_subscription_delay_' . $level->id, '' );
		if ( $subscription_delay ) {
			if ( ! is_numeric( $subscription_delay ) ) {
				$trialing_until = strtotime( pmprosd_convert_date( $subscription_delay ), current_time( 'timestamp' ) );
			} else {
				$trialing_until = strtotime( '+' . $subscription_delay . ' Days', current_time( 'timestamp' ) );
			}

			update_user_meta( $user_id, 'pmprosd_trialing_until', $trialing_until );
		} else {
			delete_user_meta( $user_id, 'pmprosd_trialing_until' );
		}
	}
}
add_action( 'pmpro_after_checkout', 'pmprosd_pmpro_after_checkout' );

/**
 * Use the pmprosd_trialing_until value to calculate pmpro_next_payment when applicable
 *
 * @since .4
 */
function pmprosd_pmpro_next_payment( $timestamp, $user_id, $order_status ) {
	// This filter likely won't ever be called after the PMPro v3.0 release, but we'll keep it here for now for pre-3.0 sites.
	// If this filter is called post-3.0 for some reason, we'll bail as subs table should already have the correct next payment date.
	if ( class_exists( 'PMPro_Subscription' ) ) {
		return $timestamp;
	}

	// find the last order for this user
	if ( ! empty( $user_id ) && ! empty( $timestamp ) ) {
		$trialing_until = get_user_meta( $user_id, 'pmprosd_trialing_until', true );
		if ( ! empty( $trialing_until ) && $trialing_until > current_time( 'timestamp' ) ) {
			$timestamp = $trialing_until;
		}
	}

	return $timestamp;
}
add_filter( 'pmpro_next_payment', 'pmprosd_pmpro_next_payment', 10, 3 );

/*
	Calculate how many days until a certain date (e.g. in YYYY-MM-DD format)
	
	NOTE: Doesn't seem like we are using this anymore, but leaving it in
	as is in case custom code was using it.
*/
function pmprosd_daysUntilDate( $date ) {
	$new_date = pmprosd_convert_date( $date );
	$now_timestamp = current_time( 'timestamp' );
	$new_date_timestamp = strtotime( $new_date, $now_timestamp );
	$diff = $new_date_timestamp - $now_timestamp;
	if ( $diff < 0 ) {
		return 0;
	} else {
		return ceil( $diff / 60 / 60 / 24 );
	}
}

/**
 * Convert dates to usable dates.
 *
 * @since 4.4
 */
function pmprosd_convert_date( $date ) {
	// handle lower-cased y/m values.
    $set_date = strtoupper($date);

    // Change "M-" and "Y-" to "M1-" and "Y1-".
    $set_date = preg_replace('/Y-/', 'Y1-', $set_date);
    $set_date = preg_replace('/M-/', 'M1-', $set_date);

    // Get number of months and years to add.
    $m_pos = stripos( $set_date, 'M' );
    $y_pos = stripos( $set_date, 'Y' );
    if($m_pos !== false) {
		$add_months = intval( pmpro_getMatches( '/M([0-9]*)/', $set_date, true ) );		
    }
    if($y_pos !== false) {
		$add_years = intval( pmpro_getMatches( '/Y([0-9]*)/', $set_date, true ) );
    }

	// Allow new dates to be set from a custom date.
	$current_date = current_time( 'timestamp' );
	$current_date = apply_filters( 'pmprosd_current_date', $current_date );

    // Get current date parts.
    $current_y = intval(date('Y', $current_date));
    $current_m = intval(date('m', $current_date));
    $current_d = intval(date('d', $current_date));

    // Get set date parts.
    $date_parts = explode( '-', $set_date);
    $set_y = intval($date_parts[0]);
    $set_m = intval($date_parts[1]);
    $set_d = intval($date_parts[2]);

    // Get temporary date parts.
    $temp_y = $set_y > 0 ? $set_y : $current_y;
    $temp_m = $set_m > 0 ? $set_m : $current_m;
    $temp_d = $set_d;

    // Add months.
	if(!empty($add_months)) {
        for($i = 0; $i < $add_months; $i++) {
            // If "M1", only add months if current date of month has already passed.
            if(0 == $i) {
                if($temp_d < $current_d) {
                    $temp_m++;
                    $add_months--;
                }
            } else {
                $temp_m++;
            }

            // If we hit 13, reset to Jan of next year and subtract one of the years to add.
            if($temp_m == 13) {
                $temp_m = 1;
                $temp_y++;
                $add_years--;
            }
        }
    }

    // Add years.
    if(!empty($add_years)) {
        for($i = 0; $i < $add_years; $i++) {
            // If "Y1", only add years if current date has already passed.
            if(0 == $i) {
                $temp_date = strtotime(date("{$temp_y}-{$temp_m}-{$temp_d}"));
                if($temp_date < $current_date) {
                    $temp_y++;
                    $add_years--;
                }
            } else {
                $temp_y++;
            }
        }
    }

    // Pad dates if necessary.
    $temp_m = str_pad($temp_m, 2, '0', STR_PAD_LEFT);
    $temp_d = str_pad($temp_d, 2, '0', STR_PAD_LEFT);

    // Put it all together.
    $set_date = date("{$temp_y}-{$temp_m}-{$temp_d}");

	// Make sure we use the right day of the month for dates > 28
	// From: http://stackoverflow.com/a/654378/1154321
    $dotm = pmpro_getMatches('/\-([0-3][0-9]$)/', $set_date, true);
    if ( $temp_m == '02' && intval($dotm) > 28 || intval($dotm) > 30 ) {
        $set_date = date('Y-m-t', strtotime(substr($set_date, 0, 8) . "01"));
    }

    // Add time
	if ( strpos( $set_date, ':') !== false ) {
	    $set_date = $date;
    } else {
	    $set_date .= 'T0:0:0';
    }
    
	return $set_date;
}

/*
	Add discount code and code id to the level object so we can use them later
*/
function pmprosd_pmpro_discount_code_level( $level, $code_id ) {
	// Favor the code_id that's already there (e.g. when using Group Discount Codes)
	if ( empty( $level->code_id ) ) {
		$level->code_id = $code_id;
	}
	return $level;
}
add_filter( 'pmpro_discount_code_level', 'pmprosd_pmpro_discount_code_level', 10, 2 );

/*
	Change the Level Cost Text
*/
function pmprosd_level_cost_text( $cost, $level ) {
	if ( ! empty( $level->code_id ) ) {
		$all_delays = pmpro_getDCSDs( $level->code_id );

		if ( ! empty( $all_delays ) && ! empty( $all_delays[ $level->id ] ) ) {
			$subscription_delay = $all_delays[ $level->id ];
		}
	} else {
		$subscription_delay = get_option( 'pmpro_subscription_delay_' . $level->id, '' );
	}

	$labels   = [ 'Year', 'Years', 'Month', 'Months', 'Week', 'Weeks', 'Day', 'Days', 'payments' ];
	$patterns = [
		'%s.'          => '%s',
		'%s</strong>.' => '%s</strong>'
	];

	$find = $replace = array();
	foreach ( $labels as $label ) {
		foreach ( $patterns as $pattern_find => $pattern_replace ) {
			$find[]    = sprintf( $pattern_find, __( $label, 'paid-memberships-pro' ) );
			$replace[] = sprintf( $pattern_replace, __( $label, 'paid-memberships-pro' ) );
		}
	}

	if ( function_exists( 'pmpro_getCustomLevelCostText' ) ) {
		$custom_text = pmpro_getCustomLevelCostText( $level->id );
	} else {
		$custom_text = null;
	}

	if ( empty( $custom_text ) ) {
		if ( ! empty( $subscription_delay ) && is_numeric( $subscription_delay ) ) {
			$cost  = str_replace( $find, $replace, $cost );
			$cost .= sprintf( __( 'after your <strong>%d</strong> day trial.', 'pmpro-subscription-delays' ), $subscription_delay );

		} elseif ( ! empty( $subscription_delay ) ) {
			$subscription_delay = pmprosd_convert_date( $subscription_delay );
			$cost               = str_replace( $find, $replace, $cost );
			$cost              .= ' ' . __('starting', 'pmpro-subscription-delays') . ' ' . date_i18n( get_option( 'date_format' ), strtotime( $subscription_delay, current_time( 'timestamp' ) ) ) . '.';
            
		}
	}

	return $cost;
}
add_filter( 'pmpro_level_cost_text', 'pmprosd_level_cost_text', 10, 2 );

/*
	Let's call these things "discount code subscription delays" or DCSDs.

	This function will save an array of delays (level_id => days) into an option storing delays for all code.
*/
function pmpro_saveDCSDs( $code_id, $delays ) {
	$all_delays             = get_option( 'pmpro_discount_code_subscription_delays', array() );
	$all_delays[ $code_id ] = $delays;
	update_option( 'pmpro_discount_code_subscription_delays', $all_delays );
}

/*
	This function will return the saved delays for a certain code.
*/
function pmpro_getDCSDs( $code_id ) {
	$all_delays = get_option( 'pmpro_discount_code_subscription_delays', array() );
	if ( ! empty( $all_delays ) && ! empty( $all_delays[ $code_id ] ) ) {
		return $all_delays[ $code_id ];
	} else {
		return false;
	}
}

/**
 * Get the delay for a specific level/code combo
 */
function pmprosd_getDelay( $level_id, $code_id = null ) {
	if ( ! empty( $code_id ) ) {
		$delays = pmpro_getDCSDs( $code_id );
		if ( ! empty( $delays[ $level_id ] ) ) {
			return $delays[ $level_id ];
		} else {
			return '';
		}
	} else {
		$subscription_delay = get_option( 'pmpro_subscription_delay_' . $level_id, '' );
		return $subscription_delay;
	}
}

/**
 * With Authorize.net, we need to set the trialoccurences to 0
 */
function pmprosd_pmpro_subscribe_order( $order, $gateway ) {
	if ( $order->gateway == 'authorizenet' ) {
		if ( ! empty( $order->discount_code ) ) {
			global $wpdb;
			$code_id            = $wpdb->get_var( "SELECT id FROM $wpdb->pmpro_discount_codes WHERE code = '" . esc_sql( $order->discount_code ) . "' LIMIT 1" );
			$subscription_delay = pmprosd_getDelay( $order->membership_id, $code_id );
		} else {
			$subscription_delay = pmprosd_getDelay( $order->membership_id );
		}

		if ( ! empty( $subscription_delay ) && $order->TrialBillingCycles == 1 ) {
			$order->TrialBillingCycles = 0;
		}
	}

	return $order;
}
add_filter( 'pmpro_subscribe_order', 'pmprosd_pmpro_subscribe_order', 10, 2 );

/*
Function to add links to the plugin row meta
*/
function pmprosd_plugin_row_meta( $links, $file ) {
	if ( strpos( $file, 'pmpro-subscription-delays.php' ) !== false ) {
		$new_links = array(
			'<a href="' . esc_url( 'https://www.paidmembershipspro.com/add-ons/subscription-delays/' ) . '" title="' . esc_attr( __( 'View Documentation', 'pmpro-subscription-delays' ) ) . '">' . __( 'Docs', 'pmpro-subscription-delays' ) . '</a>',
			'<a href="' . esc_url( 'https://paidmembershipspro.com/support/' ) . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro-subscription-delays' ) ) . '">' . __( 'Support', 'pmpro-subscription-delays' ) . '</a>',
		);
		$links     = array_merge( $links, $new_links );
	}
	return $links;
}
add_filter( 'plugin_row_meta', 'pmprosd_plugin_row_meta', 10, 2 );
