<?php

namespace Database\Seeders;

use App\Models\ChartOfAccount;
use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Service;
use App\Models\User;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Services\AccountingService;
use App\Services\ReferenceService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (app()->environment('production') && ! env('ALLOW_PRODUCTION_SEED', false) && ! $this->command?->option('force')) {
            throw new \RuntimeException('Refusing to seed production without --force or ALLOW_PRODUCTION_SEED=true.');
        }

        $seedPassword = env('SEED_USER_PASSWORD');
        if (! $seedPassword) {
            throw new \RuntimeException('Set SEED_USER_PASSWORD in .env before running db:seed.');
        }

        DB::transaction(function () use ($seedPassword) {
            $this->resetData();
            $date = fn (int $daysAgo): string => now()->subDays($daysAgo)->toDateString();

            collect([
                [1, 'أحمد الكندري', 'admin@travel.kw', 'admin', 'مدير النظام', 'أ'],
                [2, 'سارة العتيبي', 'accountant@travel.kw', 'accountant', 'محاسب', 'س'],
                [3, 'فهد المطيري', 'sales@travel.kw', 'sales', 'موظف مبيعات', 'ف'],
                [4, 'منى الرشيدي', 'auditor@travel.kw', 'auditor', 'مدقق', 'م'],
            ])->each(fn ($u) => User::create(['id' => $u[0], 'name' => $u[1], 'email' => $u[2], 'password' => Hash::make($seedPassword), 'role' => $u[3], 'role_label' => $u[4], 'avatar' => $u[5]]));

            collect([
                [1, 'تذكرة طيران', '✈️', true], [2, 'تأشيرة', '📋', true], [3, 'حجز فندق', '🏨', true], [4, 'باقة سياحية', '🌍', true], [5, 'نقل وسيارات', '🚌', true],
            ])->each(fn ($s) => Service::create(['id' => $s[0], 'name' => $s[1], 'icon' => $s[2], 'active' => $s[3]]));

            collect([
                [1, 'الخطوط الجوية الكويتية', 'airline', '1884800', 'قسم المبيعات', 'الكويت'],
                [2, 'طيران الجزيرة', 'airline', '22291222', 'خدمة العملاء', 'الكويت'],
                [3, 'فندق الشيراتون الكويت', 'hotel', '22422055', 'مكتب الحجز', 'الكويت'],
                [4, 'مكتب السفارة - تأشيرات', 'visa', '25715555', 'السيد محمد', 'الكويت'],
                [5, 'شركة رحلات الخليج', 'transport', '97123456', 'أبو عبدالله', 'الكويت'],
            ])->each(fn ($v) => Vendor::create(['id' => $v[0], 'name' => $v[1], 'category' => $v[2], 'phone' => $v[3], 'contact' => $v[4], 'address' => $v[5]]));

            collect([
                [1, 'محمد سالم الصبيح', '99001122', '65001122', '280123456789', 'm.alsabeeh@gmail.com', 'كويتي', 'عميل منذ 2020'],
                [2, 'نورة خالد العنزي', '99112233', '', '290234567890', '', 'كويتية', ''],
                [3, 'عبدالله فهد الرشيد', '99223344', '65223344', '291345678901', 'a.rashid@outlook.com', 'كويتي', 'يفضل التواصل واتساب'],
                [4, 'منى عيسى البلوشي', '99334455', '', '301456789012', '', 'كويتية', ''],
                [5, 'سعود ناصر الجاسم', '99445566', '97445566', '278567890123', 'saud.j@gmail.com', 'كويتي', 'شركة - فواتير شهرية'],
                [6, 'لطيفة منصور الهاجري', '99556677', '', '305678901234', '', 'كويتية', ''],
                [7, 'تركي سعد الدوسري', '99667788', '65667788', '284789012345', 't.aldosari@gmail.com', 'كويتي', ''],
                [8, 'شركة الخليج للتجارة', '22345678', '22345679', '', 'info@gulfco.kw', 'شركة كويتية', 'اعتماد شهري'],
            ])->each(fn ($c) => Client::create(['id' => $c[0], 'name' => $c[1], 'phone' => $c[2], 'alt_phone' => $c[3] ?: null, 'civil_id' => $c[4] ?: null, 'email' => $c[5] ?: null, 'nationality' => $c[6], 'notes' => $c[7] ?: null]));

            Safe::create(['id' => 1, 'name' => 'الصندوق الرئيسي', 'type' => 'cash', 'currency' => 'KWD', 'opening_balance' => 5000]);
            Safe::create(['id' => 2, 'name' => 'البنك الأهلي الكويتي', 'type' => 'bank', 'currency' => 'KWD', 'opening_balance' => 25000]);

            collect([
                ['1100', 'ذمم العملاء', 'asset', null],
                ['2100', 'ذمم الموردين', 'liability', null],
                ['4100', 'إيرادات الخدمات', 'revenue', null],
                ['5100', 'تكلفة الخدمات', 'expense', null],
                ['1001', 'الصندوق الرئيسي', 'asset', 1],
                ['1002', 'البنك الأهلي الكويتي', 'asset', 2],
                ['9999', 'حساب عام', 'asset', null],
            ])->each(fn ($a) => ChartOfAccount::create(['code' => $a[0], 'name' => $a[1], 'type' => $a[2], 'safe_id' => $a[3]]));

            $accounting = app(AccountingService::class);
            collect([
                [1, 'OP-001', 1, 1, 1, 'KWD', 450, 320, 130, 200, 'cash', 'تذكرة القاهرة ذهاب وإياب', 'processing', 3, $date(12)],
                [2, 'OP-002', 2, 2, 4, 'KWD', 80, 50, 30, 80, 'knet', 'تأشيرة بريطانيا', 'completed', 3, $date(11)],
                [3, 'OP-003', 3, 3, 3, 'KWD', 600, 450, 150, 300, 'bank', 'فندق دبي 5 ليالي', 'processing', 3, $date(10)],
                [4, 'OP-004', 5, 4, 5, 'KWD', 1200, 900, 300, 600, 'bank', 'باقة إسطنبول عائلة', 'processing', 3, $date(9)],
                [5, 'OP-005', 4, 1, 2, 'KWD', 180, 130, 50, 0, 'cash', 'تذكرة جدة', 'new', 3, $date(8)],
                [6, 'OP-006', 6, 2, 4, 'KWD', 120, 80, 40, 120, 'cash', 'تأشيرة أمريكا', 'processing', 3, $date(7)],
                [7, 'OP-007', 7, 5, 5, 'KWD', 250, 180, 70, 0, 'cash', 'نقل مطار + جولة', 'new', 3, $date(6)],
                [8, 'OP-008', 8, 4, 1, 'KWD', 3500, 2800, 700, 2000, 'bank', 'باقة شركة - لندن 10 أفراد', 'processing', 3, $date(5)],
                [9, 'OP-009', 1, 3, 3, 'KWD', 400, 300, 100, 400, 'knet', 'فندق مسقط', 'processing', 3, $date(4)],
                [10, 'OP-010', 2, 1, 1, 'KWD', 220, 160, 60, 0, 'cash', 'تذكرة بيروت', 'cancelled', 3, $date(4)],
                [11, 'OP-011', 3, 2, 4, 'KWD', 95, 65, 30, 95, 'cash', 'تأشيرة كندا', 'processing', 3, $date(3)],
                [12, 'OP-012', 5, 1, 2, 'KWD', 350, 260, 90, 175, 'bank', 'تذكرة فرنكفورت', 'processing', 3, $date(2)],
                [13, 'OP-013', 4, 1, 2, 'KWD', 280, 200, 80, 150, 'cash', 'تذكرة دبي - اليوم', 'processing', 3, $date(0)],
            ])->each(function ($o) use ($accounting) {
                $operation = Operation::create(['id' => $o[0], 'ref' => $o[1], 'client_id' => $o[2], 'service_id' => $o[3], 'vendor_id' => $o[4], 'currency' => $o[5], 'client_price' => $o[6], 'vendor_cost' => $o[7], 'profit' => $o[8], 'initial_payment' => $o[9], 'payment_method' => $o[10], 'notes' => $o[11], 'status' => $o[12], 'created_by' => $o[13], 'op_date' => $o[14]]);
                $accounting->postOperation($operation, $operation->status === 'cancelled' ? -1 : 1);
            });

            collect([
                [1, 'RV-001', 'receipt', 'client', 1, 250, 'KWD', 'cash', 1, 1, 'تحصيل جزئي OP-001', $date(12), 2],
                [2, 'PV-001', 'payment', 'vendor', 1, 320, 'KWD', 'bank', 2, 1, 'دفع مستحقات الخطوط الكويتية', $date(12), 2],
                [3, 'RV-002', 'receipt', 'client', 2, 80, 'KWD', 'knet', 1, 2, 'تحصيل كامل OP-002', $date(11), 2],
                [4, 'PV-002', 'payment', 'vendor', 4, 50, 'KWD', 'cash', 1, 2, 'دفع مستحقات السفارة', $date(11), 2],
                [5, 'RV-003', 'receipt', 'client', 3, 300, 'KWD', 'bank', 2, 3, 'دفعة أولى OP-003', $date(10), 2],
                [6, 'RV-004', 'receipt', 'client', 5, 600, 'KWD', 'bank', 2, 4, 'دفعة أولى OP-004', $date(9), 2],
                [7, 'RV-005', 'receipt', 'client', 6, 120, 'KWD', 'cash', 1, 6, 'تحصيل كامل OP-006', $date(7), 2],
                [8, 'RV-006', 'receipt', 'client', 8, 2000, 'KWD', 'bank', 2, 8, 'دفعة أولى OP-008', $date(5), 2],
                [9, 'RV-007', 'receipt', 'client', 1, 400, 'KWD', 'knet', 1, 9, 'تحصيل كامل OP-009', $date(4), 2],
                [10, 'PV-003', 'payment', 'vendor', 3, 450, 'KWD', 'bank', 2, 3, 'دفع مستحقات الشيراتون', $date(3), 2],
            ])->each(function ($v) use ($accounting) {
                $voucher = Voucher::create(['id' => $v[0], 'ref' => $v[1], 'type' => $v[2], 'party_type' => $v[3], 'party_id' => $v[4], 'amount' => $v[5], 'currency' => $v[6], 'method' => $v[7], 'safe_id' => $v[8], 'operation_id' => $v[9], 'description' => $v[10], 'voucher_date' => $v[11], 'created_by' => $v[12]]);
                $accounting->postVoucher($voucher);
            });

            app(ReferenceService::class)->syncFromExisting();
        });
    }

    private function resetData(): void
    {
        if (! Schema::hasTable('journal_entries')) {
            return;
        }

        foreach ([JournalEntry::class, Voucher::class, Operation::class, ChartOfAccount::class, Safe::class, Client::class, Vendor::class, Service::class, User::class] as $model) {
            $model::query()->delete();
        }

        if (Schema::hasTable('reference_sequences')) {
            DB::table('reference_sequences')->delete();
        }
    }
}
