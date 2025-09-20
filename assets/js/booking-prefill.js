(function(){
  var data = (window.KD_BOOKING||{});
  function fill(root){
    if(data.e){
      root.querySelectorAll('input[type="email"],input[name*="email" i]')
        .forEach(function(i){ if(!i.value){ i.value = data.e; i.dispatchEvent(new Event('input',{bubbles:true})); }});
    }
    if(data.n){
      var parts = data.n.trim().split(/\s+/), first = parts.shift()||'', last = parts.join(' ');
      var f = root.querySelectorAll('input[name*="first" i]');
      var l = root.querySelectorAll('input[name*="last" i]');
      if(f.length && l.length){
        f.forEach(function(i){ if(!i.value){ i.value = first; i.dispatchEvent(new Event('input',{bubbles:true})); }});
        l.forEach(function(i){ if(!i.value){ i.value = last;  i.dispatchEvent(new Event('input',{bubbles:true})); }});
      } else {
        root.querySelectorAll('input[name*="name" i]:not([name*="first" i]):not([name*="last" i])')
          .forEach(function(i){ if(i.type!=='email' && !i.value){ i.value = data.n; i.dispatchEvent(new Event('input',{bubbles:true})); }});
      }
    }
  }
  var obs = new MutationObserver(function(){ fill(document.body); });
  obs.observe(document.body, {childList:true, subtree:true});
  fill(document.body);
  setTimeout(function(){ try{obs.disconnect();}catch(e){} }, 8000);
})();
