# مستندات API شجره‌نامه

همه قابلیت‌های وب‌اپ از طریق همین API در دسترس است؛ بنابراین اپ‌های اندروید (Java/Kotlin) و iOS (Swift)
می‌توانند علاوه بر نمایش وب‌اپ، صفحات کاملاً بومی هم بسازند.

- آدرس پایه: `https://your-domain.com/api`
- قالب داده: JSON (UTF-8). آپلود فایل: `multipart/form-data`
- هدرهای لازم: `Accept: application/json`
- شناسه‌ها: UUID (رشته). هر شخص یک کد کوتاه ۸ کاراکتری خوانا (`code`) هم دارد.

## احراز هویت

| کلاینت | روش |
|---|---|
| وب‌اپ (همان دامنه) | کوکی سشن امن (Sanctum SPA). قبل از اولین POST: `GET /sanctum/csrf-cookie` و ارسال هدر `X-XSRF-TOKEN` |
| اپ موبایل / کلاینت بومی | توکن Bearer: در درخواست ورود `device_name` بفرستید؛ سپس هدر `Authorization: Bearer <token>` |

توکن‌ها به صورت پیش‌فرض ۹۰ روز اعتبار دارند (`PEDIGREE_TOKEN_TTL_DAYS`).

## قالب خطاها

```json
{ "message": "پیام فارسی قابل نمایش", "errors": { "field": ["پیام خطای فیلد"] } }
```

| کد | معنی |
|---|---|
| 401 | وارد نشده‌اید |
| 403 | اجازه ندارید (یا حساب مسدود است) |
| 404 | پیدا نشد |
| 422 | خطای اعتبارسنجی یا قانون کسب‌وکار (`message` را نمایش دهید) |
| 429 | تعداد درخواست بیش از حد؛ هدر `Retry-After` |

## قالب‌های داده

- **تاریخ جزئی شمسی**: `"1305"` یا `"1305-07"` یا `"1305-07-12"` (ورودی با اعداد فارسی و `/` هم پذیرفته می‌شود)
- **جنسیت**: `m` (مرد) یا `f` (زن)
- **گره درخت (Node)**:

```json
{
  "id": "01a0e170-12a7-731b-...", "code": "KEE2AMMQ",
  "first_name": "حسن", "last_name": "احمدی", "nickname": null, "title": "حاج",
  "gender": "m", "father_id": null, "mother_id": null, "birth_order": 1,
  "birth_date": "1270", "birth_place": "تفرش",
  "is_deceased": true, "death_date": "1345-08-21", "occupation": "تاجر فرش",
  "avatar": "/m/<media-id>/thumb?expires=...&signature=...",
  "has_parents": false
}
```

- **ایجادکننده (Author)**: هر چیزی که عضوی ثبت می‌کند (پروفایل `creator`، رسانه `uploader`، ازدواج `creator`، خاندان `creator`،
  نسخه‌های متن `user`، درخواست ویرایش `requester`، نظرها و لاگ‌ها `user`) این قالب را دارد:
  `{"id": 7, "name": "محمد صابر حسینی فرجی", "username": "sabertiger", "person_id": "01a0..."}` —
  اپ‌ها `@username` (یا اگر خالی است نام کامل) را به صورت لینک به پروفایل `person_id` نمایش می‌دهند.

لینک فایل‌ها (`avatar` و `urls` رسانه‌ها) **امضاشده و زمان‌دار** هستند و بدون توکن هم کار می‌کنند؛
آدرس نسبی است و باید به آدرس سایت اضافه شود.

---

## عمومی

### `GET /bootstrap`
تنظیمات عمومی (نام سایت، فعال بودن ثبت‌نام، طول کد پیامکی، محدودیت حجم فایل‌ها، ...).

## ورود و ثبت‌نام

### `POST /auth/otp` — درخواست کد پیامکی
```json
{ "phone": "09121234567", "captcha_id": null, "captcha_answer": null }
```
پاسخ: `{ "message", "phone", "cooldown": 60, "length": 6, "ttl": 120 }`

اگر از یک IP درخواست زیادی ارسال شود، پاسخ 422 با `captcha_required: true` و
`captcha: {id, image}` (تصویر data URI) برمی‌گردد؛ عدد تصویر را در `captcha_answer` بفرستید.

### `POST /auth/otp/verify` — بررسی کد و ورود
```json
{ "phone": "09121234567", "code": "123456", "device_name": "Pixel 8" }
```
پاسخ موفق (موبایل): `{ "token": "1|abc...", "token_type": "Bearer", "expires_at", "user": {...} }`

اگر شماره ثبت نشده و ثبت‌نام آزاد است: `{ "registration_required": true, "registration_token": "..." }`

### `POST /auth/register` — تکمیل ثبت‌نام
```json
{ "registration_token": "...", "first_name": "مریم", "last_name": "کریمی", "gender": "f",
  "national_code": "0012345679", "no_national_code": false,
  "birth_date": "1370", "password": null, "device_name": "iPhone" }
```
کد ملی اجباری است مگر `no_national_code: true` (کسی که کد ملی ایرانی ندارد؛ قابل غیرفعال کردن در تنظیمات).
کد ملی‌ای که به پروفایل موجودی تعلق دارد پذیرفته نمی‌شود (پروفایل موجود فقط با ثبت موبایل توسط بستگان ادعا می‌شود).

