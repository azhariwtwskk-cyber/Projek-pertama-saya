(function(){
  'use strict';
  var body=document.body;
  var toggle=document.getElementById('sidebarToggle');
  var sidebar=document.getElementById('adminSidebar');
  var search=document.getElementById('cpmsGlobalSearch');
  var desktop=window.matchMedia('(min-width: 821px)');

  function syncToggle(){
    if(!toggle||!sidebar)return;
    var expanded=desktop.matches?!body.classList.contains('sidebar-collapsed'):sidebar.classList.contains('is-open');
    toggle.setAttribute('aria-expanded',expanded?'true':'false');
  }
  if(toggle&&sidebar){
    toggle.addEventListener('click',function(){
      if(desktop.matches){
        body.classList.toggle('sidebar-collapsed');
        try{localStorage.setItem('cpmsSidebarCollapsed',body.classList.contains('sidebar-collapsed')?'1':'0');}catch(e){}
      }else{sidebar.classList.toggle('is-open');}
      syncToggle();
    });
    if(desktop.matches){try{if(localStorage.getItem('cpmsSidebarCollapsed')==='1')body.classList.add('sidebar-collapsed');}catch(e){}}
    syncToggle();
  }
  if(search){
    search.addEventListener('input',function(){
      var q=this.value.toLowerCase().trim();
      var rows=document.querySelectorAll('tbody tr');
      rows.forEach(function(row){row.style.display=!q||row.textContent.toLowerCase().indexOf(q)!==-1?'':'none';});
    });
    search.addEventListener('keydown',function(e){if(e.key==='Escape'){this.value='';this.dispatchEvent(new Event('input'));this.blur();}});
  }
  window.addEventListener('resize',syncToggle);
})();
