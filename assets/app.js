// client-side interactions: sum calc and basic validation
document.addEventListener('DOMContentLoaded', function(){
  var days = document.getElementById('days');
  var discount = document.getElementById('discount_percentage');
  var delivery = document.getElementById('delivery_fee');
  var tax = document.getElementById('tax_percentage');
  var sum = document.getElementById('sumCalc');
  var form = document.getElementById('orderForm');

  function calc(){
    if (!days || !sum) return;
    var b = parseFloat(days.value) || 0;
    var dPct = discount ? (parseFloat(discount.value) || 0) : 0;
    var df = delivery ? (parseFloat(delivery.value) || 0) : 0;
    var tx = tax ? (parseFloat(tax.value) || 0) : 0;
    
    var rent = 0;
    var qtys = document.querySelectorAll('.item-qty');
    var prices = document.querySelectorAll('.item-price');
    
    for (var i = 0; i < qtys.length; i++) {
        var q = parseFloat(qtys[i].value) || 0;
        var p = parseFloat(prices[i].value) || 0;
        rent += q * b * p;
    }
    
    var taxAmount = Math.round(rent * tx / 100);
    var d = Math.round(rent * dPct / 100);
    var total = Math.max(0, rent + taxAmount + df - d);
    
    sum.textContent = total.toLocaleString('ru-RU');
  }
  window.calc = calc; // export for other scopes
  
  if(days) days.addEventListener('input', calc);
  if(discount) discount.addEventListener('input', calc);
  if(delivery) delivery.addEventListener('input', calc);
  if(tax) tax.addEventListener('input', calc);
  
  document.querySelectorAll('.item-qty, .item-price').forEach(function(el) {
      el.addEventListener('input', calc);
  });
  calc();

  if(form){
    form.addEventListener('submit', function(e){
      // basic validation
      var errors = [];
      if(!document.getElementById('client_id').value && !document.getElementById('client_name').value) errors.push('Укажите клиента');
      
      var qtys = document.querySelectorAll('.item-qty');
      var hasItems = false;
      for (var i = 0; i < qtys.length; i++) {
          if (parseFloat(qtys[i].value) > 0) hasItems = true;
      }
      
      if(!hasItems) errors.push('Укажите количество хотя бы для одного товара');
      if(!(parseFloat(days.value)>0)) errors.push('Укажите количество дней больше 0');
      
      if(errors.length){
        e.preventDefault();
        alert(errors.join('\n'));
      }
    });
  }
});

document.addEventListener('DOMContentLoaded', function(){
  var clientSelect = document.getElementById('client_id');
  var panel = document.getElementById('newClientPanel');
  var nameInput = document.getElementById('client_name');
  var phoneInput = document.getElementById('client_phone');
  var taxPanel = document.getElementById('taxPanel');
  var tax = document.getElementById('tax_percentage');
  var newClientTypeSelect = document.querySelector('#newClientPanel select[name="client_type"]');

  function updateTaxVisibility() {
    if (!taxPanel) return;
    var isJuridical = false;
    if (!clientSelect || !clientSelect.value) {
      if (newClientTypeSelect && newClientTypeSelect.value === 'Юр.лицо') {
        isJuridical = true;
      }
    } else {
      var option = clientSelect.options[clientSelect.selectedIndex];
      if (option && option.getAttribute('data-type') === 'Юр.лицо') {
        isJuridical = true;
      }
    }
    taxPanel.style.display = isJuridical ? 'block' : 'none';
    if (!isJuridical && tax) { tax.value = ''; if(window.calc) window.calc(); }
  }

  if (newClientTypeSelect) newClientTypeSelect.addEventListener('change', updateTaxVisibility);

  function toggleNewClient(){
    var isNew = !clientSelect || !clientSelect.value;
    if (panel) panel.hidden = !isNew;
    if (nameInput) nameInput.required = isNew;
    updateTaxVisibility();
    if (isNew) return;

    var option = clientSelect.options[clientSelect.selectedIndex];
    if (nameInput && option) nameInput.value = option.getAttribute('data-name') || '';
    if (phoneInput && option) phoneInput.value = option.getAttribute('data-phone') || '';
  }

  if (clientSelect) {
    clientSelect.addEventListener('change', toggleNewClient);
    toggleNewClient();
  }
});