### `POST /auth/login` — ورود با رمز
```json
{ "identifier": "0012345679 | tahereh.k | 09121234567", "password": "********", "device_name": "Pixel 8" }
```
`identifier` می‌تواند کد ملی، نام کاربری یا موبایل باشد (فیلد قدیمی `national_code` هم پذیرفته می‌شود).
پس از ۵ تلاش ناموفق، ورود با آن شناسه ۱۵ دقیقه قفل می‌شود.

### `GET /auth/me` — کاربر فعلی
```json
{ "data": { "id": 1, "role": "member", "is_admin": false, "preferences": {}, "has_password": true,
  "person": { ...Node }, "counters": { "notifications": 2, "votes": 1, "links": 0 } } }
```

**فقط اعضا**: همه مسیرهای درخت، پروفایل و بقیه برای مهمان `401` می‌دهند. اگر ثبت‌نام با تأیید مدیر باشد
(`registration.require_approval` در `/bootstrap`)، `POST /auth/register` فیلد الزامی `join_note` (معرفی کوتاه، ۵ تا ۳۰۰ نویسه) دارد
و کاربر تازه `status: pending` است: فقط `/auth/me` و `/auth/logout` کار می‌کنند و بقیه مسیرها `403` با `code: pending_approval` می‌دهند.

### `POST /auth/logout`

### `GET /auth/captcha`
کپچای جدید: `{ "id", "image" }`

## حساب کاربری

| روش | مسیر | توضیح |
|---|---|---|
| PUT | `/account/preferences` | `theme` (light/dark/auto)، `arc_top` (name/fullname/nickname/none)، `arc_bottom` (dates/place/occupation/education/city/none)، `children_order` (rtl/ltr)، `show_photos`، `compact`، `calendar` |
| PUT | `/account/password` | `current_password` (اگر در ۱۵ دقیقه اخیر با پیامک وارد نشده‌اید)، `password`، `password_confirmation` |
| PUT | `/account/username` | `{username}` (حروف کوچک لاتین، عدد، `.` `_` `-`؛ `null` = حذف) |
| POST | `/account/phone/otp` | ارسال کد به شماره جدید: `{phone}` |
| PUT | `/account/phone` | تأیید شماره جدید: `{phone, code}` |
| GET | `/account/sessions` | فهرست نشست‌ها و دستگاه‌ها |
| DELETE | `/account/sessions/{id}` | بستن یک نشست |
| POST | `/account/devices` | ثبت توکن پوش: `{platform: android/ios, token, app_version}` |
| DELETE | `/account/devices` | حذف توکن پوش: `{token}` |

## اشخاص

### `GET /persons?q=&gender=&deceased=&per_page=`
جستجو (نام، نام خانوادگی، شهرت، کد). حروف عربی/فارسی و نیم‌فاصله یکدست می‌شوند.
با `q=@sab` بر اساس پیشوند نام کاربری عمومی جستجو می‌شود (مثل تلگرام).
پاسخ: `{ "data": [Node + father_name], "meta": { "current_page", "last_page", "total" } }`

### `GET /u/{username}` — پروفایلِ صاحب یک نام کاربری
برای لینک‌های `#/@sabertiger`: `{data: {person_id, username, name}}` یا 404. فقط برای اعضا؛ `throttle:60,1`.

### `GET /persons/{id}` — مشخصات کامل
علاوه بر فیلدهای Node:
- مشخصات: `death_place, burial_place, burial_location{lat,lng}, education_level, education_field, education_institution,
  education_field_group, honorific_mode (auto|none), academic_rank, workplace, country (ISO2), province, city, residence,
  blood_type, languages, interests, custom_fields[{label,value}], website, social{instagram,telegram,...}, biography,
  is_locked, avatar_medium`
- عنوان: `honorific` («دکتر»/«مهندس» خودکار یا `null`) و `display_title` (عنوان کامل مرتب: «حاج دکتر»، «دکتر سید») — در Node هم هست
- `social_profiles[{network, label, value, display, url, photo{thumb, medium, media_id}|null}]` — لینک مستقیم را سرور می‌سازد
- نشانی طبق `location_visibility` (پیش‌فرض `d1`): `address, postal_code, home_location{lat,lng}` (وگرنه `null`؛ `has_home_location`, `location_hidden`)
- تماس طبق `contact_visibility` (پیش‌فرض `all`): `phone, email, landline` و شماره واتس‌اپ/تلگرام در `social` (وگرنه حذف؛ `contact_hidden`)
- سطح‌ها: `all` همه اعضا، `d4`..`d1` بستگان تا آن درجه، `self` فقط خود شخص؛ خود شخص، مدیر و مدیرِ پروفایلِ بدون حساب همیشه می‌بینند
- فقط با دسترسی حساس: `national_code, birth_cert_no, birth_cert_place, account.has_password`
- `account.username`: نام کاربری عمومی (برای همه اعضا)
- `creator`: ایجادکننده پروفایل — قالب Author: `{id, name, username|null, person_id|null}`؛ نمایش: `@username` یا نام کامل، لینک به `#/person/{person_id}`
- `texts{summary|description|biography|resume: {segments: [[user_id, "متن"], ...], revision, updated_at}}` — نویسنده هر تکه
- `resume[{id, type, title, organization, location, start_date, end_date, is_current, description, author_id}]`
- `field_meta{field: {u: user_id, t: unix}}` آخرین ویرایشگر هر فیلد
- `contributors[{id, name, username, person_id, relation, color}]` — `color`: `owner` | `admin` | شماره پالت
- `completeness{percent, missing[]}` (فقط برای ویرایشگران)
- `permissions{edit, sensitive, upload, delete, admin, history, privacy, comment, rate}` — `privacy`: اجازه تغییر سطح نمایش

