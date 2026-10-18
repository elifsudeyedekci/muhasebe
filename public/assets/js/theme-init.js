// Tema yanıp sönmesini önler (CSP nedeniyle satır içi betik yerine harici dosya).
try {
  var t = localStorage.getItem('fb_theme');
  if (t === 'light' || t === 'dark') document.documentElement.setAttribute('data-theme', t);
} catch (e) { /* depolama kapalı */ }
