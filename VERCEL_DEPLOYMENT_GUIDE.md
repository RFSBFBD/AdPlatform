# دليل نشر المنصة على منصة Vercel (Vercel Deployment Guide)

تم تجهيز وهندسة المنصة بالكامل لتكون متوافقة 100% مع بيئة **Vercel** السحابية من خلال ملفات التكوين المجهزة مسبقاً (`vercel.json`).

---

## 1. التكوين المُعد مسبقاً (Configuration Files)

يحتوي المشروع على ملفي تكوين `vercel.json` جاهزين للعمل تلقائياً دون أي إعدادات يدوية معقدة:
1. **في المسار الرئيسي للمشروع ([vercel.json](file:///d:/soc%20platform/vercel.json)):**
   - مُهيأ للتعامل مع المستودع بالكامل كـ Monorepo.
   - يقوم ببناء تطبيق الأنجولار تلقائياً من مسار `frontend`.
   - يوجه المخرجات إلى `frontend/dist/frontend/browser`.
2. **في مسار الفرونت إند ([frontend/vercel.json](file:///d:/soc%20platform/frontend/vercel.json)):**
   - مُهيأ للعمل في حال تم تحديد الـ Root Directory في فرسل كـ `frontend`.
   - يتضمن توجيه مسارات الـ SPA (`Rewrites`) إلى `index.html` لمنع خطأ `404 Not Found` عند تحديث الصفحة أو الانتقال المباشر للروابط (`/dashboard`, `/operations-research`, إلخ).

---

## 2. خطوات النشر على Vercel بنقرات بسيطة

### الخطوة 1: استيراد المستودع من GitHub
1. افتح حسابك على [Vercel Dashboard](https://vercel.com/dashboard).
2. انقر على زر **"Add New..."** ثم اختر **"Project"**.
3. قم باختيار المستودع: **`RFSBFBD/AdPlatform`**.

### الخطوة 2: ضبط إعدادات المشروع (Project Settings)
- **Framework Preset:** سيقوم Vercel باكتشافه تلقائياً كـ **Angular**.
- **Root Directory:**
  - الخيار الأفضل: اختر **`frontend`** (انقر Edit وحدد مجلد frontend).
- **Build Command:** `npm run build` (محدد تلقائياً).
- **Output Directory:** `dist/frontend/browser` (محدد تلقائياً).

### الخطوة 3: المتغيرات البيئية (Environment Variables) - اختياري
في حال كان خادم الباك إند (Laravel API) يعمل على خادم منفصل، يمكنك إضافة المتغير:
- `API_BASE_URL`: رابط الـ API الخاص بالباك إند (مثال: `https://api.yourdomain.com`).

### الخطوة 4: النشر (Deploy)
- اضغط على زر **"Deploy"**.
- سيبدأ Vercel فوراً في تثبيت الحزم، بناء المشروع، وتوليد رابط فوري للمعاينة السحابية (Production URL) مع شهادة SSL مجانية وحماية CDN عالمية.

---

## 3. التحقق بعد النشر
- التنقل بين جميع المسارات (`/dashboard`, `/campaigns`, `/operations-research`, `/ai-center`, `/developer-settings`, `/team`).
- تجربة شاشة اللودنج الانتقالية (`Route Loading Transition Page`).
- تجربة مفتاح تبديل المظهر النهاري/الليلي في أعلى الشاشة.