### `POST /persons` — ساخت شخص مستقل (مثلاً جد اعلای یک خاندان)
### `PATCH /persons/{id}` — ویرایش
همه فیلدهای بالا (به جز متن‌ها و رزومه که مسیر جدا دارند) + `home_lat, home_lng, burial_lat, burial_lng`؛
`contact_visibility, location_visibility` فقط با `permissions.privacy` اعمال می‌شوند. ویرایشگری که تماس/نشانی را نمی‌بیند
نمی‌تواند آن‌ها را تغییر دهد یا پاک کند (نادیده گرفته می‌شوند). `social` هر شکلی را می‌پذیرد (لینک کامل، ‎@شناسه، شماره)
و به شکل استاندارد ذخیره می‌کند؛ لینکِ دامنه دیگر → `422`؛
و (با دسترسی حساس) `national_code, phone, birth_cert_no, birth_cert_place, email, username, password, is_locked`.
مقادیر مجاز `education_level`، `academic_rank`، `social` و ... در `GET /bootstrap` → `profile` هستند.

### `DELETE /persons/{id}` — حذف (قابل بازیابی توسط مدیر)

### `GET /persons/{id}/relatives`
```json
{ "father": Node|null, "mother": Node|null,
  "spouses": [{ "person": Node, "marriage": {id, husband_id, wife_id, status, marriage_date, end_date, sort_order} }],
  "children": [Node], "siblings": [Node + half] }
```

### `GET /persons/{id}/history` — تاریخچه تغییرات (لاگ ممیزی)
برای همه اعضا (قابل تنظیم)؛ IP فقط برای مدیر، و ورود/خروج فقط برای خود شخص و مدیر.

### متن‌های رنگی (نویسنده هر کلمه)
| روش | مسیر | توضیح |
|---|---|---|
| PUT | `/persons/{id}/texts/{field}` | `{text, base_revision}` — `field`: summary / description / biography / resume. اگر نسخه پایه قدیمی باشد `409` با `code: text_conflict` |
| GET | `/persons/{id}/texts/{field}/revisions` | نسخه‌ها: نویسنده، `added`/`removed` (کلمه)، `restored_from` |
| GET | `/persons/{id}/texts/{field}/revisions/{n}` | متن کامل یک نسخه + نویسندگان |
| POST | `/persons/{id}/texts/{field}/revisions/{n}/restore` | بازگردانی (نویسندگان همان نسخه حفظ می‌شوند) |

پاسخ ذخیره: `{ "data": {field, segments, revision, updated_at}, "contributors": [...] }`

### رزومه
| روش | مسیر | بدنه |
|---|---|---|
| POST | `/persons/{id}/resume` | `type (education/work/military/award/certificate/publication/skill/volunteer/travel/other), title, organization, location, start_date, end_date, is_current, description, sort_order` |
| PATCH | `/resume/{id}` | همان فیلدها |
| DELETE | `/resume/{id}` | |

### نظرها و امتیاز ویژگی‌ها
| روش | مسیر | توضیح |
|---|---|---|
| GET | `/persons/{id}/comments?page=` | نظرها (مخفی‌شده‌ها فقط برای نویسنده و ویرایشگران) + `can{write, moderate}` |
| POST | `/persons/{id}/comments` | `{body}` |
| PATCH | `/comments/{id}` | فقط نویسنده: `{body}` |
| DELETE | `/comments/{id}` | نویسنده یا مدیر |
| POST | `/comments/{id}/hide` | `{hidden: true/false}` — خود شخص، بستگان درجه یک، مدیر |
| GET | `/persons/{id}/ratings` | `{traits[{key,label,average,count,distribution[5],mine}], raters_count, raters[], can_rate}` |
| PUT | `/persons/{id}/ratings` | `{scores: {humor: 5, charisma: 4, kindness: null}}` (`null` = حذف امتیاز؛ به خود نمی‌شود) |

### `GET /map` — نقشه خاندان
`{ "data": [{kind: home|burial, person: Node, lat, lng, city?, country?, place?}] }` — خانه‌ها طبق `location_visibility` هر شخص.

### `GET /persons/{id}/kin?max=4` — بستگان تا درجه ۴ (چه کسانی شماره/نشانی را می‌بینند)
درجه (از دید شخص): خونی = `بالا + پایین − ۱` (خواهر و برادر ۱، عمو و نوه ۲، عموزاده ۳)؛ همسر ۱؛ **عروس و داماد ۱**؛ بقیه بستگان همسر (پدرزن، مادرشوهر ...) و همسرِ بستگان = درجه + ۱.
```json
{ "data": [{ "degree": 1, "count": 6, "people": [{ "person": Node, "label": "پدرزن", "inlaw": true, "has_account": true }] }], "max": 4, "total": 25 }
```

