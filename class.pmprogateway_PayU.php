<?php
//load classes init method
add_action('init', array('PMProGateway_payu', 'init'));

class PMProGateway_payu
{
function PMProGateway_payu($gateway = NULL)
{
$this->gateway = $gateway;
return $this->gateway;
}
/**
* Run on WP init
*
*/
static function init()
{
//make sure PayU MOney is a gateway option
add_filter('pmpro_gateways', array('PMProGateway_payu', 'pmpro_gateways'));
//add fields to payment settings
add_filter('pmpro_payment_options', array('PMProGateway_payu', 'pmpro_payment_options'));
add_filter('pmpro_payment_option_fields', array('PMProGateway_payu', 'pmpro_payment_option_fields'), 10, 2);
//code to add at checkout
$gateway = pmpro_getGateway();
add_filter('pmpro_include_billing_address_fields', '__return_false');
add_filter('pmpro_include_payment_information_fields', '__return_false');
add_filter('pmpro_required_billing_fields', '__return_empty_array');
add_filter('pmpro_checkout_default_submit_button', array('PMProGateway_payu', 'pmpro_checkout_default_submit_button'));
add_filter('pmpro_checkout_before_change_membership_level', array('PMProGateway_payu', 'pmpro_checkout_before_change_membership_level'), 10, 2);
}
/**
* Make sure this gateway is in the gateways list
*
*/
static function pmpro_gateways($gateways)
{
if(empty($gateways['payu']))
$gateways['payu'] = __('PayU', 'pmpro');
return $gateways;
}
/**
* Get a list of payment options that the this gateway needs/supports.
*
* @since 1.8
*/
static function getGatewayOptions()
{
$options = array(
'payu_merchant_key',
'payu_merchant_salt'
);
return $options;
}
/**
* Set payment options for payment settings page.
*
*/
static function pmpro_payment_options($options)
{
//get stripe options
$payu_options = self::getGatewayOptions();
//merge with others.
$options = array_merge($payu_options, $options);
return $options;
}
/**
* Display fields for this gateway's options.
*/
static function pmpro_payment_option_fields($values, $gateway)
{
?>

<tr class="gateway gateway_payu" <?php if( $gateway != "payu" ) { ?>style="display: none;"<?php } ?>>
<th scope="row" valign="top">
<label for="payu_merchant_key"><?php _e('PayU Merchant Key', 'pmpro');?>:</label>
</th>
<td>
<input id="payu_merchant_key" name="payu_merchant_key" value="<?php echo esc_attr($values['payu_merchant_key']); ?>" />
</td>
</tr>
<tr class="gateway gateway_payu" <?php if( $gateway != "payu" ) { ?>style="display: none;"<?php } ?>>
<th scope="row" valign="top">
<label for="payu_merchant_salt"><?php _e('PayU SALT Key', 'pmpro');?>:</label>
</th>
<td>
<input id="payu_merchant_salt" name="payu_merchant_salt" value="<?php echo esc_attr($values['payu_merchant_salt']); ?>" />
</td>
</tr>
<?php
}
/**
* Remove required billing fields
*
* @since 1.8
*/
static function pmpro_required_billing_fields($fields)
{
return array();
}
/**
* Swap in our submit buttons.
*/
static function pmpro_checkout_default_submit_button($show)
{
global $gateway, $pmpro_requirebilling;
//show our submit buttons
?>

<?php if($gateway == "payu") { ?>
<span id="pmpro_payu_checkout" <?php if($gateway != "payu" || !$pmpro_requirebilling) { ?>style="display: none;"<?php } ?>>
<input type="hidden" name="submit-checkout" value="1" />
<input type="image" value="<?php _e('Check Out with PayU', 'pmpro');?> »" src="/wp-includes/images/logo_payumoney.png" />
</span>
<?php } ?>

<span id="pmpro_submit_span" <?php if($gateway == "payu" && $pmpro_requirebilling) { ?>style="display: none;"<?php } ?>>
<input type="hidden" name="submit-checkout" value="1" />
<input type="submit" class="pmpro_btn pmpro_btn-submit-checkout" value="<?php if($pmpro_requirebilling) { _e('Submit and Check Out', 'pmpro'); } else { _e('Submit and Confirm', 'pmpro');}?> »" />
</span>

<?php
/*
<span id="pmpro_payu_checkout" <?php if(($gateway != "payu") || !$pmpro_requirebilling) { ?>style="display: none;"<?php } ?>>
<input type="hidden" name="submit-checkout" value="1" /> <label for="submit-checkout">Proceed to Checkout with</label>
<input type="image" value="<?php _e('Checkout with PayU', 'pmpro');?> »" src="/wp-includes/images/logo_payumoney.png" />
</span>*/
//don't show the default
return false;
}
/**
* Instead of change membership levels, send users to PayU to pay.
*
*/
static function pmpro_checkout_before_change_membership_level($user_id, $morder)
{
global $discount_code_id;
//if no order, no need to pay
if(empty($morder))
return;
$morder->user_id = $user_id;
$morder->saveOrder();
//save discount code use
if(!empty($discount_code_id))
$wpdb->query("INSERT INTO $wpdb->pmpro_discount_codes_uses (code_id, user_id, order_id, timestamp) VALUES('" . $discount_code_id . "', '" . $user_id . "', '" . $morder->id . "', now())");
//do_action("pmpro_before_send_to_payu", $user_id, $morder);
$morder->Gateway->sendTopayu($morder);
}

function process(&$order)
{
if(empty($order->code))
$order->code = $order->getRandomCode();
//clean up a couple values
$order->payment_type = "PayU";
$order->CardType = "";
$order->cardtype = "";
//just save, the user will go to PayU to pay
$order->status = "success";
$order->saveOrder();
return true;
}

function sendTopayu(&$order)
{
global $pmpro_currency;
//taxes on initial amount
$initial_payment = $order->InitialPayment;
$initial_payment_tax = $order->getTaxForPrice($initial_payment);
$initial_payment = round((float)$initial_payment + (float)$initial_payment_tax, 2);
//taxes on the amount
$amount = $order->PaymentAmount;
$amount_tax = $order->getTaxForPrice($amount);
$order->subtotal = $amount;
$amount = round((float)$amount + (float)$amount_tax, 2);
//build payu Redirect
$environment = pmpro_getOption("gateway_environment");
if("sandbox" === $environment || "beta-sandbox" === $environment)
{
$merchant_key = 'gtKFFx';
$merchant_salt = 'eCwWELxi';
$payu_url ="https://test.payu.in/_payment";
}
else
{
$merchant_key = pmpro_getOption("payu_merchant_key");
$merchant_salt = pmpro_getOption("payu_merchant_salt");
$payu_url = "https://secure.payu.in/_payment";
}

$productinfo = "Subscription Fees";

//$txnid = $order->code.'_'.date("ymds");
$sep= '|';
// hash-string = key|txnid|amount|productinfo|firstname|email|udf1|udf2|udf3|udf4|udf5||||||<SALT>
$str = $merchant_key . $sep . $order->code . $sep . number_format($initial_payment, 2, '.', '') . $sep . $productinfo . $sep . $order->user_nicename . $sep . $order->Email . "|||||||||||" . $merchant_salt;
$hash = hash('sha512', $str);

$data = array(

'key' => $merchant_key,
'hash' => $hash,
'txnid' => $order->code,
'firstname'	=> $order->user_nicename,
'email' => $order->Email,
'phone' => '44220000',//$order->billing_phone,
'productinfo'	=> $productinfo,
'surl' => urlencode(pmpro_url("confirmation", "?level=" . $order->membership_level->id)),
'furl' => 'http://www.yoursite.com/membership-level/',// $redirect_url,
'pg' => 'CC',
//Below are default from parent plugin
//'return_url' => pmpro_url("confirmation", "?level=" . $order->membership_level->id),
'lastname' => $order->LastName,
'service_provider'	=> 'biz',//'payu_paisa',
'amount' => number_format($initial_payment, 2, '.', ''),
//'item_name' => substr($order->membership_level->name . " at " . get_bloginfo("name"), 0, 127)
);

$pfOutput = "";
foreach( $data as $element => $val )
{
$pfOutput .= $element .'='. urlencode( trim( $val ) ) .'&';
}
$pfOutput = substr( $pfOutput, 0, -1 );
$signature = md5( $pfOutput );
//$payu_url .= '?'.$pfOutput.'&signature='.$signature;
//wp_redirect($payu_url);

//Post Method Request Sent

$payuindia_args_array = array();

foreach($data as $key => $value){
$payuindia_args_array[] = "<input type='hidden' name='$key' value='$value'/>";
}

echo '<title>Redirecting...</title><link id="main-style-css" media="all" type="text/css" href="http://www.yoursite.com/wp-content/themes/atlas/style.css" rel="stylesheet">

<div id="payment-frame" align="center"><p><h2>We will be redirecting you to Payment Gateway to make payment.</h2></p>

<p><form action="'.$payu_url.'" method="post" id="payuindia_payment_form" name="payuindia_payment_form">
' . implode('', $payuindia_args_array) . '
<input type="submit" type="hidden" id="submit_payuindia_payment_form" class="toggle-button" value="'.__('Proceed for Payment', 'pmpro').'" />

<p>'.__('Cancel Payment', 'pmpro').'</p>

<input type="image" value="Checkout with PayU" src="/wp-includes/images/logo_payumoney.png" />

<input type="image" value="Working.." src="/wp-includes/images/progress_bar.gif" />
<script type="text/javascript">
var auto_refresh = setInterval(
function()
{
submitform();
}, 900);

function submitform()
{
document.payuindia_payment_form.submit();
}
</script>
</form></p></div>';
//echo $str;
//jQuery("#submit_payuindia_payment_form").click();
// wp_redirect($payu_url);*/
exit;
}}