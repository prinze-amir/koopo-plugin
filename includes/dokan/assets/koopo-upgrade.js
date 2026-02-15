/* koopo/includes/dokan/assets/koopo-upgrade.js */
(function($){
    if ( typeof KoopoUpgradeData === 'undefined' ) return;

    let upgradeButtonInjected = false;

    // Handle return from 3D Secure redirect.
    // Stripe appends payment_intent and payment_intent_client_secret to the URL.
    (function handle3DSReturn() {
        const urlParams = new URLSearchParams(window.location.search);
        const piId = urlParams.get('payment_intent');
        const piSecret = urlParams.get('payment_intent_client_secret');
        if ( ! piId || ! piSecret ) return;

        // Clean the URL so a page refresh doesn't re-trigger
        const cleanUrl = window.location.href.split('?')[0] + (window.location.hash || '');
        window.history.replaceState({}, document.title, cleanUrl);

        // Retrieve the order_id we stashed before the redirect
        var orderId = null;
        try { orderId = parseInt(sessionStorage.getItem('koopo_upgrade_order_id')); } catch(e){}
        if ( ! orderId ) {
            console.error('Koopo Upgrade: No order_id found after 3DS redirect');
            return;
        }
        sessionStorage.removeItem('koopo_upgrade_order_id');

        // Verify the payment status with Stripe, then finalize
        var pubKey = KoopoUpgradeData.stripePublishableKey;
        if ( ! pubKey ) return;

        var s3d = Stripe(pubKey);
        s3d.retrievePaymentIntent(piSecret).then(function(result) {
            if ( result.error ) {
                alert('Payment verification failed: ' + result.error.message);
                return;
            }
            var pi = result.paymentIntent;
            if ( pi.status === 'succeeded' || pi.status === 'processing' ) {
                // Finalize the upgrade
                fetch( KoopoUpgradeData.restUrl + '/finalize', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KoopoUpgradeData.nonce },
                    body: JSON.stringify({
                        vendor_id: KoopoUpgradeData.currentUserId,
                        order_id: orderId,
                        payment_intent_id: piId
                    })
                }).then(function(r){ return r.json(); }).then(function(resp) {
                    if ( resp.success ) {
                        alert('Subscription upgrade completed successfully!');
                        location.reload();
                    } else {
                        alert('Upgrade finalization error: ' + (resp.message || 'unknown'));
                    }
                });
            } else {
                alert('Payment was not completed. Status: ' + pi.status);
            }
        });
    })();

    function injectUpgradeButton() {
        if ( upgradeButtonInjected ) return;

        // Look for the tab panel that contains "Subscription Packs" and "Subscription Orders"
        const $tablist = $('div[role="tablist"].components-tab-panel__tabs');
        
        if ( $tablist.length > 0 && $('#koopo-upgrade-tab-btn').length === 0 ) {
            // Create the upgrade button matching the tab button styles
            const upgradeTabBtn = `
            <button 
                type="button" 
                id="koopo-upgrade-tab-btn"
                role="tab" 
                aria-selected="false" 
                aria-controls="tab-panel-upgrade-view"
                class="components-button components-tab-panel__tabs-item border-0 border-b border-solid mr-5 -mb-px space-x-8 whitespace-nowrap border-b-2 px-1 py-4 text-sm font-medium cursor-pointer hover:bg-transparent focus:outline-none text-gray-500 border-gray-200 hover:text-gray-600 hover:border-gray-300 is-next-40px-default-size"
            >
                Upgrade
            </button>`;
            
            // Append to the tablist
            $tablist.append(upgradeTabBtn);
            upgradeButtonInjected = true;
        }
    }

    // Setup MutationObserver to detect when tablist appears
    function setupMutationObserver() {
        const targetNode = document.body;
        const config = { childList: true, subtree: true };

        const callback = function(mutationList, observer) {
            injectUpgradeButton();
        };

        const observer = new MutationObserver(callback);
        observer.observe(targetNode, config);
    }

    const modalHtml = `
<div id="koopo-upgrade-modal" class="koopo-modal" style="display:none;">
  <div class="koopo-modal-inner">
    <button class="koopo-modal-close" id="koopo-upgrade-close" type="button">&times;</button>
    <div id="koopo-step-1" class="koopo-step">
      <h3>Select an Upgrade Plan</h3>
      <p style="color: #666; font-size: 13px; margin-bottom: 15px;">Only upgrade plans (higher price) are shown below.</p>
      <div id="koopo-pack-list">Loading plans...</div>
      <div class="koopo-step-actions">
        <button id="koopo-step1-next" class="button button-primary" disabled type="button">Next Step</button>
      </div>
    </div>
    <div id="koopo-step-2" class="koopo-step" style="display:none;">
      <h3>Checkout Breakdown</h3>
      <div id="koopo-breakdown"></div>
      <div id="koopo-stripe-form">
        <div id="koopo-payment-element"></div>
        <div id="koopo-card-errors" role="alert" style="color: #dc3545; margin-top: 10px;"></div>
      </div>
      <div class="koopo-step-actions">
        <button id="koopo-step2-back" class="button" type="button">Back</button>
        <button id="koopo-step2-pay" class="button button-primary" type="button">Pay Now</button>
      </div>
    </div>
    <div id="koopo-step-3" class="koopo-step" style="display:none;">
      <h3>Processing Payment...</h3>
      <div style="text-align: center; padding: 20px;">
        <div id="koopo-spinner" style="display: none; border: 4px solid #f3f3f3; border-top: 4px solid #3498db; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto 10px;"></div>
        <style>
          @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
          }
        </style>
      </div>
      <div id="koopo-result"></div>
    </div>
  </div>
</div>`;
    $('body').append(modalHtml);

    // Initialize MutationObserver and interval check
    setupMutationObserver();
    
    function openModal(){ $('#koopo-upgrade-modal').show(); }
    function closeModal(){ 
        $('#koopo-upgrade-modal').hide(); 
        $('#koopo-step-2').hide(); 
        $('#koopo-step-3').hide();
        $('#koopo-step-1').show(); 
        $('#koopo-step1-next').prop('disabled', true); 
        $('#koopo-pack-list').empty(); 
        selectedPackId = null;
        clientSecret = null;
        window.koopo_order_id = null;
        window.koopo_payment_intent_id = null;
        window.koopo_no_payment_required = null;
        if ( paymentElement ) { try { paymentElement.unmount(); } catch(e){} paymentElement = null; }
        if ( elements ) { elements = null; }
        if ( stripe ) { stripe = null; }
        $('#koopo-payment-element').empty();
        $('#koopo-card-errors').empty();
        $('#koopo-result').empty();
        $('#koopo-spinner').hide();
    }

    // Open modal on button click
    $(document).on('click', '#koopo-upgrade-tab-btn', function(e){
        e.preventDefault();
        e.stopPropagation();
        openModal();
        loadPacks();
    });

    // Close modal
    $(document).on('click', '#koopo-upgrade-close', function(){ closeModal(); });

    // Back button in step 2
    $(document).on('click', '#koopo-step2-back', function(){ 
        $('#koopo-step-2').hide(); 
        $('#koopo-step-1').show(); 
    });

    let packs = [];
    let selectedPackId = null;
    let currentSubscription = null;
    let stripe = null;
    let elements = null;
    let paymentElement = null;
    let clientSecret = null;

    function loadPacks(){
        $('#koopo-pack-list').text('Loading plans...');
        fetch(KoopoUpgradeData.restUrl + '/packs', {
            headers: { 'X-WP-Nonce': KoopoUpgradeData.nonce }
        }).then(r => r.json()).then(packs_data => {
            packs = packs_data;
            return fetch(KoopoUpgradeData.restUrl + '/subscription', { headers: { 'X-WP-Nonce': KoopoUpgradeData.nonce } });
        }).then(r => r.json()).then(sub => {
            currentSubscription = sub;
            renderPackList();
        }).catch(err => {
            $('#koopo-pack-list').text('Error loading plans.');
            console.error(err);
        });
    }

    function renderPackList(){
        if ( ! Array.isArray(packs) ) packs = [];
        const $wrap = $('#koopo-pack-list');
        $wrap.empty();

        const currentPackId = parseInt( currentSubscription.product_package_id || 0 );
        const currentPrice = parseFloat( currentSubscription.price || 0 );

        packs.forEach(pack => {
            const price = parseFloat(pack.price || 0);
            const isUpgrade = price > currentPrice;
            const html = `
              <div class="koopo-pack" data-pack-id="${pack.id}" data-price="${price}">
                <div class="koopo-pack-title">${pack.title}</div>
                <div class="koopo-pack-price">${pack.price_html || '$' + price}</div>
                <div class="koopo-pack-actions">
                  ${ isUpgrade ? '<button class="button koopo-select-pack">Select</button>' : '<span class="koopo-not-upgrade">Not an upgrade</span>' }
                </div>
              </div>`;
            $wrap.append(html);
        });

        $('.koopo-select-pack').on('click', function(){
            $('.koopo-pack').removeClass('koopo-selected');
            const $p = $(this).closest('.koopo-pack');
            $p.addClass('koopo-selected');
            selectedPackId = parseInt( $p.data('pack-id') );
            $('#koopo-step1-next').prop('disabled', false);
        });
    }

    $('#koopo-step1-next').on('click', function(){
        if ( ! selectedPackId ) return;
        $('#koopo-step-1').hide();
        $('#koopo-step-2').show();
        $('#koopo-breakdown').html('Calculating...');
        fetch( KoopoUpgradeData.restUrl + '/calc', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KoopoUpgradeData.nonce },
            body: JSON.stringify({ vendor_id: KoopoUpgradeData.currentUserId, new_pack_id: selectedPackId })
        }).then(r => r.json()).then(resp => {
            if ( ! resp.success ) {
                $('#koopo-breakdown').text('Error: ' + (resp.message || 'calculation failed'));
                return;
            }
            const d = resp.data;
            const html = `
              <p>Current price:<span>$${d.current_price}</span></p>
              <p>New price: <span>$${d.new_price}</span></p>
              <p>Days remaining: <span>${d.days_remaining} out of ${d.days_total}</span></p>
              <p>Credit: <span>$${d.credit}</span></p>
              <p>First payment (now): <strong>$${d.first_payment}</strong></p>`;
            $('#koopo-breakdown').html(html);
            
            // Now call /pay to get clientSecret, then init Stripe
            fetch( KoopoUpgradeData.restUrl + '/pay', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KoopoUpgradeData.nonce },
                body: JSON.stringify({
                    vendor_id: KoopoUpgradeData.currentUserId,
                    new_pack_id: selectedPackId
                })
            }).then(r => r.json()).then(payResp => {
                if ( ! payResp.success ) {
                    $('#koopo-breakdown').append('<p style="color:red;">Error: ' + (payResp.message || 'payment creation failed') + '</p>');
                    return;
                }
                
                clientSecret = payResp.data.client_secret || null;
                window.koopo_order_id = payResp.data.order_id;
                window.koopo_payment_intent_id = payResp.data.payment_intent_id;
                window.koopo_no_payment_required = payResp.data.no_payment_required || false;
                // Only show payment form if payment is required and clientSecret is present
                if ( window.koopo_no_payment_required ) {
                    $('#koopo-step-2').hide();
                    $('#koopo-step-3').show();
                    $('#koopo-result').text('Finalizing upgrade...');
                    finalizeUpgrade();
                    return;
                }
                if ( clientSecret ) {
                    loadStripeAndInit();
                } else {
                    $('#koopo-breakdown').append('<p style="color:red;">Error: Payment could not be initialized. Please try again.</p>');
                }
            }).catch(err => {
                $('#koopo-breakdown').append('<p style="color:red;">Payment creation failed.</p>');
                console.error(err);
            });
        }).catch(err => {
            $('#koopo-breakdown').text('Calculation failed.');
            console.error(err);
        });
    });

    function loadStripeAndInit(){
        if ( window.Stripe === undefined ) {
            const s = document.createElement('script');
            s.src = 'https://js.stripe.com/v3/';
            s.onload = initStripe;
            document.head.appendChild(s);
        } else {
            initStripe();
        }
    }

    function initStripe(){
        const pubKey = KoopoUpgradeData.stripePublishableKey;
        if ( ! pubKey ) {
            $('#koopo-payment-element').html('<p style="color:red;">Error: Stripe publishable key not configured. Please contact support.</p>');
            return;
        }

        // Keep Pay button disabled until the Payment Element is ready
        $('#koopo-step2-pay').prop('disabled', true).text('Loading payment form...');

        stripe = Stripe(pubKey);
        elements = stripe.elements({ clientSecret: clientSecret });
        if ( paymentElement ) paymentElement.unmount();

        paymentElement = elements.create('payment');
        paymentElement.mount('#koopo-payment-element');

        paymentElement.on('ready', function() {
            $('#koopo-step2-pay').prop('disabled', false).text('Pay Now');
        });
    }

    $('#koopo-step2-pay').on('click', function(){
        if ( ! stripe || ! elements ) {
            $('#koopo-card-errors').text('Stripe not initialized. Please refresh and try again.');
            return;
        }

        $('#koopo-step-2').hide();
        $('#koopo-step-3').show();
        $('#koopo-spinner').show();
        $('#koopo-result').text('Confirming payment...');
        
        // Disable the button
        $(this).prop('disabled', true);

        // Stash order_id so it survives a 3D Secure browser redirect
        try { sessionStorage.setItem('koopo_upgrade_order_id', window.koopo_order_id); } catch(e){}

        // Use the modern Stripe API - confirmPayment with Payment Element
        // redirect: "if_required" ensures the promise resolves directly for
        // non-3DS payments instead of redirecting the browser away.
        stripe.confirmPayment({
            elements: elements,
            redirect: "if_required",
            confirmParams: {
                return_url: window.location.href
            }
        }).then(function(result){
            $('#koopo-spinner').hide();

            if ( result.error ) {
                // Show error and allow the user to retry
                $('#koopo-step-3').hide();
                $('#koopo-step-2').show();
                $('#koopo-step2-pay').prop('disabled', false);
                $('#koopo-card-errors').text(result.error.message);
                console.error('Stripe error:', result.error);
            } else if ( result.paymentIntent ) {
                if ( result.paymentIntent.status === 'succeeded' || result.paymentIntent.status === 'processing' ) {
                    $('#koopo-result').text('Payment confirmed. Finalizing upgrade...');
                    finalizeUpgrade();
                } else {
                    $('#koopo-result').text('Payment status: ' + result.paymentIntent.status);
                }
            }
        }).catch(err => {
            $('#koopo-spinner').hide();
            $('#koopo-step-3').hide();
            $('#koopo-step-2').show();
            $('#koopo-step2-pay').prop('disabled', false);
            $('#koopo-card-errors').text('Payment confirmation failed: ' + err.message);
            console.error('Confirmation error:', err);
        });
    });

    function finalizeUpgrade() {
        if ( ! window.koopo_order_id ) {
            $('#koopo-result').text('Missing upgrade data. Please try again.');
            return;
        }
        var payload = {
            vendor_id: KoopoUpgradeData.currentUserId,
            order_id: window.koopo_order_id
        };
        // Only send payment_intent_id if payment was required
        if ( window.koopo_payment_intent_id ) {
            payload.payment_intent_id = window.koopo_payment_intent_id;
        }
        fetch( KoopoUpgradeData.restUrl + '/finalize', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': KoopoUpgradeData.nonce },
            body: JSON.stringify(payload)
        }).then(function(r) {
            var contentType = r.headers.get('content-type') || '';
            if ( contentType.indexOf('application/json') === -1 ) {
                // Server returned non-JSON (e.g. HTML error page)
                throw new Error('Server error. Please contact support.');
            }
            return r.json();
        }).then(finalResp => {
            if ( finalResp.success ) {
                $('#koopo-result').text('Upgrade complete! Redirecting...');
                setTimeout(function(){ location.reload(); }, 1500);
            } else {
                $('#koopo-result').text('Finalization error: ' + (finalResp.message || 'unknown'));
                console.error('Finalize response:', finalResp);
            }
        }).catch(err => {
            $('#koopo-result').text('Finalization failed: ' + err.message);
            console.error('Finalization error:', err);
        });
    }

})(jQuery);
