document.addEventListener('DOMContentLoaded',()=>{
 const header=document.querySelector('.site-header');
 const menu=document.querySelector('.menu');
 const nav=document.querySelector('.site-header nav');
 const updateHeader=()=>header?.classList.toggle('scrolled',window.scrollY>35);
 updateHeader();
 window.addEventListener('scroll',updateHeader,{passive:true});
 menu?.addEventListener('click',()=>nav?.classList.toggle('open'));
 nav?.querySelectorAll('a').forEach(link=>link.addEventListener('click',()=>nav.classList.remove('open')));
 document.querySelectorAll('[data-filter]').forEach(btn=>btn.addEventListener('click',()=>{
  document.querySelectorAll('[data-filter]').forEach(b=>b.classList.remove('active'));
  btn.classList.add('active');
  const f=btn.dataset.filter;
  document.querySelectorAll('[data-category]').forEach(card=>card.style.display=(f==='all'||card.dataset.category===f)?'block':'none');
 }));
});