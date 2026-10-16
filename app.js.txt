const rupiah = n => new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(n||0));
let products = [];
let site = {};
let cart = JSON.parse(localStorage.getItem('hendra_cart') || '{}');
let activeCategory = 'Semua';
let adminPin = '';
let productImageData = '';
let chatContext = null;
let chatPollTimer = null;

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const toast = msg => { const el=$('toast'); el.textContent=msg; el.classList.add('show'); setTimeout(()=>el.classList.remove('show'),2400); };

function getChatToken(){
  let token=localStorage.getItem('hendra_chat_token');
  if(!token){
    const bytes=new Uint8Array(16); crypto.getRandomValues(bytes);
    token=Array.from(bytes,b=>b.toString(16).padStart(2,'0')).join('');
    localStorage.setItem('hendra_chat_token',token);
  }
  return token;
}

function formatDateId(value){
  if(!value) return '-';
  const raw=String(value).slice(0,10);
  const d=new Date(raw+'T12:00:00');
  if(Number.isNaN(d.getTime())) return raw;
  return new Intl.DateTimeFormat('id-ID',{day:'numeric',month:'short',year:'numeric'}).format(d);
}
function formatDateTimeId(value){
  if(!value) return '-';
  const d=new Date(String(value).replace(' ','T'));
  if(Number.isNaN(d.getTime())) return String(value);
  return new Intl.DateTimeFormat('id-ID',{day:'numeric',month:'short',year:'numeric',hour:'2-digit',minute:'2-digit'}).format(d);
}
function formatEtaRange(start,end){
  if(!start && !end) return 'Estimasi belum tersedia';
  if(start===end) return formatDateId(start);
  return `${formatDateId(start)} – ${formatDateId(end)}`;
}
function displayOrderStatus(o){
  const status=String(o?.status||'');
  if(status==='Diserahkan ke kurir') return 'Pesanan Telah Diserahkan ke Kurir';
  if(status==='Dalam pengantaran'){
    const city=String(o?.city||'').trim();
    return city ? `Dalam Pengantaran ke ${city}` : 'Dalam Pengantaran';
  }
  if(status==='Diproses') return 'Sedang Diproses';
  return status;
}
function deliveryEstimateHtml(o){
  if(o.delivered_at) return `<div class="delivery-estimate delivered">✅ Tiba pada <b>${formatDateId(o.delivered_at)}</b></div>`;
  const provisional=!['Diserahkan ke kurir','Dalam pengantaran'].includes(o.status);
  const title=provisional?'Perkiraan awal tiba':'Estimasi tiba';
  const note=provisional?'<small>Estimasi akan dihitung ulang dari tanggal saat penjual benar-benar mengirim pesanan.</small>':'';
  return `<div class="delivery-estimate">🚚 ${title}: <b>${formatEtaRange(o.eta_start,o.eta_end)}</b>${note}</div>`;
}
function paymentDeadlineHtml(o){
  if(o.payment_method==='COD' || !o.payment_due_at || ['Sudah dibayar','Menunggu verifikasi'].includes(o.payment_status) || o.status==='Selesai') return '';
  if(o.status==='Dibatalkan' && o.payment_status==='Kedaluwarsa') return '<div class="payment-deadline expired">⌛ Dibatalkan otomatis karena pembayaran tidak diterima dalam 24 jam.</div>';
  return `<div class="payment-deadline">⏰ Batas pembayaran: <b>${formatDateTimeId(o.payment_due_at)}</b> (24 jam)</div>`;
}

function previewEtaFromToday(){
  const min=Math.max(0,Number(site.delivery_eta_min_days||1));
  const max=Math.max(min,Number(site.delivery_eta_max_days||2));
  const today=new Date();
  const add=n=>{const d=new Date(today);d.setDate(d.getDate()+n);const y=d.getFullYear(),m=String(d.getMonth()+1).padStart(2,'0'),day=String(d.getDate()).padStart(2,'0');return `${y}-${m}-${day}`;};
  return {start:add(min),end:add(max)};
}

async function api(url, options={}){
  const r = await fetch(url, options);
  let j = {};
  try { j = await r.json(); } catch(e) {}
  if(!r.ok) throw new Error(j.error || 'Terjadi kesalahan.');
  return j;
}

async function loadSite(){
  site = await api('/api/site');
  applySite();
}
function applySite(){
  document.documentElement.style.setProperty('--primary', site.primary_color || '#1367d1');
  document.documentElement.style.setProperty('--accent', site.accent_color || '#0ea5e9');
  document.title = `${site.store_name || 'Hendra'} • ${site.store_subtitle || 'Air Minum & Gas'}`;
  const name = site.store_name || 'Hendra', subtitle = site.store_subtitle || 'Air Minum & Gas';
  $('announcement').textContent = site.announcement || '';
  $('brandName').textContent = name; $('brandSubtitle').textContent = subtitle;
  $('footerName').textContent = name; $('footerSubtitle').textContent = subtitle;
  $('storeCardName').textContent = `${subtitle} ${name}`;
  if($('storeCity')) $('storeCity').textContent = site.store_city || 'Palembang';
  $('brandMark').textContent = name.charAt(0).toUpperCase() || 'H';
  $('footerMark').textContent = name.charAt(0).toUpperCase() || 'H';
  $('heroBadge').textContent = site.hero_badge || '';
  $('heroTitle').textContent = site.hero_title || '';
  $('heroHighlight').textContent = site.hero_highlight || '';
  $('heroText').textContent = site.hero_text || '';
  $('heroPrimaryButton').textContent = site.hero_primary_button || 'Pesan Sekarang';
  $('heroSecondaryButton').textContent = site.hero_secondary_button || 'Coba AI Assistant';
  $('catalogTitle').textContent = site.catalog_title || 'Produk tersedia';
  $('catalogText').textContent = site.catalog_text || '';
  $('aiTitle').textContent = site.ai_title || 'Hendra Smart Assistant';
  $('aiTextIntro').textContent = site.ai_text || '';
  $('trackingTitle').textContent = site.tracking_title || 'Lacak pesanan';
  $('trackingText').textContent = site.tracking_text || '';
  $('aboutTitle').textContent = site.about_title || '';
  $('aboutText').textContent = site.about_text || '';
  $('footerText').textContent = site.footer_text || '';
  $('contactPhone').textContent = site.phone || '-';
  $('contactAddress').textContent = site.address || '-';
  const fee = Number(site.delivery_fee || 5000), min = Number(site.free_delivery_minimum || 100000);
  $('shippingNote').textContent = `Ongkir ${rupiah(fee)}. Gratis jika subtotal ≥ ${rupiah(min)}.`;
  renderPaymentOptions();

  const logo = site.logo_image_data || '';
  if(logo){
    $('brandMark').innerHTML = `<img src="${logo}" alt="Logo">`;
    $('footerMark').innerHTML = `<img src="${logo}" alt="Logo">`;
  }
  const heroImg = site.hero_image_data || '';
  if(heroImg){ $('heroImage').src=heroImg; $('heroImage').classList.remove('hidden'); $('storeCard').classList.add('with-hero-image'); }
  else { $('heroImage').removeAttribute('src'); $('heroImage').classList.add('hidden'); $('storeCard').classList.remove('with-hero-image'); }
}

