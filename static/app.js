const rupiah = n => new Intl.NumberFormat('id-ID',{style:'currency',currency:'IDR',maximumFractionDigits:0}).format(Number(n||0));
let products = [];
let site = {};
let cart = JSON.parse(localStorage.getItem('hendra_cart') || '{}');
let activeCategory = 'Semua';
let adminPin = '';
let productImageData = '';

const $ = id => document.getElementById(id);
const esc = s => String(s ?? '').replace(/[&<>'"]/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[c]));
const toast = msg => { const el=$('toast'); el.textContent=msg; el.classList.add('show'); setTimeout(()=>el.classList.remove('show'),2400); };

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

  const logo = site.logo_image_data || '';
  if(logo){
    $('brandMark').innerHTML = `<img src="${logo}" alt="Logo">`;
    $('footerMark').innerHTML = `<img src="${logo}" alt="Logo">`;
  }
  const heroImg = site.hero_image_data || '';
  if(heroImg){ $('heroImage').src=heroImg; $('heroImage').classList.remove('hidden'); $('storeCard').classList.add('with-hero-image'); }
  else { $('heroImage').removeAttribute('src'); $('heroImage').classList.add('hidden'); $('storeCard').classList.remove('with-hero-image'); }
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
document.querySelectorAll('[data-close]').forEach(b=>b.onclick=()=>closeModal(b.dataset.close));

$('checkoutBtn').onclick=()=>{
  const data=cartData(); if(!data.length){toast('Keranjang masih kosong.');return;}
  closeDrawer(); const subtotal=data.reduce((s,x)=>s+x.product.price*x.quantity,0);
  const freeMin=Number(site.free_delivery_minimum||100000), normalFee=Number(site.delivery_fee||5000), fee=subtotal>=freeMin?0:normalFee;
  $('orderPreview').innerHTML=`<b>Ringkasan</b><br>${data.map(x=>`${x.quantity}× ${esc(x.product.name)}`).join('<br>')}<hr><b>Total perkiraan: ${rupiah(subtotal+fee)}</b> <span class="muted">(ongkir ${fee?rupiah(fee):'gratis'})</span>`;
  $('checkoutForm').style.display='grid'; $('checkoutResult').innerHTML=''; openModal('checkoutModal');
};
$('checkoutForm').onsubmit=async e=>{
  e.preventDefault(); const f=new FormData(e.target), payload=Object.fromEntries(f.entries()); payload.items=cartData().map(x=>({product_id:x.product.id,quantity:x.quantity}));
  const btn=e.target.querySelector('button[type=submit]'); btn.disabled=true; btn.textContent='Memproses…';
  try{
    const j=await api('/api/orders',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)});
    cart={}; saveCart(); e.target.style.display='none';
    $('checkoutResult').innerHTML=`<div class="success-box"><b>Pesanan berhasil dibuat!</b><p>No. pesanan <b>#${j.order_id}</b><br>Total: <b>${rupiah(j.total)}</b><br>Status: ${esc(j.status)}</p><button class="btn btn-primary" onclick="closeModal('checkoutModal'); fetchProducts();">Selesai</button></div>`;
  } catch(err){ toast(err.message); }
  finally{ btn.disabled=false; btn.textContent='Buat Pesanan'; }
};

