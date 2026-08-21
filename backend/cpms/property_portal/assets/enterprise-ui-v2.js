(function(){
  'use strict';
  var key='cpms-enterprise-sidebar-collapsed';
  var button=document.getElementById('sidebarToggle');
  if(window.innerWidth>820 && localStorage.getItem(key)==='1') document.body.classList.add('sidebar-collapsed');
  if(button){button.addEventListener('click',function(){
    if(window.innerWidth<=820) return;
    document.body.classList.toggle('sidebar-collapsed');
    localStorage.setItem(key,document.body.classList.contains('sidebar-collapsed')?'1':'0');
  });}
})();
