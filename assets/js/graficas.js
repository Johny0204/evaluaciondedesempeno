(function () {
  var d = window.DATOS_GRAFICAS; if (!d || !window.Chart) { return; }
  var azul = '#1f4e79', celeste = '#2e75b6', naranja = '#ed7d31', verde = '#70ad47';
  var paleta = ['#c00000', '#ed7d31', '#ffc000', '#70ad47', '#2e75b6', '#1f4e79', '#7030a0'];
  var escala = { min: 0, max: d.max, ticks: { stepSize: 1 } };
  function crear(id, cfg) { var el = document.getElementById(id); if (el) { new Chart(el, cfg); } }
  crear('chDist', { type: 'bar', data: { labels: d.dist.labels, datasets: [{ label: 'Personas', data: d.dist.data, backgroundColor: paleta.slice(0, d.dist.labels.length) }] },
    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: { beginAtZero: true, ticks: { precision: 0 } } } } });
  if (d.cat.labels.length >= 3) {
    crear('chCat', { type: 'radar', data: { labels: d.cat.labels, datasets: [
      { label: 'Otros evaluadores', data: d.cat.otros, borderColor: azul, backgroundColor: 'rgba(31,78,121,.2)' },
      { label: 'Autoevaluación', data: d.cat.auto, borderColor: naranja, backgroundColor: 'rgba(237,125,49,.15)' }] },
      options: { maintainAspectRatio: false, scales: { r: { min: 0, max: d.max, ticks: { stepSize: 1 } } } } });
  } else {
    crear('chCat', { type: 'bar', data: { labels: d.cat.labels, datasets: [
      { label: 'Otros evaluadores', data: d.cat.otros, backgroundColor: azul }, { label: 'Autoevaluación', data: d.cat.auto, backgroundColor: naranja }] },
      options: { maintainAspectRatio: false, scales: { y: escala } } });
  }
  crear('chArea', { type: 'bar', data: { labels: d.area.labels, datasets: [{ label: 'Promedio', data: d.area.data, backgroundColor: celeste }] },
    options: { indexAxis: 'y', maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { x: escala } } });
  crear('chRel', { type: 'bar', data: { labels: d.rel.labels, datasets: [{ label: 'Promedio', data: d.rel.data, backgroundColor: [azul, naranja, verde, celeste, '#7030a0'] }] },
    options: { maintainAspectRatio: false, plugins: { legend: { display: false } }, scales: { y: escala } } });
  if (d.indiv) {
    crear('chIndiv', { type: d.indiv.labels.length >= 3 ? 'radar' : 'bar', data: { labels: d.indiv.labels, datasets: d.indiv.datasets },
      options: { maintainAspectRatio: false, scales: d.indiv.labels.length >= 3 ? { r: { min: 0, max: d.max, ticks: { stepSize: 1 } } } : { y: escala } } });
  }
})();
