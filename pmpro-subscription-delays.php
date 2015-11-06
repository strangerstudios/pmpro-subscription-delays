<?php
/*
Plugin Name: Paid Memberships Pro - Subscription Delays Addon 
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-subscription-delays/
Description: Add a field to levels and discount codes to delay the start of a subscription by X days. (Add variable-length free trials to your levels.)
Version: .4.1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/

//add subscription delay field to level price settings
function pmprosd_pmpro_membership_level_after_other_settings()
{
	$level_id = intval($_REQUEST['edit']);
	$delay = get_option("pmpro_subscription_delay_" . $level_id, "");
?>
<table>
<tbody class="form-table">
	<tr>
		<td>
			<tr>
				<th scope="row" valign="top"><label for="subscription_delay">Subscription Delay:</label></th>
				<td><input name="subscription_delay" type="text" size="20" value="<?php echo esc_attr($delay);?>" /> <small># of days to delay the start of the subscription. If set, this will override any trial/etc defined above.</small></td>
			</tr>
		</td>
	</tr> 
</tbody>
</table>
<?php
}
add_action("pmpro_membership_level_after_other_settings", "pmprosd_pmpro_membership_level_after_other_settings");

//save subscription delays for the code when the code is saved/added
function pmprosd_pmpro_save_membership_level($level_id)
{
	$subscription_delay = $_REQUEST['subscription_delay'];	//subscription delays for levels checked
	update_option("pmpro_subscription_delay_" . $level_id, $subscription_delay);
}
add_action("pmpro_save_membership_level", "pmprosd_pmpro_save_membership_level");

//add subscription delay field to level price settings
function pmprosd_pmpro_discount_code_after_level_settings($code_id, $level)
{
	$delays = pmpro_getDCSDs($code_id);
	if(!empty($delays[$level->id]))
		$delay = $delays[$level->id];
	else
		$delay = "";
?>
<table>
<tbody class="form-table">
	<tr>
		<td>
			<tr>
				<th scope="row" valign="top"><label for="subscription_delay">Subscription Delay:</label></th>
				<td><input name="subscription_delay[]" type="text" size="20" value="<?php echo esc_attr($delay);?>" /> <small># of days to delay the start of the subscription. If set, this will override any trial/etc defined above.</small></td>
			</tr>
		</td>
	</tr> 
</tbody>
</table>
<?php
}
add_action("pmpro_discount_code_after_level_settings", "pmprosd_pmpro_discount_code_after_level_settings", 10, 2);

//save subscription delays for the code when the code is saved/added
function pmprosd_pmpro_save_discount_code_level($code_id, $level_id)
{
	$all_levels_a = $_REQUEST['all_levels'];							//array of level ids checked for this code
	$subscription_delay_a = $_REQUEST['subscription_delay'];	//subscription delays for levels checked
	
	if(!empty($all_levels_a))
	{	
		$key = array_search($level_id, $all_levels_a);				//which level is it in the list?		
		$delays = pmpro_getDCSDs($code_id);						//get delays for this code		
		$delays[$level_id] = $subscription_delay_a[$key];			//add delay for this level		
		pmpro_saveDCSDs($code_id, $delays);						//save delays		
	}	
}
add_action("pmpro_save_discount_code_level", "pmprosd_pmpro_save_discount_code_level", 10, 2);

//update subscription start date based on the discount code used or levels subscription start date
function pmprosd_pmpro_profile_start_date($start_date, $order)
{	
	$subscription_delay = null;
	
	//if a discount code is used, we default to the setting there
	if(!empty($order->discount_code))
	{
		global $wpdb;
		
		//get code id
		$code_id = $wpdb->get_var("SELECT id FROM $wpdb->pmpro_discount_codes WHERE code = '" . esc_sql($order->discount_code) . "' LIMIT 1");				
		if(!empty($code_id))
		{
			//we have a code
			$delays = pmpro_getDCSDs($code_id);
			if(!empty($delays[$order->membership_id]))
			{
				if(!is_numeric($delays[$order->membership_id]))		
					$subscription_delay = pmprosd_daysUntilDate($delays[$order->membership_id]);
				else
					$subscription_delay = $delays[$order->membership_id];
				
				//we have a delay for this level, set the start date to X days out
				$start_date = date("Y-m-d", strtotime("+ " . intval($subscription_delay) . " Days", current_time('timestamp'))) . "T0:0:0";
			}
		}
	}
	else
	{
		//check the level for a subscription delay
		$subscription_delay = get_option("pmpro_subscription_delay_" . $order->membership_id, "");
		
		if(!is_numeric($subscription_delay))		
			$subscription_delay = pmprosd_daysUntilDate($subscription_delay);
		
		if(!empty($subscription_delay))
		{
			$start_date = date("Y-m-d", strtotime("+ " . intval($subscription_delay) . " Days", current_time('timestamp'))) . "T0:0:0";
		}
	}
		
	$start_date = apply_filters( 'pmprosd_modify_start_date', $start_date, $order, $subscription_delay );
	
	return $start_date;
}
add_filter("pmpro_profile_start_date", "pmprosd_pmpro_profile_start_date", 10, 2);

/**
 * Save a "pmprosd_trialing_until" user meta after checkout.
 *
 * @since .4
*/
function pmprosd_pmpro_after_checkout($user_id)
{
	$level = pmpro_getMembershipLevelForUser($user_id);;
	if(!empty($level))
	{
		$subscription_delay = get_option("pmpro_subscription_delay_" . $level->id, "");
		if($subscription_delay)
		{
			$trialing_until = strtotime("+" . $subscription_delay . " Days", current_time('timestamp'));
			update_user_meta($user_id, "pmprosd_trialing_until", $trialing_until);
		}
		else
			delete_user_meta($user_id, "pmprosd_trialing_until");
	}
}
add_action('pmpro_after_checkout', 'pmprosd_pmpro_after_checkout');

