
const menuBtn=document.getElementById('menuBtn'), sidebar=document.getElementById('sidebar');
if(menuBtn&&sidebar) menuBtn.addEventListener('click',()=>sidebar.classList.toggle('open'));
document.querySelectorAll('.switch').forEach(s=>s.addEventListener('click',()=>s.classList.toggle('on')));
document.querySelectorAll('.seat:not(.booked)').forEach(s=>s.addEventListener('click',()=>{s.classList.toggle('selected');updateSeatSummary()}));
function updateSeatSummary(){const el=document.querySelector('[data-seat-summary]'); if(!el)return; const seats=[...document.querySelectorAll('.seat.selected')].map(x=>x.textContent.trim()); el.textContent=seats.length?seats.join(', '):'None selected'; const price=document.querySelector('[data-seat-price]'); if(price)price.textContent='₹'+(seats.length*42)}
const toast=document.getElementById('toast');
document.querySelectorAll('[data-toast]').forEach(b=>b.addEventListener('click',(e)=>{e.preventDefault();if(toast){toast.textContent=b.dataset.toast||'Action completed';toast.classList.add('show');setTimeout(()=>toast.classList.remove('show'),1800)}}));
document.querySelectorAll('[data-tab]').forEach(t=>t.addEventListener('click',(e)=>{e.preventDefault(); const group=t.parentElement; group.querySelectorAll('.tab').forEach(x=>x.classList.remove('active')); t.classList.add('active')}));
const pwd=document.querySelector('[data-password]'),eye=document.querySelector('[data-eye]'); if(pwd&&eye)eye.addEventListener('click',()=>{pwd.type=pwd.type==='password'?'text':'password'});
