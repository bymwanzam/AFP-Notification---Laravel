/* SMS to district surveillance officers about past-due and due-soon AFP follow-ups, and the officers' numbers.
   Loaded before dashboard.js, whose case list calls smsRow(c) for each case; uses its DATA, api(), toast() and
   refreshData(). */

const SMS_STATUS = { queued: 'Queued', sent: 'Sent', delivered: 'Delivered', failed: 'Failed', pending: 'Queued', submitted: 'Sent' };

function smsWhen(iso) {
  const d = new Date(iso);
  return d.toLocaleDateString('en-GB', { day: '2-digit', month: 'short' }) + ' ' + d.toTimeString().slice(0, 5);
}

/* The line under a past-due or due-soon case: the last SMS about it, and the button to send one. */
function smsRow(c) {
  if (!DATA.live || (c.key !== 'past' && c.key !== 'soon')) return null;
  const row = el('div', 'smsrow');
  if (c.sms) {
    const st = SMS_STATUS[c.sms.status] || c.sms.status;
    const note = el('span', 'note sms-' + (c.sms.status === 'failed' ? 'bad' : c.sms.status === 'delivered' ? 'good' : 'wait'),
      'SMS ' + st.toLowerCase() + ' · ' + c.sms.to + ' · ' + smsWhen(c.sms.at));
    if (c.sms.status === 'failed' && c.sms.error) note.title = c.sms.error;
    row.append(note);
    if (!['delivered', 'failed'].includes(c.sms.status)) {
      const chk = el('button', 'linkbtn', 'Check delivery'); chk.type = 'button';
      chk.onclick = async () => {
        chk.disabled = true;
        try { const r = await api('/api/sms/status?id=' + c.sms.id); c.sms = r.sms; toast('SMS ' + (SMS_STATUS[r.sms.status] || r.sms.status).toLowerCase()); refreshData(); }
        catch (e) { toast(e.message); chk.disabled = false; }
      };
      row.append(chk);
    }
  }
  const b = el('button', 'btn sms', c.sms && c.sms.status !== 'failed' ? 'Send SMS again' : 'Send SMS'); b.type = 'button';
  b.prepend(smsIcon());
  b.onclick = () => smsDialog(c.epid);
  row.append(b);
  return row;
}

function smsIcon() {
  const s = document.createElementNS('http://www.w3.org/2000/svg', 'svg');
  s.setAttribute('viewBox', '0 0 24 24'); s.setAttribute('aria-hidden', 'true');
  s.innerHTML = '<path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z" fill="none" stroke="currentColor" stroke-width="2" stroke-linejoin="round"/>';
  return s;
}

function smsModal(title) {
  const d = document.createElement('dialog'); d.className = 'smsdlg';
  const head = el('div', 'dlg-head'); head.append(el('h2', null, title));
  const x = el('button', 'linkbtn', 'Close'); x.type = 'button'; x.onclick = () => d.close(); head.append(x);
  const body = el('div', 'dlg-body');
  d.append(head, body); document.body.append(d);
  d.addEventListener('close', () => d.remove());
  d.showModal();
  return body;
}

