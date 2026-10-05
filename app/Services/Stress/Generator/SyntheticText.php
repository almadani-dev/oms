<?php

namespace App\Services\Stress\Generator;

/**
 * Clearly-synthetic, harmless text. Names are random combinations from small
 * generic pools — no real beneficiary, donor, phone, identity or account
 * number is ever copied. Notes deliberately include long text, multi-line
 * text, mixed English, Unicode punctuation and values that start with
 * = + - @ so the spreadsheet formula-injection defences get exercised.
 */
final class SyntheticText
{
    private const FIRST = ['محمد', 'أحمد', 'محمود', 'خالد', 'يوسف', 'إبراهيم', 'عمر', 'علي', 'حسن', 'سامي', 'رامي', 'وليد', 'ماهر', 'نادر', 'باسل', 'طارق', 'زياد', 'هاني', 'عماد', 'فادي', 'مصطفى', 'إياد', 'أنس', 'مازن', 'جهاد', 'سليم', 'عادل', 'كريم', 'حمزة', 'بلال'];

    private const FEMALE = ['فاطمة', 'مريم', 'آمنة', 'خديجة', 'سارة', 'نور', 'هبة', 'ريم', 'أسماء', 'دعاء', 'ولاء', 'إيمان', 'رنا', 'سلمى', 'هالة', 'منى', 'عبير', 'لينا'];

    private const FAMILY = ['الشامي', 'المصري', 'النجار', 'الحداد', 'العطار', 'الخطيب', 'الحلبي', 'الكردي', 'السقا', 'البنا', 'الطويل', 'القاضي', 'الزين', 'العلي', 'الحسيني', 'الشريف', 'الفرا', 'الأسطل', 'الدباغ', 'السعدي', 'الريس', 'الجمل', 'العمري', 'البيطار', 'الصباغ', 'الخياط'];

    private const ORG_WORDS = ['الأمل', 'الرحمة', 'النور', 'البر', 'الإحسان', 'التكافل', 'العطاء', 'الخير', 'الوفاء', 'السلام', 'الفجر', 'الغد', 'الإغاثة', 'التنمية', 'الهدى'];

    private const COMPANY_KINDS = ['شركة', 'مؤسسة', 'مكتب', 'محطة', 'ورشة', 'مخازن'];

    private const COMPANY_FIELDS = ['التوريدات العامة', 'المحروقات', 'صيانة المركبات', 'الكهرباء والطاقة', 'الأدوية والمستلزمات', 'المواد الغذائية', 'النقل والشحن', 'الصرافة والتحويلات', 'المياه والتحلية', 'القرطاسية'];

    private const AREAS = ['المنطقة الشمالية', 'المنطقة الوسطى', 'المنطقة الجنوبية', 'الحي الشرقي', 'الحي الغربي', 'مخيم الأمل', 'البلدة القديمة', 'الحي الجديد', 'المنطقة الساحلية', 'الضاحية'];

    private const CITIES = ['المدينة أ', 'المدينة ب', 'المدينة ج', 'القرية د', 'البلدة هـ'];

    private const COUNTRIES = ['فلسطين', 'الأردن', 'تركيا', 'قطر', 'الكويت', 'ماليزيا'];

    private const SHORT_NOTES = [
        'تم التحقق من الوثائق.',
        'دفعة حسب الاتفاق.',
        'بانتظار الإيصال الأصلي.',
        'تمت المراجعة من المحاسب.',
        'حسب طلب المنسق الميداني.',
        'دفعة شهرية.',
        'تحويل عاجل.',
        'مطابق لكشف البنك.',
        'ملاحظة: يرجى أرشفة المستند.',
        'تمت الموافقة من الإدارة.',
    ];

    private const LONG_NOTES = [
        'تم استلام المبلغ بعد مراجعة الاتفاقية الموقعة مع الجهة المانحة، وتمت مطابقة القيمة مع الكشف البنكي الشهري، ويُرجى إرفاق صورة الإيصال في ملف المشروع وإبلاغ المنسق الميداني بموعد بدء التنفيذ الفعلي.',
        'صرف دفعة ضمن خطة التنفيذ المعتمدة؛ تم توزيع المبلغ على المستفيدين حسب الكشوفات المعتمدة من لجنة المشروع، مع الالتزام بنسب الخصم الإدارية المتفق عليها في العقد ومراعاة فروقات سعر الصرف في تاريخ التحويل.',
        'ملاحظة إدارية: تمت إعادة جدولة هذه الدفعة بسبب تأخر التحويل من البنك المراسل، وتم التنسيق مع الجهة المانحة لتمديد فترة التنفيذ أسبوعين إضافيين دون أي تغيير في القيمة الإجمالية للمشروع.',
    ];

    private const ENGLISH = [
        'Verified against bank statement (synthetic).',
        'Donor ref: SYN-REF-%04d — test data only.',
        'Batch #%d approved by finance.',
    ];

    /** Values that start with spreadsheet formula characters (harmless text). */
    private const FORMULA_LIKE = [
        '=SUM(A1:A3) نص تجريبي وليس معادلة',
        '+972 رقم تجريبي غير حقيقي',
        '-15% خصم تجريبي',
        '@ملاحظة تجريبية تبدأ بعلامة',
        '=HYPERLINK("http://example.invalid","x") نص تجريبي',
    ];