function normalizeCityName(value){
  return String(value||'').trim().toLowerCase().replace(/^(kota|kabupaten|kab\.)\s+/,'').replace(/\s+/g,' ');
}
function codAllowedForCity(city){
  // COD proyek ini khusus wilayah Palembang. Gunakan pencocokan yang toleran
  // terhadap penulisan seperti "Kota Palembang" atau "Palembang, Sumatera Selatan".
  const normalized=normalizeCityName(city);
  return normalized==='palembang' || normalized.startsWith('palembang,') || normalized.includes('palembang');
}
function anyPaymentMethodEnabled(){
  // Metode inti selalu tersedia: Transfer Bank Mandiri. COD tersedia khusus Palembang.
  return true;
}
function paymentBankName(){ return site.bank_name || 'Bank Mandiri'; }
function paymentBankAccount(){ return site.bank_account_number || '1130020365627'; }
function availablePaymentMethods(city=''){
  // Transfer selalu tersedia. COD tetap dapat diklik; validasi kota dilakukan
  // saat dipilih dan sekali lagi di server agar tidak mudah dilewati.
  return [
    {value:'Transfer Bank',label:`Transfer ${paymentBankName()} — ${paymentBankAccount()}`,disabled:false},
    {value:'COD',label:`COD / Bayar di Tempat — khusus Palembang`,disabled:false}
  ];
}
function paymentInfoHtml(method){
  if(method==='Transfer Bank'){
    return `<div class="payment-card"><b>Transfer Bank</b>
      <div class="payment-bank">${esc(paymentBankName())}</div>
      <div>No. rekening: <b class="account-number">${esc(paymentBankAccount())}</b></div>
      <small>${esc(site.payment_note||'Transfer sesuai total pesanan lalu unggah bukti pembayaran.')}</small>
    </div>`;
  }
  if(method==='COD') return `<div class="payment-card"><b>COD / Bayar di Tempat</b><div>Khusus wilayah <b>${esc(site.store_city||'Palembang')}</b></div><small>Pembayaran dilakukan saat pesanan diterima.</small></div>`;
  return `<div class="payment-card"><small>Belum ada metode pembayaran yang aktif. Hubungi admin.</small></div>`;
}
function renderPaymentOptions(){
  const select=$('paymentMethod'), info=$('paymentInfo');
  if(!select || !info) return;
  const city=$('shippingCity')?.value||'';
  const methods=availablePaymentMethods(city), current=select.value;
  select.innerHTML=methods.map(m=>`<option value="${esc(m.value)}" ${m.disabled?'disabled':''}>${esc(m.label)}</option>`).join('');
  const currentMethod=methods.find(m=>m.value===current && !m.disabled);
  select.value=currentMethod ? current : 'Transfer Bank';
  const renderInfo=()=>{
    const liveCity=$('shippingCity')?.value||'';
    const allowed=codAllowedForCity(liveCity);
    if(select.value==='COD' && !allowed){
      toast('COD hanya tersedia untuk Palembang.');
      select.value='Transfer Bank';
    }
    const codNote=`<div class="cod-location-note ${allowed?'available':'unavailable'}">${allowed?'✅ COD tersedia untuk Palembang.':'📍 COD / Bayar di Tempat khusus Palembang. Isi Kota/Kabupaten = Palembang untuk menggunakan COD.'}</div>`;
    info.innerHTML=paymentInfoHtml(select.value)+codNote;
  };
  renderInfo();
  select.onchange=renderInfo;
}
if($('shippingCity')){
  $('shippingCity').addEventListener('input',renderPaymentOptions);
  $('shippingCity').addEventListener('change',renderPaymentOptions);
}

async function fetchProducts(){
  products = await api('/api/products');
  renderFilters(); renderProducts(); renderCart();
}
function renderFilters(){
  const cats = ['Semua', ...new Set(products.map(p=>p.category))];
  if(!cats.includes(activeCategory)) activeCategory='Semua';
  $('filters').innerHTML = cats.map(c=>`<button class="filter ${c===activeCategory?'active':''}" data-cat="${esc(c)}">${esc(c)}</button>`).join('');
  $('filters').querySelectorAll('[data-cat]').forEach(b=>b.onclick=()=>{ activeCategory=b.dataset.cat; renderFilters(); renderProducts(); });
}
function productVisual(p){
  return p.image_data ? `<img src="${p.image_data}" alt="${esc(p.name)}" class="product-photo">` : `<div class="product-icon">${esc(p.emoji||'📦')}</div>`;
}
function renderProducts(){
  const list = activeCategory==='Semua' ? products : products.filter(p=>p.category===activeCategory);
  $('productGrid').innerHTML = list.map(p=>`<article class="product-card ${p.stock<=0?'out':''}">
    ${productVisual(p)}
    <div class="product-cat">${esc(p.category)}</div>
    <h3>${esc(p.name)}</h3><p class="desc">${esc(p.description||'')}</p>
    <div class="product-meta"><div class="price">${rupiah(p.price)}</div><div class="stock">Stok ${p.stock} ${esc(p.unit)}</div></div>
    <button class="btn btn-primary" ${p.stock<=0?'disabled':''} onclick="addToCart(${p.id})">${p.stock<=0?'Stok Habis':'Tambah ke Keranjang'}</button>
  </article>`).join('') || '<div class="muted">Tidak ada produk.</div>';
}
function saveCart(){ localStorage.setItem('hendra_cart',JSON.stringify(cart)); renderCart(); }
function addToCart(id){ const p=products.find(x=>x.id===id); if(!p||p.stock<=0)return; const next=(cart[id]||0)+1; if(next>p.stock){toast('Jumlah melebihi stok.');return;} cart[id]=next; saveCart(); toast(`${p.name} ditambahkan.`); }
function changeQty(id,delta){ const p=products.find(x=>x.id===id); const next=(cart[id]||0)+delta; if(next<=0) delete cart[id]; else if(p && next<=p.stock) cart[id]=next; else toast('Jumlah melebihi stok.'); saveCart(); }
function cartData(){ return Object.entries(cart).map(([id,q])=>({product:products.find(p=>p.id===Number(id)),quantity:q})).filter(x=>x.product); }
function renderCart(){
  const data=cartData(), count=data.reduce((s,x)=>s+x.quantity,0); $('cartCount').textContent=count;
  $('cartItems').innerHTML=data.length?data.map(x=>`<div class="cart-row"><div><b>${esc(x.product.name)}</b><div class="muted">${rupiah(x.product.price)} / ${esc(x.product.unit)}</div></div><div><div class="qty"><button onclick="changeQty(${x.product.id},-1)">−</button><b>${x.quantity}</b><button onclick="changeQty(${x.product.id},1)">+</button></div><small>${rupiah(x.product.price*x.quantity)}</small></div></div>`).join(''):'<p class="muted">Keranjang masih kosong.</p>';
  $('subtotal').textContent=rupiah(data.reduce((s,x)=>s+x.product.price*x.quantity,0));
}

