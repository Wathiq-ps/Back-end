<div dir="rtl">

# ملخص العمل: Hierarchical Location API

هذا الملف يشرح كل ما تم تنفيذه لبناء نظام اختيار الموقع بالتسلسل:
**Country → City → Area → Street**، مع البحث بالعربي والإنجليزي، والترتيب الأبجدي حسب اللغة، والـ pagination في كل مستوى.

> **ملاحظة:** لم يتم عمل أي commit. كل التغييرات موجودة في الـ working tree وجاهزة لمراجعتك.

---

## 1. تحليل المشروع قبل البدء

قبل أي تعديل، تمت دراسة المشروع ووُجد التالي:

- **الإصدارات:** Laravel 13.23 و PHP 8.5 و PostgreSQL 18 مع الإضافات `pg_trgm` و `citext` و `vector`، والاختبارات بـ Pest 5.
- **أسلوب المشروع:**
  - الـ schema مكتوب بـ raw SQL داخل `DB::unprepared` في الـ schema المسمّى `app`.
  - كل primary key من نوع UUID.
  - الـ routes تحت `/api/v1/...`.
  - شكل الـ response هو `{ data, meta }` بدون مفتاح `success`.
- **ما كان موجوداً مسبقاً:**
  - جدول `app.countries` موجود ومرتبط بالـ jurisdictions والـ user profiles، لذلك **تم توسيعه بدل إنشاء جدول جديد**.
  - جدول `app.locations` قديم وفارغ ولا يستخدمه أي كود، **فتُرك كما هو**.
  - الدالة `app.normalize_arabic()` موجودة مسبقاً، **وأُعيد استخدامها** في البحث.

---

## 2. القرارات التصميمية الأساسية

| الموضوع | القرار |
|---|---|
| الجداول | توسيع `app.countries`، وإنشاء `app.cities` و `app.areas` و `app.streets` بـ foreign keys |
| الـ IDs | UUID مثل باقي المشروع |
| الأسماء | `name` إجباري، و `name_ar` و `name_en` اختياريان (لا نفترض وجود الاسمين دائماً) |
| منع التكرار | unique على `(source, external_id)`، وعلى الاسم بعد الـ normalization داخل نفس الـ parent للـ areas والـ streets |
| الـ routes | `/api/v1/locations/...` لتتوافق مع المشروع |
| شكل الـ response | نفس شكل المشروع `{ data, meta }` مع إضافة `per_page` |

---

## 3. المهام السبع التي تم تنفيذها

### المهمة 1: Database (migration + models + factories)

- **الـ migration:** `2026_11_17_000001_create_location_hierarchy_tables.php`
- **أعمدة كل جدول:** `name` و `name_ar` و `name_en` و `latitude` و `longitude` من نوع `numeric(9,6)`، و `source` (`geonames` أو `osm` أو `manual`)، و `external_id`، والـ timestamps.
- **قواعد الحماية على مستوى قاعدة البيانات:**
  - foreign keys مع `on delete restrict`، فلا يمكن حذف parent لديه children.
  - check constraints: الاسم لا يكون فارغاً، والإحداثيات تأتي كزوج صحيح ضمن المدى المسموح.
  - generated columns: `search_text` للبحث، و `name_key` لمنع تكرار الأسماء.
- **الـ models:** `Country` و `City` و `Area` و `Street` مع العلاقات في الاتجاهين.
- **الـ factories:** لكل model، وبيانات اختبار وهمية فقط.

### المهمة 2: البحث والترتيب والـ cache

- **دالة SQL جديدة `app.location_search_key()`:**
  - تطبّق `normalize_arabic()` (أ/إ/آ → ا، ة → ه، ى → ي، وحذف التشكيل).
  - تحوّل النص إلى lowercase.
  - تحوّل أي punctuation أو مسافات متتالية إلى مسافة واحدة.
  - لذلك "Al-Bireh" و "al bireh" و "AL BIREH" تُعامل كاسم واحد.
- **`LocationQueryService`:**
  - **البحث:** كل كلمة يجب أن تظهر في الاسم، وبأي ترتيب. البحث partial و case-insensitive، ويستخدم trigram index.
  - **الترتيب:**
    1. عند البحث، النتائج التي يبدأ فيها البحث بكلمة تأتي أولاً.
    2. ثم أبجدياً حسب اللغة المطلوبة.
    3. ثم حسب `id`، حتى يبقى الـ pagination ثابتاً.
- **`LocationCache`:**
  - **الفكرة:** كل الـ cache تحت version واحد مشترك.
  - **الـ invalidation:** أي تعديل على location عبر Eloquent، أو أي import، يغيّر الـ version، فيُلغى كل الـ cache القديم دفعة واحدة.

### المهمة 3: الـ Endpoints

