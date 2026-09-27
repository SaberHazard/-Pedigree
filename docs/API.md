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
  "birth_date": "1370", "password": null, "device_name": "iPhone" }
```

### `POST /auth/login` — ورود با کد ملی و رمز
```json
{ "national_code": "0012345679", "password": "********", "device_name": "Pixel 8" }
```
پس از ۵ تلاش ناموفق، ورود با آن کد ملی ۱۵ دقیقه قفل می‌شود.

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
| PUT | `/account/preferences` | `theme` (light/dark/auto)، `arc_top` (name/fullname/nickname/none)، `arc_bottom` (dates/place/occupation/none)، `children_order` (rtl/ltr)، `show_photos`، `compact`، `calendar` |
| PUT | `/account/password` | `current_password` (اگر در ۱۵ دقیقه اخیر با پیامک وارد نشده‌اید)، `password`، `password_confirmation` |
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
`death_place, burial_place, education, residence, biography, is_locked, avatar_medium,
national_code, phone, birth_cert_no, birth_cert_place, email` (فیلدهای حساس فقط برای افراد مجاز، وگرنه `null`)،
`has_national_code, has_phone, account{exists, active, has_password}`،
`permissions{edit, sensitive, upload, delete, admin}`.

### `POST /persons` — ساخت شخص مستقل (مثلاً جد اعلای یک خاندان)
### `PATCH /persons/{id}` — ویرایش
فیلدها: `first_name, last_name, nickname, title, gender, birth_order, birth_date, birth_place,
is_deceased, death_date, death_place, burial_place, occupation, education, residence, biography`
و (با دسترسی حساس) `national_code, phone, birth_cert_no, birth_cert_place, email, password, is_locked`.

### `DELETE /persons/{id}` — حذف (قابل بازیابی توسط مدیر)

### `GET /persons/{id}/relatives`
```json
{ "father": Node|null, "mother": Node|null,
  "spouses": [{ "person": Node, "marriage": {id, husband_id, wife_id, status, marriage_date, end_date, sort_order} }],
  "children": [Node], "siblings": [Node + half] }
```

### `GET /persons/{id}/history` — تاریخچه تغییرات (لاگ ممیزی)
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
| GET | `/persons/{id}/media?type=image` | گالری (تأییدشده‌ها + در انتظارهای قابل مشاهده برای شما) |
| POST | `/persons/{id}/media` | آپلود: `file`, `caption`, `description`, `taken_at`, `as_avatar` |
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
