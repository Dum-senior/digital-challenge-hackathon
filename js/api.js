(function(global){
  'use strict';
  const cfg = global.DISPATCH_CONFIG;
  const KEY = 'dispatcher_ii_demo_v4_2'; // Keep existing demo data across frontend updates.
  const sleep = ms => new Promise(resolve => setTimeout(resolve, ms));
  const upcomingDeadline = (minutes) => new Date(Date.now()+minutes*60000).toISOString();
  const copy = x => JSON.parse(JSON.stringify(x));

  const seed = () => ({
    ticketSeq: 1049,
    noteSeq: 10,
    pendingSeq: 201,
    pending: [],
    tickets: [
      {id:1048,subject:'Принтер не печатает и дымится',description:'Принтер не печатает, из него идёт дым и пахнет гарью. Кабинет 205.',priority:'critical',category:'Оргтехника / безопасность',status:'open',source:'text',reporterId:4,reporterName:'Алексей Смирнов',assigneeId:2,assigneeName:'Мария Иванова',location:'Кабинет 205',createdAt:new Date().toISOString(),responseDueAt:upcomingDeadline(9),notes:[{author:'Диспетчер',text:'Передано ответственному по безопасности.',time:'08:48'}]},
      {id:1047,subject:'Не работают 100 принтеров',description:'На двух этажах одновременно перестали работать примерно 100 принтеров. Возможно, проблема с сервером печати.',priority:'high',category:'Инфраструктура',status:'in_progress',source:'voice',reporterId:5,reporterName:'Елена Ким',assigneeId:3,assigneeName:'Олег Петров',location:'Офис, этажи 2–3',createdAt:new Date().toISOString(),responseDueAt:upcomingDeadline(24),notes:[]},
      {id:1046,subject:'В принтере закончился тонер',description:'Принтер в кабинете 214 сообщает, что закончился тонер.',priority:'low',category:'Оргтехника',status:'open',source:'text',reporterId:4,reporterName:'Алексей Смирнов',assigneeId:2,assigneeName:'Мария Иванова',location:'Кабинет 214',createdAt:new Date().toISOString(),responseDueAt:upcomingDeadline(90),notes:[]},
      {id:1045,subject:'Не удаётся подключиться к VPN',description:'Не подключается корпоративный VPN, ошибка аутентификации.',priority:'medium',category:'Доступ и сети',status:'resolved',source:'public',reporterId:5,reporterName:'Елена Ким',assigneeId:3,assigneeName:'Олег Петров',location:'Удалённо',createdAt:'2026-11-13T13:18:00',notes:[{author:'Олег Петров',text:'Обновлены настройки профиля, подключение восстановлено.',time:'14:03'}]},
      {id:1043,subject:'Восстанавливается приложение на рабочей станции',description:'После обновления программа перестала запускаться; инженер выполняет восстановление.',priority:'medium',category:'ПО и учётные записи',status:'in_progress',source:'text',reporterId:4,reporterName:'Алексей Смирнов',assigneeId:2,assigneeName:'Мария Иванова',location:'Кабинет 214',createdAt:new Date().toISOString(),notes:[]},
      {id:1042,subject:'Поврежден кабель принтера — требуется ремонт',description:'У принтера повреждён кабель подключения, требуется осмотр и ремонт уполномоченным специалистом.',priority:'medium',category:'Оргтехника',status:'open',repairRequired:true,source:'text',reporterId:5,reporterName:'Елена Ким',assigneeId:2,assigneeName:'Мария Иванова',location:'Кабинет 207',createdAt:new Date().toISOString(),responseDueAt:upcomingDeadline(80),notes:[]},
      {id:1044,subject:'Проблема со входом в приложение',description:'При авторизации появляется ошибка доступа.',priority:'medium',category:'ПО и учётные записи',status:'closed',source:'text',reporterId:4,reporterName:'Алексей Смирнов',assigneeId:2,assigneeName:'Мария Иванова',location:'Офис',createdAt:'2026-11-12T10:30:00',notes:[]}
    ],
    users: [
      {id:1,name:'Ирина Соколова',email:'admin@example.test',role:'admin',status:'active'},
      {id:2,name:'Мария Иванова',email:'m.ivanova@example.test',role:'engineer',status:'active',department:'Поддержка рабочих мест',competencies:['Принтеры и МФУ','Оргтехника','Рабочие станции','Установка ПО']},
      {id:3,name:'Олег Петров',email:'o.petrov@example.test',role:'engineer',status:'active',department:'Сети и инфраструктура',competencies:['Сети и VPN','Сервер печати','Сетевая диагностика','Серверы']},
      {id:4,name:'Алексей Смирнов',email:'a.smirnov@example.test',role:'reporter',status:'active'},
      {id:5,name:'Елена Ким',email:'e.kim@example.test',role:'reporter',status:'active'},
      {id:6,name:'Анна Белова',email:'a.belova@example.test',role:'dispatcher',status:'active'}
    ],
    articles: [
      {id:1,title:'Замена картриджа и проверка тонера',category:'Оргтехника',description:'Инструкция для случая, когда закончился тонер или краска.',content:['Проверьте сообщение на панели принтера и уровень расходных материалов.','Уточните модель устройства и подходящий картридж.','Организуйте безопасную замену согласно инструкции производителя.','Если сообщение сохраняется, передайте заявку специалисту по оргтехнике.']},
      {id:2,title:'Массовый отказ принтеров: первичная диагностика',category:'Инфраструктура',description:'Когда одновременно недоступно много устройств, проверяем общие зависимости.',content:['Уточните количество затронутых устройств, площадки и время начала сбоя.','Проверьте доступность серверов печати и сегментов сети штатными средствами мониторинга.','Проверьте наличие общих ошибок в очередях печати.','Эскалируйте массовый инцидент инженерам инфраструктуры; не объявляйте причину установленной без проверки.']},
      {id:3,title:'Дым или запах гари от офисного оборудования',category:'Безопасность',description:'Первичные действия при потенциально опасной неисправности.',content:['Немедленно прекратите эксплуатацию оборудования.','При угрозе людям действуйте согласно инструкциям пожарной безопасности объекта и уведомите ответственных.','Не перезапускайте и не разбирайте дымящееся оборудование.','Передайте инцидент уполномоченным специалистам.']},
      {id:4,title:'Диагностика подключения к VPN',category:'Сети',description:'Безопасная проверка учётной записи и соединения.',content:['Проверьте состояние подключения и текст ошибки.','Уточните время возникновения проблемы и доступность других сервисов.','Проверьте действительность учётных данных через утверждённую процедуру.','Передайте системному администратору при отсутствии решения.']},
      {id:5,title:'Не получается войти в приложение',category:'Учётные записи',description:'Что проверить при ошибке доступа к сервису.',content:['Уточните название сервиса и точное сообщение об ошибке.','Проверьте корректность имени пользователя и сетевого подключения.','Убедитесь, что есть право доступа через штатный канал поддержки.','Не запрашивайте пароль пользователя в тексте заявки.']},
      {id:6,title:'Первичная обработка обращения',category:'Общие вопросы',description:'Что уточнять, если проблема описана недостаточно подробно.',content:['Уточните объект, помещение и тип оборудования.','Спросите, когда возникла неисправность.','Выясните, сколько пользователей затронуто.','Уточните, имеются ли признаки опасности, затем назначайте исполнителя.']}
    ],
    categories: ['Оргтехника', 'Инфраструктура', 'ПО и учётные записи', 'Доступ и сети', 'Безопасность'],
    audit: [
      {when:'14.11.2026 08:48',actor:'Анна Белова',action:'Эскалация опасного обращения',target:'#1048'},
      {when:'14.11.2026 08:21',actor:'Анна Белова',action:'Назначен инженер',target:'#1047'},
      {when:'13.11.2026 14:03',actor:'Олег Петров',action:'Закрыта диагностика',target:'#1045'}
    ]
  });

  function load(){
    try { const saved = JSON.parse(localStorage.getItem(KEY)); if(saved && saved.tickets && saved.users)return saved; }
    catch(_){}
    const state=seed();save(state);return state;
  }
  function save(s){localStorage.setItem(KEY,JSON.stringify(s));}
  function analyzeDemo(text){
    const x=text.toLowerCase();
    const smoke=/дым|дымит|задым|гар[ьи]|горит|пожар|искр/.test(x);
    const mass=/\b100\b|сотн|все принтер|массов|десятки принтер|много принтер|сто принтер/.test(x);
    const toner=/тонер|картридж|краск|чернил/.test(x);
    const printer=/принтер|печата|картридж|тонер/.test(x);
    let priority='medium',category='Прочее',reason='Недостаточно сведений для точной оценки; требуется уточнение.',group='Линия поддержки',assigneeId=null,assigneeName='Не выбран',questions=[];
    if(printer){category='Оргтехника';group='Поддержка оргтехники';assigneeId=2;assigneeName='Мария Иванова';}
    if(toner){priority='low';reason='Похоже на штатную замену расходного материала, признаки опасности не обнаружены.';}
    if(mass){priority='high';category='Инфраструктура';reason='Затронуто большое число принтеров; возможна общая инфраструктурная причина.';group='ИТ-инфраструктура';assigneeId=3;assigneeName='Олег Петров';}
    if(smoke){priority='critical';category='Оргтехника / безопасность';reason='Дым/запах гари означает потенциальную угрозу безопасности.';group='Ответственный за безопасность';assigneeId=null;assigneeName='Назначить после эскалации';}
    if(!printer && !smoke){questions=['Какое именно устройство или сервис не работает?','Где возникла проблема и сколько пользователей затронуто?'];}
    return {
      draft_id:'demo-'+Date.now(),status:questions.length?'needs_clarification':'ready_for_review',raw_text:text,
      extracted:{subject:text.length>72?text.slice(0,69)+'…':text,description:text,category,location_name:'',equipment:printer?'Принтер':null,object:'',floor:'',room:'',ip:'',serial_number:'',symptoms:smoke?['дым','запах гари']:mass?['массовый отказ печати']:toner?['нет тонера']:[],tags:smoke?['безопасность','оргтехника']:mass?['массовый инцидент','печать']:toner?['картридж']:[],affected_scope:mass?'~100 устройств':'не уточнено',is_potential_emergency:smoke},
      recommendation:{priority,priority_id:null,group_name:group,assignee_id:assigneeId,assignee_name:assigneeName,reason},
      questions,requires_human_review:true
    };
  }
  function getSolution(t){
    const d=load();const desc=(t.description+' '+t.subject).toLowerCase();
    let ids=[];
    if(/дым|гар[ьи]|горит/.test(desc))ids=[3];
    else if(/100|массов|сервер.*печати|все принтер/.test(desc))ids=[2];
    else if(/тонер|картридж|краск/.test(desc))ids=[1];
    else if(/vpn/.test(desc))ids=[4];
    else if(/вход|авторизац/.test(desc))ids=[5];
    const articles=d.articles.filter(a=>ids.includes(a.id));
    return {ticket_id:t.id,status:articles.length?'answered':'no_sources',requires_human_review:true,
      steps:articles.flatMap(a=>a.content),sources:articles.map(a=>({type:'kb',id:a.id,title:a.title,url:'#/knowledge/'+a.id})),
      summary:articles.length?'Рекомендации из учебной базы знаний. Не заменяют регламенты предприятия.':'Подтверждённые источники по этому обращению пока не найдены.'};
  }

  async function real(path,opts={}){
    const headers=Object.assign({'Accept':'application/json'},opts.headers||{});
    if(cfg.token)headers.Authorization='Bearer '+cfg.token;
    if(cfg.csrfToken)headers['X-CSRF-Token']=cfg.csrfToken;
    if(opts.body && !(opts.body instanceof FormData) && !headers['Content-Type'])headers['Content-Type']='application/json';
    const res=await fetch(cfg.apiBase+path,{...opts,headers,credentials:'same-origin'});
    let data;const txt=await res.text();try{data=txt?JSON.parse(txt):{}}catch(_){data={message:txt||'Некорректный JSON-ответ'};}
    if(!res.ok)throw new Error(data.message||data.error||('HTTP '+res.status));
    return data;
  }
  async function withDemo(fn,realCall){if(cfg.mode==='api')return realCall();await sleep(260);return fn();}
  const API={
    isDemo:()=>cfg.mode!=='api',
    async listTickets(){return withDemo(()=>copy(load().tickets),()=>real('/tickets',{method:'GET'}));},
    async getTicket(id){return withDemo(()=>copy(load().tickets.find(t=>String(t.id)===String(id))||null),()=>real('/tickets/'+encodeURIComponent(id)));},
    async analyze(text,source='text'){return withDemo(()=>analyzeDemo(text),()=>real('/intake/analyze',{method:'POST',body:JSON.stringify({text,source})}));},
    async clarify(draftId,answers){return withDemo(()=>analyzeDemo(answers),()=>real('/intake/drafts/'+encodeURIComponent(draftId)+'/clarify',{method:'POST',body:JSON.stringify({text:answers})}));},
    async confirm(draftId,changes){return withDemo(()=>{
      const d=load();const t={id:d.ticketSeq++,subject:changes.subject||changes.description.slice(0,70),description:changes.description,category:changes.category,
        priority:changes.priority,status:'open',source:changes.source||'text',reporterId:changes.reporterId||4,reporterName:changes.reporterName||'Алексей Смирнов',
        assigneeId:changes.assigneeId||null,assigneeName:changes.assigneeName||'Не назначен',location:changes.location||'Не указано',extracted:changes.extracted||{},createdAt:new Date().toISOString(),responseDueAt:upcomingDeadline(changes.priority==='critical'?10:changes.priority==='high'?30:120),notes:[]};
      d.tickets.unshift(t);d.audit.unshift({when:new Date().toLocaleString('ru-RU'),actor:'Анна Белова',action:'Создана заявка',target:'#'+t.id});save(d);return {ticket_id:t.id,ticket:t};
    },()=>real('/intake/drafts/'+encodeURIComponent(draftId)+'/confirm',{method:'POST',body:JSON.stringify(changes)}));},
    async submitPublic(data){return withDemo(()=>{
      const s=load();const request={id:s.pendingSeq++,description:data.description,name:data.name||'Заявитель',location:data.location||'',source:'public',reporterId:4,createdAt:new Date().toISOString()};s.pending.unshift(request);save(s);return{request_id:request.id,status:'pending_review'};
    },()=>real('/public/tickets',{method:'POST',body:JSON.stringify(data)}));},
    async listPending(){return withDemo(()=>copy(load().pending||[]),()=>real('/intake/submissions'));},
    async markPendingProcessed(id,ticketId){return withDemo(()=>{const s=load();s.pending=(s.pending||[]).filter(x=>String(x.id)!==String(id));save(s);return {ticket_id:ticketId};},async()=>({ticket_id:ticketId}));},
    async updateTicket(id,changes){return withDemo(()=>{
      const s=load(),t=s.tickets.find(x=>String(x.id)===String(id));if(!t)throw Error('Заявка не найдена');Object.assign(t,changes);s.audit.unshift({when:new Date().toLocaleString('ru-RU'),actor:'Демо-пользователь',action:'Изменение заявки',target:'#'+id});save(s);return copy(t);
    },()=>real('/tickets/'+encodeURIComponent(id)+'/update',{method:'POST',body:JSON.stringify(changes)}));},
    async addNote(id,text){return withDemo(()=>{
      const s=load(),t=s.tickets.find(x=>String(x.id)===String(id));if(!t)throw Error('Заявка не найдена');t.notes.push({author:'Текущий сотрудник',text,time:new Date().toLocaleTimeString('ru-RU',{hour:'2-digit',minute:'2-digit'})});save(s);return copy(t);
    },()=>real('/tickets/'+encodeURIComponent(id)+'/notes',{method:'POST',body:JSON.stringify({text})}));},
    async engineerAssist(id){return withDemo(()=>{const t=load().tickets.find(x=>String(x.id)===String(id));if(!t)throw Error('Заявка не найдена');return getSolution(t);},()=>real('/tickets/'+encodeURIComponent(id)+'/engineer-assist',{method:'POST'}));},
    async ticketStatistics({from='',to=''}={}){return withDemo(()=>{
      const tickets=load().tickets.filter(t=>{
        const day=(t.createdAt||'').slice(0,10);
        return (!from||day>=from)&&(!to||day<=to);
      });
      const counter=(key,values)=>Object.fromEntries(values.map(v=>[v,tickets.filter(t=>t[key]===v).length]));
      return {from,to,total:tickets.length,open:tickets.filter(t=>!['closed','resolved'].includes(t.status)).length,
        resolved:tickets.filter(t=>['closed','resolved'].includes(t.status)).length,
        critical:tickets.filter(t=>t.priority==='critical').length,
        byPriority:counter('priority',['low','medium','high','critical']),
        byStatus:counter('status',['new','open','in_progress','resolved','closed'])};
    },()=>real('/admin/reports/tickets?'+new URLSearchParams({from,to}).toString()));},
    async createArticle(article){return withDemo(()=>{
      const s=load();const id=Math.max(0,...s.articles.map(a=>Number(a.id)||0))+1;
      const created={id,...article,content:Array.isArray(article.content)?article.content:String(article.content||'').split('\n').filter(Boolean)};
      s.articles.unshift(created);save(s);return copy(created);
    },()=>real('/admin/kb/articles',{method:'POST',body:JSON.stringify(article)}));},
    async updateArticle(id,changes){return withDemo(()=>{
      const s=load(),a=s.articles.find(a=>String(a.id)===String(id));if(!a)throw Error('Статья не найдена');
      Object.assign(a,changes);save(s);return copy(a);
    },()=>real('/admin/kb/articles/'+encodeURIComponent(id),{method:'PATCH',body:JSON.stringify(changes)}));},
    async deleteArticle(id){return withDemo(()=>{
      const s=load();if(!s.articles.some(a=>String(a.id)===String(id)))throw Error('Статья не найдена');
      s.articles=s.articles.filter(a=>String(a.id)!==String(id));save(s);return {deleted:true};
    },()=>real('/admin/kb/articles/'+encodeURIComponent(id),{method:'DELETE'}));},
    async importWebsite(url){return withDemo(()=>{
      const x=new URL(url);if(!['http:','https:'].includes(x.protocol))throw Error('Нужна ссылка http/https');
      const s=load(),id=Math.max(0,...s.articles.map(a=>Number(a.id)||0))+1;
      const item={id,title:'Источник: '+x.hostname,category:'Сайт',description:'Демо-ссылка. В режиме DEMO сайт не скачивается и не индексируется.',content:[],source_url:url,import_status:'demo_only'};
      s.articles.unshift(item);save(s);return copy(item);
    },()=>real('/admin/kb/import-website',{method:'POST',body:JSON.stringify({url})}));},
    async listArticles(){return withDemo(()=>copy(load().articles),()=>real('/kb/articles'));},
    async getArticle(id){return withDemo(()=>copy(load().articles.find(a=>String(a.id)===String(id))||null),()=>real('/kb/articles/'+encodeURIComponent(id)));},
    async listEngineersDashboard(){return withDemo(()=>{
      const d=load(),defaults=seed().users;
      return {engineers:d.users.filter(u=>u.role==='engineer').map(u=>{
        // Earlier demo releases stored users without competencies. Restore only
        // the bundled demo-profile fields for the same seeded identities.
        const preset=defaults.find(p=>p.id===u.id&&p.name===u.name);
        return {id:u.id,name:u.name,department:u.department??preset?.department??null,
          competencies:Array.isArray(u.competencies)?u.competencies:(preset?.competencies||[]),
          tickets:copy(d.tickets.filter(t=>String(t.assigneeId)===String(u.id)))};
      })};
    },()=>real('/admin/engineers'));},
    async listUsers(){return withDemo(()=>copy(load().users),()=>real('/admin/users'));},
    async listAudit(){return withDemo(()=>copy(load().audit),()=>real('/admin/audit'));},
    async listCategories(){return withDemo(()=>copy(load().categories),()=>real('/meta'));},
    async health(){return withDemo(()=>({mode:'demo',status:'demo',services:[{name:'Backend API',status:'demo'},{name:'MariaDB',status:'demo'},{name:'Ollama',status:'demo'},{name:'Голосовой ИИ',status:'demo'}]}),()=>real('/health'));},
    async uploadTicketImage(id,file){
      if(cfg.mode!=='api')throw Error('Учебный режим не загружает изображения: файл не сохранён.');
      const fd=new FormData();fd.append('image',file,file.name);return real('/tickets/'+encodeURIComponent(id)+'/attachments',{method:'POST',body:fd});
    },
    async demoCriticalTicket(assigneeId,assigneeName){
      if(cfg.mode==='api')throw Error('Запрещено в рабочем режиме');
      const s=load(),id=s.ticketSeq++;s.tickets.unshift({id,subject:'Принтер дымится — срочная реакция',description:'Демо-инцидент: принтер не печатает, из него идёт дым. Нужна срочная реакция.',priority:'critical',category:'Оргтехника / безопасность',status:'open',source:'text',reporterId:4,reporterName:'Алексей Смирнов',assigneeId,assigneeName,location:'Кабинет 205',createdAt:new Date().toISOString(),responseDueAt:upcomingDeadline(7),notes:[]});save(s);return {ticket_id:id};
    },
    async transcribe(audioBlob,filename='recording.webm'){
      if(cfg.mode!=='api')throw new Error('В демо-режиме настоящее распознавание речи недоступно. Подключите API Вани.');
      const fd=new FormData();fd.append('audio',audioBlob,filename);fd.append('source','browser');
      return real('/intake/transcriptions',{method:'POST',body:fd});
    },
    async addUser(user){return withDemo(()=>{const s=load();const id=Math.max(...s.users.map(u=>u.id))+1;s.users.push({id,...user,status:'active'});save(s);return {id};},()=>real('/admin/users',{method:'POST',body:JSON.stringify(user)}));},
    resetDemo(){if(cfg.mode==='api')throw Error('Нельзя сбрасывать данные через UI в API-режиме');save(seed());}
  };
  global.DispatchAPI=API;
})(window);