```
GET /api/v1/locations/countries
GET /api/v1/locations/cities?country_id=...
GET /api/v1/locations/areas?city_id=...
GET /api/v1/locations/streets?area_id=...
```

- **Form Requests للـ validation:**
  - الـ parent ID المفقود يُرجع 422.
  - الـ parent ID الذي ليس UUID يُرجع 422 قبل الوصول إلى قاعدة البيانات، لأن Postgres كان سيُرجع 500.
  - الـ parent ID غير الموجود يُرجع 422 مع رسالة واضحة.
- **API Resources:** الحقل `name` يُرجع الاسم باللغة المطلوبة، مع fallback إن لم يوجد.
- **اللغة:** من `?lang=ar|en`، وإلا من header `Accept-Language`، وإلا الإنجليزية.
- **Rate limiting:** 120 request في الدقيقة لكل IP.

### المهمة 4: الـ Global Search

```
GET /api/v1/locations/search?search=...&types=cities,areas&limit=10&country_id=...
```

- النتائج مجمّعة حسب المستوى.
- كل نتيجة تحمل الـ parents الخاصة بها (`city` و `country` …)، حتى يملأ الـ frontend كل مستويات الـ picker من نتيجة واحدة.
- `meta.has_more` يوضح إن كانت هناك نتائج أكثر من الـ `limit`.
- الـ parents تُحمّل بـ eager loading، فلا توجد مشكلة N+1، وعدد الـ queries ثابت دائماً.

### المهمة 5: الـ Import Commands

| الـ Command | المصدر |
|---|---|
| `locations:import-countries` | GeoNames `countryInfo.txt`، والأسماء العربية من ICU/CLDR الموجودة في PHP `intl` |
| `locations:import-cities PS` | GeoNames dump الخاص بالدولة، مع ملف الـ alternate names |
| `locations:import-areas PS` | OpenStreetMap عبر Overpass API |
| `locations:import-streets <city-id>` | OpenStreetMap عبر Overpass API، مدينة واحدة في كل مرة |

**نقاط مهمة:**

- **لم يُستخدم REST Countries.** الإصدار v3.1 أصبح deprecated، والإصدار v5 يحتاج API key، وشروطه تمنع تخزين البيانات أكثر من 3 أيام، وهذا لا يناسب جدولاً دائماً.
- **أسماء الدول الموجودة لا تُستبدل أبداً.** المصادر تسمّي فلسطين "Palestinian Territory"، لكن السجل الموجود "Palestine / فلسطين" بقي كما هو.
- **الـ areas تُربط بأقرب مدينة** ضمن مسافة محددة، والـ streets تُربط بأقرب area في نفس المدينة.
- **إعادة التشغيل آمنة (idempotent):**
  - لا يحدث تكرار في البيانات.
  - الأسماء الاختيارية تُملأ فقط ولا تُمسح.
  - السجلات المضافة يدوياً تُربط بمصدرها بدل أن تتكرر.
- **أجزاء الشارع الواحد** في OSM تُدمج في شارع واحد.
- **عند فشل التحميل** (مثل 429 أو 502 من Overpass)، تظهر رسالة من سطر واحد ولا يُكتب أي شيء في قاعدة البيانات.

### المهمة 6: مراجعة الاختبارات

- تمت مقارنة قائمة الاختبارات التي طلبتها مع الاختبارات الموجودة، وكلها مغطاة.
- **الفجوات التي وُجدت وتمت تغطيتها:**
  - الترتيب والبحث والـ pagination على مستوى HTTP لكل مستوى.
  - unit tests لكود أقرب نقطة (`NearestPoint`).
  - حالات الملفات التالفة أو المفقودة في الـ import، وكانت تُرجع stack trace، فأصبحت رسالة واضحة.

### المهمة 7: التوثيق

- ملف `docs/locations.md` يشرح:
  - الـ database structure.
  - الـ endpoints والـ parameters مع أمثلة.
  - طريقة الـ import والتحديث.
  - الـ attribution والـ licensing.
  - حدود التغطية الجغرافية.
- تحديث `README.md` بروابط وأقسام جديدة.

---

## 4. المشاكل التي ظهرت وتم حلها

### المشكلة 1: الخطأ `Attempt to read property "id" on string`

- **السبب:** ملف `config/cache.php` يحتوي على `'serializable_classes' => false`، وهو إعداد أمني مقصود. هذا يعني أن الـ cache لا يعيد بناء أي PHP object.
  - كان الكود يخزّن Eloquent models في الـ cache.
  - أول request كان يعمل.
  - من ثاني request كان الـ Collection يرجع كـ `__PHP_Incomplete_Class`، وأول عنصر فيه هو نص اسم الـ class.
- **لماذا لم تكشفه الاختبارات:** الاختبارات تستخدم `array` cache الذي لا يعمل serialization.
- **الحل:**
  - تخزين plain arrays في الـ cache، ثم إعادة بناء الـ models عند القراءة، بدون تغيير الإعداد الأمني.
  - إضافة اختبار يستخدم `database` cache الحقيقي.