function openDrawer(){ $('overlay').classList.add('open'); $('cartDrawer').classList.add('open'); }
function closeDrawer(){ $('overlay').classList.remove('open'); $('cartDrawer').classList.remove('open'); }
function openModal(id){ $(id).classList.add('open'); }
function closeModal(id){ $(id).classList.remove('open'); }
$('cartOpen').onclick=openDrawer; $('cartClose').onclick=closeDrawer; $('overlay').onclick=closeDrawer;
$('menuBtn').onclick=()=> $('navLinks').classList.toggle('open');
document.querySelectorAll('.nav-links a').forEach(a=>a.onclick=()=> $('navLinks').classList.remove('open'));
document.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>{ if(b.dataset.close==='chatModal') stopChatPolling(); closeModal(b.dataset.close); });

$('checkoutBtn').onclick=()=>{
  const data=cartData(); if(!data.length){toast('Keranjang masih kosong.');return;}
  if(!anyPaymentMethodEnabled()){toast('Belum ada metode pembayaran aktif. Hubungi admin.');return;}
  closeDrawer(); const subtotal=data.reduce((s,x)=>s+x.product.price*x.quantity,0);
  const freeMin=Number(site.free_delivery_minimum||100000), normalFee=Number(site.delivery_fee||5000), fee=subtotal>=freeMin?0:normalFee;
  const etaPreview=previewEtaFromToday();
  $('orderPreview').innerHTML=`<b>Ringkasan</b><br>${data.map(x=>`${x.quantity}× ${esc(x.product.name)}`).join('<br>')}<hr><b>Total perkiraan: ${rupiah(subtotal+fee)}</b> <span class="muted">(ongkir ${fee?rupiah(fee):'gratis'})</span><div class="checkout-eta">🚚 Estimasi awal tiba: <b>${formatEtaRange(etaPreview.start,etaPreview.end)}</b><small>Untuk transfer bank, estimasi diperbarui otomatis setelah pembayaran diverifikasi.</small></div>`;
  $('checkoutForm').reset();
  renderPaymentOptions();
  $('checkoutForm').style.display='grid'; $('checkoutResult').innerHTML=''; openModal('checkoutModal');
};
$('checkoutForm').onsubmit=async e=>{
  e.preventDefault(); const f=new FormData(e.target), payload=Object.fromEntries(f.entries()); payload.items=cartData().map(x=>({product_id:x.product.id,quantity:x.quantity})); payload.chat_token=getChatToken();
  if(payload.payment_method==='COD' && !codAllowedForCity(payload.city)){toast(`COD hanya tersedia untuk ${site.store_city||'Palembang'}.`);return;}
  const btn=e.target.querySelector('button[type=submit]'); btn.disabled=true; btn.textContent='Memproses…';
  try{
    const j=await api('/api/orders',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    cart={}; saveCart(); e.target.style.display='none';
    const payBlock=j.requires_payment_proof?`
      <div class="payment-card payment-after-order">
        ${paymentInfoHtml(j.payment_method)}
        <label>Bukti pembayaran<input id="checkoutProof" type="file" accept="image/*"></label>
        <button class="btn btn-primary full" type="button" onclick="uploadCheckoutProof(${j.order_id},'${esc(payload.phone).replace(/'/g,"&#39;")}')">Unggah Bukti Pembayaran</button>
        <small>Pesanan belum diproses sampai pembayaran diverifikasi admin.</small>
      </div>`:`<div class="payment-card"><b>COD dipilih.</b><small>Pesanan dapat diproses dan pembayaran dilakukan saat barang diterima.</small></div>`;
    const etaNote='Estimasi akan dihitung ulang dari tanggal saat pesanan benar-benar dikirim.';
    const deadline=j.requires_payment_proof&&j.payment_due_at?`<div class="payment-deadline">⏰ Bayar sebelum <b>${formatDateTimeId(j.payment_due_at)}</b>. Jika belum dibayar selama 24 jam, pesanan dibatalkan otomatis.</div>`:'';
    $('checkoutResult').innerHTML=`<div class="success-box"><b>Pesanan berhasil dibuat!</b><p>No. pesanan <b>#${j.order_id}</b><br>Total: <b>${rupiah(j.total)}</b><br>Status: ${esc(j.status)}<br>Pembayaran: ${esc(j.payment_status)}</p>${deadline}<div class="delivery-estimate">🚚 Perkiraan awal tiba: <b>${formatEtaRange(j.eta_start,j.eta_end)}</b><small>${etaNote}</small></div>${payBlock}<div class="checkout-actions"><button class="btn btn-primary full" type="button" onclick="openBuyerChat()">💬 Chat Penjual</button><button class="btn btn-outline full" type="button" onclick="closeModal('checkoutModal'); fetchProducts();">Selesai</button></div></div>`;
  } catch(err){ toast(err.message); }
  finally{ btn.disabled=false; btn.textContent='Buat Pesanan'; }
};

async function uploadCheckoutProof(orderId,phone){
  const input=$('checkoutProof'); if(!input?.files?.[0]){toast('Pilih foto bukti pembayaran terlebih dahulu.');return;}
  try{
    const image=await imageToDataUrl(input.files[0],1200,1200);
    await api(`/api/orders/${orderId}/payment-proof`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({phone,image_data:image})});
    toast('Bukti pembayaran berhasil dikirim.');
    const card=input.closest('.payment-card'); if(card) card.innerHTML='<b>Bukti pembayaran terkirim.</b><small>Status sekarang menunggu verifikasi admin.</small>';
  }catch(err){toast(err.message);}
}

