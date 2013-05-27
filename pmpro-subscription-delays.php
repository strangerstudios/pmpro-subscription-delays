<?php
/*
Plugin Name: PMPro Subscription Delays
Plugin URI: http://www.paidmembershipspro.com/wp/pmpro-subscription-delays/
Description: Add a field to levels and discount codes to delay the start of a subscription by X days. (Add variable-length free trials to your levels.)
Version: .1
Author: Stranger Studios
Author URI: http://www.strangerstudios.com
*/

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
				<td><input name="subscription_delay[]" type="text" size="20" value="<?php echo intval($delay);?>" /> <small># of days to delay the start of the subscription. If set, this will override any trial/etc defined above.</small></td>
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

//update subscription start date based on the discount code used
function pmprosd_pmpro_profile_start_date($start_date, $order)
{
	if(!empty($order->discount_code))
	{
		global $wpdb;
		
		//get code id
		$code_id = $wpdb->get_var("SELECT id FROM $wpdb->pmpro_discount_codes WHERE code = '" . $wpdb->escape($order->discount_code) . "' LIMIT 1");				
		if(!empty($code_id))
		{
			//we have a code
			$delays = pmpro_getDCSDs($code_id);
			if(!empty($delays[$order->membership_id]))
			{
				//we have a delay for this level, set the start date to X days out
				$start_date = date("Y-m-d", strtotime("+ " . intval($delays[$order->membership_id]) . " Days")) . "T0:0:0";
			}
		}
	}
	
	return $start_date;
}
add_filter("pmpro_profile_start_date", "pmprosd_pmpro_profile_start_date", 10, 2);

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
	return $all_delays[$code_id];
}
