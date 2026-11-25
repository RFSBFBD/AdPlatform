## Snapchat source-scope correction, 24 November 2026

Read [the correction and reduced functionality](docs/SNAP_ACCOUNT_SOURCE_CORRECTION.md) first. The bare Snapchat ad-account endpoint supplies spend only. Snapchat canonical delivery/conversion analytics and stored analytical decisions are unavailable, including histories carrying older normalization markers. Source spend inspection remains separate and is not a full sync or budget input. No replacement hierarchy, live repair or new portfolio branch is included. This replacement includes the prior consolidated functionality plus this fail-closed correction; it is not full-spec completion.

## Consolidated source checkpoint, 24 November 2026

Start with [the successful-use guide and limits](docs/SOURCE_CHECKPOINT_2026_11_24.md). This is a source-only local checkpoint, not a deployed product or a completed original specification. It consolidates original-provider fetch boundaries, outcome-semantic separation, explicit objective/estimand preflight and guided one-account declared-lead CURRENT / OPTIMIZED / CUSTOM scenarios. Cross-provider portfolio allocation remains unavailable; the Meta+Snap purchase-credit branch is blocked. Earlier frozen packages are unchanged.

## Local objective and outcome-basis preflight

See [OBJECTIVE_ESTIMAND_PREFLIGHT.md](docs/OBJECTIVE_ESTIMAND_PREFLIGHT.md). The portfolio request must explicitly select objective and outcome basis; missing intent is not inferred from a profile or silently called revenue. Source-account-currency reported arithmetic remains descriptive. Common business outcomes need a supported event/value source, not an equivalence checkbox. Cross-provider portfolio allocation remains unavailable, not completed by this contract. Local synthetic verification: backend534tests/4219assertions, frontend145tests/build. No new package or live verification.

## Current local semantic-comparison limit

Read [OUTCOME_SEMANTIC_BOUNDARY.md](docs/OUTCOME_SEMANTIC_BOUNDARY.md). Parsing and matching declarations do not establish equivalent business outcomes. Overview retains spend/delivery events while incompatible joint outcomes are withheld. The current platform portfolio route needs two providers and now abstains; this is an incomplete original capability, not a completed cross-platform budget system. Source-scoped entity scenarios remain separate. No new source delivery or live verification is implied by this local work.

## Later local input-boundary review

See [SHARED_NORMALIZATION_REPLACEMENT.md](docs/SHARED_NORMALIZATION_REPLACEMENT.md) and [INPUT_BOUNDARY_REVIEW.md](docs/INPUT_BOUNDARY_REVIEW.md). Earlier numeric coercion/default risk affected Google, Snapchat and TikTok readers as well as Meta. Current selected readers use strict shared operand checks and old histories are withheld by transition gates; this is not proof of live metric meaning or full provider correctness. Do not use imported provider numbers or recommendations from this development checkpoint for real budget decisions. No historical repair or live verification has occurred.

# Meta outcome safety correction

Read [META_CORRECTED_REPLACEMENT.md](docs/META_CORRECTED_REPLACEMENT.md) before applying this source. Earlier checkpoints572a8d8/c70d5ce can contain inflated or mixed Meta purchase/lead totals. Legacy Meta histories and unsafe archived decisions are now blocked, not repaired. New ingestion is exact website pixel-purchase only, with unknown omitted actions. No live deployment or complete original-spec claim.

# AdPlatform — منصة إدارة وتحليل الإعلانات

منصة تربط حسابات الإعلانات (Meta, Google, TikTok, Snapchat)، تسحب بيانات الأداء، وتعتمد على محرك **Operations Research (OR)** كقلب المنصة: سيناريوهات ميزانية حتمية واقتراحات مبنية على البيانات المسحوبة فقط. لا يتم تنفيذ أي تغيير على الحملات أو الميزانيات من المنصة.

## حالة التطوير الحالية

التطوير المحلي ما زال مستمرًا، ولا تعني الاختبارات اكتمال كل المواصفات أو نجاح الربط الحقيقي. أحدث نقطة تحقق محلية: 534 اختبار backend و4219 assertion، و145 اختبار للواجهة مع build ناجح. كل البيانات المستخدمة في التحقق صناعية، ولم تُجرَ تغييرات على حسابات أو حملات حقيقية.

