<?php

namespace Database\Seeders;

use App\Models\Family;
use App\Models\Marriage;
use App\Models\Person;
use App\Models\PersonComment;
use App\Models\PersonRating;
use App\Models\ResumeItem;
use App\Models\User;
use App\Services\People\PersonTextService;
use Illuminate\Database\Seeder;

/**
 * داده نمونه برای آشنایی با برنامه (فقط محیط توسعه):
 *   php artisan db:seed --class=DemoSeeder
 *
 * شامل: ۵ نسل، پدربزرگی با دو همسر، ازدواج فامیلی (دخترعمو/پسرعمو)،
 * و همسری که درخت خانوادگی خودش (خاندان رضایی) تا جدش ثبت شده است.
 *
 * ورود نمونه: کد ملی 0012345679 و رمز demo1234 (یا موبایل 09120000000)
 * پدر و مادر او هم حساب دارند (موبایل 09120000001 و 09120000002، رمز demo1234)
 * تا رنگ‌بندی نویسندگان متن‌ها، نظرها و امتیازها دیده شود.
 */
class DemoSeeder extends Seeder
{
    private ?User $admin = null;

    public function run(): void
    {
        $this->admin = User::create(['role' => User::ROLE_SUPER_ADMIN]);

        // ---------------------------------------------------------------- خاندان رضایی (درخت همسر)
        $qasem = $this->p('قاسم', 'رضایی', 'm', '1245', '1320', ['title' => 'کربلایی', 'birth_place' => 'اراک', 'occupation' => 'کشاورز', 'burial_place' => 'قم، قبرستان شیخان']);
        $khanom = $this->p('خانم‌جان', 'اکبری', 'f', '1250', '1318');
        $this->m($qasem, $khanom, '1268');
        $akbar = $this->p('اکبر', 'رضایی', 'm', '1275', '1350', ['title' => 'حاج', 'birth_place' => 'اراک', 'occupation' => 'بازاری'], $qasem, $khanom);
        $tahereh = $this->p('طاهره', 'نوری', 'f', '1280', '1358');
        $this->m($akbar, $tahereh, '1298');
        $masoumeh = $this->p('معصومه', 'رضایی', 'f', '1305-03-14', '1388-11-02', ['birth_place' => 'اراک'], $akbar, $tahereh);
        $this->p('ابراهیم', 'رضایی', 'm', '1302', '1370', [], $akbar, $tahereh);
        $this->p('صغری', 'رضایی', 'f', '1309', '1395', [], $akbar, $tahereh);

        // ---------------------------------------------------------------- خاندان احمدی
        $hasan = $this->p('حسن', 'احمدی', 'm', '1270', '1345-08-21', ['title' => 'حاج', 'birth_place' => 'تفرش', 'occupation' => 'تاجر فرش', 'burial_place' => 'تهران، ابن‌بابویه', 'burial_lat' => 35.5856, 'burial_lng' => 51.4426, 'education_level' => 'literate']);
        $fatemeh = $this->p('فاطمه', 'حسینی', 'f', '1275', '1340', ['title' => 'سیده']);
        $sakineh = $this->p('سکینه', 'کاظمی', 'f', '1285', '1360');
        $this->m($hasan, $fatemeh, '1297', 0);
        $this->m($hasan, $sakineh, '1318', 1);

        $mohammad = $this->p('محمد', 'احمدی', 'm', '1300-02-10', '1375-06-30', ['occupation' => 'معلم', 'birth_order' => 1], $hasan, $fatemeh);
        $ali = $this->p('علی', 'احمدی', 'm', '1303', '1380', ['occupation' => 'کارمند راه‌آهن', 'birth_order' => 2], $hasan, $fatemeh);
        $zahra = $this->p('زهرا', 'احمدی', 'f', '1306', '1390', ['birth_order' => 3], $hasan, $fatemeh);
        $reza = $this->p('رضا', 'احمدی', 'm', '1320-07-05', null, ['occupation' => 'مهندس عمران', 'residence' => 'تهران'], $hasan, $sakineh);
        $maryam = $this->p('مریم', 'احمدی', 'f', '1322', null, ['residence' => 'اصفهان'], $hasan, $sakineh);

        // همسران نسل اول (معصومه از درخت رضایی)
        $this->m($mohammad, $masoumeh, '1325');
        $narges = $this->p('نرگس', 'کریمی', 'f', '1307', '1392');
        $this->m($ali, $narges, '1328');
        $javad = $this->p('جواد', 'موسوی', 'm', '1300', '1378', ['title' => 'سید']);
        $this->m($javad, $zahra, '1326');
        $leila = $this->p('لیلا', 'صادقی', 'f', '1325', null);
        $this->m($reza, $leila, '1348');
        $mahmoud = $this->p('محمود', 'جعفری', 'm', '1318', '1399');
        $this->m($mahmoud, $maryam, '1342');

        // نسل دوم
        $hossein = $this->p('حسین', 'احمدی', 'm', '1330-01-20', null, ['occupation' => 'پزشک', 'title' => 'دکتر', 'education_level' => 'subspecialist', 'education_field' => 'فوق‌تخصص قلب و عروق', 'education_institution' => 'دانشگاه علوم پزشکی تهران', 'academic_rank' => 'associate_professor', 'workplace' => 'بیمارستان شریعتی', 'country' => 'IR', 'city' => 'تهران'], $mohammad, $masoumeh);
        $fateme2 = $this->p('فاطمه', 'احمدی', 'f', '1333', null, [], $mohammad, $masoumeh);
        $amir = $this->p('امیر', 'احمدی', 'm', '1336-05-12', null, ['occupation' => 'بازنشسته بانک', 'education_level' => 'bachelor', 'country' => 'IR', 'city' => 'تهران'], $mohammad, $masoumeh);
        $mahdi = $this->p('مهدی', 'احمدی', 'm', '1332', '1361', ['burial_place' => 'بهشت زهرا، قطعه ۲۴', 'burial_lat' => 35.5316, 'burial_lng' => 51.3725, 'title' => 'شهید', 'education_level' => 'high_school'], $ali, $narges);
        $sara = $this->p('سارا', 'احمدی', 'f', '1335', null, [], $ali, $narges);
        $mostafa = $this->p('مصطفی', 'موسوی', 'm', '1334', null, ['title' => 'سید', 'occupation' => 'استاد دانشگاه', 'education_level' => 'phd', 'education_field' => 'ادبیات فارسی', 'academic_rank' => 'professor', 'country' => 'IR', 'city' => 'شیراز'], $javad, $zahra);
        $zeynab = $this->p('زینب', 'موسوی', 'f', '1338', null, ['title' => 'سیده'], $javad, $zahra);
        $alireza = $this->p('علیرضا', 'احمدی', 'm', '1350', null, [], $reza, $leila);
        $niloufar = $this->p('نیلوفر', 'احمدی', 'f', '1353-09-30', null, ['occupation' => 'معمار', 'education_level' => 'master', 'country' => 'DE', 'province' => 'برلین', 'city' => 'برلین', 'home_lat' => '52.5200', 'home_lng' => '13.4050', 'share_location' => true, 'languages' => 'فارسی، آلمانی، انگلیسی'], $reza, $leila);
        $this->p('کاوه', 'جعفری', 'm', '1345', null, [], $mahmoud, $maryam);

        // ازدواج فامیلی: حسین (پسرِ محمد) با سارا (دخترِ علی) — دخترعمو
        $this->m($hossein, $sara, '1356');
        $pari = $this->p('پریسا', 'نادری', 'f', '1340', null);
        $this->m($amir, $pari, '1362');
        $hamid = $this->p('حمید', 'طاهری', 'm', '1330', null);
        $this->m($hamid, $fateme2, '1352', 0, 'divorced');
        $roya = $this->p('رویا', 'اکبری', 'f', '1352', null);
        $this->m($alireza, $roya, '1378');

        // نسل سوم
        $reza2 = $this->p('رضا', 'احمدی', 'm', '1360', null, [], $hossein, $sara);
        $this->p('مریم', 'احمدی', 'f', '1363', null, [], $hossein, $sara);
        $mreza = $this->p('محمدرضا', 'احمدی', 'm', '1368-07-12', null, [
            'occupation' => 'برنامه‌نویس', 'residence' => 'نارمک', 'birth_order' => 1,
            'education_level' => 'master', 'education_field' => 'مهندسی نرم‌افزار', 'education_institution' => 'دانشگاه صنعتی شریف',
            'workplace' => 'یک شرکت نرم‌افزاری', 'country' => 'IR', 'province' => 'تهران', 'city' => 'تهران',
            'home_lat' => '35.7448', 'home_lng' => '51.5049', 'share_location' => true, 'blood_type' => 'O+',
            'languages' => 'فارسی، انگلیسی', 'interests' => 'کوهنوردی، تاریخ خانواده، عکاسی',
            'social' => ['telegram' => '@mreza_demo', 'github' => 'mreza-demo'],
            'custom_fields' => [['label' => 'غذای محبوب', 'value' => 'قورمه‌سبزی'], ['label' => 'تیم محبوب', 'value' => 'ندارد!']],
        ], $amir, $pari);
        $this->p('زینب', 'احمدی', 'f', '1372', null, ['birth_order' => 2], $amir, $pari);
        $this->p('سینا', 'طاهری', 'm', '1355', null, [], $hamid, $fateme2);
        $this->p('آرش', 'احمدی', 'm', '1380', null, [], $alireza, $roya);
        $this->p('آوا', 'احمدی', 'f', '1384', null, [], $alireza, $roya);

        // نسل چهارم
        $elham = $this->p('الهام', 'رحیمی', 'f', '1370', null);
        $this->m($mreza, $elham, '1395');
        $this->p('آرین', 'احمدی', 'm', '1398-02-01', null, [], $mreza, $elham);
        $this->p('ترانه', 'احمدی', 'f', '1401', null, [], $mreza, $elham);
        $this->p('پارسا', 'احمدی', 'm', '1389', null, [], $reza2, null);

        // حساب مدیر نمونه = محمدرضا احمدی
        $mreza->phone = '09120000000';
        $mreza->national_code = '0012345679';
        $mreza->created_by = $this->admin->id;
        $mreza->save();
        $this->admin->person_id = $mreza->id;
        $this->admin->password = 'demo1234';
        $this->admin->password_set_by = $this->admin->id;
        $this->admin->last_login_at = now();
        $this->admin->save();

        $this->richContent($hasan, $mreza, $amir, $pari, $hossein);

        Family::create(['name' => 'خاندان احمدی', 'description' => 'نوادگان حاج حسن احمدی تاجر فرش تفرشی', 'root_person_id' => $hasan->id, 'color' => '#0f766e'])
            ->forceFill(['created_by' => $this->admin->id])->save();
        Family::create(['name' => 'خاندان رضایی', 'description' => 'خانواده مادری؛ از اراک', 'root_person_id' => $qasem->id, 'color' => '#b45309'])
            ->forceFill(['created_by' => $this->admin->id])->save();
    }