### شبکه‌های اجتماعی
| روش | مسیر | توضیح |
|---|---|---|
| GET | `/social/preview?network=&value=` | شکل استاندارد، لینک مستقیم، و برای تلگرام/اینستاگرام/گیت‌هاب نام و عکس عمومی (`image`: data URI)؛ ۲۰ در دقیقه |
| POST | `/persons/{id}/social-avatar` | `network` (+ اختیاری `file`): دریافت عکس پروفایل آن شبکه یا آپلود دستی (مثلاً واتس‌اپ)؛ مثل هر آپلود از مسیر تأیید می‌گذرد. اگر شخص عکس آپلودی ندارد، به ترتیب اینستاگرام ← واتس‌اپ ← تلگرام عکس پروفایل می‌شود |
### `GET /persons/{id}/relationship/{otherId}` — نسبت خانوادگی
```json
{ "found": true, "label": "پسرعمو", "description": "پسرِ برادرِ پدر", "path": [ids], "path_names": [...], "steps": ["father","brother","son"] }
```

## بستگان و اتصال درخت‌ها

### `POST /persons/{id}/relatives` — افزودن شخص **جدید** به عنوان بستگان
```json
{ "type": "child", "first_name": "رضا", "gender": "m", "birth_date": "1395",
  "other_parent_id": "<spouse-id>" }
```
`type`: `child | spouse | father | mother | sibling`. برای `spouse`: `marriage_status`, `marriage_date`.

### `POST /persons/{id}/link` — اتصال شخص **موجود** (وصل کردن درخت‌ها)
```json
{ "type": "spouse", "target_id": "<person-id>", "marriage_date": "1390", "message": "همسر من است" }
```
اگر به طرف مقابل دسترسی ویرایش داشته باشید: 200 و `status: linked`.
در غیر این صورت: 202 و `status: requested` — یکی از بستگانِ طرف مقابل باید تأیید کند.

### `DELETE /persons/{id}/parents/{father|mother}` — قطع ارتباط والد
### `GET /marriages/{id}` — جزئیات ازدواج (کلیک روی قلب در درخت)
`{marriage{status, marriage_date, end_date, ...}, husband, wife, creator (Author), can_edit, can_suggest, pending_suggestion}`

### `PATCH /marriages/{id}` — `status (married/divorced/widowed), marriage_date, end_date, sort_order, notes, reason?`
ویرایش مستقیم با بستگان درجه یک زن یا شوهر (یا درجه دو وقتی هیچ‌کدام از بستگان درجه یک عضو نیستند) و مدیر → `200`.
بستگان درجه دو و سه (در بقیه حالت‌ها) → `202 {pending: true, request_id}`: پیشنهاد برای تأیید مدیر.
### `DELETE /marriages/{id}`

### پیشنهاد ویرایش پروفایل (بستگان درجه ۲ و ۳)
`PATCH /persons/{id}` برای کسی که فقط اجازه پیشنهاد دارد → `202 {pending: true, request_id, message}` (فیلد اختیاری
`edit_reason`). تا تأیید مدیر چیزی در سایت عوض نمی‌شود. کد ملی، موبایل، نشانی و تنظیم حریم خصوصی پیشنهادی نیست.
حداکثر ۳۰ پیشنهاد در انتظار برای هر عضو (`429`).

### درخواست‌های اتصال
| روش | مسیر |
|---|---|
| GET | `/link-requests` (دریافتی‌ها و ارسالی‌های من) |
| POST | `/link-requests/{id}/accept` |
| POST | `/link-requests/{id}/reject` |
| POST | `/link-requests/{id}/cancel` |

## درخت

| مسیر | توضیح |
|---|---|
| `GET /tree/{id}/descendants?depth=4` | نوادگان + همسرانشان |
| `GET /tree/{id}/ancestors?depth=8` | نیاکان تا بالاترین جد ثبت‌شده |
| `GET /tree/{id}/hourglass?up=4&down=3` | هر دو |
| `GET /tree/lineage?from={id}&to={id}&down=0` | مسیر نسبی بین دو نفر |

پاسخ همه:
```json
{ "mode": "descendants", "focus": "<id>",
  "persons": [Node], "marriages": [{id, husband_id, wife_id, status, marriage_date, end_date, sort_order}],
  "meta": { "depth": 4, "count": 37, "truncated": false, "expandable": ["<id>"], "path": [ids] } }
```
`expandable`: کسانی که فرزند/والد بیشتری دارند ولی به دلیل محدودیت عمق نیامده‌اند.
چیدمان گرافیکی در سمت کلاینت انجام می‌شود (نمونه کامل: `web/public/assets/js/tree/layout.js`).

