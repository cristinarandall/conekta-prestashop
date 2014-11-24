
if ( $.mobile ) {
	//we use both loading methods to invoke setup if mobile is detected.  Sometimes the .mobile is true even if mobile is not currently being used
   //jq mobile loaded
	$(document).on('pageinit', function() {
		conektaSetup();
	});
	$(document).ready(function() {
		conektaSetup();
	});

} else {
  // not jqm
	$(document).ready(function() {
		conektaSetup();
	});
} 



function conektaSetup()
{
	//no need to execute the setup if it has already been executed
	//this covers the following scenarios
	// 1) in case jquery mobile executes the page load (pageinit/.ready) multiple times
	// 2) if terms are not accepted yet, then setup wold have executed but since the payment form was hidden, it would need to be executed a second time.
	if ($('#conekta_setup_complete').val()==1)
		return false;

	//we should not execute unless the conekta form exists.  if the TOC checkbox is not yet checked, then the form will not yet exist
	if (!$('#conekta-payment-form').length)
		return false;

	/* Set Conekta publishable key */
	Conekta.setPublishableKey(conekta_public_key);

	//since we are using smarty html_select_date custom function, which forces a name attribute, we are removing it here.  
	//This is to prevent the form data from being submitted to the server.  
	//(All form data is tokenized and therefore not submitted to the server)
	$('#conekta-card-expiry-month').removeAttr('name');
	$('#conekta-card-expiry-year').removeAttr('name');

//	$('#conekta-payment-form-cc').submit(function(event) {
//		$('.conekta-payment-errors').hide();
//		$('#conekta-payment-form-cc').hide();
//		$('#conekta-ajax-loader').show();
//		$('.conekta-submit-button-cc').attr('disabled', 'disabled'); /* Disable the submit button to prevent repeated clicks */
//	});

	$('#conekta-payment-form').submit(function(event) {

        var $form = $('#conekta-payment-form');
    	$form.find("button").prop("disabled", true);

      	if( $form.find('[name=conektaToken]').length)
        	return true;

     	Conekta.token.create($form, conektaSuccessResponseHandler, conektaErrorResponseHandler);
		return false; /* Prevent the form from submitting with the default action */
	});

}


var conektaSuccessResponseHandler = function(response) {
    var $form = $('#conekta-payment-form');
    $form.append($('<input type="hidden" name="conektaToken" />').val(response.id));
    $form.append($('<input type="hidden" name="conektaLastDigits" />').val(parseInt($('.conekta-card-number').val().slice(-4))));
    $form.append($('<input type="hidden" name="conektaBin" />').val(parseInt($('.conekta-card-number').val().slice(0, 6))));
    $form.get(0).submit();
    
};


var conektaErrorResponseHandler = function(response) {
    var $form = $('#conekta-payment-form');
    $form.unblock();
    
    if ($('.conekta-payment-errors').length)
        $('.conekta-payment-errors').fadeIn(1000);
    else
    {
        $('#conekta-payment-form').prepend('<div class="conekta-payment-errors">' + response.message +'</div>');
        $('.conekta-payment-errors').fadeIn(1000);
    }
    
    $('#conekta-submit-button').removeAttr('disabled');
    $('#conekta-payment-form').show();
    $('#conekta-ajax-loader').hide();
    
};