$('aiAsk').onclick=async()=>{
  $('aiResult').innerHTML='Menganalisis kebutuhan…';
  try{
    const j=await api('/api/ai/recommend',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({text:$('aiText').value.trim(),household:$('household').value})});
    $('aiResult').innerHTML=`<b>Rekomendasi:</b> ${j.recommendations.map(esc).join(', ')}<br><br>${j.explanation.map(x=>'• '+esc(x)).join('<br>')}<br><br><small><b>Catatan etika:</b> ${esc(j.ethics_note)}</small>`;
  }catch(err){ $('aiResult').textContent=err.message; }
};
$('trackBtn').onclick=async()=>{
  const phone=$('trackPhone').value.trim(); if(!phone){toast('Masukkan nomor telepon.');return;} $('trackResult').innerHTML='Mencari pesanan…';
  try{
    const j=await api('/api/orders/track?phone='+encodeURIComponent(phone));
    if(!j.length){$('trackResult').innerHTML='<span class="muted">Belum ada pesanan untuk nomor tersebut.</span>';return;}
    $('trackResult').innerHTML=j.map(o=>`<div class="track-order"><div><b>Pesanan #${o.id}</b> <span class="status-badge">${esc(o.status)}</span></div><div class="muted">${esc(o.created_at)} • ${rupiah(o.total)}</div><small>${o.items.map(i=>`${i.quantity}× ${esc(i.product_name)}`).join(', ')}</small></div>`).join('');
  }catch(err){ $('trackResult').textContent=err.message; }
};

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
  else await renderOrdersAdmin();
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
      ${frow('Nomor WhatsApp/Telepon','phone')} ${frow('Alamat','address')}
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

      <div class="span-2 admin-section-title"><h3>Pengantaran</h3></div>
      ${frow('Biaya ongkir','delivery_fee','number')} ${frow('Gratis ongkir mulai','free_delivery_minimum','number')}
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
    const btn=e.target.querySelector('button[type=submit]'); btn.disabled=true; btn.textContent='Menyimpan…';
    try{ const j=await api('/api/admin/site',{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify(payload)}); site=j.settings; applySite(); toast('Website berhasil diperbarui.'); }
    catch(err){ toast(err.message); }
    finally{ btn.disabled=false; btn.textContent='Simpan Semua Perubahan Website'; }
  };
}

async function renderProductsAdmin(){
  const list=await api('/api/admin/products',{headers:{'X-Admin-Pin':adminPin}});
  $('adminContent').innerHTML=`<div class="admin-toolbar"><div><h3>Kelola Produk</h3><p class="muted">Tambah, edit, hapus, ganti gambar, harga, dan stok.</p></div><button class="btn btn-primary" onclick="openProductEditor()">+ Tambah Produk</button></div>
  <div class="admin-product-list">${list.map(p=>`<div class="admin-product-row"><div class="admin-product-thumb">${p.image_data?`<img src="${p.image_data}" alt="">`:esc(p.emoji||'📦')}</div><div class="admin-product-info"><b>${esc(p.name)}</b><span>${esc(p.category)} • ${rupiah(p.price)} • Stok ${p.stock} ${esc(p.unit)}</span></div><div class="admin-product-actions"><button class="btn btn-light" onclick="openProductEditor(${p.id})">Edit</button><button class="btn btn-danger" onclick="deleteProduct(${p.id},'${esc(p.name).replace(/'/g,"&#39;")}')">Hapus</button></div></div>`).join('')||'<p class="muted">Belum ada produk.</p>'}</div>`;
}
async function renderOrdersAdmin(){
  const j=await api('/api/admin/orders',{headers:{'X-Admin-Pin':adminPin}});
  $('adminContent').innerHTML=j.length?`<div style="overflow:auto"><table class="admin-table"><thead><tr><th>ID</th><th>Pelanggan</th><th>Alamat</th><th>Total</th><th>Status</th><th>Aksi</th></tr></thead><tbody>${j.map(o=>`<tr><td>#${o.id}</td><td>${esc(o.customer_name)}<br><small>${esc(o.phone)}</small></td><td>${esc(o.address)}</td><td>${rupiah(o.total)}</td><td><select id="status-${o.id}">${['Pesanan diterima','Diproses','Dalam pengantaran','Selesai','Dibatalkan'].map(s=>`<option ${s===o.status?'selected':''}>${s}</option>`).join('')}</select></td><td><button class="btn btn-light" onclick="saveStatus(${o.id})">Update</button></td></tr>`).join('')}</tbody></table></div>`:'<p class="muted">Belum ada pesanan.</p>';
}
async function saveStatus(id){
  try{ await api('/api/admin/orders/'+id,{method:'PATCH',headers:{'Content-Type':'application/json'},body:JSON.stringify({pin:adminPin,status:$('status-'+id).value})}); toast('Status pesanan diperbarui.'); }
  catch(err){ toast(err.message); }
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