$('aiAsk').onclick=async()=>{
  $('aiResult').innerHTML='Menganalisis kebutuhan…';
  try{
    const j=await api('/api/ai/recommend',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({text:$('aiText').value.trim(),household:$('household').value})});
    $('aiResult').innerHTML=`<b>Rekomendasi:</b> ${j.recommendations.map(esc).join(', ')}<br><br>${j.explanation.map(x=>'• '+esc(x)).join('<br>')}<br><br><small><b>Catatan etika:</b> ${esc(j.ethics_note)}</small>`;
  }catch(err){ $('aiResult').textContent=err.message; }
};
$('trackBtn').onclick=async()=>{ await trackOrders(); };
async function trackOrders(){
  const phone=$('trackPhone').value.trim(); if(!phone){toast('Masukkan nomor telepon.');return;} $('trackResult').innerHTML='Mencari pesanan…';
  try{
    const j=await api('/api/orders/track?phone='+encodeURIComponent(phone));
    if(!j.length){$('trackResult').innerHTML='<span class="muted">Belum ada pesanan untuk nomor tersebut.</span>';return;}
    $('trackResult').innerHTML=j.map(o=>{
      const needProof=o.payment_method!=='COD' && !['Sudah dibayar'].includes(o.payment_status) && o.status!=='Dibatalkan';
      return `<div class="track-order">
        <div><b>Pesanan #${o.id}</b> <span class="status-badge">${esc(displayOrderStatus(o))}</span></div>
        <div class="muted">${esc(o.created_at)} • ${rupiah(o.total)}</div>
        <div class="payment-status-row">Metode: <b>${esc(o.payment_method)}</b> • Pembayaran: <b>${esc(o.payment_status||'-')}</b></div>
        ${paymentDeadlineHtml(o)}
        ${deliveryEstimateHtml(o)}
        <small>${o.items.map(i=>`${i.quantity}× ${esc(i.product_name)}`).join(', ')}</small>
        ${needProof?`<div class="proof-upload"><div>${paymentInfoHtml(o.payment_method)}</div><label>Unggah / ganti bukti pembayaran<input id="proof-${o.id}" type="file" accept="image/*"></label><button class="btn btn-light" type="button" onclick="uploadTrackedProof(${o.id})">Kirim Bukti Pembayaran</button></div>`:''}
        ${o.status==='Dalam pengantaran'?`<button class="btn btn-primary chat-order-btn" type="button" onclick="confirmOrderReceived(${o.id})">✅ Pesanan Diterima</button>`:''}
        <button class="btn btn-outline chat-order-btn" type="button" onclick="openBuyerChat()">💬 Chat Penjual</button>
      </div>`;
    }).join('');
  }catch(err){ $('trackResult').textContent=err.message; }
}
async function uploadTrackedProof(orderId){
  const phone=$('trackPhone').value.trim(), input=$(`proof-${orderId}`);
  if(!input?.files?.[0]){toast('Pilih foto bukti pembayaran terlebih dahulu.');return;}
  try{
    const image=await imageToDataUrl(input.files[0],1200,1200);
    await api(`/api/orders/${orderId}/payment-proof`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({phone,image_data:image})});
    toast('Bukti pembayaran berhasil dikirim.'); await trackOrders();
  }catch(err){toast(err.message);}
}


async function confirmOrderReceived(orderId){
  const phone=$('trackPhone').value.trim();
  if(!phone){toast('Masukkan nomor telepon untuk mengonfirmasi pesanan.');return;}
  if(!confirm('Konfirmasi bahwa pesanan #'+orderId+' sudah diterima?')) return;
  try{
    await api(`/api/orders/${orderId}/received`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({phone})});
    toast('Pesanan dikonfirmasi sudah diterima.');
    await trackOrders();
  }catch(err){toast(err.message);}
}