/**
 * Use the pmprosd_trialing_until value to calculate pmpro_next_payment when applicable
 *
 * @since .4
*/
function pmprosd_pmpro_next_payment($timestamp, $user_id, $order_status)
{
	//find the last order for this user
	if(!empty($user_id) && !empty($timestamp))
	{
		$trialing_until = get_user_meta($user_id, "pmprosd_trialing_until", true);
		if(!empty($trialing_until) && $trialing_until > current_time('timestamp'))
			$timestamp = $trialing_until;
	}
				
	return $timestamp;
}
add_filter('pmpro_next_payment', 'pmprosd_pmpro_next_payment', 10, 3);

/*
	Calculate how many days until a certain date (e.g. in YYYY-MM-DD format)
	
	Some logic taken from: http://stackoverflow.com/a/654378/1154321
*/
function pmprosd_daysUntilDate($date)
{
	//replace vars
	$Y = date("Y");
	$Y2 = intval($Y) + 1;
	$M = date("m");
	if($M == 12)
		$M2 = "01";
	else
		$M2 = str_pad(intval($M) + 1, 2, "0", STR_PAD_LEFT);

	$searches = array("Y-", "Y2-", "M-", "M2-");
	$replacements = array($Y . "-", $Y2 . "-", $M . "-", $M2 . "-");

	$date = str_replace($searches, $replacements, $date);

	$datetime = strtotime($date, current_time('timestamp'));

	$today = current_time('timestamp');;
	$diff = $datetime - $today;
	if($diff < 0)
		return 0;
	else
		return floor($diff/60/60/24);
}

/*
	Add discount code and code id to the level object so we can use them later
*/
function pmprosd_pmpro_discount_code_level($level, $code_id)
{
	$level->code_id = $code_id;
	return $level;
}
add_filter("pmpro_discount_code_level", "pmprosd_pmpro_discount_code_level", 10, 2);

/*
	Change the Level Cost Text
*/
function pmprosd_level_cost_text($cost, $level)
{
	if(!empty($level->code_id))
	{
		$all_delays = pmpro_getDCSDs($level->code_id);
		
		if(!empty($all_delays) && !empty($all_delays[$level->id]))
			$subscription_delay = $all_delays[$level->id];
	}
	else
	{
		$subscription_delay = get_option("pmpro_subscription_delay_" . $level->id, "");
	}
	
	$find = array("Year.", "Month.", "Week.", "Year</strong>.", "Month</strong>.", "Week</strong>.", "Years.", "Months.", "Weeks.", "Years</strong>.", "Months</strong>.", "Weeks</strong>.", "payments.", "payments</strong>.");
	$replace = array("Year", "Month", "Week", "Year</strong>", "Month</strong>", "Week</strong>", "Years", "Months", "Weeks", "Years</strong>", "Months</strong>", "Weeks</strong>", "payments", "payments</strong>");

	if(!empty($subscription_delay) && is_numeric($subscription_delay))
	{
		$cost = str_replace($find, $replace, $cost);
		$cost .= " after your <strong>" . $subscription_delay . " day trial</strong>.";
	}
	elseif(!empty($subscription_delay))
	{
		$cost = str_replace($find, $replace, $cost);
		$cost .= " starting " . date_i18n(get_option("date_format"), strtotime($subscription_delay, current_time("timestamp"))) . ".";
	}
 
	return $cost;
}
add_filter("pmpro_level_cost_text", "pmprosd_level_cost_text", 10, 2);

/*
	Let's call these things "discount code subscription delays" or DCSDs.
	
	This function will save an array of delays (level_id => days) into an option storing delays for all code.
*/
function pmpro_saveDCSDs($code_id, $delays)
{	
	$all_delays = get_option("pmpro_discount_code_subscription_delays", array());		
	$all_delays[$code_id] = $delays;
	update_option("pmpro_discount_code_subscription_delays", $all_delays);
}

/*
	This function will return the saved delays for a certain code.
*/
function pmpro_getDCSDs($code_id)
{
	$all_delays = get_option("pmpro_discount_code_subscription_delays", array());		
	if(!empty($all_delays) && !empty($all_delays[$code_id]))
		return $all_delays[$code_id];
	else
		return false;
}

/*
Function to add links to the plugin row meta
*/
function pmprosd_plugin_row_meta($links, $file) {
	if(strpos($file, 'pmpro-subscription-delays.php') !== false)
	{
		$new_links = array(
			'<a href="' . esc_url('http://www.paidmembershipspro.com/add-ons/plugins-on-github/subscription-delays/')  . '" title="' . esc_attr( __( 'View Documentation', 'pmpro' ) ) . '">' . __( 'Docs', 'pmpro' ) . '</a>',
			'<a href="' . esc_url('http://paidmembershipspro.com/support/') . '" title="' . esc_attr( __( 'Visit Customer Support Forum', 'pmpro' ) ) . '">' . __( 'Support', 'pmpro' ) . '</a>',
		);
		$links = array_merge($links, $new_links);
	}
	return $links;
}
add_filter('plugin_row_meta', 'pmprosd_plugin_row_meta', 10, 2);
