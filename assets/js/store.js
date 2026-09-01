/* ============ NAGARE · Data Store (Laravel + MySQL) ============ */

const DEPARTMENTS = ['Design', 'Development', 'Content', 'Marketing', 'SEO'];

const DEPT_COLOR = {
  Design:      { bg:'rgba(122,92,62,.13)',  fg:'#7a5c3e' },
  Development: { bg:'rgba(58,110,165,.13)',  fg:'#3a6ea5' },
  Content:     { bg:'rgba(201,162,95,.18)',  fg:'#a97e2e' },
  Marketing:   { bg:'rgba(217,74,61,.12)',   fg:'#d94a3d' },
  SEO:         { bg:'rgba(63,163,77,.14)',   fg:'#3fa34d' },
};

/* Auto-delegation rules: keyword -> department (first match wins) */
const DEFAULT_RULES = [
  { kw: 'logo,brand,poster,banner,design,graphic,ui,ux,mockup,creative', dept: 'Design' },
  { kw: 'website,web,app,bug,code,develop,api,landing page,wordpress,html', dept: 'Development' },
  { kw: 'blog,article,copy,caption,content,write,script,newsletter', dept: 'Content' },
  { kw: 'ad,ads,campaign,promo,social,instagram,facebook,marketing,launch', dept: 'Marketing' },
  { kw: 'seo,keyword,rank,search,backlink,meta,traffic', dept: 'SEO' },
];

const now = () => Date.now();
const mins = (m) => m * 60 * 1000;
const uid = () => Math.random().toString(36).slice(2, 9);