// ---------- Chat langsung Penjual & Pembeli ----------
function stopChatPolling(){
  if(chatPollTimer){ clearInterval(chatPollTimer); chatPollTimer=null; }
}
async function openBuyerChat(){
  const visitorToken=getChatToken();
  try{
    const session=await api('/api/chat/session',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({visitor_token:visitorToken})});
    chatContext={mode:'buyer',sessionId:Number(session.session_id),visitorToken};
    $('chatModalTitle').textContent='Chat Penjual';
    $('chatOrderMeta').innerHTML=`Terhubung langsung dengan <b>${esc(session.seller_name||site.store_name||'Hendra')}</b>${session.last_order_id?` • Pesanan terbaru <b>#${session.last_order_id}</b>`:''}`;
    $('chatMessages').innerHTML='<div class="muted">Memuat chat…</div>';
    $('chatMessageInput').value='';
    openModal('chatModal');
    stopChatPolling();
    await loadChat(false);
    chatPollTimer=setInterval(()=>loadChat(true),4000);
  }catch(err){toast(err.message);}
}
async function openAdminChatSession(sessionId,customerName){
  chatContext={mode:'seller',sessionId:Number(sessionId),customerName:String(customerName||'Pengunjung')};
  $('chatModalTitle').textContent=`Chat dengan ${chatContext.customerName}`;
  $('chatOrderMeta').innerHTML='Mode Penjual';
  $('chatMessages').innerHTML='<div class="muted">Memuat chat…</div>';
  $('chatMessageInput').value='';
  openModal('chatModal');
  stopChatPolling();
  await loadChat(false);
  chatPollTimer=setInterval(()=>loadChat(true),4000);
}
async function loadChat(silent=false){
  if(!chatContext) return;
  try{
    let j;
    if(chatContext.mode==='seller'){
      j=await api(`/api/admin/chats/${chatContext.sessionId}`,{headers:{'X-Admin-Pin':adminPin}});
      const who=j.customer_name||`Pengunjung #${j.id}`;
      $('chatOrderMeta').innerHTML=`${esc(who)}${j.last_order_id?` • Pesanan terbaru <b>#${j.last_order_id}</b>`:''} • Mode Penjual`;
    }else{
      j=await api('/api/chat?visitor_token='+encodeURIComponent(chatContext.visitorToken));
      $('chatOrderMeta').innerHTML=`Terhubung langsung dengan <b>${esc(j.seller_name||site.store_name||'Hendra')}</b>${j.last_order_id?` • Pesanan terbaru <b>#${j.last_order_id}</b>`:''}`;
    }
    renderChatMessages(j.messages||[]);
  }catch(err){
    if(!silent){$('chatMessages').innerHTML=`<div class="muted">${esc(err.message)}</div>`;toast(err.message);}
  }
}
function renderChatMessages(messages){
  const box=$('chatMessages');
  const nearBottom=box.scrollHeight-box.scrollTop-box.clientHeight<80;
  if(!messages.length){box.innerHTML='<div class="chat-empty">Belum ada pesan. Mulai percakapan dengan penjual.</div>';return;}
  box.innerHTML=messages.map(m=>{
    const mine=chatContext?.mode==='seller'?m.sender==='seller':m.sender==='buyer';
    const label=m.sender==='seller'?'Penjual':'Pembeli';
    return `<div class="chat-row ${mine?'mine':'theirs'}"><div class="chat-bubble"><span class="chat-sender">${label}</span><div>${esc(m.message)}</div><small>${esc(m.created_at)}</small></div></div>`;
  }).join('');
  if(nearBottom||messages.length<4) box.scrollTop=box.scrollHeight;
}
$('chatForm').onsubmit=async e=>{
  e.preventDefault();
  if(!chatContext){toast('Buka percakapan terlebih dahulu.');return;}
  const input=$('chatMessageInput'), message=input.value.trim();
  if(!message)return;
  const btn=e.target.querySelector('button[type=submit]');btn.disabled=true;
  try{
    if(chatContext.mode==='seller'){
      await api(`/api/admin/chats/${chatContext.sessionId}`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pin:adminPin,message})});
    }else{
      await api('/api/chat',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({visitor_token:chatContext.visitorToken,message})});
    }
    input.value='';await loadChat(true);input.focus();
  }catch(err){toast(err.message);}
  finally{btn.disabled=false;}
};
$('openBuyerChat').onclick=()=>openBuyerChat();

// ---------- Admin ----------
$('adminOpen').onclick=()=>openModal('adminModal');
$('adminLogin').onclick=async()=>{
  adminPin=$('adminPin').value;
  try{
    await api('/api/admin/login',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({pin:adminPin})});
    $('adminLoginBox').classList.add('hidden'); $('adminPanel').classList.remove('hidden'); loadAdmin('site');
  } catch(err){ toast('PIN admin salah.'); }
};
document.querySelectorAll('[data-admin-tab]').forEach(b=>b.onclick=()=>{
  document.querySelectorAll('[data-admin-tab]').forEach(x=>x.classList.remove('active')); b.classList.add('active'); loadAdmin(b.dataset.adminTab);
});

