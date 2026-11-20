
const menuToggle=document.getElementById('menuToggle'),nav=document.getElementById('nav');
menuToggle.addEventListener('click',()=>{const o=nav.classList.toggle('open');menuToggle.setAttribute('aria-expanded',String(o));menuToggle.textContent=o?'×':'☰';});
nav.querySelectorAll('a').forEach(a=>a.addEventListener('click',()=>{nav.classList.remove('open');menuToggle.setAttribute('aria-expanded','false');menuToggle.textContent='☰';}));
document.getElementById('year').textContent=new Date().getFullYear();

const T={
en:{
dir:'ltr',font:'Inter,Arial,sans-serif',
nav:['Home','Services','About us','How it works','Contact'],top:['Reliable cargo. On time. Every time.','Request a quote'],
eyebrow:['YOUR TRUSTED TRANSPORT PARTNER','WHAT WE DO','ABOUT FBN GROUP','SIMPLE & STRAIGHTFORWARD','READY TO MOVE?','CONTACT OUR TEAM'],
hero:['Moving cargo.<br><em>Connecting</em> opportunities.','Dependable cargo and transport solutions for businesses that keep Afghanistan moving. From pickup to delivery, your shipment is in trusted hands.','Get a transport quote','Explore our services'],
trust:[['Reliable delivery','Careful planning at every step'],['Flexible cargo','Solutions for different shipment needs'],['Customer-first','Support from enquiry to delivery'],['Built on trust','Long-term business relationships']],
serviceTitle:'Transport solutions<br><em>that move business forward.</em>',serviceIntro:'Practical logistics support designed around your cargo, schedule and business requirements.',
services:[['Road Cargo Transport','Dependable road transport for commercial goods and general cargo, with attention to safe handling and timely coordination'],['Freight & Logistics','Organized freight support to help your goods move efficiently between suppliers, warehouses and customers'],['Commercial Transport','Transport coordination for companies, traders and organizations that need a reliable logistics partner']],
note:'Service availability, routes and cargo requirements are confirmed individually when you request a quote.',
aboutTitle:'Built on trust.<br><em>Driven by commitment.</em>',aboutText:'Ferdaws Belal Nabizada Group is a commercial and transport company focused on helping customers move goods with confidence. We value professional communication, dependable coordination and relationships built for the long term.',
values:[['Responsibility','We take every enquiry seriously.'],['Coordination','We keep the process clear and organized.'],['Partnership','Your business matters to us.']],aboutBtn:'Work with FBN Group',
processTitle:'From enquiry to <em>delivery.</em>',processIntro:'A clear process helps keep your shipment organized from the first conversation.',
steps:[['Tell us what you need','Share cargo details, pickup and destination, and your preferred timeline.'],['Receive a quote','We review the requirements and discuss availability, pricing and next steps.'],['Coordinate transport','Once confirmed, we coordinate the transport arrangements with you.'],['Stay in touch','Keep communication open with our team throughout the agreed process.']],
cta:'Let’s get your cargo<br><em>on the road.</em>',ctaBtn:'Request a quote',contactTitle:'Tell us about<br><em>your shipment.</em>',contactIntro:'Send your cargo details and we’ll have the information needed to discuss the next steps.',
contact:[['EMAIL','info@fbngroup.com'],['COMPANY','Ferdaws Belal Nabizada Group'],['BUSINESS ENQUIRIES','Request a quote using the form']],
formTitle:'Request a transport quote',formHint:'Fields marked * are required.',labels:['Your name *','Company','Email *','Phone / WhatsApp','Pickup location *','Destination *','Type of cargo','Approx. cargo weight','Shipment details'],placeholders:['Full name','Company name','you@company.com','+93 ...','City or address','City or address','','e.g. 2,000 kg','Cargo type, dimensions, preferred date, or other requirements'],
cargo:['General cargo','Commercial goods','Bulk cargo','Other / discuss with us'],submit:'Prepare email enquiry',formNote:'This demo form opens your email app with the enquiry prepared. It does not send or store your information on a server.',
footerTag:'CARGO · LOGISTICS · TRANSPORT',footerPromise:'Reliability in every mile.',footerNav:['Services','About','Contact'],mailStatus:'Your email app should open with the enquiry details. Please review and send it.'
},
fa:{
dir:'rtl',font:'Tahoma,Arial,sans-serif',
nav:['خانه','خدمات','درباره ما','مراحل کار','تماس با ما'],top:['انتقال مطمئن بار؛ به‌موقع و قابل اعتماد.','درخواست قیمت'],
eyebrow:['همراه قابل اعتماد ترانسپورتی شما','خدمات ما','درباره گروه اف‌بی‌ان','ساده و روشن','آماده انتقال هستید؟','با تیم ما تماس بگیرید'],
hero:['انتقال بار؛<br><em>پیوند فرصت‌ها</em>','راهکارهای قابل اعتماد انتقال بار و ترانسپورت برای تجارت شما. از دریافت تا تحویل، محموله شما با مسئولیت‌پذیری پیگیری می‌شود.','درخواست قیمت ترانسپورت','مشاهده خدمات'],
trust:[['تحویل قابل اعتماد','برنامه‌ریزی دقیق در هر مرحله'],['انتقال انعطاف‌پذیر','راهکار متناسب با نیاز محموله'],['مشتری در اولویت','پشتیبانی از درخواست تا تحویل'],['اعتماد پایدار','روابط تجارتی درازمدت']],
serviceTitle:'راهکارهای ترانسپورتی<br><em>برای پیشرفت تجارت شما</em>',serviceIntro:'پشتیبانی عملی لوژستیکی بر اساس نوع بار، زمان‌بندی و نیازهای تجارتی شما.',
services:[['انتقال بار از راه زمینی','انتقال قابل اعتماد اموال تجارتی و بار عمومی با توجه به مصونیت و هماهنگی به‌موقع'],['فریت و لوژستیک','هماهنگی منظم انتقال کالا میان عرضه‌کنندگان، گدام‌ها و مشتریان'],['ترانسپورت تجارتی','هماهنگی ترانسپورت برای شرکت‌ها، تاجران و سازمان‌ها']],
note:'موجودیت خدمات، مسیرها و شرایط انتقال بار هنگام درخواست قیمت به‌صورت جداگانه تأیید می‌شود.',
aboutTitle:'بر پایه اعتماد؛<br><em>با تعهد به خدمت</em>',aboutText:'شرکت تجارتی و ترانسپورتی فردوس بلال لمتید برای انتقال مطمئن کالا فعالیت می‌کند. ما به ارتباطات مسلکی، هماهنگی قابل اعتماد و همکاری‌های درازمدت ارزش می‌دهیم.',
values:[['مسئولیت‌پذیری','هر درخواست شما برای ما مهم است.'],['هماهنگی','مراحل کار را روشن و منظم نگه می‌داریم.'],['همکاری','تجارت شما برای ما ارزش دارد.']],aboutBtn:'همکاری با گروه اف‌بی‌ان',
processTitle:'از درخواست تا <em>تحویل</em>',processIntro:'یک روند روشن کمک می‌کند محموله شما از نخستین تماس تا تحویل منظم بماند.',
steps:[['نیاز خود را بیان کنید','نوع بار، محل دریافت، مقصد و زمان مورد نظر را شریک سازید.'],['دریافت قیمت','نیازها را بررسی کرده و درباره هزینه و مراحل بعدی صحبت می‌کنیم.'],['هماهنگی ترانسپورت','پس از تأیید، ترتیبات انتقال را با شما هماهنگ می‌سازیم.'],['در تماس بمانید','در جریان روند توافق‌شده با تیم ما در ارتباط باشید.']],
cta:'بیایید محموله شما را<br><em>به حرکت درآوریم.</em>',ctaBtn:'درخواست قیمت',contactTitle:'درباره محموله<br><em>خود به ما بگویید.</em>',contactIntro:'جزئیات محموله را بفرستید تا بتوانیم درباره مراحل بعدی صحبت کنیم.',
contact:[['ایمیل','info@fbngroup.com'],['شرکت','گروه فردوس بلال نبی‌زاده'],['درخواست تجارتی','از فورم درخواست قیمت استفاده کنید']],
formTitle:'درخواست قیمت ترانسپورت',formHint:'خانه‌های دارای * ضروری هستند.',labels:['نام شما *','شرکت','ایمیل *','تلفن / واتس‌اپ','محل دریافت *','مقصد *','نوع بار','وزن تقریبی بار','جزئیات محموله'],placeholders:['نام کامل','نام شرکت','you@company.com','+93 ...','شهر یا آدرس','شهر یا آدرس','','مثلاً ۲٬۰۰۰ کیلوگرام','نوع بار، اندازه، تاریخ مورد نظر یا نیازهای دیگر'],
cargo:['بار عمومی','اموال تجارتی','بار فله‌ای','سایر / مشوره با ما'],submit:'آماده‌سازی درخواست ایمیلی',formNote:'این فورم برنامه ایمیل شما را با جزئیات درخواست باز می‌کند؛ اطلاعات در سرور ذخیره یا خودکار ارسال نمی‌شود.',
footerTag:'ترانسپورت · لوژستیک · انتقال بار',footerPromise:'اعتماد در هر مسیر.',footerNav:['خدمات','درباره ما','تماس'],mailStatus:'برنامه ایمیل شما باید با جزئیات درخواست باز شود. لطفاً بررسی کرده و ارسال کنید.'
},
ps:{
dir:'rtl',font:'Tahoma,Arial,sans-serif',
nav:['کورپاڼه','خدمتونه','زموږ په اړه','د کار طریقه','اړیکه'],top:['باوري بار لېږد؛ پر وخت او هر ځل.','د بیې غوښتنه'],
eyebrow:['ستاسو باوري ترانسپورتي ملګری','زموږ خدمتونه','د FBN ډلې په اړه','ساده او روښانه','د لېږد لپاره چمتو یاست؟','زموږ له ډلې سره اړیکه'],
hero:['د بار لېږد؛<br><em>د فرصتونو نښلول</em>','د هغو سوداګریو لپاره د بار او ترانسپورټ باوري حللارې چې افغانستان په حرکت کې ساتي. له اخیستلو تر سپارلو پورې، ستاسو بار په باوري لاسونو کې دی.','د ترانسپورټ بیه وغواړئ','زموږ خدمتونه وګورئ'],
trust:[['باوري سپارنه','په هر پړاو کې دقیق پلان'],['انعطاف منونکی لېږد','د بار اړتیا مطابق حللارې'],['پېرودونکی لومړی','له غوښتنې تر سپارلو ملاتړ'],['پر باور ولاړ','اوږدمهاله سوداګریزې اړیکې']],
serviceTitle:'د ترانسپورټ حللارې<br><em>ستاسو سوداګري مخته وړي</em>',serviceIntro:'ستاسو د بار، مهال‌وېش او سوداګریزو اړتیاوو مطابق عملي لوژستیکي ملاتړ.',
services:[['د سړک له لارې د بار لېږد','د سوداګریزو توکو او عمومي بار باوري لېږد، د خوندي سمبالښت او پر وخت همغږۍ سره'],['فریټ او لوژستیک','د عرضه‌کوونکو، ګودامونو او پېرودونکو ترمنځ د توکو د منظم لېږد همغږي'],['سوداګریز ترانسپورټ','د شرکتونو، سوداګرو او ادارو لپاره د ترانسپورټ همغږي']],
note:'د خدمتونو شتون، لارې او د بار شرایط د بیې غوښتنې پر مهال جلا تاییدېږي.',
aboutTitle:'پر باور ولاړ؛<br><em>په ژمنتیا روان</em>',aboutText:'د فردوس بلال نبی‌زاده تجارتي او ترانسپورتي شرکت د توکو د باوري لېږد په موخه فعالیت کوي. موږ مسلکي اړیکو، باوري همغږۍ او اوږدمهاله همکاریو ته ارزښت ورکوو.',
values:[['مسؤلیت','ستاسو هره غوښتنه زموږ لپاره مهمه ده.'],['همغږي','د کار بهیر روښانه او منظم ساتو.'],['ملګرتیا','ستاسو سوداګري زموږ لپاره ارزښت لري.']],aboutBtn:'له FBN ډلې سره همکاري',
processTitle:'له غوښتنې تر <em>سپارلو</em>',processIntro:'روښانه کاري بهیر ستاسو بار له لومړۍ اړیکې تر سپارلو پورې منظم ساتي.',
steps:[['خپله اړتیا ووایاست','د بار معلومات، د اخیستلو ځای، منزل او مطلوب وخت شریک کړئ.'],['بیه ترلاسه کړئ','اړتیاوې ارزونه کوو او د لګښت او راتلونکو ګامونو په اړه خبرې کوو.'],['ترانسپورټ همغږی کړئ','له تایید وروسته د لېږد ترتیبات له تاسو سره همغږي کوو.'],['په اړیکه کې اوسئ','د هوکړه شوي بهیر په اوږدو کې له ډلې سره اړیکه وساتئ.']],
cta:'راځئ ستاسو بار<br><em>پر لاره واچوو.</em>',ctaBtn:'د بیې غوښتنه',contactTitle:'د خپل بار په اړه<br><em>موږ ته ووایاست.</em>',contactIntro:'د بار معلومات راولېږئ، څو د راتلونکو ګامونو په اړه خبرې وکړو.',
contact:[['برېښنالیک','info@fbngroup.com'],['شرکت','د فردوس بلال نبی‌زاده ډله'],['سوداګریزې پوښتنې','د بیې غوښتنې فورمه وکاروئ']],
formTitle:'د ترانسپورټ د بیې غوښتنه',formHint:'د * نښه لرونکي ځایونه اړین دي.',labels:['ستاسو نوم *','شرکت','برېښنالیک *','ټیلیفون / واټس‌اپ','د اخیستلو ځای *','منزل *','د بار ډول','د بار اټکلي وزن','د بار جزئیات'],placeholders:['بشپړ نوم','د شرکت نوم','you@company.com','+93 ...','ښار یا پته','ښار یا پته','','لکه ۲٬۰۰۰ کیلوګرامه','د بار ډول، اندازه، نېټه یا نورې اړتیاوې'],
cargo:['عمومي بار','سوداګریز توکي','فله‌یي بار','نور / له موږ سره مشوره'],submit:'د برېښنالیک غوښتنه چمتو کړئ',formNote:'دا فورمه ستاسو د ایمیل اپلېکېشن د غوښتنې له معلوماتو سره پرانیزي. معلومات په سرور کې نه ساتل کېږي او په خپله نه لېږل کېږي.',
footerTag:'بار لېږد · لوژستیک · ترانسپورټ',footerPromise:'په هره لاره کې باور.',footerNav:['خدمتونه','زموږ په اړه','اړیکه'],mailStatus:'ستاسو د ایمیل اپلېکېشن باید د غوښتنې معلومات پرانیزي. مهرباني وکړئ وګورئ او ولېږئ.'
},
zh:{
dir:'ltr',font:'Inter,"Microsoft YaHei",sans-serif',
nav:['首页','服务','关于我们','服务流程','联系我们'],top:['可靠运输，准时送达。','获取报价'],
eyebrow:['您值得信赖的运输伙伴','我们的服务','关于 FBN 集团','简单清晰','准备好发货了吗？','联系我们的团队'],
hero:['运送货物，<br><em>连接机遇。</em>','为推动阿富汗商业发展的企业提供可靠的货运与运输解决方案。从取货到交付，我们认真协调每一批货物。','获取运输报价','查看服务'],
trust:[['可靠交付','每个环节都认真规划'],['灵活运输','满足不同货运需求'],['客户优先','从咨询到交付全程支持'],['建立信任','发展长期商业合作']],
serviceTitle:'运输解决方案<br><em>助力业务不断前进</em>',serviceIntro:'根据您的货物、时间安排和业务需求，提供实用的物流支持。',
services:[['公路货运','为商业货物和普通货物提供可靠的公路运输，并重视安全处理和及时协调'],['货运与物流','协调供应商、仓库和客户之间的货物运输，让物流更有条理'],['商业运输','为需要可靠物流伙伴的公司、商户和机构协调运输服务']],
note:'具体服务、路线和货物要求将在您申请报价时逐项确认。',
aboutTitle:'以信任为基础，<br><em>以承诺为动力。</em>',aboutText:'Ferdaws Belal Nabizada Group 是一家商业与运输公司，致力于帮助客户安心运输货物。我们重视专业沟通、可靠协调以及长期合作关系。',
values:[['责任','认真对待每一项咨询。'],['协调','让运输流程清晰有序。'],['合作','您的业务对我们很重要。']],aboutBtn:'与 FBN 集团合作',
processTitle:'从咨询到<em>交付</em>',processIntro:'清晰的流程有助于从首次沟通开始妥善安排您的货物。',
steps:[['告知您的需求','提供货物信息、取货地点、目的地和期望时间。'],['获取报价','我们评估需求，并沟通运力、价格和后续步骤。'],['协调运输','确认后，我们将与您协调运输安排。'],['保持联系','在约定流程中与我们的团队保持沟通。']],
cta:'让我们把您的货物<br><em>安全送上路。</em>',ctaBtn:'获取报价',contactTitle:'告诉我们<br><em>您的货运需求。</em>',contactIntro:'请发送货物详情，方便我们与您讨论后续安排。',
contact:[['电子邮箱','info@fbngroup.com'],['公司','Ferdaws Belal Nabizada Group'],['商务咨询','请填写报价申请表']],
formTitle:'申请运输报价',formHint:'* 为必填项。',labels:['姓名 *','公司','电子邮箱 *','电话 / WhatsApp','取货地点 *','目的地 *','货物类型','大约货物重量','货运详情'],placeholders:['姓名','公司名称','you@company.com','+93 ...','城市或地址','城市或地址','','例如 2,000 公斤','货物类型、尺寸、期望日期或其他要求'],
cargo:['普通货物','商业商品','散装货物','其他 / 与我们沟通'],submit:'准备邮件咨询',formNote:'此演示表单会在您的邮件应用中准备好咨询内容，不会在服务器上发送或保存您的信息。',
footerTag:'货运 · 物流 · 运输',footerPromise:'每一段路程都值得信赖。',footerNav:['服务','关于我们','联系'],mailStatus:'您的邮件应用应会打开并显示咨询详情。请检查后发送。'
}};
function txt(sel,v,html=false){const e=document.querySelector(sel);if(e){if(html)e.innerHTML=v;else e.textContent=v;}}
function applyLang(code){
 const t=T[code]||T.en; document.documentElement.lang=code;document.documentElement.dir=t.dir;document.body.style.fontFamily=t.font;
 nav.querySelectorAll('a').forEach((e,i)=>{if(t.nav[i])e.textContent=t.nav[i]});
 txt('.topbar-inner > span',t.top[0]);txt('.top-links a:last-child',t.top[1]);
 document.querySelectorAll('.eyebrow').forEach((e,i)=>{if(t.eyebrow[i])e.lastChild.textContent=' '+t.eyebrow[i]});
 txt('#heroTitle',t.hero[0],true);txt('#heroText',t.hero[1]);
 document.querySelectorAll('.hero-actions a').forEach((e,i)=>{if(t.hero[i+2])e.childNodes[0].textContent=t.hero[i+2]+' '});
 document.querySelectorAll('.trust-grid > div').forEach((e,i)=>{if(t.trust[i]){txtFrom(e,'b',t.trust[i][0]);txtFrom(e,'small',t.trust[i][1])}});
 txt('.section-heading h2',t.serviceTitle,true);txt('.section-heading > p',t.serviceIntro);
 document.querySelectorAll('.service-card').forEach((e,i)=>{if(t.services[i]){txtFrom(e,'h3',t.services[i][0]);txtFrom(e,'.service-body p',t.services[i][1]);e.querySelector('.service-body a').childNodes[0].textContent=(code==='en'?'Discuss your shipment':code==='fa'?'درباره محموله بپرسید':code==='ps'?'د خپل بار په اړه اړیکه ونیسئ':'咨询货运需求')+' '}});
 txt('.services .note',t.note);txt('.about-copy h2',t.aboutTitle,true);txt('.about-copy > p:not(.dari)',t.aboutText);
 document.querySelectorAll('.about-values > div').forEach((e,i)=>{if(t.values[i]){txtFrom(e,'strong',t.values[i][0]);txtFrom(e,'small',t.values[i][1])}});
 txt('.about-copy .btn',t.aboutBtn);txt('.center-heading h2',t.processTitle,true);txt('.center-heading > p',t.processIntro);
 document.querySelectorAll('.step').forEach((e,i)=>{if(t.steps[i]){txtFrom(e,'h3',t.steps[i][0]);txtFrom(e,'p',t.steps[i][1])}});
 txt('.cta-band h2',t.cta,true);txt('.cta-band .btn',t.ctaBtn);txt('.contact-copy h2',t.contactTitle,true);txt('.contact-copy > p',t.contactIntro);
 document.querySelectorAll('.contact-detail').forEach((e,i)=>{if(t.contact[i]){txtFrom(e,'small',t.contact[i][0]);if(i!==1)txtFrom(e,'b',t.contact[i][1])}});
 txt('.form-heading h3',t.formTitle);txt('.form-heading p',t.formHint);
 const labs=document.querySelectorAll('.quote-form label');t.labels.forEach((v,i)=>{const e=labs[i];if(!e)return;const input=e.querySelector('input,select,textarea');e.childNodes[0].textContent=v;if(input&&t.placeholders[i]&&input.tagName!=='SELECT')input.placeholder=t.placeholders[i]});
 const cs=document.querySelector('select[name="cargo"]');if(cs)[...cs.options].forEach((o,i)=>{if(t.cargo[i])o.textContent=t.cargo[i]});
 txt('.submit-btn',t.submit);txt('.form-note',t.formNote);txt('.footer-brand .brand-copy small',t.footerTag);txt('.footer-bottom span:last-child',t.footerPromise);
 document.querySelectorAll('.footer-nav a').forEach((e,i)=>{if(t.footerNav[i])e.textContent=t.footerNav[i]});
 document.getElementById('formStatus').textContent='';
}
function txtFrom(parent,sel,v){const e=parent.querySelector(sel);if(e)e.textContent=v}
document.getElementById('langToggle').addEventListener('change',e=>applyLang(e.target.value));applyLang('en');
document.getElementById('quoteForm').addEventListener('submit',e=>{
 e.preventDefault();const d=new FormData(e.currentTarget),t=T[document.getElementById('langToggle').value]||T.en;
 const subject=encodeURIComponent('Cargo transport quote request — FBN Group');
 const body=encodeURIComponent(`New cargo transport enquiry\n\nName: ${d.get('name')}\nCompany: ${d.get('company')||'Not provided'}\nEmail: ${d.get('email')}\nPhone / WhatsApp: ${d.get('phone')||'Not provided'}\nPickup: ${d.get('pickup')}\nDestination: ${d.get('destination')}\nCargo type: ${d.get('cargo')}\nApprox. weight: ${d.get('weight')||'Not provided'}\n\nShipment details:\n${d.get('details')||'Not provided'}`);
 document.getElementById('formStatus').textContent=t.mailStatus;window.location.href=`mailto:info@fbngroup.com?subject=${subject}&body=${body}`;
});
