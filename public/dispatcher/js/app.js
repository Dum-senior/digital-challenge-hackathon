(function(){
'use strict';
const API=window.DispatchAPI;
const CFG=window.DISPATCH_CONFIG;
const root=document.getElementById('app');
const esc=value=>String(value??'').replace(/[&<>"']/g,ch=>({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[ch]));
const icons={
 spark:'<path d="m12 3 1.8 6.2L20 11l-6.2 1.8L12 19l-1.8-6.2L4 11l6.2-1.8L12 3Z"/><path d="m19 19 .7 1.3L21 21l-1.3.7L19 23l-.7-1.3L17 21l1.3-.7L19 19Z"/>',
 plus:'<path d="M12 5v14M5 12h14"/>',
 clipboard:'<rect x="5" y="5" width="14" height="16" rx="2"/><path d="M9 5V3h6v2M9 11h6M9 15h6"/>',
 book:'<path d="M12 6c-2-2-5-2-9-1v14c4-1 7-1 9 1 2-2 5-2 9-1V5c-4-1-7-1-9 1Zm0 0v14"/>',
 mic:'<rect x="9" y="2" width="6" height="13" rx="3"/><path d="M5 11a7 7 0 0 0 14 0M12 18v4m-4 0h8"/>',
 users:'<path d="M16 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2M9 11a4 4 0 1 0 0-8 4 4 0 0 0 0 8ZM23 21v-2a4 4 0 0 0-3-3.9M16 3.1a4 4 0 0 1 0 7.8"/>',
 shield:'<path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10Z"/><path d="m9 12 2 2 4-4"/>',
 chart:'<path d="M3 3v18h18M8 16v-4M13 16V7M18 16v-7"/>',
 clock:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
 arrow:'<path d="M5 12h14m-6-6 6 6-6 6"/>',
 back:'<path d="M19 12H5m6 6-6-6 6-6"/>',
 check:'<path d="m4 12 5 5L20 6"/>',
 chevron:'<path d="m9 18 6-6-6-6"/>',
 search:'<circle cx="11" cy="11" r="7"/><path d="m16 16 5 5"/>',
 warning:'<path d="M10.2 3.4 2 18a2 2 0 0 0 1.7 3h16.6a2 2 0 0 0 1.7-3L13.8 3.4a2 2 0 0 0-3.6 0ZM12 9v5m0 3h.01"/>',
 file:'<path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8Z"/><path d="M14 2v6h6M8 13h8M8 17h8"/>',
 gear:'<path d="M12 2v2m0 16v2M4.9 4.9l1.4 1.4m11.4 11.4 1.4 1.4M2 12h2m16 0h2M4.9 19.1l1.4-1.4M17.7 6.3l1.4-1.4"/><circle cx="12" cy="12" r="5"/>',
 refresh:'<path d="M20 11a8 8 0 0 0-14-5L3 9m0-6v6h6M4 13a8 8 0 0 0 14 5l3-3m0 6v-6h-6"/>',
 logout:'<path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4M16 17l5-5-5-5m5 5H9"/>',
 upload:'<path d="M12 16V3m-4 4 4-4 4 4M3 16v4a1 1 0 0 0 1 1h16a1 1 0 0 0 1-1v-4"/>',
 headset:'<path d="M3 14v-3a9 9 0 0 1 18 0v3M3 14h4v7H6a3 3 0 0 1-3-3v-4Zm18 0h-4v7h1a3 3 0 0 0 3-3v-4Z"/>',
 menu:'<path d="M4 7h16M4 12h16M4 17h16"/>',
 wave:'<path d="M2 10v4M6 6v12M10 3v18M14 8v8M18 5v14M22 10v4"/>',
 lock:'<rect x="4" y="10" width="16" height="12" rx="2"/><path d="M8 10V7a4 4 0 0 1 8 0v3"/>',
 info:'<circle cx="12" cy="12" r="10"/><path d="M12 11v6m0-10h.01"/>',
 pending:'<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
 working:'<path d="m20.3 5.7-4.4 4.4-2-2 4.4-4.4a6 6 0 0 0-7.9 7.9l-7 7a2 2 0 0 0 2.8 2.8l7-7a6 6 0 0 0 7.9-7.9Z"/>',
 repair:'<path d="m14.7 6.4 3-3a4.5 4.5 0 0 0-5.7 5.7L5 16a2.2 2.2 0 1 0 3 3l7-7a4.5 4.5 0 0 0 5.7-5.7l-3 3-3-2.9Z"/>',
 done:'<circle cx="12" cy="12" r="9"/><path d="m8 12 2.5 2.5L16 9"/>'
};
function icon(name,size=18){return `<svg aria-hidden="true" width="${size}" height="${size}" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round">${icons[name]||icons.info}</svg>`;}
const priorities={low:'Низкий',medium:'Средний',high:'Высокий',critical:'Авария'};
const statuses={new:'Новая',open:'Открыта',in_progress:'В работе',resolved:'Завершена',closed:'Закрыта',needs_clarification:'Уточнение'};
const roles={dispatcher:'Диспетчер',engineer:'Инженер',admin:'Администратор'};
const navigation={
 dispatcher:[['/intake','Обращения','plus'],['/tickets','Заявки','clipboard']],
 engineer:[['/assigned','Мои заявки','clipboard']],
 admin:[['/reports','Статистика','chart'],['/engineers','Инженеры','users'],['/knowledge','База знаний','book']]
};
let state={role:CFG.currentUser?.role||'dispatcher',currentUser:CFG.currentUser||null,tickets:[],pending:[],pendingId:null,articles:[],users:[],engineers:[],engineersLoaded:false,engineersError:null,engineersQuery:'',audit:[],categories:[],draft:null,submissionText:'',intakeSource:'text',kbQuery:'',ticketQuery:'',ticketStatus:'all',ticketPriority:'all',assist:{},assistPending:{},stats:null,loading:false,recording:null,recorder:null,stream:null,auditLoaded:false,audit:[],metrics:{manual:null,ai:null},timerStarted:null,timerKind:null,metricsOpen:false,metricCounts:{manual:'',ai:''},attachment:null,queueSignature:null};
if(!roles[state.role])state.role='dispatcher';
function user(){return state.currentUser;}
function route(){const h=decodeURIComponent(location.hash.replace(/^#/,''));return h.startsWith('/')?h:(navigation[state.role]||navigation.dispatcher)[0][0];}
function navigate(path){if(location.hash==='#'+path){render();return;}location.hash='#'+path;}
function intro(title,subtitle,action=''){return `<div class="intro"><div class="section-heading"><div><h1>${esc(title)}</h1><p>${esc(subtitle)}</p></div>${action}</div></div>`;}
function pillPriority(val){if(!priorities[val])return '<span class="muted">Не задан</span>';const p=val;return `<span class="pill ${p}">${p==='critical'?icon('warning',12):''}${esc(priorities[p])}</span>`;}
function pillStatus(v){return `<span class="pill ${esc(v)}">${esc(statuses[v]||v||'Неизвестно')}</span>`;}
// Only the engineer queue uses these visual labels. The backend retains its original
// workflow statuses; repair_required is an optional issue flag, NOT a new status.
function engineerStateBadge(ticket){
 const s=ticket.status;
 if(['resolved','closed'].includes(s))return `<span class="work-badge work-done">${icon('done',16)}<span>Выполнено</span></span>`;
 if(s==='in_progress')return `<span class="work-badge work-working">${icon('working',16)}<span>Работа выполняется</span></span>`;
 if(['new','open'].includes(s)&&(ticket.repairRequired===true||ticket.repair_required===true))return `<span class="work-badge work-repair">${icon('repair',16)}<span>Требует починки</span></span>`;
 if(['new','open'].includes(s))return `<span class="work-badge work-pending">${icon('pending',16)}<span>Не выполнено</span></span>`;
 return `<span class="work-badge work-pending">${icon('info',16)}<span>${esc(statuses[s]||s||'Неизвестно')}</span></span>`;
}

function btn(text,action,cls='button',attrs=''){return `<button type="button" class="${cls}" data-action="${action}" ${attrs}>${text}</button>`;}
function formatDate(date){const d=new Date(date);return Number.isNaN(d.getTime())?esc(date):new Intl.DateTimeFormat('ru-RU',{day:'2-digit',month:'2-digit',hour:'2-digit',minute:'2-digit'}).format(d);}
function heading(text){return `<div class="section-heading"><h2>${esc(text)}</h2></div>`;}
function responseDeadline(t){return t.responseDueAt||t.response_due_at||t.slaResponseDueAt||t.sla_response_due_at||null;}
function isAwaitingAcceptance(t){return ['new','open'].includes(t.status);}
function isCriticalUnaccepted(t){return t.priority==='critical'&&isAwaitingAcceptance(t);}
function slaText(deadline){
 if(!deadline)return 'Срок реакции не указан';
 const diff=new Date(deadline).getTime()-Date.now();
 if(!Number.isFinite(diff))return 'Срок реакции не указан';
 const absolute=Math.abs(diff),h=Math.floor(absolute/3600000),m=Math.floor((absolute%3600000)/60000),sec=Math.floor((absolute%60000)/1000);
 const time=h?`${h} ч ${String(m).padStart(2,'0')} мин`:`${m} мин ${String(sec).padStart(2,'0')} сек`;
 return diff<0?`SLA просрочен на ${time}`:`Осталось на реакцию: ${time}`;
}
function deadlineHtml(t){
 if(!isAwaitingAcceptance(t))return '';
 const iso=responseDeadline(t);if(!iso)return '<span class="sla-text muted" title="Сервер не предоставил срок реакции">SLA не задан</span>';
 const date=new Date(iso);if(Number.isNaN(date.getTime()))return '<span class="sla-text muted">SLA не задан</span>';
 const overdue=date.getTime()<Date.now();
 return `<span class="sla-text ${overdue?'overdue':''}" data-sla-deadline="${esc(date.toISOString())}" title="Срок реакции: ${esc(date.toLocaleString('ru-RU'))}">${esc(slaText(iso))}</span>`;
}
function refreshCountdowns(){
 root.querySelectorAll('[data-sla-deadline]').forEach(el=>{const deadline=el.dataset.slaDeadline;el.textContent=slaText(deadline);el.classList.toggle('overdue',new Date(deadline).getTime()<Date.now());});
 const timer=root.querySelector('[data-measurement-timer]');if(timer&&state.timerStarted!==null){timer.textContent=measurementTime(Date.now()-state.timerStarted);}
}
function measurementTime(ms){const t=Math.max(0,Math.floor(ms/1000));return `${String(Math.floor(t/60)).padStart(2,'0')}:${String(t%60).padStart(2,'0')}`;}
function formValue(id){return root.querySelector('#'+id)?.value?.trim()||'';}

function readOnlyMessage(){return `<div class="notice warning">Настоящие действия будут доступны после подключения API и проверки прав пользователя. В режиме DEMO изменения сохраняются только в браузере.</div>`;}
function listIsEmpty(title,desc){return `<div class="surface empty"><div class="empty-icon">${icon('search',24)}</div><h3>${esc(title)}</h3><p>${esc(desc)}</p></div>`;}
function resetWarn(){return API.isDemo()?'<span class="hint">Учебный режим: данные хранятся только в этом браузере.</span>':'<span class="hint">Ответы поступают от сервера.</span>';}
function shell(page){
 const nav=(navigation[state.role]||navigation.dispatcher).map(([path,label,ico])=>`<a class="nav-item ${page===path||((page.startsWith('/tickets/')||page==='/tickets')&&path==='/tickets')?'active':''}" href="#${path}">${icon(ico)}<span>${esc(label)}</span></a>`).join('');
 const who=user(),current=page.split('/').filter(Boolean).join(' / ');
 const avatar=(who.name||'Пользователь').split(' ').map(w=>w[0]).slice(0,2).join('').toUpperCase();
 const roleSelect=(API.isDemo()||CFG.demoRoles)?`<label><span class="sr-only">Выбрать роль для демонстрации</span><select class="role-choice" id="role-select">${Object.entries(roles).map(([k,v])=>`<option value="${k}" ${state.role===k?'selected':''}>${v}</option>`).join('')}</select></label>`:`<span class="role-choice">${esc(roles[state.role]||state.role)}</span>`;
 return `<div class="shell"><aside class="sidebar"><div class="brand"><div class="brand-icon">${icon('spark',22)}</div><div><div class="brand-title">Диспетчер ИИ</div></div></div><div class="nav-caption">Рабочее пространство</div><nav aria-label="Основные разделы">${nav}</nav><div class="sidebar-footer"><span class="dot"></span>${API.isDemo()?'Демо-режим':'Подключённый режим'}<div>${API.isDemo()?'Без настоящих API и LLM':'Данные через API'}</div><div style="margin-top:12px"><a href="/workspace">${icon('logout',14)} <span style="display:inline-block;vertical-align:middle">Главное меню</span></a></div></div></aside><div class="content"><div class="container"><header class="topbar"><div class="breadcrumbs">Рабочее пространство / ${esc(current||'Главная')}</div><div class="account-area"><span class="mode ${API.isDemo()?'':'api'}">${API.isDemo()?'DEMO':'API'}</span>${roleSelect}<div class="avatar" title="${esc(who.name)}">${esc(avatar)}</div></div></header><main id="view"></main><footer class="footer-note">Диспетчер ИИ · ${API.isDemo()?'Демонстрационные данные. Не подключено к рабочей базе.':''}</footer></div></div></div><div class="toast-wrap" aria-live="polite"></div><div id="modal-root"></div>`;
}
/** Render editable values from the current server revision of the draft. */
function classifyPreview() {
 const draft=state.draft;
 if(!draft)return listIsEmpty('Здесь появится результат анализа','Введите описание и нажмите «Анализировать».');
 const ex=draft.extracted||{}, a=draft.approved||{}, meta=state.meta||{};
 const options=(items,selected)=>'<option value="">Не выбрано</option>'+items.map(item=>
   `<option value="${esc(item.id)}" ${String(item.id)===String(selected)?'selected':''}>${esc(item.name)}</option>`).join('');
 const adviceKey='draft:'+draft.draft_id+':'+draft.revision;
 const advice=state.assist[adviceKey];
 const engineers=(meta.agents||[]).map(e=>({id:e.group_id+':'+e.id,name:e.first_name+' '+e.last_name}));
 const places=(meta.locations||[]).map(l=>({...l,name:l.address?l.name+' · '+l.address:l.name}));
 return `<div class="surface card"><h2>Проверка черновика №${esc(draft.draft_id)}</h2><div class="grid-2">
 <label class="form-field"><span>Категория</span><select id="confirm-category">${options(meta.types||[],a.type_id)}</select></label>
 <label class="form-field"><span>Приоритет *</span><select id="confirm-priority">${options(meta.priorities||[],a.priority_id)}</select></label>
 <label class="form-field"><span>Место</span><select id="confirm-location">${options(places,a.location_id)}</select></label>
 <label class="form-field"><span>Инженер</span><select id="confirm-assignee">${options(engineers,a.group_id+':'+a.assigned_to)}</select></label></div>
 <p>${esc(ex.severity_reason||'')} ${esc(draft.recommendation?.reason||'')}</p>
 <label class="form-field"><span>Тема</span><textarea id="confirm-subject" maxlength="255">${esc(a.subject||ex.subject)}</textarea></label>
 <label class="form-field"><span>Описание</span><textarea id="confirm-description">${esc(a.description||ex.description)}</textarea></label>
 <section class="top-gap"><h3>Предложенное решение</h3><div id="assist-container" data-assist-key="${esc(adviceKey)}">${advice?assistHtml(advice):'<p>Ищем решение в базе знаний…</p>'}</div></section>
 ${btn('Подтвердить и создать заявку','confirm-draft','button primary',state.loading?'disabled':'')}</div>`;
}
function intakeView(){
 return intro('Новое обращение','Опишите проблему — система поможет классифицировать заявку.')+`<section class="surface card"><div class="mini-title"><span class="num">01</span> Расскажите, что произошло</div><label class="sr-only" for="intake-text">Описание обращения</label><textarea id="intake-text" placeholder="Например: Не печатает принтер в кабинете 205, из него идёт дым...">${esc(state.submissionText)}</textarea><div class="form-actions"><div style="display:flex;gap:9px;flex-wrap:wrap">${btn(icon('mic')+' Записать голос','voice-start')}${btn(icon('upload')+' Загрузить аудио','upload-audio')}${btn(icon('file')+' Скриншот','select-image','button')}</div>${btn(icon('spark')+' Анализировать','analyze','button primary',state.loading?'disabled':'')}</div><input id="audio-file" class="sr-only" type="file" accept="audio/*" /><input id="incident-image" class="sr-only" type="file" accept="image/png,image/jpeg,image/webp" /><div id="attachment-label" class="hint top-gap">${''}</div><div class="examples"><span class="hint">Примеры:</span>${btn('Нет краски','example','chip','data-example="toner"')}${btn('100 принтеров','example','chip','data-example="mass"')}${btn('Принтер дымится','example','chip','data-example="smoke"')}</div></section><section class="result-area" id="analysis-result">${classifyPreview()}</section>${state.pending.length?`<section class="surface card top-gap"><h2 class="panel-heading">Ожидают обработки (${state.pending.length})</h2>${state.pending.map(p=>`<div class="question"><div class="field-value"><strong>${esc(p.name)}</strong> · ${esc(p.location||'Место не указано')}</div><div style="margin:7px 0">${esc(p.description)}</div>${btn('Взять в обработку '+icon('arrow',13),'load-pending','button small soft','data-id="'+esc(p.id)+'"')}</div>`).join('')}</section>`:''}`;
}
function ticketsForRole(){
 let list=[...state.tickets];
 if(state.role==='engineer')list=list.filter(t=>String(t.assigneeId)===String(user().id));
 return list;
}
function ticketsView(assigned=false){
 const isEngineer=state.role==='engineer';
 const rows=ticketsForRole().filter(t=>{
  const q=state.ticketQuery.toLowerCase();
  return (!q||(String(t.id)+' '+(t.subject||'')+' '+(t.description||'')).toLowerCase().includes(q)) &&
   (state.ticketStatus==='all'||t.status===state.ticketStatus);
 });
 const controls=t=>{
  const canAccept=['new','open'].includes(t.status);
  const canFinish=t.status==='in_progress';
  const attrs='data-id="'+esc(t.id)+'"';
  // For a newly arrived critical incident there is exactly ONE visible action.
  // Don't show even the disabled «Выполнено» until the engineer accepts it.
  if(isCriticalUnaccepted(t))return `<div class="engineer-actions" aria-label="Срочно принять заявку #${esc(t.id)}">${btn(icon('warning',15)+' СРОЧНО ПРИНЯТЬ','start-ticket','button small urgent-accept',attrs)}</div>`;
  return `<div class="engineer-actions" aria-label="Действия по заявке #${esc(t.id)}">
   ${btn('Принять','start-ticket','button small primary',attrs+(canAccept?'':' disabled title="Сначала должна быть новая или открытая заявка"'))}
   ${btn('Выполнено','finish-ticket','button small dark',attrs+(canFinish?'':' disabled title="Доступно после принятия заявки"'))}
  </div>`;
 };
 const urgent=ticketsForRole().filter(isCriticalUnaccepted);
 const banner=isEngineer&&urgent.length?`<div class="urgent-banner" role="status" aria-label="Критические заявки ожидают реакции"><span class="urgent-banner-icon">${icon('warning',25)}</span><div><div class="urgent-head">СРОЧНО! ОЖИДАЮТ ПРИНЯТИЯ: ${urgent.length}</div><div class="urgent-details">Критическая заявка #${esc(urgent[0].id)} · ${esc(urgent[0].subject)} · ${deadlineHtml(urgent[0])}</div></div>${btn('Перейти к заявке '+icon('arrow',15),'focus-urgent','button urgent-quick','data-id="'+esc(urgent[0].id)+'"')}</div>`:'';
 return intro(assigned?'Мои заявки':'Заявки',assigned?'Ваши обращения. Примите заявку, затем отметьте её выполнение прямо в списке.':'Просмотр, статусы и корректировка текста обращений.')+banner+
 (isEngineer&&API.isDemo()?`<div class="demo-help">Демо: ${btn(icon('warning',13)+' Создать новую тревогу','demo-critical','button small soft')} ${btn(icon('refresh',13)+' Проверить обновления','refresh-queue','button small soft')}</div>`:isEngineer?`<div class="demo-help">${btn(icon('refresh',13)+' Проверить новые заявки','refresh-queue','button small soft')}</div>`:'')+
 `<div class="filters"><input id="ticket-search" type="search" placeholder="Поиск по номеру или тексту" value="${esc(state.ticketQuery)}"/><select id="ticket-filter-status"><option value="all">Все статусы</option>${Object.entries(statuses).filter(([k])=>k!=='needs_clarification').map(([k,v])=>`<option value="${k}" ${state.ticketStatus===k?'selected':''}>${v}</option>`).join('')}</select></div>
 <section class="surface"><div class="table-head"><strong>Список заявок</strong><small>${rows.length} из ${ticketsForRole().length}</small></div><div class="table-wrap"><table class="table"><thead><tr><th>№</th><th>Обращение</th><th>Приоритет</th><th>Статус</th>${isEngineer?'<th>Действия</th>':'<th>Исполнитель</th><th>Действие</th>'}</tr></thead><tbody>
 ${rows.length?rows.map(t=>`<tr class="${isEngineer&&isCriticalUnaccepted(t)?'urgent-ticket-row':''}" data-ticket-row="${esc(t.id)}"><td><button class="button link" data-action="open-ticket" data-id="${esc(t.id)}">#${esc(t.id)}</button></td><td><button class="ticket-title-link" data-action="open-ticket" data-id="${esc(t.id)}">${esc(t.subject)}</button><div class="small muted">${esc(t.location||'Место не указано')}</div>${isEngineer?`<div class="row-sla">${deadlineHtml(t)}</div>`:''}</td>${isEngineer&&isCriticalUnaccepted(t)?`<td colspan="3" class="urgent-only-cell">${controls(t)}</td>`:`<td>${pillPriority(t.priority)}</td><td>${isEngineer?engineerStateBadge(t):pillStatus(t.status)}</td>${isEngineer?`<td>${controls(t)}</td>`:`<td>${esc(t.assigneeName||'Не назначен')}</td><td>${btn(icon('file')+' Изменить','edit-ticket','button small soft','data-id="'+esc(t.id)+'"')}</td>`}`}</tr>`).join(''):`<tr><td colspan="${isEngineer?5:6}" class="muted" style="text-align:center;padding:30px">Нет подходящих заявок</td></tr>`}</tbody></table></div></section>`;
}
function ticketView(ticket){
 if(!ticket)return listIsEmpty('Заявка не найдена','Вернитесь к списку.');
 const isEngineer=state.role==='engineer';
 const assist=state.assist[ticket.id];
 const detailFields=`<div><div class="field-label">Статус</div>${isEngineer?engineerStateBadge(ticket):pillStatus(ticket.status)}</div>${isEngineer?'':`<div><div class="field-label">Исполнитель</div><div class="field-value">${esc(ticket.assigneeName||'Не назначен')}</div></div>`}<div><div class="field-label">Объект / кабинет</div><div class="field-value">${esc(ticket.location||'Не указан')}</div></div><div><div class="field-label">Категория</div><div class="field-value">${esc(ticket.category||'Не определена')}</div></div>`;
 const information=`<div class="surface card"><div class="ticket-head"><div><div class="view-label">ЗАЯВКА #${esc(ticket.id)}</div><h2>${esc(ticket.subject)}</h2></div>${pillPriority(ticket.priority)}</div><div class="ticket-desc">${esc(ticket.description)}</div><div class="grid-2">${detailFields}</div>${ticket.extracted?.equipment||ticket.extracted?.ip||ticket.extracted?.tags?.length?`<details class="extract-details top-gap"><summary>Дополнительные данные заявки</summary><div class="grid-2 top-gap">${Object.entries(ticket.extracted).filter(([k,v])=>['equipment','ip','floor','room','object','serial_number','symptoms','tags'].includes(k)&&v).map(([k,v])=>`<div><span class="field-label">${esc(({equipment:'Оборудование',ip:'IP',floor:'Этаж',room:'Кабинет',object:'Объект',serial_number:'Серийный номер',symptoms:'Симптомы',tags:'Теги'})[k]||k)}</span><span class="field-value">${esc(Array.isArray(v)?v.join(', '):v)}</span></div>`).join('')}</div></details>`:''}</div>`;
 if(isEngineer)return `<button class="back" data-action="back-tickets">${icon('back',15)} Мои заявки</button><div class="work-focus">${information}<div class="surface card top-gap"><div class="section-heading"><h2>Предложенное решение</h2></div><div id="assist-container" data-assist-key="${esc(ticket.id)}">${assist?assistHtml(assist):'<div class="notice"><span class="spinner"></span> Загружаем рекомендации и источники…</div>'}</div></div><p class="hint top-gap">Чтобы принять или завершить заявку, используйте кнопки напротив неё в списке «Мои заявки».</p></div>`;
 return `<button class="back" data-action="back-tickets">${icon('back',15)} К заявкам</button><div class="work-focus">${information}<div class="surface card top-gap"><div class="section-heading"><h2>Описание заявки</h2>${btn(icon('file')+' Редактировать','edit-ticket','button soft','data-id="'+esc(ticket.id)+'"')}</div><p class="muted">Диспетчер может поправить формулировку, не меняя историю исполнения.</p></div></div>`;
}
function assistHtml(a){
 if(a.error)return '<p class="notice danger">'+esc('Ошибка поиска: '+a.error)+'</p>';
 const steps=Array.isArray(a.steps)?a.steps:[];
 const sources=Array.isArray(a.sources)?a.sources:[];
 const title=['answered','suggested'].includes(a.status)?'Рекомендованные действия':a.status==='sources_only'?'Найденные источники':'Нет подтверждённого решения';
 return `<div class="advice ${a.emergency?'danger':''}"><h3>${icon('book',17)} ${esc(title)}</h3>${a.summary?`<p>${esc(a.summary)}</p>`:''}
 ${steps.length?`<ol>${steps.map(x=>`<li>${esc(typeof x==='string'?x:x.text||x.description||'')}</li>`).join('')}</ol>`:''}
 ${sources.length?`<div class="divider"></div><div class="field-label">Откуда взята информация</div>${sources.map(src=>`<div class="source-line">${icon('file',14)} <button class="button link" data-action="view-source" data-type="${esc(src.type||'kb')}" data-id="${esc(src.id??'')}" data-title="${esc(src.title||src.name||'Источник')}">${esc(src.title||src.name||'Источник')}</button></div>`).join('')}`:''}
 ${!steps.length&&!sources.length?'<p>Достоверных рекомендаций не найдено. Уточните информацию у ответственного специалиста.</p>':''}</div><p class="hint top-gap">Рекомендация носит справочный характер. Решение принимает инженер.</p>`;
}
function knowledgeView(){
 const docs=state.articles.filter(d=>((d.title||'')+' '+(d.description||'')+' '+(d.category||'')).toLowerCase().includes(state.kbQuery.toLowerCase()));
 return intro('База знаний','Инструкции для поиска решений.')+
 `<input id="kb-search" type="search" placeholder="Поиск по статьям…" value="${esc(state.kbQuery)}" style="margin:16px 0;background:#fff"/>
 <div class="kb-grid">${docs.map(d=>`<article class="surface kb-card"><span class="view-label">${esc(d.category||'Без категории')}</span><h3>${esc(d.title)}</h3><p>${esc(d.description||'')}</p>${d.source_url?`<span class="meta">Сайт: ${esc(d.source_url)}</span>`:''}
 <div class="kb-actions">${btn('Просмотр','open-article','button small soft','data-id="'+esc(d.id)+'"')}</div></article>`).join('')}</div>
 ${!docs.length?listIsEmpty('Статей нет','Добавьте статью или ссылку на источник.'):''}`;
}
function articleView(article){
 if(!article)return listIsEmpty('Статья не найдена','Вернитесь к списку.');
 const lines=Array.isArray(article.content)?article.content:String(article.content||'').split(/\n+/).filter(Boolean);
 return `<button class="back" data-action="back-kb">${icon('back',15)} К базе знаний</button><article class="surface card work-focus"><div class="section-heading"><h2>${esc(article.title)}</h2></div><p class="muted">${esc(article.description||'')}</p><div class="divider"></div><ol class="steps">${lines.map(s=>`<li>${esc(s)}</li>`).join('')}</ol></article>`;
}
function progressLine(title,value,total,colour){const count=Math.max(0,Number(value||0)),max=Math.max(1,total);return `<div class="progress-row"><div class="progress-label"><span>${esc(title)}</span><strong>${count}</strong></div><div class="progress-track"><div class="progress-fill ${colour}" style="width:${Math.min(100,count/max*100)}%"></div></div></div>`;}
function auditWidget(){
 if(!state.auditLoaded)return `<div class="section-heading"><h2>Журнал действий</h2>${btn('Загрузить журнал','load-audit','button small soft')}</div><p class="hint">Создание и изменение заявок, переходы статусов, решения диспетчера.</p>`;
 return `<div class="section-heading"><h2>Журнал действий</h2>${btn(icon('refresh',13)+' Обновить','load-audit','button small soft')}</div><div class="table-wrap"><table class="table"><thead><tr><th>Когда</th><th>Кто</th><th>Действие</th><th>Объект</th></tr></thead><tbody>${state.audit.length?state.audit.slice(0,20).map(x=>`<tr><td>${esc(x.when||x.created_at||'—')}</td><td>${esc(x.actor||x.user||'—')}</td><td>${esc(x.action||'—')}</td><td>${esc(x.target||'—')}</td></tr>`).join(''):'<tr><td colspan="4" class="muted">Нет записей или API вернул пустой журнал</td></tr>'}</tbody></table></div>`;
}
function timingWidget(){
 const manual=state.metrics.manual,ai=state.metrics.ai;
 const m=manual?.ms,a=ai?.ms;
 return `<details class="extract-details top-gap" ${state.metricsOpen?'open':''}><summary>Измерение времени: вручную и с ИИ</summary><p class="hint top-gap">Засеките фактическое время на одинаковом обращении. Это секундомер демонстрации, не прогноз и не статистика из БД.</p><div class="metric-card"><span class="metric-time" data-measurement-timer>${state.timerStarted!==null?measurementTime(Date.now()-state.timerStarted):'00:00'}</span><span class="metric-context">${state.timerKind==='manual'?'Ручной сценарий':state.timerKind==='ai'?'С ИИ':'Выберите сценарий'}</span></div><div class="form-actions">${btn('▶ Без ИИ','measure-manual','button small soft',state.timerStarted!==null?'disabled':'')}${btn('▶ С ИИ','measure-ai','button small soft',state.timerStarted!==null?'disabled':'')}${btn('■ Остановить','measure-stop','button small primary',state.timerStarted===null?'disabled':'')}</div><div class="grid-2 top-gap"><div class="notice">Без ИИ: <strong>${m!=null?measurementTime(m):'не измерено'}</strong></div><div class="notice">С ИИ: <strong>${a!=null?measurementTime(a):'не измерено'}</strong></div></div><div class="grid-2 top-gap"><label class="form-field"><span>Количество действий без ИИ</span><input id="manual-action-count" type="number" min="0" max="999" value="${esc(state.metricCounts.manual)}" placeholder="Измерьте вручную" /></label><label class="form-field"><span>Количество действий с ИИ</span><input id="ai-action-count" type="number" min="0" max="999" value="${esc(state.metricCounts.ai)}" placeholder="Измерьте вручную" /></label></div>${state.metricCounts.manual!==''&&state.metricCounts.ai!==''?`<div class="notice">Ручных действий: ${esc(state.metricCounts.manual)}; с ИИ: ${esc(state.metricCounts.ai)}. Разница: ${Number(state.metricCounts.manual)-Number(state.metricCounts.ai)}.</div>`:''}${m!=null&&a!=null?`<p class="hint top-gap">Разница измерений: ${esc(measurementTime(Math.abs(m-a)))} ${a<m?'быстрее с ИИ':a>m?'быстрее без ИИ':'— одинаково'} (один пробный замер, не средний результат).</p>`:''}</details>`;
}
function reportsView(){
 const stats=state.stats;
 const total=Number(stats?.total||0),unresolved=Number(stats?.open||0),resolved=Number(stats?.resolved||0),critical=Number(stats?.critical||0);
 const byPriority=stats?.byPriority||{};const byStatus=stats?.byStatus||{};
 const sla=stats?.sla||stats?.slaSummary||null;
 return intro('Статистика заявок','Запросите сводку за выбранный период из базы данных.')+
 `<section class="surface card"><form id="stats-form"><div class="stats-filters"><label class="form-field"><span>Дата с</span><input name="from" type="date" value="${esc(state.statsFrom||'')}" /></label><label class="form-field"><span>Дата по</span><input name="to" type="date" value="${esc(state.statsTo||'')}" /></label><button type="submit" class="button primary">${icon('chart')} Запросить статистику</button></div></form></section>
 ${stats?`<div class="grid-3 stats-cards top-gap"><div class="surface stat"><div class="stat-label">Всего заявок</div><div class="stat-value">${total}</div></div><div class="surface stat"><div class="stat-label">В работе и ожидают</div><div class="stat-value">${unresolved}</div></div><div class="surface stat"><div class="stat-label">Завершены</div><div class="stat-value">${resolved}</div></div></div>
 <div class="grid-2 top-gap"><section class="surface card"><h2 class="panel-heading">По приоритетам</h2>${Object.entries(priorities).map(([k,v])=>progressLine(v,byPriority[k],total,k)).join('')}</section><section class="surface card"><h2 class="panel-heading">По статусам</h2>${Object.entries(statuses).filter(([k])=>['new','open','in_progress','resolved','closed'].includes(k)).map(([k,v])=>progressLine(v,byStatus[k],total,'medium')).join('')}</section></div>
 <section class="surface card top-gap"><h2 class="panel-heading">SLA и риски</h2>${sla?`<div class="grid-2"><div class="notice warning">Риск нарушения: <strong>${Number(sla.atRisk??sla.at_risk??0)}</strong></div><div class="notice danger">Просрочено: <strong>${Number(sla.breached??0)}</strong></div></div>`:'<div class="notice">Сервер ещё не передал статистику по SLA. Показатели не рассчитываем из догадок.</div>'}</section>`:listIsEmpty('Статистика ещё не запрошена','Выберите период и нажмите «Запросить статистику».')}
 <section class="surface card top-gap">${auditWidget()}</section><section class="surface card top-gap"><h2 class="panel-heading">Показать эффект автоматизации</h2>${timingWidget()}</section>`;
}
// Read-only administrator overview. In API mode, all records come from
// /admin/engineers; the browser never connects directly to MariaDB.
function engineersView(){
 const all=state.engineers.filter(e=>!e.role||e.role==='engineer');
 const query=state.engineersQuery.trim().toLocaleLowerCase('ru-RU');
 const matches=all.filter(e=>{
   const skills=Array.isArray(e.competencies)?e.competencies:(Array.isArray(e.skills)?e.skills:[]);
   const assigned=Array.isArray(e.tickets)?e.tickets:(Array.isArray(e.active_tickets)?e.active_tickets:(Array.isArray(e.assigned_tickets)?e.assigned_tickets:[]));
   const searchable=[e.name||'',e.department||'',...skills.map(x=>typeof x==='string'?x:(x?.name||'')),...assigned.map(t=>[t.id,t.subject||t.title||'',t.category||''].join(' '))].join(' ').toLocaleLowerCase('ru-RU');
   return !query||searchable.includes(query);
 });
 const activeStatuses=new Set(['new','open','in_progress','needs_clarification']);
 const assignedTo=e=>{
   const list=Array.isArray(e.tickets)?e.tickets:(Array.isArray(e.active_tickets)?e.active_tickets:(Array.isArray(e.assigned_tickets)?e.assigned_tickets:[]));
   return list.filter(t=>activeStatuses.has(t.status));
 };
 const activeTotal=all.reduce((sum,e)=>sum+assignedTo(e).length,0);
 const criticalTotal=all.reduce((sum,e)=>sum+assignedTo(e).filter(t=>t.priority==='critical').length,0);
 const warning=state.engineersError?`<div class="notice warning top-gap" role="alert">Не удалось получить список инженеров из API: ${esc(state.engineersError)}. Проверьте права администратора и маршрут GET /api/v1/admin/engineers.</div>`:'';
 const header=intro('Инженеры','Компетенции сотрудников и заявки, которые сейчас закреплены за ними.',btn(icon('refresh',15)+' Обновить','refresh-engineers','button soft'));
 const summary=state.engineersLoaded&&!state.engineersError?`<div class="engineer-summary"><div class="surface engineer-stat"><span>Инженеров</span><strong>${all.length}</strong></div><div class="surface engineer-stat"><span>Назначенных активных заявок</span><strong>${activeTotal}</strong></div><div class="surface engineer-stat"><span>Критических активных</span><strong>${criticalTotal}</strong></div></div>`:'';
 const search=`<div class="engineers-search"><label class="sr-only" for="engineers-search">Поиск инженера или компетенции</label><input id="engineers-search" type="search" placeholder="Поиск по инженеру, компетенции или заявке…" value="${esc(state.engineersQuery)}" /></div>`;
 const cards=matches.map(e=>{
   const skills=(Array.isArray(e.competencies)?e.competencies:(Array.isArray(e.skills)?e.skills:[])).map(x=>typeof x==='string'?x:x?.name).filter(Boolean);
   const tasks=assignedTo(e).sort((a,b)=>({critical:0,high:1,medium:2,low:3}[a.priority]??4)-({critical:0,high:1,medium:2,low:3}[b.priority]??4));
   const started=tasks.filter(t=>t.status==='in_progress').length;
   const awaiting=tasks.length-started;
   const openTable=tasks.length?`<div class="table-wrap"><table class="table engineer-task-table"><thead><tr><th>№</th><th>Заявка</th><th>Приоритет</th><th>Статус</th></tr></thead><tbody>${tasks.map(t=>`<tr><td class="primary-text">#${esc(t.id)}</td><td><strong>${esc(t.subject||t.title||'Без названия')}</strong>${t.location?`<div class="small muted">${esc(t.location)}</div>`:''}</td><td>${pillPriority(t.priority)}</td><td>${pillStatus(t.status)}</td></tr>`).join('')}</tbody></table></div>`:'<div class="engineer-no-tasks">Нет активных заявок</div>';
   const declaredCount=Number(e.active_count??e.activeCount);
   const incomplete=!tasks.length&&Number.isFinite(declaredCount)&&declaredCount>0;
   return `<section class="surface engineer-card"><div class="engineer-profile"><div class="engineer-profile-meta"><div class="engineer-avatar">${esc(String(e.name||'?').trim().split(/\s+/).slice(0,2).map(x=>x.slice(0,1)).join('').toUpperCase())}</div><div><h2>${esc(e.name||'Имя не указано')}</h2><p>${esc(e.department||'Инженер технической поддержки')}</p></div></div><div class="engineer-workload"><strong>${tasks.length}</strong><span>активных заявок</span></div></div><div class="engineer-skill-row"><span class="field-label">Компетенции</span><div class="engineer-skills">${skills.length?skills.map(s=>`<span class="engineer-skill">${esc(s)}</span>`).join(''):'<span class="muted small">Компетенции не указаны в БД</span>'}</div></div><div class="engineer-loadline"><span>Ожидают принятия: <strong>${awaiting}</strong></span><span>В работе: <strong>${started}</strong></span></div><div class="engineer-task-head">Закреплённые заявки <span>${tasks.length}</span></div>${incomplete?'<div class="notice warning">API передал количество заявок, но не их список. Для просмотра необходим массив tickets.</div>':''}${openTable}</section>`;
 }).join('');
 const status=state.engineersError?'':!state.engineersLoaded?'<div class="surface card">Загружаем список инженеров…</div>':!all.length?listIsEmpty('Инженеры не найдены','В БД пока нет доступных записей об инженерах.'):!matches.length?listIsEmpty('Совпадений нет','Измените поисковый запрос.'):cards;
 return header+warning+summary+search+`<div class="engineers-list">${status}</div>`+(API.isDemo()?'<p class="hint top-gap">ДЕМО: компетенции и распределение заявок учебные. В рабочем режиме раздел получает сведения из MariaDB через защищённый API.</p>':'');
}
function notAllowed(){return `<div class="surface empty"><div class="empty-icon">${icon('lock')}</div><h3>Раздел недоступен для текущей роли</h3><p>Выберите доступный пункт в меню. На настоящем сервере права проверяет backend.</p></div>`;}
function permitted(r){
 if(state.role==='dispatcher')return r==='/intake'||r==='/tickets'||r.startsWith('/tickets/');
 if(state.role==='engineer')return r==='/assigned'||r.startsWith('/tickets/');
 if(state.role==='admin')return r==='/reports'||r==='/engineers'||r==='/knowledge'||r.startsWith('/knowledge/');
 return false;
}
function render(){
 const p=route();document.title='Диспетчер ИИ';
 if(p==='/login'){navigate(navigation[state.role][0][0]);return;}
 root.innerHTML=shell(p);
 let html;
 if(!permitted(p))html=notAllowed();
 else if(p==='/intake')html=intakeView();
 else if(p==='/tickets'||p==='/assigned')html=ticketsView(p==='/assigned');
 else if(p.startsWith('/tickets/')){
  const ticket=state.tickets.find(t=>String(t.id)===p.split('/')[2]);
  if(ticket&&state.role==='engineer'&&String(ticket.assigneeId)!==String(user().id))html=notAllowed();
  else html=ticketView(ticket);
 }
 else if(p==='/knowledge')html=knowledgeView();
 else if(p.startsWith('/knowledge/'))html=articleView(state.articles.find(a=>String(a.id)===p.split('/')[2]));
 else if(p==='/reports')html=reportsView();
 else if(p==='/engineers')html=engineersView();
 else html=notAllowed();
 document.getElementById('view').innerHTML=html;
 const draft=state.draft;
 if(document.querySelector('#assist-container')?.dataset.assistKey.startsWith('draft:')){
  const key='draft:'+draft.draft_id+':'+draft.revision;
  if(!state.assist[key]&&!state.assistPending[key])findSolution(key,()=>API.draftAssist(draft.draft_id));
 }
 // Engineer sees proposal automatically; no additional knowledge-base navigation or button.
 if(state.role==='engineer'&&p.startsWith('/tickets/')){
  const ticket=state.tickets.find(t=>String(t.id)===p.split('/')[2]);
  if(ticket&&String(ticket.assigneeId)===String(user().id)&&!state.assist[ticket.id]&&!state.assistPending[ticket.id])findSolution(ticket.id);
 }
}
function toast(text,type=''){const wrap=root.querySelector('.toast-wrap');if(!wrap)return;const el=document.createElement('div');el.className='toast '+type;el.textContent=text;wrap.appendChild(el);setTimeout(()=>el.remove(),5000);}
function setLoading(on){state.loading=on;}
function normList(v,keys=[]){if(Array.isArray(v))return v;for(const k of keys)if(Array.isArray(v?.[k]))return v[k];return [];}
async function loadData(){
 try{
  const queries=state.role==='dispatcher'?{
   tickets:API.listTickets(),categories:API.listCategories(),pending:API.listPending()
  }:state.role==='engineer'?{
   tickets:API.listTickets()
  }:{articles:API.listArticles(),engineers:API.listEngineersDashboard()};
  const keys=Object.keys(queries),values=await Promise.allSettled(Object.values(queries));
  const convert=(v,keys)=>normList(v,keys);
  keys.forEach((key,i)=>{
   if(values[i].status!=='fulfilled'){
    if(key==='engineers'){state.engineersLoaded=true;state.engineersError=values[i].reason.message;state.engineers=[];}
    toast('Ошибка загрузки данных: '+values[i].reason.message,'error');return;
   }
   const v=values[i].value;
   if(key==='tickets')state.tickets=convert(v,['tickets','data','items']);
   if(key==='users')state.users=convert(v,['users','data','items']);
   if(key==='pending')state.pending=convert(v,['items','requests','data']);
   if(key==='articles')state.articles=convert(v,['articles','data','items']);
   if(key==='engineers'){state.engineers=convert(v,['engineers','data','items']);state.engineersLoaded=true;state.engineersError=null;}
   if(key==='categories')state.meta=v;
  });
  if(state.role==='engineer')state.queueSignature=JSON.stringify(state.tickets.map(t=>[t.id,t.status,t.priority,t.responseDueAt,t.response_due_at]));
 }catch(e){toast('Ошибка загрузки: '+e.message,'error');}
 render();
}
async function analyze(){if(state.loading)return;const field=root.querySelector('#intake-text');const text=(field?.value||state.submissionText).trim();if(text.length<8){toast('Опишите проблему подробнее (от 8 символов).','error');field?.focus();return;}
 state.submissionText=text;state.draft=null;setLoading(true);const out=root.querySelector('#analysis-result');if(out)out.innerHTML='<div class="surface card"><span class="spinner"></span> Анализируем обращение…</div>';
 try{state.draft=await API.analyze(text,state.intakeSource);render();toast('Черновик готов. Проверьте поля перед созданием.','success');}catch(e){toast('Анализ не выполнен: '+e.message,'error');render();}finally{setLoading(false);render();}}
function previewText(key){const x={toner:'В принтере закончилась краска, нужно заменить картридж.',mass:'Одновременно перестали работать 100 принтеров в офисе.',smoke:'Принтер не печатает, из него идёт дым и пахнет гарью.'};state.submissionText=x[key]||'';state.pendingId=null;state.draft=null;state.intakeSource='text';render();root.querySelector('#intake-text')?.focus();}
async function confirmDraft() {
 const draft=state.draft;
 if(!draft||state.loading)return;
 const value=id=>root.querySelector('#confirm-'+id)?.value?.trim()||'';
 if(!value('priority')){toast('Выберите приоритет заявки.','error');return;}
 const [group,engineer]=value('assignee').split(':').map(Number);
 const approved={subject:value('subject'),description:value('description'),type_id:Number(value('category'))||null,
  priority_id:Number(value('priority')),location_id:Number(value('location'))||null,
  group_id:group||null,assigned_to:engineer||null,
  tag_ids:[]};
 const emergencyOverride=draft.extracted?.is_potential_emergency&&draft.recommendation?.priority_id!=null
  && Number(draft.recommendation.priority_id)!==approved.priority_id;
 const needsManualReview=draft.status!=='awaiting_review'||emergencyOverride;
 const reviewMessage=emergencyOverride
  ?'Подтверждаю изменение приоритета потенциальной аварии после ручной проверки.'
  :'Подтверждаю создание заявки после ручной проверки данных.';
 if(needsManualReview&&!window.confirm(reviewMessage))return;
 state.loading=true;
 try {
  const result=await API.confirm(draft.draft_id,{revision:draft.revision,approve:true,approved,
   ...(needsManualReview?{manual_review_reason:reviewMessage}:{})});
  state.draft=null;state.submissionText='';await loadData();navigate('/tickets/'+result.ticket_id);toast('Заявка создана','success');
 } catch (error) {toast(error.message,'error');}
 finally {state.loading=false;}
}
async function findSolution(key, request=()=>API.engineerAssist(key)){
 if(state.assistPending[key])return;
 state.assistPending[key]=true;
 try{state.assist[key]=await request();}
 catch(e){state.assist[key]={error:e.message};}
 finally{delete state.assistPending[key];}
 const node=root.querySelector('#assist-container');
 if(node?.dataset.assistKey===String(key))node.innerHTML=assistHtml(state.assist[key]);
}
async function loadAudit(){
 try{const out=await API.listAudit();state.audit=normList(out,['events','audit','data','items']);state.auditLoaded=true;render();}
 catch(e){toast('Не удалось загрузить журнал: '+e.message,'error');}
}
async function refreshEngineerQueue(manual=false){
 if(state.role!=='engineer'||route()!=='/assigned')return;
 try{
  const v=await API.listTickets();const tickets=normList(v,['tickets','data','items']);
  const oldIds=new Set(state.tickets.filter(t=>String(t.assigneeId)===String(user().id)&&isCriticalUnaccepted(t)).map(t=>String(t.id)));
  const newlyCritical=tickets.filter(t=>String(t.assigneeId)===String(user().id)&&isCriticalUnaccepted(t)&&!oldIds.has(String(t.id)));
  const signature=JSON.stringify(tickets.map(t=>[t.id,t.status,t.priority,t.responseDueAt,t.response_due_at]));
  const changed=state.queueSignature!==signature;state.queueSignature=signature;state.tickets=tickets;
  if(changed&&(!root.querySelector('#ticket-search:focus')&&!root.querySelector('.modal-backdrop')))render();
  if(newlyCritical.length){toast('ТРЕВОГА! Новая критическая заявка №'+newlyCritical[0].id,'error');}
  else if(manual)toast('Список заявок обновлён','success');
 }catch(e){if(manual)toast('Не удалось обновить заявки: '+e.message,'error');}
}
async function setEngineerStatus(id,status){
 if(state.role!=='engineer')return;
 const ticket=state.tickets.find(t=>String(t.id)===String(id));
 if(!ticket||String(ticket.assigneeId)!==String(user().id))return toast('Заявка не назначена вам.','error');
 if(status==='in_progress'&&!['new','open'].includes(ticket.status))return toast('Эту заявку нельзя принять повторно.','error');
 if(status==='resolved'&&ticket.status!=='in_progress')return toast('Сначала примите заявку.','error');
 try{await API.updateTicket(id,{status});await loadData();navigate('/assigned');toast(status==='in_progress'?'Заявка принята':'Заявка выполнена','success');}
 catch(e){toast('Не удалось обновить статус: '+e.message,'error');}
}
function showEditTicket(id){
 if(state.role!=='dispatcher')return;
 const t=state.tickets.find(x=>String(x.id)===String(id));if(!t)return;
 modal(`<h2>Редактировать заявку #${esc(t.id)}</h2><p>Поправьте текст обращения. Изменения сохраняются через API заявок.</p>
 <form id="edit-ticket-form" data-id="${esc(t.id)}"><label class="form-field"><span>Заголовок</span><input name="subject" maxlength="200" required value="${esc(t.subject)}" /></label>
 <label class="form-field"><span>Текст заявки</span><textarea name="description" maxlength="5000" required>${esc(t.description)}</textarea></label>
 <div class="form-actions end">${btn('Отмена','close-modal')}<button class="button primary" type="submit">Сохранить</button></div></form>`);
}
async function showSource(button){
 const id=button.dataset.id,type=button.dataset.type||'kb';
 let article=['kb','article','knowledge'].includes(type)?state.articles.find(x=>String(x.id)===String(id)):null;
 if(!article&&id&&['kb','article','knowledge'].includes(type)){
  try{article=await API.getArticle(id);}catch(_e){}
 }
 const name=button.dataset.title||article?.title||'Источник';
 const content=article?(Array.isArray(article.content)?article.content.join('\n'):String(article.content||'')):'';
 modal(`<h2>${esc(name)}</h2><p>${article?esc(article.description||'Источник из базы знаний'):'Ссылка на источник рекомендации. Для открытия полного текста требуется доступ через сервер.'}</p>
 ${content?`<div class="source-preview">${esc(content).replace(/\n/g,'<br>')}</div>`:''}<div class="form-actions end">${btn('Закрыть','close-modal')}</div>`);
}
async function requestStats(form){
 if(state.role!=='admin')return;
 const fd=new FormData(form),from=String(fd.get('from')||''),to=String(fd.get('to')||'');
 if(from&&to&&from>to)return toast('Дата начала больше даты окончания.','error');
 try{const data=await API.ticketStatistics({from,to});state.stats=data;state.statsFrom=from;state.statsTo=to;render();toast('Статистика загружена','success');}
 catch(e){toast('Не удалось запросить статистику: '+e.message,'error');}
}
function modal(content){const place=root.querySelector('#modal-root');if(place)place.innerHTML=`<div class="modal-backdrop" data-action="close-modal"><div class="modal" role="dialog" aria-modal="true" aria-label="Диалог">${content}</div></div>`;}
function closeModal(){const place=root.querySelector('#modal-root');if(place)place.innerHTML='';}
async function transcribe(blob,filename){if(blob.size>CFG.maxAudioBytes){toast('Аудиофайл слишком большой.','error');return;}
 toast('Отправляем аудио на распознавание…');try{const result=await API.transcribe(blob,filename);const text=result.text||result.transcript||result.transcription||'';if(!text)throw Error('Сервис вернул пустую расшифровку');state.submissionText=text;state.intakeSource='voice';state.draft=null;render();toast('Речь распознана. Проверьте текст.','success');}catch(e){toast('Распознавание не выполнено: '+e.message,'error');}}
async function startVoice(){if(!navigator.mediaDevices?.getUserMedia||!window.MediaRecorder){toast('Микрофон недоступен в этом браузере/контексте.','error');return;}
 if(API.isDemo()){toast('Распознавание микрофона доступно после подключения API Вани. В демо используйте текст или примеры.');return;}
 try{const stream=await navigator.mediaDevices.getUserMedia({audio:true});const chunks=[];const rec=new MediaRecorder(stream);state.recorder=rec;state.stream=stream;
 rec.ondataavailable=e=>{if(e.data.size)chunks.push(e.data)};rec.onstop=()=>{clearTimeout(timer);closeModal();stream.getTracks().forEach(t=>t.stop());state.recorder=null;state.stream=null;transcribe(new Blob(chunks,{type:rec.mimeType}),`voice.${rec.mimeType.includes('ogg')?'ogg':'webm'}`)};rec.start();const timer=setTimeout(stopVoice,115000);modal(`<h2>${icon('mic',21)} Идёт запись</h2><p>Говорите чётко, затем нажмите «Остановить». После этого аудио отправится в ваш локальный сервис распознавания.</p><div class="notice">${icon('wave',24)} Запись микрофона активна</div><div class="form-actions end">${btn('Остановить запись','voice-stop','button primary')}</div>`);
 }catch(e){toast('Не удалось получить доступ к микрофону: '+e.message,'error');}}
function stopVoice(){const rec=state.recorder;closeModal();if(rec&&rec.state==='recording')rec.stop();}
function attach(){
 root.addEventListener('click',async e=>{
  const target=e.target.closest('[data-action]');if(!target)return;
  const act=target.dataset.action;if(act==='close-modal'&&e.target.closest('.modal')&&e.target!==target)return;
  if(act==='example')previewText(target.dataset.example);
  else if(act==='load-pending'){try{state.draft=await API.getDraft(target.dataset.id);state.submissionText=state.draft.raw_text;render();}catch(error){toast(error.message,'error');}}
  else if(act==='analyze')await analyze();
  else if(act==='confirm-draft')await confirmDraft();
  else if(act==='open-ticket')navigate('/tickets/'+target.dataset.id);
  else if(act==='back-tickets')navigate(state.role==='engineer'?'/assigned':'/tickets');
  else if(act==='focus-urgent'){const id=target.dataset.id;const row=[...root.querySelectorAll('[data-ticket-row]')].find(el=>el.dataset.ticketRow===id);row?.scrollIntoView({behavior:'smooth',block:'center'});row?.classList.add('urgent-highlight');setTimeout(()=>row?.classList.remove('urgent-highlight'),2000);}
  else if(act==='refresh-queue')await refreshEngineerQueue(true);
  else if(act==='demo-critical'&&API.isDemo()&&state.role==='engineer'){await API.demoCriticalTicket(user().id,user().name);await loadData();toast('ДЕМО: появилась критическая заявка.','success');}
  else if(act==='load-audit'&&state.role==='admin')await loadAudit();
  else if(act==='refresh-engineers'&&state.role==='admin'){try{const data=await API.listEngineersDashboard();state.engineers=normList(data,['engineers','data','items']);state.engineersLoaded=true;state.engineersError=null;render();toast('Данные инженеров обновлены','success');}catch(err){state.engineers=[];state.engineersLoaded=true;state.engineersError=err.message;render();toast('Ошибка загрузки инженеров: '+err.message,'error');}}
  else if(act==='measure-manual'||act==='measure-ai'){if(state.role==='admin'&&state.timerStarted===null){state.timerStarted=Date.now();state.timerKind=act==='measure-manual'?'manual':'ai';state.metricsOpen=true;render();}}
  else if(act==='measure-stop'&&state.role==='admin'&&state.timerStarted!==null){state.metrics[state.timerKind]={ms:Date.now()-state.timerStarted};state.timerStarted=null;state.timerKind=null;state.metricsOpen=true;render();}
  else if(act==='find-solution')await findSolution(target.dataset.id);
  else if(act==='start-ticket')await setEngineerStatus(target.dataset.id,'in_progress');
  else if(act==='finish-ticket')await setEngineerStatus(target.dataset.id,'resolved');
  else if(act==='edit-ticket')showEditTicket(target.dataset.id);
  else if(act==='view-source')await showSource(target);
  else if(act==='open-article'&&state.role==='admin')navigate('/knowledge/'+target.dataset.id);
  else if(act==='back-kb')navigate('/knowledge');
  else if(act==='login-demo'){if(!roles[target.dataset.role])return;state.role=target.dataset.role;state.currentUser=null;navigate(navigation[state.role][0][0]);await loadData();}
  else if(act==='voice-start')await startVoice();
  else if(act==='voice-stop')stopVoice();
  else if(act==='upload-audio')root.querySelector('#audio-file')?.click();
  else if(act==='select-image')root.querySelector('#incident-image')?.click();
  else if(act==='close-modal')closeModal();
 });
 root.addEventListener('submit',async e=>{
  const f=e.target;if(!f.id)return;e.preventDefault();
  if(f.id==='stats-form')await requestStats(f);
  else if(f.id==='edit-ticket-form'){if(state.role==='dispatcher'){const fd=new FormData(f);try{await API.updateTicket(f.dataset.id,{subject:String(fd.get('subject')||'').trim(),description:String(fd.get('description')||'').trim()});closeModal();await loadData();toast('Текст заявки сохранён','success');}catch(e){toast('Ошибка редактирования: '+e.message,'error');}}}
 });
 root.addEventListener('input',e=>{
  if(e.target.id==='intake-text')state.submissionText=e.target.value;
  if(e.target.id==='ticket-search'){state.ticketQuery=e.target.value;render();root.querySelector('#ticket-search')?.focus();root.querySelector('#ticket-search')?.setSelectionRange(state.ticketQuery.length,state.ticketQuery.length);}
  if(e.target.id==='kb-search'){state.kbQuery=e.target.value;render();root.querySelector('#kb-search')?.focus();root.querySelector('#kb-search')?.setSelectionRange(state.kbQuery.length,state.kbQuery.length);}
  if(e.target.id==='engineers-search'&&state.role==='admin'){state.engineersQuery=e.target.value;render();const field=root.querySelector('#engineers-search');field?.focus();field?.setSelectionRange(state.engineersQuery.length,state.engineersQuery.length);}
 });
 root.addEventListener('change',async e=>{
  if(e.target.id==='role-select'&&CFG.demoRoles&&!API.isDemo()){
   try{const response=await fetch('/workspace/api/demo-role',{method:'POST',headers:{'Content-Type':'application/json','X-CSRF-Token':CFG.csrfToken},body:JSON.stringify({role:e.target.value})});if(!response.ok)throw new Error('Не удалось изменить роль');location.hash='';location.reload();}catch(err){toast(err.message,'error');render();}return;
  }
  if(e.target.id==='role-select'&&API.isDemo()){if(!roles[e.target.value])return;state.role=e.target.value;state.currentUser=null;state.ticketQuery='';state.kbQuery='';state.draft=null;navigate(navigation[state.role][0][0]);await loadData();}
  if(e.target.id==='ticket-filter-status'){state.ticketStatus=e.target.value;render();}
  if(e.target.id==='ticket-filter-priority'){state.ticketPriority=e.target.value;render();}
  if(e.target.id==='manual-action-count'||e.target.id==='ai-action-count'){const key=e.target.id==='manual-action-count'?'manual':'ai';state.metricCounts[key]=e.target.value;state.metricsOpen=true;render();}
  if(e.target.id==='audio-file'&&e.target.files?.[0]){const file=e.target.files[0];await transcribe(file,file.name);}
  if(e.target.id==='incident-image'&&e.target.files?.[0]){const file=e.target.files[0];if(file.size>5*1024*1024||!['image/png','image/jpeg','image/webp'].includes(file.type))return toast('Поддерживаются PNG/JPG/WebP до 5 МБ','error');try{const result=await API.ocr(file);if(!result.text)throw Error('Текст на изображении не найден');state.submissionText=result.text;state.draft=null;state.intakeSource='text';render();toast('Текст распознан','success');}catch(error){toast(error.message,'error');}}
 });
 window.addEventListener('hashchange',render);
}
attach();
loadData();
setInterval(()=>{
 refreshCountdowns();
},1000);
setInterval(()=>{
 if(CFG.mode==='api'&&state.role==='engineer'&&route()==='/assigned'&&document.visibilityState==='visible'&&!root.querySelector('.modal-backdrop'))refreshEngineerQueue(false);
},15000);
})();
