(function (global) {
  'use strict';
  const config = global.DISPATCH_CONFIG;

  /** @param {string} path @param {RequestInit} [options] */
  async function request(path, options = {}) {
    const headers = new Headers(options.headers);
    headers.set('Accept', 'application/json');
    if (options.method === 'POST') headers.set('X-CSRF-Token', config.csrfToken);
    const response = await fetch(path, {...options, headers, credentials: 'same-origin'});
    if (response.redirected || response.status === 401) {
      location.assign('/login?next=/workspace');
      throw Error('Войдите снова');
    }
    const body = await response.json();
    if (!response.ok) throw Error(body.error || (typeof body.detail === 'string' ? body.detail : 'HTTP ' + response.status));
    return body;
  }

  /** @param {string} path @param {object} [body] */
  const post = (path, body = {}) => request(path, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify(body)});
  const api = '/workspace/api';
  const id = encodeURIComponent;
  const multipart = (path,key,file,name) => {
    const body = new FormData();
    body.append(key,file,name);
    return request(path,{method:'POST',body});
  };
  global.DispatchAPI = {
    isDemo: () => false,
    listTickets: () => request(api+'/tickets'),
    listUsers: () => request(api+'/users'),
    listPending: () => request('/intake/drafts').then(data => data.data.map(row => ({...row,name:'Черновик №'+row.id,description:'Открыть для проверки'}))),
    getDraft: draft => request('/intake/drafts/'+id(draft)),
    listCategories: () => request('/intake/meta'),
    analyze: (text,source) => post('/intake/analyze',{text,source,request_id:crypto.randomUUID()}),
    clarify: (draft,revision,answer) => post('/intake/drafts/'+id(draft)+'/clarify',{revision,answer}),
    confirm: (draft,body) => post('/intake/drafts/'+id(draft)+'/confirm',body),
    updateTicket: (ticket,body) => post(api+'/tickets/'+id(ticket)+'/update',body),
    engineerAssist: ticket => post(api+'/tickets/'+id(ticket)+'/assist'),
    ticketStatistics: range => request(api+'/reports?'+new URLSearchParams(range)),
    listAudit: () => request(api+'/audit'),
    listEngineersDashboard: () => request(api+'/engineers'),
    listArticles: () => request(api+'/articles'),
    getArticle: article => request(api+'/articles/'+id(article)),
    transcribe: (file,name) => multipart('/intake/transcriptions','audio',file,name),
    ocr: file => multipart(api+'/ocr','file',file,file.name)
  };
})(window);
