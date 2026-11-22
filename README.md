# AdPlatform — منصة إدارة وتحليل الإعلانات

منصة تربط حسابات الإعلانات (Meta, Google, TikTok, Snapchat)، تسحب بيانات الأداء، وتعتمد على محرك **Operations Research (OR)** كقلب المنصة: سيناريوهات ميزانية حتمية واقتراحات مبنية على البيانات المسحوبة فقط. لا يتم تنفيذ أي تغيير على الحملات أو الميزانيات من المنصة.

## البنية

- `backend/` — Laravel API (كل شيء تحت `/api/v1`).
- `frontend/` — Angular SPA.
- `looker-studio/` — كود Google Looker Studio Community Connector (اختياري).
- `vercel.json` — نشر Vercel متعدد الخدمات: الـfrontend كـAngular والـbackend كحاوية (Dockerfile).

## الصفحات

| الصفحة | الوظيفة |
|---|---|
| Overview Dashboard | ملخص الأداء + بطاقة محرك OR وأحدث الاقتراحات |
| Operations Research | **النواة**: سيناريو توزيع الميزانية + الاقتراحات |
| Campaigns | قائمة الحملات من البيانات المزامَنة |
| Connected Accounts | ربط الحسابات + **دليل تفعيل رسمي تحت كل منصة** (روابط، خطوات، بيانات مطلوبة) |
| Business Profile | مدخلات الـOR (الميزانية، الأفق، العملة) |
| AI Model APIs | ربط موديل الذكاء الاصطناعي (BYOK) — دوره الوحيد: **ترتيب** اقتراحات الـOR وإرفاق ملاحظات ثابتة مكتوبة من السيرفر. لا يولّد أي نص حر |
| Developer API Hub | مفاتيح تطبيقات المطورين لكل منصة إعلانية + موصل Looker Studio |
| Team & Access | إدارة المستخدمين والأدوار |

## دور الذكاء الاصطناعي (مقيّد بعقد)

الموديل المتصل لا يكتب أي نص يصل للمستخدم. صياغة الاقتراحات وأرقامها ومنصاتها تأتي من المحرك الحتمي فقط. إذا تعذّر الوصول للموديل أو خرج عن العقد (ترتيب + أكواد ملاحظات مغلقة)، يُحفظ الترتيب الحتمي تلقائيًا. الاختبارات: `SuggestionNarratorTest`.

## التشغيل محليًا

- Backend: `composer install`، انسخ `.env.example` إلى `.env`، `php artisan key:generate`، ثم `php artisan migrate`. أول مالك: عيّن `ADMIN_BOOTSTRAP_EMAIL` و `ADMIN_BOOTSTRAP_PASSWORD` في البيئة قبل الـseed.
- Frontend: `npm ci` ثم `ng build` أو `ng serve`.
- الاختبارات: `php vendor/bin/phpunit` (PostgreSQL حسب `phpunit.xml`)، و`ng test --watch=false` للواجهة. الـmigrations تعمل على PostgreSQL وSQLite (مسار الإنتاج = PostgreSQL/Neon).

## النشر

`vercel.json` يوجّه `/api/*` لخدمة الـbackend (حاوية) والباقي للـfrontend. قاعدة البيانات: Neon Postgres. المزامنة مع منصات الإعلانات يدوية (زر Sync) — الجدولة التلقائية غير مفعّلة في النشر الحالي.

## أرشيف

الوثائق القديمة (تقارير جاهزية سابقة، دليل نشر قديم، مرجع المشروع الأصلي) محفوظة تحت `docs/archive/` للتاريخ فقط — محتواها لا يصف الحالة الحالية.