## عکس و ویدیو

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/persons/{id}/media?type=image&category=story` | گالری، استوری‌ها یا خاطرات گروه (`memory`؛ عکس‌هایی که شخص در گروه گذاشته یا در آن‌ها نشان‌گذاری شده) — تأییدشده‌ها + در انتظارهای قابل مشاهده برای شما |
| POST | `/persons/{id}/media` | آپلود: `file`, `caption`, `description`, `taken_at`, `as_avatar`, `category` (gallery/story). تقریباً هر قالب عکس (HEIC، AVIF، TIFF، PSD ...) به JPEG و هر قالب فیلم به MP4 تبدیل می‌شود؛ خطاها: `video_queue_full` (۵۰۳)، سقف روزانه (۴۲۹)، فیلم غیرقابل‌پخش (۴۲۲) |
| POST | `/persons/{id}/avatar` | آپلود عکس پروفایل: `file` |
| PUT | `/persons/{id}/avatar` | انتخاب عکس پروفایل از عکس‌های تأییدشده: `{media_id}` |
| GET | `/media/{id}` | |
| PATCH | `/media/{id}` | `caption, description, taken_at` |
| DELETE | `/media/{id}` | |
| POST | `/media/{id}/vote` | `{decision: approve/reject, comment}` |
| POST | `/media/{id}/decide` | تصمیم مستقیم مدیر: `{decision, note}` |
| GET | `/approvals` | `{to_vote: [], mine: [], admin_queue: []}` |

شیء رسانه:
```json
{ "id", "person_id", "type": "image|video", "status": "pending|approved|rejected",
  "approval_mode": "auto|owner|vote|admin", "processing": "queued|processing|ready|failed",
  "urls": { "thumb", "medium", "poster", "original" },
  "votes": { "total", "approve", "reject", "mine", "is_voter" },
  "can": { "edit", "delete", "vote", "decide", "set_avatar" } }
```

## خاندان‌ها، داشبورد، اعلان‌ها، خروجی

| روش | مسیر |
|---|---|
| GET/POST | `/families` (`name, description, root_person_id, color`) |
| PATCH/DELETE | `/families/{id}` |
| GET | `/dashboard/stats`، `/dashboard/events?days=30`، `/dashboard/activity` |
| GET | `/notifications` |
| POST | `/notifications/{id}/read`، `/notifications/read-all` |
| GET | `/export/gedcom?mode=descendants|ancestors|hourglass|all&person={id}&depth=10` |

## تبریک مناسبت‌ها و پیامک از پنل سایت

متن پیامک ثابت است: یکی از قالب‌هایی که **فقط مدیر کل** می‌نویسد (با متغیرها) + نام کامل گیرنده و فرستنده با عنوان
«دکتر/مهندس» + نسبت فامیلی محاسبه‌شده + یادداشت کوتاه اختیاری (حداکثر ۳۰ نویسه، بدون عدد و لینک) + نام سایت.
مقدار فیلدهای پروفایل پیش از رفتن در پیامک پاک‌سازی می‌شود (بدون لینک، دامنه، ‎@شناسه و عدد ۵ رقمی به بالا).
دریافت پیامک تبریک و اعلان تولد قابل خاموش کردن نیست.

مناسبت‌ها (`occasion`): `birthday` (امروز و دیروز و هفته پیش رو)، `anniversary` (سالگرد ازدواج؛ امروز و دیروز)،
`nowruz` (۲۵ اسفند تا ۱۳ فروردین)، `yalda` (۳۰ آذر و ۱ دی).

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/greetings` | `eligibility{eligible, reason (provider/incomplete/no_profile), message, percent, required, missing[{key,label}], limits{daily_left,...}}`، `occasions[{key, label, emoji, open, window, templates[{id,label,text}]}]`، `birthdays[{person, in_days (-1..7), age, degree, relation, can_sms, problem, greeted, is_me}]`، `anniversaries[]`، `relatives[]` (در نوروز و یلدا، بستگان تا درجه ۴)، `note_max`، `auto{auto, scope, template, note, max_scope, send_hour}`، `history[{..., kind}]` |
| POST | `/greetings/preview` | `{person_id, occasion?, template (id), note?}` → `{text, length, parts}` متن نهایی (۹۰ در دقیقه) |
| POST | `/greetings/sms` | `{person_id, occasion? (پیش‌فرض birthday), template (id), note?}` ← ارسال (۱۰ در دقیقه). خطاها با `code`: `sms_incomplete`، `sms_provider`، `sms_recipient`، `sms_limit`، `sms_recipient_limit`، `sms_global_limit`، `sms_failed` |
| PUT | `/greetings/auto` | `{auto, scope (d1..d4/all), template, note?}` (تبریک خودکار تولد) |

اعلان تولد با `kind: birthday` و `link: #/greetings?person={id}` در `/notifications` می‌آید.

## پیام‌رسان اعضا (متن، ایموجی و پیام صوتی)