function seed() {
  const team = [
    { id:'u_admin_sales', name:'Sales Admin', role:'admin', dept:null, color:'#7a5c3e' },
    { id:'u_admin_ceo', name:'CEO Admin', role:'admin', dept:null, color:'#c9a25f' },
    { id:'u_admin_agam', name:'Agam Bahri', role:'admin', dept:null, color:'#3a6ea5' },
    { id:'u_riya',  name:'Riya Kapoor', role:'team', dept:'Design', color:'#c9a25f' },
    { id:'u_arjun', name:'Arjun Mehta', role:'team', dept:'Development', color:'#3a6ea5' },
    { id:'u_sara',  name:'Sara Khan',   role:'team', dept:'Content', color:'#3fa34d' },
    { id:'u_dev',   name:'Dev Sharma',  role:'team', dept:'Marketing', color:'#d94a3d' },
    { id:'u_neha',  name:'Neha Rao',    role:'team', dept:'SEO', color:'#8a6d3b' },
  ];
  const clients = [
    { id:'c_lumen', name:'Priya Nair', company:'Lumen Cafe', email:'priya@lumencafe.com', phone:'+91 98xxx xxx01', color:'#7a5c3e' },
    { id:'c_volt',  name:'Karan Shah', company:'Volt Fitness', email:'karan@voltfit.in', phone:'+91 98xxx xxx02', color:'#3a6ea5' },
    { id:'c_bloom', name:'Ananya Das', company:'Bloom Skincare', email:'ananya@bloom.co', phone:'+91 98xxx xxx03', color:'#3fa34d' },
  ];
  const users = [...team, ...clients.map(c => ({ id:c.id, name:c.name, role:'client', clientId:c.id, company:c.company, color:c.color }))];

  const T = now();
  const tasks = [
    { id:uid(), clientId:'c_lumen', title:'New menu poster for winter specials', desc:'Need an A3 poster, warm tones, 6 dishes highlighted.', dept:'Design', ownerId:'u_riya', status:'in_progress', priority:'high', createdAt:T-mins(180), stageAt:T-mins(31), dueDate:T+mins(1440), recurring:null },
    { id:uid(), clientId:'c_lumen', title:'Weekly Instagram carousel', desc:'3 slides on new coffee blend.', dept:'Content', ownerId:'u_sara', status:'review', priority:'med', createdAt:T-mins(300), stageAt:T-mins(12), dueDate:T+mins(600), recurring:'weekly' },
    { id:uid(), clientId:'c_volt', title:'Landing page bug — signup form not submitting', desc:'Form throws error on mobile Safari.', dept:'Development', ownerId:'u_arjun', status:'in_progress', priority:'high', createdAt:T-mins(90), stageAt:T-mins(47), dueDate:T+mins(300), recurring:null },
    { id:uid(), clientId:'c_volt', title:'Google Ads campaign for Jan promo', desc:'Budget ₹20k, target 25-40 fitness audience.', dept:'Marketing', ownerId:'u_dev', status:'todo', priority:'med', createdAt:T-mins(60), stageAt:T-mins(60), dueDate:T+mins(2880), recurring:null },
    { id:uid(), clientId:'c_bloom', title:'SEO audit + keyword plan', desc:'Full audit of bloom.co, top 20 keywords.', dept:'SEO', ownerId:'u_neha', status:'in_progress', priority:'low', createdAt:T-mins(240), stageAt:T-mins(18), dueDate:T+mins(4320), recurring:'monthly' },
    { id:uid(), clientId:'c_bloom', title:'Rebrand logo concepts', desc:'3 directions, minimal, botanical feel.', dept:'Design', ownerId:'u_riya', status:'todo', priority:'med', createdAt:T-mins(120), stageAt:T-mins(120), dueDate:T+mins(5760), recurring:null },
    { id:uid(), clientId:'c_lumen', title:'Blog: "5 winter drinks" article', desc:'800 words, SEO friendly.', dept:'Content', ownerId:'u_sara', status:'done', priority:'low', createdAt:T-mins(600), stageAt:T-mins(200), dueDate:T-mins(60), recurring:null },
    { id:uid(), clientId:'c_volt', title:'Monthly performance report', desc:'Auto-generated every 1st.', dept:'Marketing', ownerId:'u_dev', status:'new', priority:'med', createdAt:T-mins(20), stageAt:T-mins(20), dueDate:T+mins(1440), recurring:'monthly' },
  ];

  const tid = tasks[0].id, tid2 = tasks[2].id;
  const messages = [
    { id:uid(), clientId:'c_lumen', taskId:tid, fromId:'c_lumen', fromRole:'client', text:'Hi team! Can we make the poster feel cozy and warm? Winter vibes 🙂', voice:null, at:T-mins(170) },
    { id:uid(), clientId:'c_lumen', taskId:tid, fromId:'u_riya',  fromRole:'team', text:'Absolutely Priya — starting on warm tones now. Will share a draft by evening.', voice:null, at:T-mins(160) },
    { id:uid(), clientId:'c_lumen', taskId:tid, fromId:'c_lumen', fromRole:'client', text:'Perfect, thank you!', voice:null, at:T-mins(150) },
    { id:uid(), clientId:'c_volt', taskId:tid2, fromId:'c_volt', fromRole:'client', text:'The signup bug is urgent, we are losing leads. Please prioritise 🙏', voice:null, at:T-mins(88) },
    { id:uid(), clientId:'c_volt', taskId:tid2, fromId:'u_arjun', fromRole:'team', text:'On it Karan — reproduced on iOS Safari, fixing the validation now.', voice:null, at:T-mins(80) },
  ];

  const activity = [
    { id:uid(), at:T-mins(20), text:'New brief received from Volt Fitness → awaiting admin assignment', type:'brief' },
    { id:uid(), at:T-mins(47), text:'Arjun moved "Landing page bug" to In Progress', type:'move' },
    { id:uid(), at:T-mins(88), text:'Karan Shah (Volt Fitness) sent a message', type:'msg' },
  ];

  const notifications = [
    { id:uid(), channel:'whatsapp', to:'+91 98xxx xxx02', text:'✅ Your request "Landing page bug" was received. An admin will assign it shortly.', at:T-mins(88) },
    { id:uid(), channel:'email', to:'karan@voltfit.in', text:'Subject: We got your request — Landing page bug', at:T-mins(88) },
  ];

  return {
    users, clients, projects: [], tasks, messages, activity, notifications,
    rules: DEFAULT_RULES,
    settings: { amberMin: 15, redMin: 25 },
  };
}

const Store = {
  data: null,
  async load() {
    const response = await fetch('/api/state', { headers: { Accept: 'application/json' } });
    if (!response.ok) throw new Error('Unable to load application data');
    this.data = await response.json();
    return this.data;
  },
  async save() {
    const response = await fetch('/api/state', {
      method: 'PUT',
      headers: { 'Content-Type': 'application/json', Accept: 'application/json' },
      body: JSON.stringify(this.data),
    });
    if (!response.ok) throw new Error('Unable to save application data');
  },
  async reset() {
    const response = await fetch('/api/state/reset', {
      method: 'POST',
      headers: { Accept: 'application/json' },
    });
    if (!response.ok) throw new Error('Unable to reset application data');
    this.data = await response.json();
  },
  async sendChatEmail(payload) {
    const response = await fetch('/api/chat-email', {method:'POST',headers:{'Content-Type':'application/json',Accept:'application/json'},body:JSON.stringify(payload)});
    const data = await response.json();
    if(!response.ok)throw new Error(data.message||'Unable to email the client');
    return data;
  },
};
