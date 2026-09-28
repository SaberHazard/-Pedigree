/**
 * برچسب‌های فارسی مقادیر ثابت (برای یکدستی در همه صفحات)
 */
import { store } from '../core/store.js';

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
  'text.updated': 'متن را ویرایش کرد',
  'text.restored': 'نسخه قبلی متن را بازگرداند',
  'resume.created': 'سابقه‌ای به رزومه افزود',
  'resume.updated': 'سابقه رزومه را ویرایش کرد',
  'resume.deleted': 'سابقه‌ای از رزومه حذف کرد',
  'comment.created': 'نظر نوشت',
  'comment.updated': 'نظرش را ویرایش کرد',
  'comment.deleted': 'نظری را حذف کرد',
  'comment.hidden': 'نظری را مخفی کرد',
  'comment.unhidden': 'نظری را آشکار کرد',
  'rating.updated': 'به ویژگی‌ها امتیاز داد',
  'account.username_changed': 'نام کاربری را تغییر داد',
  'auth.claimed': 'حساب خود را با پیامک تأیید کرد',
  'settings.updated': 'تنظیمات سایت / کلیدهای API را تغییر داد',
  'sms.greeting_sent': 'پیامک تبریک تولد فرستاد برای',
  'sms.greeting_auto': 'پیامک تبریک خودکار فرستاد برای',
};

export const actionLabel = (a) => ACTIONS[a] || a;

export const FIELDS = {
  first_name: 'نام', last_name: 'نام خانوادگی', nickname: 'شهرت/لقب', title: 'عنوان', gender: 'جنسیت',
  birth_order: 'ترتیب تولد', birth_date: 'تاریخ تولد', birth_place: 'محل تولد', is_deceased: 'درگذشته',
  death_date: 'تاریخ وفات', death_place: 'محل وفات', burial_place: 'محل دفن', national_code: 'کد ملی',
  phone: 'موبایل', birth_cert_no: 'شماره شناسنامه', birth_cert_place: 'محل صدور', email: 'ایمیل',
  occupation: 'شغل', education: 'تحصیلات', residence: 'محل سکونت', biography: 'زندگی‌نامه', is_locked: 'قفل پروفایل',
  password: 'رمز عبور', father_id: 'پدر', mother_id: 'مادر', avatar_media_id: 'عکس پروفایل',
  status: 'وضعیت', marriage_date: 'تاریخ ازدواج', end_date: 'تاریخ پایان', username: 'نام کاربری',
  education_level: 'مقطع تحصیلی', education_field: 'رشته تحصیلی', education_institution: 'دانشگاه / مدرسه',
  academic_rank: 'مرتبه علمی', workplace: 'محل کار', country: 'کشور', province: 'استان', city: 'شهر',
  address: 'نشانی', postal_code: 'کد پستی', home_lat: 'موقعیت خانه', home_lng: 'موقعیت خانه',
  location_visibility: 'نمایش نشانی', landline: 'تلفن ثابت', website: 'وب‌سایت', social: 'شبکه‌های اجتماعی',
  contact_visibility: 'نمایش شماره و راه‌های ارتباطی', education_field_group: 'گروه رشته', honorific_mode: 'عنوان خودکار دکتر/مهندس', blood_type: 'گروه خونی', languages: 'زبان‌ها', interests: 'علاقه‌مندی‌ها',
  custom_fields: 'ویژگی‌های دیگر', burial_lat: 'موقعیت مزار', burial_lng: 'موقعیت مزار',
  summary: 'بیوگرافی', description: 'توضیحات بستگان', resume: 'رزومه', title_resume: 'عنوان', organization: 'سازمان',
  body: 'متن نظر', start_date: 'تاریخ شروع', is_current: 'ادامه دارد',
};

/** برچسب یکی از گزینه‌های پروفایل (مقطع تحصیلی، مرتبه علمی ...) از تنظیمات سرور */
export function profileOption(group, key) {
  return (key && store.config.profile?.[group]?.[key]) || key || '';
}

/** نام فارسی کشور از کد ISO (با Intl؛ اگر مرورگر پشتیبانی نکند خود کد) */
let regionNames;
export function countryName(code) {
  if (!code) return '';
  try {
    regionNames ??= new Intl.DisplayNames(['fa'], { type: 'region' });
    return regionNames.of(code) || code;
  } catch {
    return code;
  }
}

/** استان‌های ایران (پیشنهاد در فرم وقتی کشور ایران است) */
export const IRAN_PROVINCES = [
  'آذربایجان شرقی', 'آذربایجان غربی', 'اردبیل', 'اصفهان', 'البرز', 'ایلام', 'بوشهر', 'تهران', 'چهارمحال و بختیاری',
  'خراسان جنوبی', 'خراسان رضوی', 'خراسان شمالی', 'خوزستان', 'زنجان', 'سمنان', 'سیستان و بلوچستان', 'فارس', 'قزوین',
  'قم', 'کردستان', 'کرمان', 'کرمانشاه', 'کهگیلویه و بویراحمد', 'گلستان', 'گیلان', 'لرستان', 'مازندران', 'مرکزی',
  'هرمزگان', 'همدان', 'یزد',
];

/** بخش‌های «درصد تکمیل پروفایل» */
export const COMPLETENESS = {
  avatar: 'عکس پروفایل', birth_date: 'تاریخ تولد', birth_place: 'محل تولد', education_level: 'تحصیلات',
  occupation: 'شغل', location: 'محل زندگی', summary: 'بیوگرافی', biography: 'زندگی‌نامه کامل', resume: 'رزومه',
  father: 'پدر', mother: 'مادر', death_date: 'تاریخ وفات', burial_place: 'آرامگاه', contact: 'راه ارتباطی',
  social: 'شبکه‌های اجتماعی',
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