    public function __construct(private readonly StressRandom $random) {}

    public function maleName(): string
    {
        return $this->random->pick(self::FIRST).' '.$this->random->pick(self::FIRST).' '.$this->random->pick(self::FAMILY);
    }

    public function femaleName(): string
    {
        return $this->random->pick(self::FEMALE).' '.$this->random->pick(self::FIRST).' '.$this->random->pick(self::FAMILY);
    }

    public function personName(): string
    {
        return $this->random->chance(0.7) ? $this->maleName() : $this->femaleName();
    }

    public function charityName(): string
    {
        return 'جمعية '.$this->random->pick(self::ORG_WORDS).' '.$this->random->pick(['الخيرية', 'للتنمية', 'الإنسانية', 'للإغاثة']);
    }

    public function companyName(): string
    {
        return $this->random->pick(self::COMPANY_KINDS).' '.$this->random->pick(self::ORG_WORDS).' '.$this->random->pick(self::COMPANY_FIELDS);
    }

    public function ministryName(): string
    {
        return 'وزارة '.$this->random->pick(['التنمية الاجتماعية', 'الصحة', 'الأوقاف', 'الزراعة', 'الحكم المحلي']).' - '.$this->random->pick(self::CITIES);
    }

    public function area(): string
    {
        return $this->random->pick(self::AREAS);
    }

    public function city(): string
    {
        return $this->random->pick(self::CITIES);
    }

    public function country(): string
    {
        return $this->random->pick(self::COUNTRIES);
    }

    /** Fake mobile in a reserved-looking range; never a real number. */
    public function phone(int $sequence): string
    {
        return '05900'.str_pad((string) ($sequence % 100000), 5, '0', STR_PAD_LEFT);
    }

    /** 9-digit synthetic national id in the 99xxxxxxx range, unique per sequence. */
    public function nationalId(int $sequence): string
    {
        return '99'.str_pad((string) $sequence, 7, '0', STR_PAD_LEFT);
    }

    /** Obviously fake IBAN-shaped string. */
    public function fakeIban(int $sequence): string
    {
        return 'PS00STRS'.str_pad((string) $sequence, 18, '0', STR_PAD_LEFT);
    }

    /**
     * A note for roughly $probability of records, otherwise null.
     */
    public function note(float $probability = 0.3): ?string
    {
        if (! $this->random->chance($probability)) {
            return null;
        }

        return match ($this->random->weighted(['short' => 50, 'long' => 15, 'multi' => 10, 'english' => 10, 'formula' => 8, 'unicode' => 7])) {
            'short' => $this->random->pick(self::SHORT_NOTES),
            'long' => $this->random->pick(self::LONG_NOTES),
            'multi' => $this->random->pick(self::SHORT_NOTES)."\n".$this->random->pick(self::SHORT_NOTES)."\n— ".$this->random->pick(self::SHORT_NOTES),
            'english' => sprintf($this->random->pick(self::ENGLISH), $this->random->int(1, 9999)),
            'formula' => $this->random->pick(self::FORMULA_LIKE),
            default => '«ملاحظة» — دفعة رقم '.$this->random->int(1, 99).' ✓ (٪'.$this->random->int(1, 20).') “تجريبي”',
        };
    }

    public function expenseDescription(string $kind): string
    {
        return match ($kind) {
            'salary' => 'راتب شهر '.$this->random->pick(['يناير', 'فبراير', 'مارس', 'أبريل', 'مايو', 'يونيو', 'يوليو', 'أغسطس', 'سبتمبر', 'أكتوبر', 'نوفمبر', 'ديسمبر']),
            'fuel' => $this->random->pick(['محروقات سيارة التوزيع', 'سولار للمولد', 'بنزين لسيارة الإدارة']),
            'maintenance' => $this->random->pick(['صيانة دورية لسيارة النقل', 'إصلاح إطارات', 'تغيير زيت وفلاتر']),
            'electricity' => $this->random->pick(['فاتورة كهرباء المقر', 'اشتراك مولد الحي', 'فاتورة طاقة المستودع']),
            'aid_medical' => $this->random->pick(['مساعدة علاج لحالة مرضية', 'شراء أدوية لمريض مزمن', 'تغطية عملية جراحية']),
            'aid_marriage' => $this->random->pick(['مساعدة زواج', 'مساهمة في تجهيز عروسين']),
            default => 'مصروف عام',
        };
    }

    public function projectName(?string $superPrefix, int $sequence): string
    {
        $area = $this->area();

        return match ($superPrefix) {
            'ORPHANS' => 'كفالة أيتام - '.$area.' - '.$sequence,
            'FOOD' => 'سلال غذائية - '.$area.' - دفعة '.$sequence,
            'WATER' => $this->random->pick(['حفر بئر مياه', 'محطة تحلية', 'توزيع مياه شرب']).' - '.$area.' - '.$sequence,
            'MID' => $this->random->pick(['توفير أدوية', 'دعم عيادة', 'مستلزمات طبية']).' - '.$area.' - '.$sequence,
            'MUWAKHA' => 'مؤاخاة - '.$area.' - '.$sequence,
            default => 'مشروع عام - '.$area.' - '.$sequence,
        };
    }
}
