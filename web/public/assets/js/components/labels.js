/**
 * برچسب‌های فارسی مقادیر ثابت (برای یکدستی در همه صفحات)
 */
export const ACTIONS = {
  'person.created': 'شخص جدیدی ثبت کرد',
  'person.updated': 'اطلاعات را ویرایش کرد',
  'person.deleted': 'شخصی را حذف کرد',
  'person.restored': 'شخصی را بازیابی کرد',
  'person.merged': 'دو پروفایل را ادغام کرد',
  'person.avatar_changed': 'عکس پروفایل را تغییر داد',
  'relation.child_added': 'فرزند اضافه کرد برای',
  'relation.spouse_added': 'همسر اضافه کرد برای',
  'relation.parent_added': 'والد اضافه کرد برای',
  'relation.sibling_added': 'خواهر/برادر اضافه کرد برای',
  'relation.parent_removed': 'ارتباط والد را حذف کرد برای',
  'link.spouse': 'همسر را از درخت دیگر وصل کرد برای',
  'link.father': 'پدر را از درخت دیگر وصل کرد برای',
  'link.mother': 'مادر را از درخت دیگر وصل کرد برای',
  'link.child': 'فرزند را از درخت دیگر وصل کرد برای',
  'link.requested': 'درخواست اتصال درخت داد',
  'link.accepted': 'درخواست اتصال را پذیرفت',
  'link.rejected': 'درخواست اتصال را رد کرد',
  'media.uploaded': 'فایل جدید آپلود کرد',
  'media.approved': 'عکس/ویدیوی جدید منتشر شد برای',
  'media.rejected': 'فایلی رد شد',
  'media.voted': 'رأی داد',
  'media.deleted': 'فایلی را حذف کرد',
  'marriage.updated': 'اطلاعات ازدواج را ویرایش کرد',
  'marriage.deleted': 'ازدواجی را حذف کرد',
  'family.created': 'خاندان جدیدی ساخت',
  'family.updated': 'خاندان را ویرایش کرد',
  'family.deleted': 'خاندانی را حذف کرد',
  'auth.login': 'وارد شد',
  'auth.logout': 'خارج شد',
  'auth.registered': 'ثبت‌نام کرد',
  'auth.password_failed': 'رمز اشتباه وارد کرد',
  'auth.otp_failed': 'کد پیامکی اشتباه وارد کرد',
  'account.password_changed': 'رمز عبور را تغییر داد',
  'account.phone_changed': 'شماره موبایل را تغییر داد',
  'account.session_revoked': 'یک نشست را بست',
  'admin.user_updated': 'نقش/وضعیت کاربر را تغییر داد',
  'export.gedcom': 'خروجی GEDCOM گرفت',
};

export const actionLabel = (a) => ACTIONS[a] || a;

export const FIELDS = {
  first_name: 'نام', last_name: 'نام خانوادگی', nickname: 'شهرت/لقب', title: 'عنوان', gender: 'جنسیت',
  birth_order: 'ترتیب تولد', birth_date: 'تاریخ تولد', birth_place: 'محل تولد', is_deceased: 'درگذشته',
  death_date: 'تاریخ وفات', death_place: 'محل وفات', burial_place: 'محل دفن', national_code: 'کد ملی',
  phone: 'موبایل', birth_cert_no: 'شماره شناسنامه', birth_cert_place: 'محل صدور', email: 'ایمیل',
  occupation: 'شغل', education: 'تحصیلات', residence: 'محل سکونت', biography: 'زندگی‌نامه', is_locked: 'قفل پروفایل',
  password: 'رمز عبور', father_id: 'پدر', mother_id: 'مادر', avatar_media_id: 'عکس پروفایل',
  status: 'وضعیت', marriage_date: 'تاریخ ازدواج', end_date: 'تاریخ پایان',
};

export const RELATIONS = {
  child: { label: 'فرزند', icon: 'baby' },
  spouse: { label: 'همسر', icon: 'heart' },
  father: { label: 'پدر', icon: 'user' },
  mother: { label: 'مادر', icon: 'user' },
  sibling: { label: 'خواهر/برادر', icon: 'users' },
};

export const MARRIAGE_STATUS = { married: 'متأهل', divorced: 'جدا شده', widowed: 'فوت همسر' };

export const TITLES = ['حاج', 'حاجیه', 'سید', 'سیده', 'میرزا', 'کربلایی', 'مشهدی', 'دکتر', 'مهندس', 'استاد', 'شهید', 'آیت‌الله', 'حجت‌الاسلام', 'خان', 'بانو'];
