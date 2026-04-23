(function(){
  const input = document.getElementById('dateRangeInput');
  const panel = document.getElementById('rangePicker');
  const grid  = document.getElementById('rpGrid');
  const title = document.getElementById('rpTitle');
  const prev  = document.getElementById('rpPrev');
  const next  = document.getElementById('rpNext');
  const clear = document.getElementById('rpClear');
  const close = document.getElementById('rpClose');

  const fromHidden = document.getElementById('fromHidden');
  const toHidden   = document.getElementById('toHidden');

  if (!input || !panel || !grid || !title || !prev || !next || !clear || !close || !fromHidden || !toHidden) {
    return;
  }

  let start = fromHidden.value ? new Date(fromHidden.value + "T00:00:00") : null;
  let end   = toHidden.value   ? new Date(toHidden.value   + "T00:00:00") : null;
  let hover = null;
  let picking = (start && !end) ? 'end' : 'start';

  let view = start ? new Date(start) : new Date();
  view.setDate(1);

  const DOW = ['Pn','Wt','Śr','Cz','Pt','So','Nd'];
  let raf = 0;

  function fmt(d){
    const y=d.getFullYear();
    const m=String(d.getMonth()+1).padStart(2,'0');
    const da=String(d.getDate()).padStart(2,'0');
    return `${y}-${m}-${da}`;
  }

  function strip(d){
    const x=new Date(d);
    x.setHours(0,0,0,0);
    return x;
  }

  function sameDay(a,b){
    return a && b &&
      a.getFullYear()===b.getFullYear() &&
      a.getMonth()===b.getMonth() &&
      a.getDate()===b.getDate();
  }

  function clampRange(a,b){
    if(!a || !b) return [a,b];
    return a <= b ? [a,b] : [b,a];
  }

  function updateInput(){
    if(start && end){
      const [a,b]=clampRange(start,end);
      input.value = `${fmt(a)} → ${fmt(b)}`;
      fromHidden.value = fmt(a);
      toHidden.value = fmt(b);
      picking = 'start';
    } else if(start && !end){
      input.value = `${fmt(start)} → …`;
      fromHidden.value = fmt(start);
      toHidden.value = '';
      picking = 'end';
    } else {
      input.value = '';
      fromHidden.value = '';
      toHidden.value = '';
      picking = 'start';
    }
  }

  function scheduleRender(){
    if (raf) return;
    raf = requestAnimationFrame(() => {
      raf = 0;
      render();
    });
  }

  function open(){
    panel.style.display = 'block';
    if (start) {
      view = new Date(start);
      view.setDate(1);
    }
    render();
  }

  function hide(){
    panel.style.display = 'none';
    hover = null;
  }

  function render(){
    const m = view.toLocaleString('pl-PL', { month: 'long' });
    title.textContent = `${m.charAt(0).toUpperCase()+m.slice(1)} ${view.getFullYear()}`;

    grid.innerHTML = '';

    for(const d of DOW){
      const el=document.createElement('div');
      el.className='rp-dow';
      el.textContent=d;
      grid.appendChild(el);
    }

    const first = new Date(view);
    const jsDay = first.getDay();
    const offset = (jsDay + 6) % 7;
    const startCell = new Date(first);
    startCell.setDate(first.getDate() - offset);

    const [rangeA, rangeB] = (start && end)
      ? clampRange(start,end)
      : (start && hover ? clampRange(start,hover) : [null,null]);

    for(let i=0;i<42;i++){
      const d = new Date(startCell);
      d.setDate(startCell.getDate()+i);

      const el=document.createElement('div');
      el.className='rp-day';
      el.textContent=d.getDate();

      if(d.getMonth()!==view.getMonth()) el.classList.add('is-out');

      if(start && !end && hover && rangeA && rangeB){
        if(strip(d) >= strip(rangeA) && strip(d) <= strip(rangeB)) el.classList.add('is-hover');
        if(sameDay(d,start)) el.classList.remove('is-hover');
      }

      if(start && end && rangeA && rangeB){
        if(strip(d) >= strip(rangeA) && strip(d) <= strip(rangeB)) el.classList.add('is-inrange');
      }

      if(start && sameDay(d,start)) el.classList.add('is-start');
      if(end && sameDay(d,end)) el.classList.add('is-end');

      el.addEventListener('mouseenter', ()=>{
        if(start && !end){
          hover = d;
          scheduleRender();
        }
      });

      el.addEventListener('mousedown', (ev)=>{
        ev.preventDefault();
        ev.stopPropagation();

        if(picking === 'start'){
          start = d;
          end = null;
          hover = d;
          picking = 'end';
          updateInput();
          render();
          return;
        }

        end = d;
        const [a,b]=clampRange(start,end);
        start=a; end=b;
        hover=null;
        updateInput();
        render();
        hide();
      });

      grid.appendChild(el);
    }
  }

  prev.addEventListener('mousedown', (ev)=>{
    ev.preventDefault();
    ev.stopPropagation();
    view.setMonth(view.getMonth()-1);
    render();
  });

  next.addEventListener('mousedown', (ev)=>{
    ev.preventDefault();
    ev.stopPropagation();
    view.setMonth(view.getMonth()+1);
    render();
  });

  clear.addEventListener('mousedown',(ev)=>{
    ev.preventDefault();
    ev.stopPropagation();
    start=null;
    end=null;
    hover=null;
    picking='start';
    updateInput();
    render();
  });

  close.addEventListener('mousedown',(ev)=>{
    ev.preventDefault();
    ev.stopPropagation();
    hide();
  });

  input.addEventListener('mousedown', (ev)=>{
    ev.preventDefault();
    ev.stopPropagation();
    panel.style.display === 'none' ? open() : hide();
  });

  document.addEventListener('mousedown', (e)=>{
    if(panel.style.display === 'none') return;
    const isInside = panel.contains(e.target) || input.contains(e.target);
    if(!isInside) hide();
  });

  if(start && end){
    const [a,b]=clampRange(start,end);
    start=a; end=b;
  }

  updateInput();
})();