### المشكلة 2: فشل الـ import في قاعدة بيانات الـ dev

- **السبب:** شغّلتَ الـ migration قبل أن أعدّل فيها الـ indexes لتصبح non-partial، لأن Postgres لا يقبل `ON CONFLICT` مع partial index.
- **الحل:**
  - تمت مقارنة الـ schema بين قاعدة الـ dev وقاعدة الاختبار، وكان الاختلاف في هذين الـ index فقط.
  - أُعيد إنشاؤهما في transaction واحدة.
- **تنبيه:** أي جهاز آخر شغّل الـ migration قبل المهمة 5 سيحتاج نفس الإصلاح.

### المشكلة 3: الأسماء التي تبدأ بـ ‘ تظهر أولاً في الترتيب

- **السبب:** الـ ICU collation الافتراضي يضع الـ punctuation قبل الحروف، وفي PS هناك 63 اسماً تبدأ بـ ‘ مثل "‘Ābā".
- **الحل:**
  - migration جديدة `2026_11_17_000002_create_location_sort_collations.php` تنشئ collations تتجاهل الـ punctuation (`ka-shifted`).
  - أصبحت migration جديدة وليست تعديلاً على القديمة، حتى لا يتكرر اختلاف الـ schema.
- **التحقق:** الاختبارات الجديدة فشلت مع الترتيب القديم، ونجحت مع الجديد.

---

## 5. البيانات الموجودة الآن في قاعدة الـ dev (`wathiq`)

| المستوى | العدد |
|---|---|
| Countries | 250 |
| Cities (فلسطين) | 837 |
| Areas (فلسطين) | 91 |
| Streets | 0، لم يتم الـ import بعد |

تم التحقق من البيانات عبر الـ API على `http://127.0.0.1:8000`.

---

## 6. نتائج الاختبارات

| البيئة | النتيجة |
|---|---|
| PostgreSQL (`wathiq_test`) | 229 test كلها passed |
| SQLite (الافتراضي) | 41 passed و 8 skipped (الاختبارات التي تحتاج Postgres) |
| Pint | passed |

> **تنبيه مهم:** شغّل الاختبارات دائماً على `DB_DATABASE=wathiq_test`، لأن الاختبارات تعيد بناء الـ schema من الصفر.

---

## 7. الملفات التي تم إنشاؤها أو تعديلها

**جديدة:**

- `database/migrations/2026_11_17_000001_create_location_hierarchy_tables.php`
- `database/migrations/2026_11_17_000002_create_location_sort_collations.php`
- `app/Models/` → `Country.php` و `City.php` و `Area.php` و `Street.php` و `Concerns/FlushesLocationCache.php`
- `app/Services/Locations/` → الـ query service والـ cache والـ importers
- `app/Http/Controllers/Location/LocationController.php`
- `app/Http/Requests/Location/` → الـ Form Requests
- `app/Http/Resources/Location/` → الـ API Resources
- `app/Console/Commands/Locations/` → أوامر الـ import
- `app/Support/LocationLocale.php`
- `config/locations.php`
- `database/factories/` → factories للمستويات الأربعة
- `tests/Feature/Locations/` و `tests/Unit/Locations/`
- `docs/locations.md`

**معدّلة:**

- `routes/api.php`
- `README.md`

---

## 8. الحدود المعروفة والخطوات القادمة

- **الـ streets لم تُستورد بعد.** تحتاج أمراً لكل مدينة:
  `php artisan locations:import-streets <city-id>`
- **تكرار في غزة:** هناك منطقتان "تل الهوا" و "تل الهوى" لنفس الحي، بسبب اختلاف ا و ى. يمكن حذف إحداهما يدوياً.
- **المخيمات:** مخيمات اللاجئين في GeoNames تحت الـ feature code `CMPRF`، وهي غير مستوردة كمدن افتراضياً. يمكن إضافتها عبر `--feature-codes` أو في `config/locations.php`.
- **الأسماء الإنجليزية قليلة للأماكن الصغيرة،** و160 مدينة ليس لها اسم عربي فتظهر بالـ transliteration.
- **الربط بالمسافة وليس بالحدود الجغرافية،** لأن PostGIS غير مثبت. أحياناً قد تُربط منطقة قريبة من حدود بلدتين بالبلدة الخطأ.
- **الـ ODbL** (رخصة OpenStreetMap) فيها شرط share-alike. إذا تمت مشاركة جداول الـ locations بشكل عام، يُنصح بمراجعة قانونية.
- **الـ attribution:** يجب عرض نص مثل:
  "Location data © GeoNames (CC BY 4.0) and © OpenStreetMap contributors (ODbL)"

</div>