/* Preview the SMS for a case, then send it. */
async function smsDialog(epid) {
  const body = smsModal('SMS to the district officer');
  body.append(el('p', 'note', 'Loading…'));
  let p;
  try { p = await api('/api/sms/preview?epid=' + encodeURIComponent(epid)); }
  catch (e) { body.textContent = ''; body.append(el('p', 'sms-error', e.message)); return; }
  body.textContent = '';
  const to = el('div', 'sms-to');
  to.append(el('span', 'label', 'To'));
  if (p.officer) to.append(el('strong', null, (p.officer.name || 'District officer') + ' · ' + p.officer.phone), el('span', 'note', p.district + ', ' + p.region));
  else to.append(el('span', 'sms-error', 'No officer’s number is saved for ' + p.district + '.'));
  const msg = el('div', 'sms-msg');
  msg.append(el('span', 'label', 'Message · ' + p.message.length + ' characters'), el('p', null, p.message));
  body.append(to, msg);
  if (p.last) body.append(el('p', 'note', 'Last SMS about this case: ' + (SMS_STATUS[p.last.status] || p.last.status).toLowerCase() + ', to ' + p.last.to + ' on ' + smsWhen(p.last.at) + ' by ' + p.last.by + '.'));
  if (!p.ready) body.append(el('p', 'sms-error', 'SMS is not set up yet. The support account adds the Fish Africa App ID and App Secret under SMS settings.'));
  const acts = el('div', 'dlg-acts');
  const edit = el('button', 'btn', p.officer ? 'Change number' : 'Add the number'); edit.type = 'button';
  edit.onclick = () => { body.closest('dialog').close(); smsContacts(p.region, p.code); };
  const send = el('button', 'btn solid', 'Send SMS'); send.type = 'button';
  send.disabled = !p.officer || !p.ready;
  send.onclick = async () => {
    send.disabled = true; send.textContent = 'Sending…';
    try {
      const r = await api('/api/sms/send', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ epid }) });
      body.closest('dialog').close();
      toast('SMS ' + (SMS_STATUS[r.sms.status] || r.sms.status).toLowerCase() + ' to ' + r.sms.to);
      refreshData();
    } catch (e) {
      send.textContent = 'Send SMS'; send.disabled = false;
      const old = body.querySelector('.send-error'); if (old) old.remove();
      const err = el('p', 'sms-error send-error', e.message); acts.before(err);
    }
  };
  acts.append(edit, send); body.append(acts);
}

/* The district officers' names and numbers for a region. focus: a district code to start on. */
async function smsContacts(region, focus) {
  const body = smsModal('SMS contacts · district officers');
  body.append(el('p', 'note', 'The SMS about a district’s due and overdue follow-ups goes to the number saved here. Clear a number to remove it.'));
  let pick = null;
  if (DATA.national) {
    pick = document.createElement('select'); pick.className = 'in'; pick.setAttribute('aria-label', 'Region');
    DATA.districts.forEach(([, n]) => { const o = document.createElement('option'); o.value = o.textContent = n; pick.append(o); });
    if (region) pick.value = region;
    pick.onchange = () => load(pick.value);
    body.append(pick);
  }
  const list = el('div', 'sms-list'); body.append(list);
  async function load(r) {
    list.textContent = 'Loading…';
    let res;
    try { res = await api('/api/sms/officers' + (DATA.national ? '?region=' + encodeURIComponent(r) : '')); }
    catch (e) { list.textContent = ''; list.append(el('p', 'sms-error', e.message)); return; }
    list.textContent = '';
    res.officers.forEach(o => {
      const f = document.createElement('form'); f.className = 'sms-officer';
      const name = document.createElement('input'); name.className = 'in'; name.value = o.name; name.placeholder = 'Officer’s name'; name.maxLength = 80; name.setAttribute('aria-label', o.district + ' officer’s name');
      const phone = document.createElement('input'); phone.className = 'in'; phone.type = 'tel'; phone.value = o.phone; phone.placeholder = 'Phone number'; phone.setAttribute('aria-label', o.district + ' officer’s phone');
      const save = el('button', 'btn', 'Save'); save.type = 'submit';
      f.append(el('span', 'dist', o.district), name, phone, save);
      f.onsubmit = async e => {
        e.preventDefault(); save.disabled = true;
        try {
          const r2 = await api('/api/sms/officers', { method: 'POST', headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ region: res.region, code: o.code, name: name.value, phone: phone.value }) });
          const now = r2.officers.find(x => x.code === o.code);
          name.value = now.name; phone.value = now.phone;
          toast(now.phone ? 'Saved for ' + o.district : 'Number removed for ' + o.district);
        } catch (err) { toast(err.message); }
        save.disabled = false;
      };
      list.append(f);
      if (o.code === focus) setTimeout(() => { f.scrollIntoView({ block: 'center' }); (o.phone ? name : phone).focus(); }, 50);
    });
  }
  load(DATA.national ? pick.value : region);
}