    /**
     * متن‌های چندنویسنده، رزومه، نظر و امتیاز نمونه (برای دیدن رنگ‌بندی نویسندگان)
     */
    private function richContent(Person $hasan, Person $mreza, Person $amir, Person $pari, Person $hossein): void
    {
        $texts = app(PersonTextService::class);
        $father = $this->account($amir, '09120000001');
        $mother = $this->account($pari, '09120000002');

        $texts->save($hasan, 'biography', "حاج حسن احمدی در تفرش به دنیا آمد و در جوانی به تهران مهاجرت کرد.\nحجره فرش او در بازار بزرگ تهران سال‌ها پاتوق خانواده بود.", $this->admin);
        $texts->save($mreza, 'summary', 'برنامه‌نویس و علاقه‌مند به تاریخ خانواده؛ سازنده همین شجره‌نامه.', $this->admin);
        // پدر توضیح می‌نویسد، مادر یک غلط املایی را اصلاح و جمله‌ای اضافه می‌کند
        $texts->save($mreza, 'description', "محمدرضا از کودکی به کامپیوتر علاقه داشت و ساعت‌ها با کامپیوتر قدیمی پدربزرگش بازی میکرد.\nدر دانشگاه شریف مهندسی نرم‌افزار خواند.", $father);
        $texts->save($mreza, 'description', "محمدرضا از کودکی به کامپیوتر علاقه داشت و ساعت‌ها با کامپیوتر قدیمی پدربزرگش بازی می‌کرد.\nدر دانشگاه شریف مهندسی نرم‌افزار خواند.\nپسری مهربان و دل‌سوز است که همیشه به فکر خانواده است.", $mother);

        foreach ([
            ['education', 'کارشناسی مهندسی کامپیوتر', 'دانشگاه صنعتی شریف', '1386', '1390'],
            ['education', 'کارشناسی ارشد مهندسی نرم‌افزار', 'دانشگاه صنعتی شریف', '1390', '1392'],
            ['military', 'خدمت سربازی (امریه)', 'وزارت نیرو', '1392', '1394'],
            ['work', 'برنامه‌نویس ارشد', 'یک شرکت نرم‌افزاری', '1394', null],
        ] as $i => [$type, $title, $org, $start, $end]) {
            $item = new ResumeItem(['type' => $type, 'title' => $title, 'organization' => $org, 'start_date' => $start, 'end_date' => $end, 'is_current' => $end === null, 'sort_order' => $i]);
            $item->person_id = $mreza->id;
            $item->created_by = $item->updated_by = $i < 2 ? $father->id : $this->admin->id;
            $item->save();
        }
        $texts->save($hossein, 'summary', 'فوق‌تخصص قلب و دانشیار دانشگاه؛ پزشک خانواده بزرگ احمدی.', $father);

        foreach ([[$father, 'از بچگی پسر باهوش و کنجکاوی بود. به او افتخار می‌کنیم.'], [$mother, 'همیشه اولین نفری است که برای کمک به فامیل می‌آید.']] as [$user, $body]) {
            $comment = new PersonComment(['body' => $body]);
            $comment->person_id = $mreza->id;
            $comment->user_id = $user->id;
            $comment->save();
        }
        foreach ([$father->id => [5, 4, 4, 5, 5, 4], $mother->id => [5, 5, 3, 5, 4, 5]] as $userId => $scores) {
            foreach (['kindness', 'humor', 'charisma', 'honesty', 'responsibility', 'family_devotion'] as $k => $trait) {
                PersonRating::create(['person_id' => $mreza->id, 'user_id' => $userId, 'trait' => $trait, 'score' => $scores[$k]]);
            }
        }
    }