همه مسیرها فقط برای دو طرف گفتگو پاسخ می‌دهند؛ برای بقیه (حتی مدیر) `404`. متن در پایگاه داده رمزنگاری‌شده است.

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/messages` | `data[{id, other{user_id, person, name, active}, last{text, deleted, mine, read, at}, unread}]`، `unread_total` |
| GET | `/messages/unread` | `{unread}` (در `/auth/me` هم `counters.messages`) |
| POST | `/messages/start` | `{person_id}` → `{data:{id}}`؛ خطا `not_member` (هنوز عضو فعال نیست)، `blocked` (۴۰۳) — ۳۰ در دقیقه |
| GET | `/messages/{id}?before=&after=` | ۵۰ پیام آخر (یا قدیمی‌تر از `before` / تازه‌تر از `after`)؛ `conversation{other, blocked_by_me, can_send, read_up_to}`، `has_more`؛ پیام‌های طرف مقابل خوانده می‌شوند |
| POST | `/messages/{id}` | `{body}` (حداکثر ۲۰۰۰ نویسه) → `201`؛ سقف ۲۰ در دقیقه و ۵۰۰ در روز (`message_limit`) |
| DELETE | `/direct-messages/{id}` | حذف پیام خودم برای هر دو طرف |
| POST | `/messages/{id}/block` | `{blocked: true/false}` — مسدودسازی دوطرفه |
| POST | `/messages/{id}/voice` | multipart: `voice` (فایل صوتی ضبط‌شده؛ WebM/Opus، MP4/AAC، OGG، MP3، WAV ...)، `waveform?` (JSON آرایه تا ۶۴ عدد ۰..۳۱) → `201` با `voice{id, url, duration, waveform}` — ۱۲ در دقیقه |

### پیام صوتی (مشترک)
فایل در سرور به AAC تک‌کاناله حدود ۳۲ کیلوبیت تبدیل می‌شود. `voice.url` یک لینک **امضاشده و زمان‌دار** (`/v/{uuid}?expires=&signature=`)
است که فقط برای کسی ساخته می‌شود که پیام را می‌بیند؛ از `Range` پشتیبانی می‌کند. خطاها: `voice_off` (۴۰۳، خاموش از پنل)،
`voice_limit` (۴۲۹)، فایل نامعتبر/خیلی کوتاه/بیش از سقف مدت (۴۲۲).

## گروه خاطرات خاندان

همه اعضای فعال. متن پیام‌ها در پایگاه داده رمزنگاری‌شده است. اگر مدیر گروه را خاموش کند همه مسیرها `404` می‌دهند.

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/group` | `{name, members, can_post, problem, is_admin, reactions[], max_length, allow_links, video_enabled, read_id, unread, pinned[]}` |
| GET | `/group/messages?before=&after=&around=&since=` | ۵۰ پیام (قدیمی‌تر از `before`، تازه‌تر از `after`، اطراف `around`)؛ `updated[]` (پیام‌های ویرایش/حذف/واکنش‌خورده از `since`)، `has_more`، `server_time`، `unread` |
| POST | `/group/messages` | `{body, reply_to?}` → `201` — ۳۰ در دقیقه. خطاها: `emoji_only` (۴۲۲؛ «باید متن یا کلمه‌ای هم اضافه شود»)، `links` (۴۲۲)، `duplicate`، `slow_mode` (۴۲۹)، `group_limit` (۴۲۹)، `group_blocked` (۴۰۳) |
| POST | `/group/media` | multipart: `file` (عکس یا فیلم)، `caption` (الزامی، فقط ایموجی نه)، `taken_at?`، `tags[]?` (شناسه اشخاص داخل عکس، تا ۱۵) — عکس/فیلم در پروفایل فرستنده (دسته `memory`) هم می‌ماند؛ ۱۰ در دقیقه و سقف روزانه |
| POST | `/group/read` | `{up_to}` → `{unread}` |
| POST | `/group/messages/{id}/react` | `{emoji}` یکی از ۷ واکنش؛ `null` = برداشتن |
| POST | `/group/messages/{id}/report` | `{reason?}` — ۱۰ در دقیقه |
| DELETE | `/group/messages/{id}` | حذف پیام خود (مدیر: هر پیام) |
| POST | `/group/voice` | multipart: `voice`، `waveform?`، `reply_to?` → `201` پیام با `kind: voice` — ۱۲ در دقیقه |

## پشتیبانی (تنها راه ارتباط با مدیران سایت)

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/support?after=` | گفتگوی خودم: `{thread, data[{id, mine, from_admin, sender, body, voice, deleted, at}], voice{enabled, max_seconds}}`؛ نام مدیر به عضو نشان داده نمی‌شود (`sender: پشتیبانی`) |
| POST | `/support/messages` | `{body}` → `201` — ۱۰ در دقیقه و ۱۰۰ در روز (`support_limit`) |
| POST | `/support/voice` | multipart: `voice`، `waveform?` → `201` |
| DELETE | `/support/messages/{id}` | حذف پیام خودم |

شمارنده پاسخ‌های نخوانده در `/auth/me`: `counters.support` (برای مدیران `counters.support_admin`).

## بازی‌ها

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/games/question?type=who\|kin\|older` | `{type, question, image, options[{id, label, image?}], answer, explain, person_id}` — ۶۰ در دقیقه؛ خطای `game_empty` (۴۲۲) وقتی داده کافی نیست |

