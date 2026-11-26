// Registers the service worker and shows a banner when the phone goes offline.
(() => {
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', () => navigator.serviceWorker.register('/sw.js').catch(() => {}));
  }
  const banner = document.getElementById('offline-banner');
  if (!banner) return;
  const sync = () => { banner.hidden = navigator.onLine; };
  window.addEventListener('online', sync);
  window.addEventListener('offline', sync);
  sync();
})();
