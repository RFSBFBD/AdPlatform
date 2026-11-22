نشر AdPlatform على Vercel (Docker للباك) + Neon (قاعدة البيانات)
=================================================================
الحالة بصراحة: الكود اتراجع واتجرّب بالكامل خارج الاستضافة الحقيقية. اللي ما اتجرّبش: بناء وتشغيل صورة Docker بـ PHP 8.4 (Dockerfile.vercel) على Docker فعلي، والنشر الفعلي على Vercel، والاتصال بـ Neon الحقيقي (SSL وpooling). ده كله أول اختبار حقيقي عند أول نشر.

اللي اتجرّب فعلًا (مراجع مستقل، على PostgreSQL 16.2 محلي وFrankenPHP 1.14 بـ PHP 8.6 محمول، مش Neon ولا Vercel ولا Docker 8.4):
- كل الـ 17 migration اتشغلت بنجاح من خلال Laravel/PDO على Postgres محلي مع pg_trgm. ده بيشمل الـ migrationين الخاصين بـ Postgres (2026_11_19_110000 و2026_11_19_151519). كمان اتجرّب SQL نفس الـ migrationين على PGlite (PostgreSQL 18) من عندي.
- الـ backend الكامل: 196 اختبار/1954 assertion نجحوا كلهم بكاش وجلسات database.
- تشغيل HTTP بـ APP_ENV=production وDB_URL وPostgres وكاش/جلسة database وqueue sync: /api/v1/health رجعت 200 وredis=not_used، وlogin رجع 200 بصلاحية Owner، و/me بالـ bearer رجع 200. استُخدم Caddyfile معدل لمسار الـ docroot فقط.
- الواجهة: ng test 54/54 و ng build ناجحين.
- أمر db:verify-backup اتشغل فعليًا محليًا بـ pg_dump وgzip وpsql: تطابق كل جداول public (31 جدول) و78 index و21 FK و23 sequence وعدد الصفوف لكل جدول. اتجرّب صف بيانات عادي فيه كلمة transaction_timeout وصف قيمته بالظبط "SET transaction_timeout = 0;" وفضلوا سليمين. واتحقن فشل pg_dump (exit 7) وفشل restore (exit 8) وكلاهما فشل صراحة مع تنظيف. DB_URL وsslmode وصلوا للأمر محليًا (مش TLS على سيرفر بعيد).
- Neon بيدعم Postgres 14 لـ 19 (الافتراضي 18) وpg_trgm مدعوم (https://neon.com/docs/reference/compatibility ، https://neon.com/docs/extensions/pg_trgm ). NULLS NOT DISTINCT يحتاج 15+.

اللي اتضاف (docker-compose وdocker/php القديم ما اتغيروش):
- backend/Dockerfile.vercel (FrankenPHP، حسب https://vercel.com/kb/guide/laravel-php-with-docker )، backend/Caddyfile، backend/.dockerignore، backend/.env.vercel.example (أسماء المتغيرات فقط).
- deploy/vercel-docker/vercel.json (مشروع واحد فيه services: frontend + backend، و /api/* للباك، حسب https://vercel.com/docs/services/routing )، وvercel.root.current.json (نسخة من vercel.json الحالي للرجوع).
- vercel.json في الجذر لم يتغير. لتفعيل المسار الجديد: انسخ deploy/vercel-docker/vercel.json مكان vercel.json في الجذر، وفي Vercel اضبط الـ Root Directory على جذر المستودع (مش frontend).

إصلاحات كود جت من الفحص:
- كاش جلسة ربط الحسابات كان بيخزن كائن تاريخ، وده بيفشل مع كاش مشترك. دلوقتي الـ payload JSON مشفر (الـ tokens مش plain text) وتاريخ ISO.
- /api/v1/health كان بيفشل بدون Redis وبيطبع رسالة خطأ الـ driver؛ دلوقتي redis=not_used لو مش مستعمل، والخطأ بدون تفاصيل.
- ولما الـ queue driver يكون sync، API المزامنة بيرجع النتيجة الحقيقية (200 وحالة الـ run وقد تكون failed) مش 202 queued. مع queue حقيقي بيفضل 202.
- db:verify-backup: بيستخدم إعداد الاتصال المحلول من DB_URL مع sslmode، وكل مرحلة (pg_dump، ضغط، gzip -t، restore) بتتفحص لوحدها بدل shell pipeline، والـ dump بيتضغط بايت ببايت بدون أي فلترة، وفحص تطابق الصفوف على كل جداول public.

حدود وظيفية (قرارك): QUEUE_CONNECTION=sync والمزامنة بزرار "Sync now" اليدوي فقط. الجدولة التلقائية (adplatform:sync-all) والـ queue worker مش شغالين على الوضع ده. الطلب محدود بـ 300 ثانية على Hobby (https://vercel.com/docs/functions/limitations ).

الخطوات (كلها بإيدك، ولا سر في الشات):
1) Neon: أنشئ مشروع Free من حسابك واختار Postgres 15 أو أحدث (شرط البطاقة: صفحة التسعير بتقول Free بدون بطاقة https://neon.com/pricing ، لكن لو شاشة الإنشاء طلبتها وقف). انسخ الـ connection string المباشر (مش pooled).
2) Vercel: في Settings > Environment Variables حط المتغيرات المذكورة في backend/.env.vercel.example (DB_URL، APP_KEY من php artisan key:generate --show، APP_URL...). مفيش أسرار في الريبو.
3) Migrations على Neon (إجراء production بإيدك): من جهازك: DB_CONNECTION=pgsql DB_URL=... php artisan migrate --force. كل الـ 17 migration اتجرّبوا على Postgres محلي (16.2) مش على Neon نفسه، فلو ظهر خطأ على Neon ابعتلنا رسالته.
4) أول أدمن: الـ seeder في production (APP_ENV=production) ما بيعملش أي owner لو ADMIN_BOOTSTRAP_PASSWORD مش مضبوط (الباسورد العشوائي بيتولد بس خارج production). اضبط ADMIN_BOOTSTRAP_PASSWORD (تكتبه إنت بس في terminal عندك، مش في شات) واختياريًا ADMIN_BOOTSTRAP_EMAIL (الافتراضي admin@adplatform.local) وشغّل مرة واحدة: php artisan db:seed --class=RoleAndPermissionSeeder --force ثم امسح المتغير. الحساب بيتعمل لو الإيميل مش موجود؛ لو موجود الباسورد ما بيتغيرش.
4-ب) ربط منصات الإعلانات (OAuth): لازم تضبط OAUTH_ALLOWED_REDIRECT_URIS في Vercel، وإلا كل محاولة ربط بتترفض (config/oauth.php الافتراضي فاضي). الواجهة بتبعت دايمًا window.location.origin + '/accounts' (accounts.component.ts)، فالقيمة بالظبط https://<الدومين النهائي على Vercel>/accounts من غير slash في الآخر ولا query. نفس النص لازم يتسجل كـ redirect URI في تطبيق المطوّر عند كل منصة، والمقارنة حرفية. ما اخترعتش دومين؛ تعبّيه بعد ما Vercel تدّيك الدومين. بيانات التطبيقات (client id/secret لكل منصة) بتتسجّل من شاشة Developer Settings بعد الدخول، مش في الريبو.
5) رفع الكود لـ GitHub بإيدك، وVercel بيبني من Dockerfile.vercel.
6) افتح /api/v1/health بعد النشر؛ لو رجعت HTML يبقى الـ rewrite مش شغال، ولو 502 شوف runtime logs (الدليل الرسمي بيقول: تأكد أن FrankenPHP بيسمع على PORT).

النسخ الاحتياطي: db:verify-backup أمر تحقق محلي، مش للتشغيل داخل صورة Vercel (مفيهاش pg_dump/psql). يشتغل من بيئة صيانة فيها pg_dump/psql بنفس الـ major version بتاع السيرفر وصلاحية CREATE DATABASE. اتجرّب على Postgres محلي فقط. ما اتجرّبش على Neon الحقيقي: مفيش backup اتعمل على Neon، وصلاحية CREATE DATABASE هناك غير مؤكدة.

الـ SPA routing: الإعداد المكتوب في deploy/vercel-docker/vercel.json متوافق مع صيغة التوثيق الرسمي (rewrites جوه الـ service مسموحة، والـ service بترجع 404 لو ما لقيت route)، لكن شغله الفعلي على الاستضافة ما اتجرّبش. بعد أول نشر جرّب refresh على /dashboard؛ لو رجع 404 يبقى الـ fallback مش شغال ولازم نعدله.

مش مؤكد لحد النشر: pooled/direct مع PDO على Neon، SSL على Neon، أي تكلفة أو بطاقة تظهر عند إنشاء الموارد، وسلوك صورة PHP 8.4 على Docker فعلي.