## دستیار هوش مصنوعی

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/assistant` | `{enabled, provider, remaining, max_chars, modes[{key, label, emoji, starter}], restore{enabled, remaining, modes}}` |
| POST | `/assistant/chat` | `{messages: [{role: user/assistant, content}], mode?}` (کل گفتگو تا اینجا، آخری از کاربر؛ سرور چیزی ذخیره نمی‌کند). `mode`: `chat`، `memory`، `mushaere`، `twenty`، `riddle`، `quiz`، `story`، `proverb`، `family_quiz` (مسابقه خاندان از روی اطلاعات عمومی بستگان خود کاربر) → `{reply, remaining}` — ۸ در دقیقه. خطاها با `code`: `ai_off` (۵۰۳)، `ai_quota` (۴۲۹)، `ai_busy`، `ai_auth`، `ai_model`، `ai_rejected`، `ai_blocked`، `ai_empty`، `ai_down` |
| POST | `/assistant/transcribe` | multipart: `voice` (حداکثر ۶ مگابایت و ۲ دقیقه) → `{data{text, remaining}}` صدای فارسی به متن (Groq/OpenAI Whisper یا Gemini)؛ از سهمیه روزانه دستیار کم می‌شود — ۸ در دقیقه. خطا `ai_voice_off` (۵۰۳) |
| POST | `/assistant/speak` | `{text}` → فایل صوتی (`audio/mp4` یا `audio/aac`؛ ذخیره نمی‌شود) — ۱۰ در دقیقه و سقف روزانه؛ اگر سرویس صدای سرور تنظیم نشده `422` با `code: tts_browser` (از صدای خود دستگاه استفاده کنید) |
| POST | `/assistant/live` | `{mode?}` → `{data{provider: gemini\|openai, token, url, setup?, max_seconds, left}}` کلید **یک‌بارمصرف کوتاه‌عمر** برای اتصال مستقیم دستگاه به Gemini Live (WebSocket با `access_token`) یا OpenAI Realtime (WebRTC)؛ کلید اصلی هرگز فرستاده نمی‌شود — ۳ در دقیقه و سقف روزانه هر عضو و کل سایت. خطا `ai_live_off` (۵۰۳) |
| POST | `/media/{id}/restore` | `{mode: restore\|colorize\|both}` → `201 {data: Media, remaining, message}` نسخه بازسازی/رنگی‌شده در همان پروفایل — ۴ در دقیقه و سقف روزانه |

## حمایت از سازنده

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/donate` | `{enabled, title, message, gateway{key, label}\|null, limits{min, max, suggested[]}, accounts[{id, kind: card\|sheba\|account\|link, title, holder, value, note}], thanks[{name, person, message, at}], mine[]}` (اگر خاموش باشد فقط `{enabled: false}`) |
| POST | `/donate` | `{amount (تومان), message?, anonymous?}` → `201 {data{url, id}}`؛ کاربر به `url` (صفحه امن درگاه) هدایت می‌شود — ۶ بار در ۱۰ دقیقه. خطا `donate_off` (۵۰۳)، `donate_gateway` (۵۰۲) |
| GET | `/donate/{id}` | نتیجه پرداخت خودم `{id, status: pending\|paid\|failed, amount, ref}` |

بازگشت درگاه به `GET|POST /donate/callback/{token}` (بیرون از `/api`، بدون ورود و بدون CSRF) است: سرور پرداخت را **سرور-به-سرور**
با همان مبلغ تأیید می‌کند و به `/#/donate?result=paid|failed&id=` برمی‌گرداند؛ هر توکن فقط یک بار تأیید می‌شود.

## مدیریت (فقط مدیران)

| روش | مسیر |
|---|---|
| GET | `/admin/users?q=&role=&status=` |
| PATCH | `/admin/users/{id}` (`role`, `status`) |
| POST | `/admin/users/{id}/approve` — تأیید عضو در انتظار (`?status=pending` در فهرست کاربران؛ `pending` در پاسخ = تعداد) |
| POST | `/admin/users/{id}/reject` — رد عضو در انتظار |
| GET | `/admin/edit-requests?status=pending\|approved\|rejected` — `{data[{id, kind: person\|marriage, status, degree, reason, decision_note, created_at, decided_at, person, marriage, requester{id, name, person_id}, decider, fields[{field, label, old, new, stale}]}], pending}` |
| POST | `/admin/edit-requests/{id}/approve` — اعمال پیشنهاد در سایت |
| POST | `/admin/edit-requests/{id}/reject` — `{note?}` |
| GET | `/admin/support` — `{data[{id, user, last{text, from_admin, at}, unread}], unread_threads}` |
| GET | `/admin/support/{threadId}?after=` — پیام‌های یک گفتگو (خوانده می‌شود) |
| POST | `/admin/support/{threadId}/messages` — `{body}`؛ `/admin/support/{threadId}/voice` — multipart `voice` |
| GET | `/admin/activity?action=&user_id=&subject_id=` |
| GET | `/admin/trash` |
| POST | `/admin/trash/{id}/restore` |
| POST | `/admin/persons/{id}/merge` (`duplicate_id`) |
| GET | `/admin/sms-messages?status=` — گزارش پیامک‌های تبریک + آمار |
| GET | `/admin/settings` — (مدیر کل) گروه‌های تنظیمات؛ کلیدها فقط با `is_set` و `hint` |
| PUT | `/admin/settings` — `{values: {"pedigree.sms.drivers.kavenegar.api_key": "...", ...}, clear: [keys]}`؛ کلید خالی = بدون تغییر |
| POST | `/admin/settings/test` — `{action: sms_credit|sms_send|push|social|ai, provider?, network?, handle?}` (پیامک آزمایشی فقط به موبایل خود مدیر؛ `ai` یک پیام کوتاه با سرویس هوش مصنوعی انتخاب‌شده) |
| GET | `/admin/overview` — `{stats{members, active_30d, persons, images, videos, storage_bytes, pending_media, video_queue, messages_today, group_today, open_reports, sms_today, ai_today, ...}, checks[{key, ok, label, value, fix}]}` (سلامت سرور) |
| POST | `/admin/users/{id}/logout-all` — خروج کاربر از همه دستگاه‌ها (خروج مدیران فقط با مدیر کل) |
| POST | `/admin/broadcast` — (مدیر کل) `{title, body}` اعلان به همه اعضا؛ ۳ بار در ساعت |
| GET | `/admin/group/reports` — `{data[{message, author, reports[{by, reason}]}], muted[]}` |
| POST | `/admin/group/reports/{messageId}/resolve` — `{action: dismiss|delete|mute, days?}` (`days` خالی با `mute` = دائم) |
| POST | `/admin/group/messages/{id}/pin` — `{pinned: bool}` (حداکثر ۵) |
| POST | `/admin/users/{id}/group-mute` — `{days}`: `0` برداشتن، `null` دائم، `1..365` روز |

