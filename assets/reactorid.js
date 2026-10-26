(function () {
  var root = document.getElementById('reactorid-root');
  if (!root) return;
  var boot = {};
  try { boot = JSON.parse(root.getAttribute('data-boot') || '{}'); } catch (e) { boot = {}; }
  var state = boot.state || { step: 1 };

  var banks = {
    '636214':'بانک آینده','627412':'اقتصاد نوین','627381':'انصار','505785':'ایران زمین',
    '622106':'پارسیان','627884':'پارسیان','639194':'پارسیان','627760':'پست بانک',
    '585983':'تجارت','627353':'تجارت','502908':'توسعه تعاون','627648':'توسعه صادرات',
    '585947':'خاورمیانه','502938':'دی','504172':'رسالت','589463':'رفاه','621986':'سامان',
    '589210':'سپه','639607':'سرمایه','639346':'سینا','502806':'شهر','504706':'شهر',
    '603769':'صادرات','627961':'صنعت و معدن','606373':'مهر ایران','639599':'قوامین',
    '627488':'کارآفرین','502910':'کارآفرین','603770':'کشاورزی','639217':'کشاورزی',
    '505416':'گردشگری','628023':'مسکن','610433':'ملت','991975':'ملت','170019':'ملی',
    '603799':'ملی','606256':'ملل','639370':'مهر اقتصاد','505801':'کوثر','606737':'رسالت'
  };

  function $(sel, ctx) { return (ctx || root).querySelector(sel); }
  function $all(sel, ctx) { return Array.prototype.slice.call((ctx || root).querySelectorAll(sel)); }
  function fa2en(s) {
    return (s || '').replace(/[۰-۹]/g, function (d) { return '۰۱۲۳۴۵۶۷۸۹'.indexOf(d); })
                    .replace(/[٠-٩]/g, function (d) { return '٠١٢٣٤٥٦٧٨٩'.indexOf(d); });
  }
  function digits(s) { return fa2en(s).replace(/\D+/g, ''); }

  var msgEl = $('#afk-msg');
  var msgTimer = null;
  function msg(text, type) {
    if (!msgEl) return;
    msgEl.textContent = text;
    msgEl.className = 'afk-msg ' + (type || 'err');
    msgEl.hidden = false;
    if (msgTimer) clearTimeout(msgTimer);
    if (type === 'ok') msgTimer = setTimeout(function () { msgEl.hidden = true; }, 4000);
  }
  function clearMsg() { if (msgEl) msgEl.hidden = true; }

  function post(action, data, btn) {
    data = data || {};
    data.csrf = boot.csrf;
    var body = Object.keys(data).map(function (k) {
      return encodeURIComponent(k) + '=' + encodeURIComponent(data[k]);
    }).join('&');
    if (btn) btn.classList.add('is-loading');
    return fetch(boot.endpoint + '&kycajax=' + action, {
      method: 'POST',
      headers: { 'Content-Type': 'application/x-www-form-urlencoded', 'X-Requested-With': 'XMLHttpRequest' },
      body: body,
      credentials: 'same-origin'
    }).then(function (r) { return r.json(); }).catch(function () {
      return { ok: false, message: 'خطا در ارتباط با سرور. دوباره تلاش کنید.' };
    }).then(function (res) {
      if (btn) btn.classList.remove('is-loading');
      return res;
    });
  }

  function goStep(n) {
    state.step = n;
    $all('.afk-panel').forEach(function (p) { p.hidden = (parseInt(p.getAttribute('data-panel'), 10) !== n); });
    $all('.afk-step').forEach(function (s) {
      var i = parseInt(s.getAttribute('data-step'), 10);
      s.classList.toggle('is-active', i === n);
      s.classList.toggle('is-done', i < n);
    });
    clearMsg();
  }

  var otpBox = $('.afk-otp-box');
  var timer = null;
  function startTimer(sec) {
    var resend = $('#afk-resend'), tspan = $('#afk-timer');
    resend.disabled = true;
    var left = sec || 90;
    tspan.textContent = left;
    if (timer) clearInterval(timer);
    timer = setInterval(function () {
      left--;
      tspan.textContent = left;
      if (left <= 0) { clearInterval(timer); resend.disabled = false; resend.firstChild && (resend.textContent = 'ارسال مجدد کد'); }
    }, 1000);
  }

  function sendOtp(btn) {
    var mobile = digits($('#afk-mobile').value);
    if (!/^09\d{9}$/.test(mobile)) { return msg('شماره موبایل معتبر نیست.'); }
    post('send_otp', { mobile: mobile }, btn).then(function (res) {
      if (!res.ok) { return msg(res.message || 'ارسال ناموفق بود.'); }
      $('#afk-otp-target').textContent = mobile;
      otpBox.hidden = false;
      $('#afk-send-otp').textContent = 'ارسال مجدد شماره';
      startTimer(res.cooldown || 90);
      var first = $('.afk-otp-digit');
      if (first) first.focus();
      msg(res.message || 'کد ارسال شد.', 'ok');
    });
  }

  $('#afk-send-otp').addEventListener('click', function () { sendOtp(this); });
  $('#afk-mobile').addEventListener('input', function () { this.value = digits(this.value).slice(0, 11); });
  $('#afk-mobile').addEventListener('keydown', function (e) { if (e.key === 'Enter') sendOtp($('#afk-send-otp')); });

  var otpDigits = $all('.afk-otp-digit');
  otpDigits.forEach(function (inp, idx) {
    inp.addEventListener('input', function () {
      this.value = digits(this.value).slice(0, 1);
      this.classList.toggle('filled', !!this.value);
      if (this.value && idx < otpDigits.length - 1) otpDigits[idx + 1].focus();
      if (otpCode().length === 5) verifyOtp($('#afk-verify-otp'));
    });
    inp.addEventListener('keydown', function (e) {
      if (e.key === 'Backspace' && !this.value && idx > 0) otpDigits[idx - 1].focus();
    });
    inp.addEventListener('paste', function (e) {
      e.preventDefault();
      var txt = digits((e.clipboardData || window.clipboardData).getData('text')).slice(0, 5);
      for (var i = 0; i < txt.length; i++) { otpDigits[i].value = txt[i]; otpDigits[i].classList.add('filled'); }
      if (txt.length === 5) verifyOtp($('#afk-verify-otp'));
    });
  });
  function otpCode() { return otpDigits.map(function (d) { return d.value; }).join(''); }

  function verifyOtp(btn) {
    var code = otpCode();
    if (code.length !== 5) { return msg('کد ۵ رقمی را کامل وارد کنید.'); }
    post('verify_otp', { code: code, mobile: digits($('#afk-mobile').value) }, btn).then(function (res) {
      if (!res.ok) {
        msg(res.message || 'کد نادرست است.');
        otpDigits.forEach(function (d) { d.value = ''; d.classList.remove('filled'); });
        otpDigits[0].focus();
        return;
      }
      msg(res.message || 'موبایل تأیید شد.', 'ok');
      if (timer) clearInterval(timer);
      goStep(2);
    });
  }
  $('#afk-verify-otp').addEventListener('click', function () { verifyOtp(this); });
  $('#afk-resend').addEventListener('click', function () { sendOtp($('#afk-send-otp')); });
  $('#afk-edit-mobile').addEventListener('click', function () {
    otpBox.hidden = true;
    $('#afk-send-otp').textContent = 'ارسال کد تأیید';
    $('#afk-mobile').focus();
    if (timer) clearInterval(timer);
  });

  var months = ['فروردین','اردیبهشت','خرداد','تیر','مرداد','شهریور','مهر','آبان','آذر','دی','بهمن','اسفند'];
  function fillDates() {
    var dsel = $('#afk-bd-day'), msel = $('#afk-bd-month'), ysel = $('#afk-bd-year');
    if (!dsel || !msel || !ysel) return;

    function addOption(select, value, label) {
      var option = document.createElement('option');
      option.value = value;
      option.textContent = label;
      select.appendChild(option);
    }
    [dsel, msel, ysel].forEach(function (select) {
      select.innerHTML = '';
      select.disabled = false;
      select.style.pointerEvents = 'auto';
    });
    addOption(dsel, '', 'روز');
    for (var d = 1; d <= 31; d++) addOption(dsel, String(d), String(d));
    addOption(msel, '', 'ماه');
    for (var m = 0; m < 12; m++) addOption(msel, String(m + 1), months[m]);
    addOption(ysel, '', 'سال');

    var currentYear = new Date().getFullYear() - 621;
    for (var y = currentYear; y >= 1300; y--) addOption(ysel, String(y), String(y));

    [dsel, msel, ysel].forEach(function (select) {
      select.setAttribute('aria-label', select === dsel ? 'روز تولد' : (select === msel ? 'ماه تولد' : 'سال تولد'));
      select.style.position = 'relative';
      select.style.zIndex = '2';
    });
  }
  fillDates();
  function updateBirthDays() {
    var dsel = $('#afk-bd-day'), msel = $('#afk-bd-month'), ysel = $('#afk-bd-year');
    if (!dsel || !msel || !ysel) return;
    var previous = dsel.value;
    var month = parseInt(msel.value, 10);
    var year = parseInt(ysel.value, 10);
    var max = 31;
    if (month >= 7 && month <= 11) max = 30;
    if (month === 12) {
      var remainder = year % 33;
      var leap = [1, 5, 9, 13, 17, 22, 26, 30].indexOf(remainder) !== -1;
      max = leap ? 30 : 29;
    }
    while (dsel.options.length > max + 1) dsel.remove(dsel.options.length - 1);
    while (dsel.options.length < max + 1) {
      var day = dsel.options.length;
      var option = document.createElement('option');
      option.value = String(day);
      option.textContent = String(day);
      dsel.appendChild(option);
    }
    if (previous && parseInt(previous, 10) <= max) dsel.value = previous;
    else dsel.value = '';
  }
  $('#afk-bd-month').addEventListener('change', updateBirthDays);
  $('#afk-bd-year').addEventListener('change', updateBirthDays);
  updateBirthDays();

  function birthValue() {
    var y = $('#afk-bd-year').value, m = $('#afk-bd-month').value, d = $('#afk-bd-day').value;
    if (!y || !m || !d) return '';
    return y + ('0' + m).slice(-2) + ('0' + d).slice(-2);
  }
  $('#afk-national').addEventListener('input', function () { this.value = digits(this.value).slice(0, 10); });

  $('#afk-verify-identity').addEventListener('click', function () {
    var btn = this;
    var nc = digits($('#afk-national').value);
    var bd = birthValue();
    if (nc.length !== 10) { return msg('کد ملی ۱۰ رقمی را وارد کنید.'); }
    if (!bd) { return msg('تاریخ تولد را کامل انتخاب کنید.'); }
    post('verify_identity', { national_code: nc, birth_date: bd }, btn).then(function (res) {
      if (!res.ok) { return msg(res.message || 'استعلام ناموفق بود.'); }
      if (res.fullname) msg('هویت ' + res.fullname + ' تأیید شد.', 'ok');
      else msg(res.message || 'هویت تأیید شد.', 'ok');
      if (res.state) state = res.state;
      goStep(3);
    });
  });

  var cardInp = $('#afk-card');
  cardInp.addEventListener('input', function () {
    var v = digits(this.value).slice(0, 16);
    this.value = v.replace(/(.{4})/g, '$1 ').trim();
    var bank = banks[v.slice(0, 6)] || '';
    $('#afk-bank').textContent = bank ? 'بانک ' + bank : '';
  });
  $('#afk-verify-card').addEventListener('click', function () {
    var btn = this;
    var card = digits(cardInp.value);
    if (card.length !== 16) { return msg('شماره کارت ۱۶ رقمی را وارد کنید.'); }
    post('verify_card', { card_number: card }, btn).then(function (res) {
      if (!res.ok) { return msg(res.message || 'تأیید کارت ناموفق بود.'); }
      if (res.state) state = res.state;
      finish();
    });
  });

  function finish() {
    buildSummary();
    goStep(4);
  }
  function buildSummary() {
    var s = state || {};
    var box = $('#afk-summary');
    if (!box) return;
    box.textContent = '';
    if (s.fullname) appendSummaryRow(box, 'نام', document.createTextNode(String(s.fullname)));
    if (s.mobile) appendSummaryRow(box, 'موبایل', ltrNode(s.mobile));
    if (s.national) appendSummaryRow(box, 'کد ملی', ltrNode(s.national));
    if (s.card_mask) {
      var cardSpan = document.createElement('span');
      if (s.card_bank) { cardSpan.appendChild(document.createTextNode(s.card_bank + ' ')); }
      cardSpan.appendChild(ltrNode(s.card_mask));
      appendSummaryRow(box, 'کارت', cardSpan);
    }
  }

  function ltrNode(text) {
    var b = document.createElement('bdi');
    b.setAttribute('dir', 'ltr');
    b.textContent = String(text);
    return b;
  }
  function appendSummaryRow(box, label, valueNode) {
    var row = document.createElement('div');
    row.className = 'row';
    var heading = document.createElement('b');
    heading.textContent = label;
    var content = document.createElement('span');
    if (valueNode && valueNode.nodeType) {
      content.appendChild(valueNode);
    } else {
      content.textContent = String(valueNode);
    }
    row.appendChild(heading);
    row.appendChild(content);
    box.appendChild(row);
  }

  if (!boot.configured) { goStep(1); $all('.afk-btn').forEach(function (b) { if (b.tagName === 'BUTTON') b.disabled = true; }); }
  else if (state.ready) { buildSummary(); goStep(4); }
  else if (state.needCard) { goStep(3); msg('برای فعال شدن امکان خرید، شماره کارت بانکی خود را ثبت کنید.', 'err'); }
  else { goStep(state.step || 1); }
})();