صفحة Evidence & Diagnostics تشمل حسابات فعلية وتقارير تشخيصية، وسيناريوهات كيانات بحلول مستمرة أو قوائم ميزانية منفصلة، وأرشيفًا خاصًا بالمالك مع إعادة الحساب وتقرير HTML محلي. البيانات الفعلية والتوقعات والسيناريوهات تظل منفصلة. نسب الثبات والضغط ليست احتمالات ثقة.

التغطية تختلف بين المنصات. راجع `docs/PROVIDER_IMPLEMENTATION_MATRIX.md`، وللتشغيل الحالي `docs/OPERATING_GUIDE.md`، ولتفاصيل التحقق والتكملة `docs/CHECKPOINT_VERIFICATION.md`، وحدود المواصفات `docs/MATH_SPEC_COVERAGE.md`. التغذية الآلية للأحداث وسجلات العملاء وتوحيد هوية الأشخاص، وattribution المعتمد على نموذج مدرب، وحد تشبع بمعايرة إحصائية، ومحلل AI كامل ما زالت أجزاء غير مكتملة. يوجد الآن سجل أحداث يدوي ورابط نقرات معلن من المصدر، وهو ليس ربط CRM تلقائيًا أو إثباتًا لهوية العميل. تمت إضافة هوية creative بحسب الحساب والمنصة ومعرّف المصدر، بمحول Meta أولي وسجل ملاحظات، دون نسب أرقام الأيام السابقة إلى creative الحالي. راجع `docs/CANONICAL_CREATIVE_IDENTITY.md`. هذا التحديث محلي فقط، وليس نشرًا أو تحققًا من حالة أي موقع إنتاجي.

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

إعدادات النشر الموجودة في المصدر مسودات تحتاج مراجعة وتجربة مستقلة. لم يتم بناء حاوية Docker أو التحقق من حالة أي موقع منشور في نقطة التسليم هذه. راجع `docs/DEPLOY_LATER.md`. لا توجد جدولة مزامنة موثقة كجزء من هذا التحقق المحلي.

## أرشيف

الوثائق القديمة (تقارير جاهزية سابقة، دليل نشر قديم، مرجع المشروع الأصلي) محفوظة تحت `docs/archive/` للتاريخ فقط — محتواها لا يصف الحالة الحالية.

## Running the backend tests (Linux, macOS, Windows)
- Run `scripts/test-backend.sh` from Git Bash or WSL (it is a bash script with LF line endings; do not convert it to CRLF). It needs a local PostgreSQL with an empty database whose name ends in `_test`, and `PHP_BIN` if `php` is not on PATH.
- Some tests run the optional OR solver in Python and need Python with numpy and scipy (versions in `backend/solver/requirements.txt`). The tests look for `python3` then `python` on PATH. Set `TEST_SOLVER_PYTHON` to the full interpreter path to choose one (for example `C:\Python312\python.exe`). An invalid override fails the run, it is never ignored.
- If no usable Python is found, those tests are SKIPPED with the reason "Solver runtime unavailable, solver coverage NOT executed". Read the Skipped count in the summary: a run with skips did not cover the solver and is not a full pass. The reference suite is 668 tests; a Unit-only count (about 169) is not the full suite.
- Production code is unchanged: the app reads `OR_SOLVER_PYTHON`.
- Verified on Linux only, including simulated missing and invalid Python. A real Windows run has not been performed.

### Windows specifics
- Use Git Bash. A `bash.exe` in `C:\Windows\System32` or `WindowsApps` is the WSL launcher; it is not a working shell for these tests unless a WSL distro is installed and can open the project path. The guard tests probe each bash and SKIP with a visible reason if none works. File existence is not enough.
- Python: numpy 2.2.6 and scipy 1.15.3 declare `Python >=3.10` on PyPI. Install a normal Python (python.org), then `python -m pip install -r backend/solver/requirements.txt`, then set `TEST_SOLVER_PYTHON` to that `python.exe`. The Microsoft Store `python3.exe` alias is not a working interpreter; the tests reject it with a reason. If pip cannot find wheels for a very new Python version, use an older supported one (3.12 is a safe choice).
- Run the Unit suite and the Feature suite. Feature tests need PostgreSQL. A Unit-only result is not the full suite (reference on Linux: 670 tests total, 171 of them in Unit).
- Skipped tests are not passes. Read the Skipped line: guard skips mean no working bash, solver skips mean no working Python with numpy and scipy.