### حمایت از سازنده (فقط مدیر کل)

| روش | مسیر |
|---|---|
| GET | `/admin/donations` — `{accounts[], donations[{id, name, amount, status, gateway, ref, card, message, anonymous, at}], totals{count, sum, month}}` |
| POST | `/admin/donation-accounts` — `{kind: card\|sheba\|account\|link, title, holder?, value, note?, active?}`؛ کارت با رقم کنترل (Luhn)، شبا با IBAN (mod-97)، لینک فقط `https` (اعداد فارسی پذیرفته و یکسان‌سازی می‌شوند) |
| PUT | `/admin/donation-accounts/{id}` — همان فیلدها |
| DELETE | `/admin/donation-accounts/{id}` |
| POST | `/admin/donation-accounts/reorder` — `{ids[]}` |

### قالب‌های پیامک (فقط مدیر کل؛ برای بقیه `403`)

| روش | مسیر |
|---|---|
| GET | `/admin/sms-templates` — `{data[{id, occasion, title, body, active, default, sort_order, updated_at}], occasions[{key, label, emoji, window}], variables[{key, token, label, group, occasions, sample}], limits{body_max, title_max, per_occasion}}` |
| POST | `/admin/sms-templates` — `{occasion, title, body, active}`؛ متن با متغیرهای فارسی (`{نام_کامل_گیرنده}`) یا انگلیسی (`{to_full_name}`)؛ متغیر ناشناخته، متن بدون نام فرستنده یا بیش از ۵۰۰ نویسه → `422` |
| PUT | `/admin/sms-templates/{id}` — همان فیلدها (آخرین قالب فعال تولد را نمی‌شود خاموش یا حذف کرد) |
| DELETE | `/admin/sms-templates/{id}` |
| POST | `/admin/sms-templates/reorder` — `{occasion, ids[]}` |
| POST | `/admin/sms-templates/preview` — `{occasion, body, person_id?, note?}` → `{text, length, parts}` (با مقادیر نمونه، یا مشخصات واقعی `person_id` و خود مدیر به عنوان فرستنده؛ متن نامعتبر → `422`) |
| POST | `/admin/sms-templates/defaults` — بازگردانی متن‌های پیش‌فرض (قالب‌های ساخته‌شده توسط مدیر دست نمی‌خورند) |

## بینش‌های خاندان و ابزارهای هوشمند

| روش | مسیر | توضیح |
|---|---|---|
| GET | `/insights/stats` | آمار خاندان: `totals{persons, living, deceased, male, female, marriages, divorces, members, generations}`، `lifespan{all, male, female, count}`، `births_by_decade[]`، `birth_months[]`، `family_size[{decade, avg, mothers}]`، `names{male, female, last}`، `places{birth, city}`، `occupations`، `education`، `records{oldest_living, longest_lived, most_children, most_grandchildren}` — کش ۱۰ دقیقه، `throttle:20,1` |
| GET | `/insights/consistency` | بررسی داده‌ها: `issues[{code, level: error\|warning, person{id,name,gender}, other, message}]` (حداکثر ۵۰۰)، `total`، `counts{error, warning}`، `by_code`، `codes` (برچسب فارسی هر نوع)، `checked` |
| GET | `/insights/ai` | وضعیت ابزارهای هوشمند: `biographer`, `photo_reader`, `tones`, `tasks`, `remaining` |
| GET | `/persons/{id}/timeline` | خط زمان زندگی: `events[{date, year, kind, title, person?, age, approx, category?}]` — `kind`: birth, death, marriage, divorce, child, grandchild, sibling, parent_death, spouse_death, child_death, sibling_death, child_marriage, history؛ `category` برای history: iran, world, science, disaster |
| POST | `/persons/{id}/ai/biography` | `{tone: warm\|formal\|story\|short}` ← `{text, remaining}` پیش‌نویس زندگی‌نامه (ذخیره نمی‌شود؛ فقط ویرایشگران پروفایل؛ ۴ در دقیقه) |
| POST | `/media/{id}/ai/read` | `{task: describe\|transcribe}` ← `{text, can_save, remaining}` توضیح عکس و حدس دهه یا خواندن دست‌خط (هر کسی که عکس را می‌بیند؛ ۶ در دقیقه). خطای `ai_vision` یعنی مدل انتخاب‌شده عکس نمی‌پذیرد |

## خطاها

- هر خطای پیش‌بینی‌نشده: `500 {message: "... کد پیگیری: ABC123", code: "server_error", ref: "ABC123"}` — بدون هیچ جزئیات فنی.
- `POST /client-errors` `{message, type?, source?, line?, page?}` ← `202` گزارش خطای مرورگر (فقط اعضا، ۱۰ در دقیقه، ذخیره پاک‌شده).
- مدیر کل: `GET /admin/errors?status=open|resolved|all&source=server|client&q=REF`، `POST /admin/errors/{id}/resolve`،
  `POST /admin/errors/resolve-all`، `DELETE /admin/errors/resolved`.