// dynamic units and price auto-fill (deprecated since all items are shown at once)

// referral and delivery toggles
document.addEventListener('DOMContentLoaded', function(){
  var refSelect = document.getElementById('referral_client_id');
  var refPanel = document.getElementById('newReferralClientPanel');
  var refNameInput = document.getElementById('referral_client_name');
  var hasReferral = document.getElementById('has_referral');
  var referralContainer = document.getElementById('referralPanel');
  var discountInput = document.getElementById('discount_percentage');

  function updateReferralVisibility() {
    if (hasReferral && referralContainer) {
      var checked = hasReferral.checked;
      referralContainer.style.display = checked ? 'block' : 'none';
      if (!checked) {
        if (refSelect) refSelect.value = '';
        if (discountInput) { discountInput.value = ''; if(window.calc) window.calc(); }
      }
    }
  }

  if (hasReferral) hasReferral.addEventListener('change', updateReferralVisibility);
  updateReferralVisibility();

  if (refSelect && refPanel) {
    function toggleNewReferralClient(){
      var isNew = refSelect.value === 'new';
      refPanel.style.display = isNew ? 'grid' : 'none';
      if (refNameInput) refNameInput.required = isNew;
    }
    refSelect.addEventListener('change', toggleNewReferralClient);
    toggleNewReferralClient();
  }

  var delType = document.getElementById('delivery_type');
  var delPanel = document.getElementById('deliveryPanel');
  var delFee = document.getElementById('delivery_fee');
  
  function updateDelivery() {
    if (delType && delPanel) {
      var isDel = delType.value === 'delivery';
      delPanel.style.display = isDel ? 'block' : 'none';
      if (!isDel && delFee) {
         delFee.value = '';
         if(window.calc) window.calc();
      }
    }
  }
  if (delType) {
    delType.addEventListener('change', updateDelivery);
    updateDelivery();
  }
});

// charts rendering moved to dashboard.php

// mobile nav toggle
document.addEventListener('DOMContentLoaded', function(){
  var btn = document.getElementById('navToggle');
  var links = document.getElementById('navLinks');
  if (btn && links){
    btn.addEventListener('click', function(){
      var isOpen = links.classList.toggle('open');
      btn.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
    });
  }
});

if ('serviceWorker' in navigator) {
  window.addEventListener('load', function(){
    navigator.serviceWorker.register('/sw.js').catch(function(){});
  });
}

// PWA Install Prompt Logic
document.addEventListener('DOMContentLoaded', function() {
  let deferredPrompt;
  const pwaBanner = document.getElementById('pwaBanner');
  const pwaInstallBtn = document.getElementById('pwaInstallBtn');
  const pwaCloseBtn = document.getElementById('pwaCloseBtn');

  if (pwaBanner && pwaInstallBtn && pwaCloseBtn) {
    window.addEventListener('beforeinstallprompt', (e) => {
      e.preventDefault();
      deferredPrompt = e;
      if (localStorage.getItem('pwaDismissed') !== 'true') {
        pwaBanner.classList.add('show');
      }
    });

    pwaCloseBtn.addEventListener('click', () => {
      pwaBanner.classList.remove('show');
      localStorage.setItem('pwaDismissed', 'true');
    });

    pwaInstallBtn.addEventListener('click', async () => {
      pwaBanner.classList.remove('show');
      if (deferredPrompt) {
        deferredPrompt.prompt();
        const { outcome } = await deferredPrompt.userChoice;
        deferredPrompt = null;
      }
    });
  }
});