    /** حساب کاربری نمونه برای یک شخص */
    private function account(Person $person, string $phone): User
    {
        $person->phone = $phone;
        $person->save();
        $user = User::create(['person_id' => $person->id, 'role' => User::ROLE_MEMBER]);
        $user->password = 'demo1234';
        $user->last_login_at = now();
        $user->save();

        return $user;
    }

    private function p(string $first, string $last, string $gender, ?string $birth, ?string $death, array $extra = [], ?Person $father = null, ?Person $mother = null): Person
    {
        $person = new Person(array_merge([
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'birth_date' => $birth,
            'death_date' => $death,
            'is_deceased' => $death !== null || in_array($extra['title'] ?? '', ['شهید'], true),
        ], $extra));
        $person->father_id = $father?->id;
        $person->mother_id = $mother?->id;
        $person->created_by = $this->admin->id;
        $person->save();

        return $person;
    }

    private function m(Person $husband, Person $wife, ?string $date = null, int $order = 0, string $status = 'married'): Marriage
    {
        $marriage = new Marriage(['husband_id' => $husband->id, 'wife_id' => $wife->id, 'marriage_date' => $date, 'sort_order' => $order, 'status' => $status]);
        $marriage->created_by = $this->admin->id;
        $marriage->save();

        return $marriage;
    }
}