/* Support only: the Fish Africa account the app sends with, and a test SMS. */
async function smsSettings() {
  const body = smsModal('SMS settings · Fish Africa');
  body.append(el('p', 'note', 'Loading…'));
  let s;
  try { s = await api('/api/sms/settings'); } catch (e) { body.textContent = ''; body.append(el('p', 'sms-error', e.message)); return; }
  body.textContent = '';
  const state = el('p', 'sms-state');
  const showState = () => {
    state.className = 'sms-state ' + (s.source ? 'on' : 'off');
    state.textContent = s.source === 'app' ? 'SMS is on. The App ID and App Secret saved here are used.'
      : s.source === 'env' ? 'SMS is on, using the keys in the server’s .env file (App ID ' + s.appIdHint + '). Save keys here to use those instead.'
      : 'SMS is off: no App ID and App Secret yet. Nobody can send SMS until they are saved here.';
  };
  showState();
  const help = el('p', 'note');
  help.append('Find them on the ');
  const a = el('a', null, 'Fish Africa dashboard'); a.href = 'https://letsfish.africa'; a.target = '_blank'; a.rel = 'noopener';
  help.append(a, ' under Settings › Applications › Default App.');

  const f = document.createElement('form'); f.className = 'sms-form'; f.autocomplete = 'off';
  const field = (label, input, hint) => { const l = el('label', 'sms-field'); l.append(el('span', 'label', label), input); if (hint) l.append(el('span', 'note', hint)); return l; };
  const id = document.createElement('input'); id.className = 'in'; id.value = s.appId; id.required = true; id.spellcheck = false;
  const secret = document.createElement('input'); secret.className = 'in'; secret.type = 'password'; secret.spellcheck = false; secret.autocomplete = 'new-password';
  secret.placeholder = s.source === 'app' ? 'Saved · leave blank to keep it' : '';
  const sender = document.createElement('input'); sender.className = 'in'; sender.value = s.senderId; sender.maxLength = 11; sender.required = true;
  const save = el('button', 'btn solid', 'Save'); save.type = 'submit';
  const remove = el('button', 'btn danger', 'Remove saved keys'); remove.type = 'button'; remove.hidden = s.source !== 'app';
  const acts = el('div', 'dlg-acts'); acts.append(remove, save);
  f.append(field('App ID', id), field('App Secret', secret, 'Stored encrypted. It is never shown again.'),
    field('Sender ID', sender, 'What phones show as the sender, up to 11 characters. It must be approved on your Fish Africa account.'), acts);
  f.onsubmit = async e => {
    e.preventDefault(); save.disabled = true;
    try {
      s = await api('/api/sms/settings', { method: 'POST', headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ appId: id.value, appSecret: secret.value, senderId: sender.value }) });
      secret.value = ''; secret.placeholder = 'Saved · leave blank to keep it'; remove.hidden = false;
      showState(); toast('SMS settings saved. Send a test SMS to check them.'); refreshData();
    } catch (err) { toast(err.message); }
    save.disabled = false;
  };
  remove.onclick = async () => {
    if (!confirm('Remove the saved App ID and App Secret? SMS stops unless the server’s .env has keys.')) return;
    try { s = await api('/api/sms/settings', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ remove: true }) });
      id.value = ''; secret.placeholder = ''; remove.hidden = true; showState(); refreshData(); toast('Saved keys removed'); }
    catch (err) { toast(err.message); }
  };

  const t = document.createElement('form'); t.className = 'sms-test';
  const phone = document.createElement('input'); phone.className = 'in'; phone.type = 'tel'; phone.placeholder = 'Your phone number'; phone.required = true;
  const go = el('button', 'btn', 'Send test SMS'); go.type = 'submit';
  const result = el('p', 'note');
  t.append(el('span', 'label', 'Check it works'), phone, go, result);
  t.onsubmit = async e => {
    e.preventDefault(); go.disabled = true; result.className = 'note'; result.textContent = 'Sending…';
    try {
      const r = await api('/api/sms/test', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ phone: phone.value }) });
      result.className = 'note sms-good'; result.textContent = 'Fish Africa accepted it (' + r.status + '). It should reach ' + r.to + ' within a minute.';
    } catch (err) { result.className = 'sms-error'; result.textContent = err.message; }
    go.disabled = false;
  };
  body.append(state, help, f, t);
}

document.addEventListener('DOMContentLoaded', () => {
  const b = document.getElementById('smsContacts');
  if (b && DATA.live) { b.hidden = false; b.onclick = () => smsContacts(DATA.national ? null : DATA.regionName); }
  const s = document.getElementById('smsSettings');
  if (s && DATA.live) fetch('/api/me', { headers: { 'X-CSRFToken': csrfToken() } }).then(r => r.ok ? r.json() : null).then(me => {
    if (me && me.role === 'support') { s.hidden = false; s.onclick = smsSettings; }
  });
});
