/**
 * Frontend-only configuration. In PHP integration, inject this object BEFORE api.js.
 * mode: 'demo' uses localStorage only; 'api' makes real HTTP requests.
 * NEVER inject admin/service credentials into a browser bundle.
 */
window.DISPATCH_CONFIG = Object.assign({
  mode: 'demo',
  apiBase: '/api/v1',
  csrfToken: null,
  token: null,
  currentUser: null,
  maxAudioBytes: 15 * 1024 * 1024
}, window.DISPATCH_CONFIG || {});