async function loadAdmin(tab){
  if(tab==='site') renderSiteAdmin();
  else if(tab==='products') await renderProductsAdmin();
  else if(tab==='payments') renderPaymentsAdmin();
  else if(tab==='orders') await renderOrdersAdmin();
  else if(tab==='chats') await renderChatsAdmin();
}
function frow(label,key,type='text',extra=''){
  const v=site[key]??'';
  if(type==='textarea') return `<label class="span-2">${label}<textarea data-site-key="${key}" rows="3">${esc(v)}</textarea></label>`;
  if(type==='color') return `<label>${label}<input data-site-key="${key}" type="color" value="${esc(v)}"></label>`;
  if(type==='number') return `<label>${label}<input data-site-key="${key}" type="number" min="0" value="${esc(v)}"></label>`;
  return `<label>${label}<input data-site-key="${key}" ${extra} value="${esc(v)}"></label>`;
}
function renderSiteAdmin(){
  $('adminContent').innerHTML=`
    <form id="siteForm" class="admin-site-form form-grid">
      <div class="span-2 admin-section-title"><h3>Identitas & Kontak</h3><p>Ubah nama toko, kontak, alamat, logo, dan warna.</p></div>
      ${frow('Nama toko','store_name')} ${frow('Subjudul toko','store_subtitle')}
      ${frow('Nomor WhatsApp/Telepon','phone')} ${frow('Alamat toko','address')}
      ${frow('Kota toko / asal pengiriman','store_city')} <div></div>
      ${frow('Warna utama','primary_color','color')} ${frow('Warna aksen','accent_color','color')}
      <label class="span-2">Logo toko<input id="logoFile" type="file" accept="image/*"><small class="muted">Opsional. Foto diperkecil otomatis.</small></label>
      <div class="span-2 image-preview-wrap"><img id="logoPreview" class="image-preview ${site.logo_image_data?'':'hidden'}" src="${site.logo_image_data||''}" alt="Logo"><button type="button" class="btn btn-light" id="removeLogo">Hapus Logo</button></div>

      <div class="span-2 admin-section-title"><h3>Beranda</h3></div>
      ${frow('Bar pengumuman','announcement')} ${frow('Badge hero','hero_badge')}
      ${frow('Judul hero','hero_title')} ${frow('Teks sorotan','hero_highlight')}
      ${frow('Deskripsi hero','hero_text','textarea')}
      ${frow('Tombol utama','hero_primary_button')} ${frow('Tombol kedua','hero_secondary_button')}
      <label class="span-2">Gambar utama/banner<input id="heroFile" type="file" accept="image/*"><small class="muted">Opsional. Akan tampil di sisi kanan beranda.</small></label>
      <div class="span-2 image-preview-wrap"><img id="heroPreview" class="image-preview wide ${site.hero_image_data?'':'hidden'}" src="${site.hero_image_data||''}" alt="Banner"><button type="button" class="btn btn-light" id="removeHero">Hapus Gambar</button></div>

      <div class="span-2 admin-section-title"><h3>Teks Halaman</h3></div>
      ${frow('Judul katalog','catalog_title')} ${frow('Deskripsi katalog','catalog_text','textarea')}
      ${frow('Judul AI','ai_title')} ${frow('Deskripsi AI','ai_text','textarea')}
      ${frow('Judul tracking','tracking_title')} ${frow('Deskripsi tracking','tracking_text','textarea')}
      ${frow('Judul tentang','about_title')} ${frow('Deskripsi tentang','about_text','textarea')}
      ${frow('Teks footer','footer_text','textarea')}

      <div class="span-2 admin-section-title"><h3>Pengantaran & Estimasi Otomatis</h3><p>Rentang estimasi dapat ditulis bebas, misalnya <b>2-3</b> atau <b>3-5</b> hari.</p></div>
      ${frow('Biaya ongkir','delivery_fee','number')} ${frow('Gratis ongkir mulai','free_delivery_minimum','number')}
      <label class="span-2">Rentang estimasi tiba (hari)<input id="deliveryEtaRange" type="text" inputmode="numeric" value="${esc(String(site.delivery_eta_min_days||1))}-${esc(String(site.delivery_eta_max_days||2))}" placeholder="Contoh: 2-3 atau 3-5"><small class="muted">Gunakan format angka-angka. Maksimal 30 hari.</small></label>
      <div class="span-2 admin-section-title"><h3>Alur Status Pesanan</h3><p>Status mengikuti kejadian nyata: <b>Pesanan diterima → Sedang diproses → Diserahkan ke kurir → Dalam pengantaran ke kota pembeli → Selesai</b>. Nama kota diambil otomatis dari Kota/Kabupaten saat checkout. Transfer yang belum dibayar selama <b>24 jam</b> dibatalkan otomatis; COD dibayar saat barang tiba.</p></div>
      <div class="span-2 admin-section-title"><h3>Chat Pelanggan</h3><p>Pesan otomatis pertama yang tampil saat pembeli membuka chat langsung dengan penjual.</p></div>
      ${frow('Pesan sambutan chat','chat_welcome_message','textarea')}
      <button class="btn btn-primary full span-2" type="submit">Simpan Semua Perubahan Website</button>
      <button class="btn btn-outline full span-2" type="button" id="downloadProjectBtn">⬇ Download ZIP Hasil Edit</button>
      <small class="muted span-2">ZIP hasil edit menyertakan database, gambar, produk, dan perubahan konten saat ini.</small>
    </form>`;

  let logoData=site.logo_image_data||'', heroData=site.hero_image_data||'';
  $('logoFile').onchange=async e=>{ if(e.target.files[0]){ logoData=await imageToDataUrl(e.target.files[0],500,500); $('logoPreview').src=logoData; $('logoPreview').classList.remove('hidden'); } };
  $('heroFile').onchange=async e=>{ if(e.target.files[0]){ heroData=await imageToDataUrl(e.target.files[0],1400,900); $('heroPreview').src=heroData; $('heroPreview').classList.remove('hidden'); } };
  $('removeLogo').onclick=()=>{ logoData=''; $('logoPreview').classList.add('hidden'); $('logoPreview').removeAttribute('src'); };
  $('removeHero').onclick=()=>{ heroData=''; $('heroPreview').classList.add('hidden'); $('heroPreview').removeAttribute('src'); };
  $('downloadProjectBtn').onclick=downloadEditedProject;
  $('siteForm').onsubmit=async e=>{
    e.preventDefault(); const payload={pin:adminPin,logo_image_data:logoData,hero_image_data:heroData};
    e.target.querySelectorAll('[data-site-key]').forEach(el=>payload[el.dataset.siteKey]=el.value);
    const etaText=($('deliveryEtaRange')?.value||'').trim();
    const etaMatch=etaText.match(/^(\d+)\s*-\s*(\d+)$/);
    if(!etaMatch){ toast('Rentang estimasi harus seperti 2-3 atau 3-5.'); return; }
    const etaMin=Number(etaMatch[1]), etaMax=Number(etaMatch[2]);
    if(etaMin<0 || etaMax<etaMin || etaMax>30){ toast('Rentang estimasi tidak valid. Contoh: 2-3 atau 3-5, maksimal 30 hari.'); return; }
    payload.delivery_eta_min_days=String(etaMin); payload.delivery_eta_max_days=String(etaMax);
    const btn=e.target.querySelector('button[type=submit]'); btn.disabled=true; btn.textContent='Menyimpan…';
    try{ const j=await api('/api/admin/site',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); site=j.settings; applySite(); toast('Website berhasil diperbarui.'); }
    catch(err){ toast(err.message); }
    finally{ btn.disabled=false; btn.textContent='Simpan Semua Perubahan Website'; }
  };
}

