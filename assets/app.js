// client-side interactions: sum calc and basic validation
document.addEventListener('DOMContentLoaded', function(){
  var m2 = document.getElementById('m2');
  var days = document.getElementById('days');
  var price = document.getElementById('price');
  var sum = document.getElementById('sumCalc');
  var form = document.getElementById('orderForm');
  var submit = document.getElementById('submitBtn');

  function calc(){
    if (!m2 || !days || !price || !sum) return;
    var a = parseFloat(m2.value) || 0;
    var b = parseFloat(days.value) || 0;
    var c = parseFloat(price.value) || 0;
    sum.textContent = (a*b*c).toLocaleString('ru-RU');
  }
  if(m2) m2.addEventListener('input', calc);
  if(days) days.addEventListener('input', calc);
  if(price) price.addEventListener('input', calc);
  calc();

  if(form){
    form.addEventListener('submit', function(e){
      // basic validation
      var errors = [];
      if(!document.getElementById('client_id').value && !document.getElementById('client_name').value) errors.push('Укажите клиента');
      if(!(parseFloat(m2.value)>0)) errors.push('Укажите количество м² больше 0');
      if(!(parseFloat(days.value)>0)) errors.push('Укажите количество дней больше 0');
      if(!(parseFloat(price.value)>0)) errors.push('Укажите цену больше 0');
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

  if (!clientSelect || !panel) return;

  function toggleNewClient(){
    var isNew = !clientSelect.value;
    panel.hidden = !isNew;
    if (nameInput) nameInput.required = isNew;
    if (isNew) return;

    var option = clientSelect.options[clientSelect.selectedIndex];
    if (nameInput && option) nameInput.value = option.getAttribute('data-name') || '';
    if (phoneInput && option) phoneInput.value = option.getAttribute('data-phone') || '';
  }

  clientSelect.addEventListener('change', toggleNewClient);
  toggleNewClient();
});

// charts rendering if data available
document.addEventListener('DOMContentLoaded', function(){
  if (window.__labels && typeof Chart !== 'undefined'){
    var opts = { responsive: true, maintainAspectRatio: false };
    
    var ctx = document.getElementById('m2Chart');
    if (ctx) new Chart(ctx.getContext('2d'), {type:'line', data:{labels:window.__labels, datasets:[{label:'м² выдано', data:window.__m2, borderColor:'blue', fill:false}]}, options: opts});
    
    var ctx2 = document.getElementById('moneyChart');
    if (ctx2) new Chart(ctx2.getContext('2d'), {type:'bar', data:{labels:window.__labels, datasets:[{label:'Сумма аренды', data:window.__money, backgroundColor:'green'}]}, options: opts});
    
    var ctx3 = document.getElementById('popularChart');
    if (ctx3) new Chart(ctx3.getContext('2d'), {type:'pie', data:{labels:window.__popLabels, datasets:[{data:window.__popVals, backgroundColor:['#ff6384','#36a2eb','#ffcd56','#8bc34a','#9c27b0']} ]}, options: opts});
    
    var ctx4 = document.getElementById('statusChart');
    if (ctx4 && window.__statusLabels) {
      new Chart(ctx4.getContext('2d'), {type:'doughnut', data:{labels:window.__statusLabels, datasets:[{data:window.__statusVals, backgroundColor:['#4bc0c0','#ff9f40','#ffcd56']}]}, options: opts});
    }
  }
});

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
