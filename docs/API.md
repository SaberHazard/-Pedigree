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
پاسخ: `{ "data": [Node + father_name], "meta": { "current_page", "last_page", "total" } }`

### `GET /persons/{id}` — مشخصات کامل
علاوه بر فیلدهای Node:
- مشخصات: `death_place, burial_place, burial_location{lat,lng}, education_level, education_field, education_institution,
  academic_rank, workplace, country (ISO2), province, city, residence, blood_type, languages, interests,
  custom_fields[{label,value}], website, social{instagram,telegram,...}, biography, is_locked, avatar_medium`
- با اجازه خود شخص (`share_location`) یا برای ویرایشگران: `address, postal_code, home_location{lat,lng}` (وگرنه `null`؛ `has_home_location`)
- با اجازه خود شخص (`share_contact`) یا دسترسی حساس: `phone, email, landline`
- فقط با دسترسی حساس: `national_code, birth_cert_no, birth_cert_place, account.username, account.has_password`
- `texts{summary|description|biography|resume: {segments: [[user_id, "متن"], ...], revision, updated_at}}` — نویسنده هر تکه
- `resume[{id, type, title, organization, location, start_date, end_date, is_current, description, author_id}]`
- `field_meta{field: {u: user_id, t: unix}}` آخرین ویرایشگر هر فیلد
- `contributors[{id, name, person_id, relation, color}]` — `color`: `owner` | `admin` | شماره پالت
- `completeness{percent, missing[]}` (فقط برای ویرایشگران)
- `permissions{edit, sensitive, upload, delete, admin, history, comment, rate}`

### `POST /persons` — ساخت شخص مستقل (مثلاً جد اعلای یک خاندان)
### `PATCH /persons/{id}` — ویرایش
همه فیلدهای بالا (به جز متن‌ها و رزومه که مسیر جدا دارند) + `home_lat, home_lng, burial_lat, burial_lng, share_location, share_contact`؛
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
`{ "data": [{kind: home|burial, person: Node, lat, lng, city?, country?, place?}] }` — خانه‌ها فقط با اجازه خود شخص یا برای بستگان درجه یک.
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
### `PATCH /marriages/{id}` — `status (married/divorced/widowed), marriage_date, end_date, sort_order, notes`
### `DELETE /marriages/{id}`

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
| GET | `/persons/{id}/media?type=image&category=story` | گالری یا استوری‌ها (تأییدشده‌ها + در انتظارهای قابل مشاهده برای شما) |
| POST | `/persons/{id}/media` | آپلود: `file`, `caption`, `description`, `taken_at`, `as_avatar`, `category` (gallery/story) |
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

## مدیریت (فقط مدیران)

| روش | مسیر |
|---|---|
| GET | `/admin/users?q=&role=&status=` |
| PATCH | `/admin/users/{id}` (`role`, `status`) |
| GET | `/admin/activity?action=&user_id=&subject_id=` |
| GET | `/admin/trash` |
| POST | `/admin/trash/{id}/restore` |
| POST | `/admin/persons/{id}/merge` (`duplicate_id`) |