function renderPaymentsAdmin(){
  $('adminContent').innerHTML=`
    <form id="paymentSettingsForm" class="admin-site-form form-grid">
      <div class="span-2 admin-section-title"><h3>Metode Pembayaran</h3><p>Metode pembayaran utama selalu aktif: Transfer Bank Mandiri dan COD khusus Palembang.</p></div>
      <div class="span-2 payment-admin-note">✅ <b>Transfer Bank Mandiri</b> aktif untuk semua kota.<br>✅ <b>COD</b> aktif hanya jika Kota/Kabupaten pembeli adalah <b>${esc(site.store_city||'Palembang')}</b>.</div>
      ${frow('Nama bank','bank_name')} ${frow('Nomor rekening','bank_account_number')}
      <label class="span-2">Catatan pembayaran<textarea id="paymentNote" rows="3">${esc(site.payment_note||'')}</textarea></label>
      <button class="btn btn-primary full span-2" type="submit">Simpan Pengaturan Pembayaran</button>
    </form>`;
  $('paymentSettingsForm').onsubmit=async e=>{
    e.preventDefault();
    const payload={
      pin:adminPin,
      transfer_enabled:'1',
      cod_enabled:'1',
      bank_name:e.target.querySelector('[data-site-key="bank_name"]').value || 'Bank Mandiri',
      bank_account_number:e.target.querySelector('[data-site-key="bank_account_number"]').value || '1130020365627',
      payment_note:$('paymentNote').value
    };
    const btn=e.target.querySelector('button[type=submit]');btn.disabled=true;btn.textContent='Menyimpan…';
    try{
      const j=await api('/api/admin/site',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
      site=j.settings;applySite();toast('Pengaturan pembayaran berhasil disimpan.');
    }catch(err){toast(err.message);}
    finally{btn.disabled=false;btn.textContent='Simpan Pengaturan Pembayaran';}
  };
}

async function renderProductsAdmin(){
  const list=await api('/api/admin/products',{headers:{'X-Admin-Pin':adminPin}});
  $('adminContent').innerHTML=`<div class="admin-toolbar"><div><h3>Kelola Produk</h3><p class="muted">Tambah, edit, hapus, ganti gambar, harga, dan stok.</p></div><button class="btn btn-primary" onclick="openProductEditor()">+ Tambah Produk</button></div>
  <div class="admin-product-list">${list.map(p=>`<div class="admin-product-row"><div class="admin-product-thumb">${p.image_data?`<img src="${p.image_data}" alt="">`:esc(p.emoji||'📦')}</div><div class="admin-product-info"><b>${esc(p.name)}</b><span>${esc(p.category)} • ${rupiah(p.price)} • Stok ${p.stock} ${esc(p.unit)}</span></div><div class="admin-product-actions"><button class="btn btn-light" onclick="openProductEditor(${p.id})">Edit</button><button class="btn btn-danger" onclick="deleteProduct(${p.id},'${esc(p.name).replace(/'/g,"&#39;")}')">Hapus</button></div></div>`).join('')||'<p class="muted">Belum ada produk.</p>'}</div>`;
}
async function renderOrdersAdmin(){
  const j=await api('/api/admin/orders',{headers:{'X-Admin-Pin':adminPin}});
  const paymentStatuses=['Belum dibayar','Menunggu verifikasi','Sudah dibayar','Ditolak'];
  const actions=o=>{
    if(o.status==='Selesai' || o.status==='Dibatalkan') return '<span class="muted">Tidak ada aksi</span>';
    const paidOrCod=o.payment_method==='COD' || o.payment_status==='Sudah dibayar';
    const buttons=[];
    if(paidOrCod && o.status==='Pesanan diterima') buttons.push(`<button class="btn btn-light" onclick="advanceOrder(${o.id},'process')">Mulai Proses</button>`);
    if(paidOrCod && o.status==='Diproses') buttons.push(`<button class="btn btn-primary" onclick="advanceOrder(${o.id},'handover')">Serahkan ke Kurir</button>`);
    if(paidOrCod && o.status==='Diserahkan ke kurir') buttons.push(`<button class="btn btn-primary" onclick="advanceOrder(${o.id},'transit')">Mulai Pengantaran</button>`);
    if(o.status==='Dalam pengantaran') buttons.push(`<button class="btn btn-light" onclick="advanceOrder(${o.id},'complete')">Tandai Selesai</button>`);
    buttons.push(`<button class="btn btn-danger" onclick="cancelOrder(${o.id})">Batalkan</button>`);
    return buttons.join('<br>');
  };
  $('adminContent').innerHTML=j.length?`<div class="auto-status-info">📦 <b>Status mengikuti kejadian nyata.</b> Pesanan diproses, diserahkan ke kurir, lalu masuk pengantaran ke kota pembeli. Tidak ada timer status. Transfer belum dibayar 24 jam dibatalkan otomatis; COD dibayar saat barang diterima.</div><div style="overflow:auto"><table class="admin-table payment-orders"><thead><tr><th>ID</th><th>Pelanggan</th><th>Total</th><th>Metode</th><th>Pembayaran</th><th>Estimasi</th><th>Status Pesanan</th><th>Bukti</th><th>Aksi</th></tr></thead><tbody>${j.map(o=>`<tr>
    <td>#${o.id}<br><small>${esc(o.created_at||'')}</small></td>
    <td>${esc(o.customer_name)}<br><small>${esc(o.phone)}</small><br><small>${esc(o.address)}</small>${o.city?`<br><small>📍 ${esc(o.city)}</small>`:''}</td>
    <td>${rupiah(o.total)}</td>
    <td>${esc(o.payment_method||'-')}</td>
    <td>${o.payment_method==='COD'?`<b>${esc(o.payment_status||'Bayar di tempat')}</b>`:(o.status==='Dibatalkan'?`<b>${esc(o.payment_status||'Belum dibayar')}</b>`:`<select id="paystatus-${o.id}">${paymentStatuses.map(x=>`<option ${x===(o.payment_status||'Belum dibayar')?'selected':''}>${x}</option>`).join('')}</select><br><button class="btn btn-light" style="margin-top:6px" onclick="savePayment(${o.id})">Simpan Pembayaran</button>`)}${paymentDeadlineHtml(o)}</td>
    <td><b>${formatEtaRange(o.eta_start,o.eta_end)}</b>${o.shipped_at?`<br><small>📦 Diserahkan ke kurir ${formatDateTimeId(o.shipped_at)}</small>`:''}${o.delivered_at?`<br><small>✅ Tiba ${formatDateTimeId(o.delivered_at)}</small>`:''}</td>
    <td><span class="status-badge">${esc(displayOrderStatus(o))}</span><br><small class="muted">Berubah saat ada kejadian nyata</small></td>
    <td>${o.payment_proof?`<a href="${o.payment_proof}" target="_blank" rel="noopener"><img class="proof-thumb" src="${o.payment_proof}" alt="Bukti pembayaran"></a>`:'<span class="muted">Belum ada</span>'}</td>
    <td class="order-actions">${actions(o)}</td>
  </tr>`).join('')}</tbody></table></div>`:'<p class="muted">Belum ada pesanan.</p>';
}


async function renderChatsAdmin(){
  const chats=await api('/api/admin/chats',{headers:{'X-Admin-Pin':adminPin}});
  $('adminContent').innerHTML=`<div class="admin-toolbar"><div><h3>Chat Pembeli</h3><p class="muted">Percakapan langsung seperti chat toko. Pembeli tidak perlu memasukkan nomor telepon atau nomor pesanan.</p></div></div>
  <div class="admin-chat-list">${chats.length?chats.map(c=>{
    const name=c.customer_name||`Pengunjung #${c.id}`;
    return `<button class="admin-chat-thread" type="button" onclick="openAdminChatSession(${c.id},'${esc(name).replace(/'/g,"&#39;")}')"><div><b>${esc(name)}</b>${c.last_order_id?`<span>Pesanan terbaru #${c.last_order_id}</span>`:''}</div><p>${esc(c.last_message||'Belum ada pesan')}</p><small>${esc(c.updated_at||'')} • ${c.message_count} pesan</small></button>`;
  }).join(''):'<p class="muted">Belum ada percakapan.</p>'}</div>`;
}

async function savePayment(id){
  try{
    const el=$('paystatus-'+id); if(!el) return;
    await api('/api/admin/orders/'+id,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({pin:adminPin,payment_status:el.value})});
    toast('Status pembayaran disimpan.');
    await renderOrdersAdmin();
  } catch(err){ toast(err.message); }
}
async function advanceOrder(id,action){
  const labels={process:'mulai diproses',handover:'diserahkan ke kurir',transit:'mulai dalam pengantaran',complete:'diselesaikan'};
  if(!confirm(`Ubah pesanan #${id} menjadi ${labels[action]||action}?`)) return;
  try{
    await api('/api/admin/orders/'+id,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({pin:adminPin,action})});
    toast('Status pesanan berhasil diperbarui.'); await renderOrdersAdmin();
  }catch(err){toast(err.message);}
}
async function cancelOrder(id){
  if(!confirm('Batalkan pesanan #'+id+'? Stok produk akan dikembalikan.')) return;
  try{
    await api('/api/admin/orders/'+id,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({pin:adminPin,action:'cancel'})});
    toast('Pesanan dibatalkan.'); await renderOrdersAdmin();
  }catch(err){toast(err.message);}
}



function openProductEditor(id){
  const p=id?products.find(x=>x.id===id):null;
  $('productModalTitle').textContent=p?'Edit Produk':'Tambah Produk'; $('productId').value=p?.id||'';
  $('productName').value=p?.name||''; $('productCategory').value=p?.category||''; $('productPrice').value=p?.price??''; $('productStock').value=p?.stock??''; $('productUnit').value=p?.unit||''; $('productEmoji').value=p?.emoji||'📦'; $('productDescription').value=p?.description||'';
  productImageData=p?.image_data||''; if(productImageData){$('productImagePreview').src=productImageData;$('productImagePreview').classList.remove('hidden');}else{$('productImagePreview').classList.add('hidden');$('productImagePreview').removeAttribute('src');}
  $('productImageFile').value=''; openModal('productModal');
}
$('productImageFile').onchange=async e=>{ if(e.target.files[0]){ productImageData=await imageToDataUrl(e.target.files[0],900,900); $('productImagePreview').src=productImageData; $('productImagePreview').classList.remove('hidden'); } };
$('removeProductImage').onclick=()=>{ productImageData=''; $('productImagePreview').classList.add('hidden'); $('productImagePreview').removeAttribute('src'); };
$('productForm').onsubmit=async e=>{
  e.preventDefault(); const id=$('productId').value;
  const payload={pin:adminPin,name:$('productName').value,category:$('productCategory').value,price:Number($('productPrice').value),stock:Number($('productStock').value),unit:$('productUnit').value,emoji:$('productEmoji').value||'📦',description:$('productDescription').value,image_data:productImageData};
  const btn=e.target.querySelector('button[type=submit]'); btn.disabled=true; btn.textContent='Menyimpan…';
  try{
    await api(id?'/api/admin/products/'+id:'/api/admin/products',{method:id?'PATCH':'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    await fetchProducts(); closeModal('productModal'); await renderProductsAdmin(); toast('Produk berhasil disimpan.');
  }catch(err){toast(err.message);}finally{btn.disabled=false;btn.textContent='Simpan Produk';}
};
async function deleteProduct(id,name){
  if(!confirm(`Hapus produk "${name}" dari katalog?`)) return;
  try{ await api('/api/admin/products/'+id,{method:'DELETE',headers:{'Content-Type':'application/json'},body:JSON.stringify({pin:adminPin})}); delete cart[id]; saveCart(); await fetchProducts(); await renderProductsAdmin(); toast('Produk dihapus dari katalog.'); }
  catch(err){toast(err.message);}
}

async function downloadEditedProject(){
  const btn=$('downloadProjectBtn'); btn.disabled=true; btn.textContent='Menyiapkan ZIP…';
  try{
    const r=await fetch('/api/admin/export',{headers:{'X-Admin-Pin':adminPin}});
    if(!r.ok){let j={};try{j=await r.json()}catch(e){};throw new Error(j.error||'Gagal membuat ZIP.');}
    const blob=await r.blob(), url=URL.createObjectURL(blob), a=document.createElement('a');
    a.href=url; a.download='Air_Minum_Gas_Hendra_Hasil_Edit.zip'; document.body.appendChild(a); a.click(); a.remove(); setTimeout(()=>URL.revokeObjectURL(url),2000); toast('ZIP hasil edit berhasil dibuat.');
  }catch(err){toast(err.message);}finally{btn.disabled=false;btn.textContent='⬇ Download ZIP Hasil Edit';}
}

function imageToDataUrl(file,maxW=1200,maxH=1200){
  return new Promise((resolve,reject)=>{
    const reader=new FileReader(); reader.onerror=()=>reject(new Error('Gagal membaca gambar.')); reader.onload=()=>{
      const img=new Image(); img.onerror=()=>reject(new Error('File gambar tidak valid.')); img.onload=()=>{
        let w=img.width,h=img.height,scale=Math.min(1,maxW/w,maxH/h); w=Math.max(1,Math.round(w*scale)); h=Math.max(1,Math.round(h*scale));
        const canvas=document.createElement('canvas'); canvas.width=w; canvas.height=h; canvas.getContext('2d').drawImage(img,0,0,w,h);
        resolve(canvas.toDataURL('image/jpeg',0.82));
      }; img.src=reader.result;
    }; reader.readAsDataURL(file);
  });
}

Promise.all([loadSite(),fetchProducts()]).catch(err=>{ console.error(err); $('productGrid').innerHTML='<div class="muted">Gagal memuat website. Pastikan server berjalan.</div>'; });
