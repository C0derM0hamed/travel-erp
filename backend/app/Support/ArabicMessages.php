<?php

namespace App\Support;

class ArabicMessages
{
    public const FORBIDDEN = 'ليس لديك صلاحية لتنفيذ هذا الإجراء.';

    public const UNAUTHORIZED = 'يرجى تسجيل الدخول للمتابعة.';

    public const NOT_FOUND = 'العنصر المطلوب غير موجود.';

    public const SERVER_ERROR = 'حدث خطأ غير متوقع، يرجى المحاولة مرة أخرى.';

    public const VALIDATION_FAILED = 'يرجى التحقق من البيانات المدخلة.';

    public static function activityAction(?string $action): string
    {
        return match ($action) {
            'operation.created' => 'تم إنشاء عملية',
            'operation.updated' => 'تم تعديل عملية',
            'operation.hidden' => 'تم إخفاء عملية',
            'operation.restored' => 'تم استعادة عملية',
            'operation.cancelled' => 'تم إلغاء عملية',
            'operation.status_updated' => 'تم تحديث حالة عملية',
            'client.created' => 'تم إنشاء عميل',
            'client.updated' => 'تم تعديل عميل',
            'client.deleted' => 'تم حذف عميل',
            'client.hidden' => 'تم إخفاء عميل',
            'client.restored' => 'تم استعادة عميل',
            'vendor.created' => 'تم إنشاء مورد',
            'vendor.updated' => 'تم تعديل مورد',
            'vendor.deleted' => 'تم حذف مورد',
            'voucher.created' => 'تم إنشاء سند',
            'voucher.voided' => 'تم إلغاء سند',
            'safe.created' => 'تم إنشاء صندوق',
            'safe.updated' => 'تم تعديل صندوق',
            'safe.toggled' => 'تم تغيير حالة صندوق',
            'safe_transfer.created' => 'تم إنشاء تحويل بين الصناديق',
            'user.created' => 'تم إنشاء مستخدم',
            'user.updated' => 'تم تعديل مستخدم',
            'user.password_reset' => 'تم إعادة تعيين كلمة مرور',
            'office.created' => 'تم إنشاء مكتب',
            'office.updated' => 'تم تعديل مكتب',
            'office.logo_updated' => 'تم تحديث شعار المكتب',
            'office.logo_removed' => 'تم حذف شعار المكتب',
            'currency.created' => 'تم إنشاء عملة',
            'currency.updated' => 'تم تعديل عملة',
            'currency.activated' => 'تم تفعيل عملة',
            'currency.deactivated' => 'تم تعطيل عملة',
            'currency.default_set' => 'تم تعيين العملة الافتراضية',
            'service.toggled' => 'تم تغيير حالة خدمة',
            default => self::humanizeActionKey($action),
        };
    }

    /** @param array<string, mixed>|null $payload */
    public static function activityDetails(?string $action, ?array $payload): string
    {
        if (! is_array($payload) || $payload === []) {
            return '—';
        }

        $parts = [];

        if (! empty($payload['ref'])) {
            $parts[] = 'المرجع: '.$payload['ref'];
        }
        if (! empty($payload['name'])) {
            $parts[] = 'الاسم: '.$payload['name'];
        }
        if (! empty($payload['email'])) {
            $parts[] = 'البريد: '.$payload['email'];
        }
        if (! empty($payload['office_code'])) {
            $parts[] = 'رمز المكتب: '.$payload['office_code'];
        }
        if (isset($payload['type']) && is_string($payload['type'])) {
            $parts[] = 'النوع: '.self::translateToken($payload['type'], [
                'receipt' => 'قبض',
                'payment' => 'صرف',
                'cash' => 'صندوق نقدي',
                'bank' => 'حساب بنكي',
            ]);
        }
        if (isset($payload['status']) && is_string($payload['status'])) {
            $parts[] = 'الحالة: '.self::translateToken($payload['status'], [
                'new' => 'جديدة',
                'processing' => 'قيد التنفيذ',
                'completed' => 'مكتملة',
                'cancelled' => 'ملغاة',
            ]);
        }
        if (array_key_exists('active', $payload)) {
            $parts[] = $payload['active'] ? 'الحالة: مفعّل' : 'الحالة: معطّل';
        }
        if (array_key_exists('is_active', $payload)) {
            $parts[] = $payload['is_active'] ? 'الحالة: مفعّل' : 'الحالة: معطّل';
        }

        $skip = ['ref', 'name', 'email', 'office_code', 'type', 'status', 'active', 'is_active', 'user_name', 'office_name', 'entity'];
        foreach ($payload as $key => $value) {
            if (in_array($key, $skip, true) || $value === null || $value === '') {
                continue;
            }
            if (is_scalar($value)) {
                $parts[] = self::translateFieldKey($key).': '.$value;
            }
        }

        return $parts !== [] ? implode(' · ', $parts) : '—';
    }

    /** @return array<string, string> */
    public static function validationAttributes(): array
    {
        return [
            'client_id' => 'العميل',
            'vendor_id' => 'المورد',
            'service_id' => 'الخدمة',
            'client_price' => 'سعر العميل',
            'vendor_cost' => 'تكلفة المورد',
            'initial_payment' => 'الدفعة الأولى',
            'payment_method' => 'طريقة الدفع',
            'operation_id' => 'العملية',
            'party_id' => 'الطرف',
            'party_type' => 'نوع الطرف',
            'safe_id' => 'الصندوق',
            'from_safe_id' => 'صندوق المصدر',
            'to_safe_id' => 'صندوق الوجهة',
            'amount' => 'المبلغ',
            'type' => 'النوع',
            'name' => 'الاسم',
            'phone' => 'الهاتف',
            'email' => 'البريد الإلكتروني',
            'password' => 'كلمة المرور',
            'date' => 'التاريخ',
            'notes' => 'الملاحظات',
            'status' => 'الحالة',
            'office_code' => 'رمز المكتب',
            'office_name' => 'اسم المكتب',
            'transfer_date' => 'تاريخ التحويل',
            'opening_balance' => 'الرصيد الافتتاحي',
        ];
    }

    /** @return array<string, string> */
    public static function scopedExistsMessages(): array
    {
        return [
            'client_id.exists' => 'العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
            'vendor_id.exists' => 'المورد المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
            'operation_id.exists' => 'العملية المحددة غير موجودة أو لا تنتمي إلى المكتب الحالي.',
            'safe_id.exists' => 'الصندوق المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
            'from_safe_id.exists' => 'صندوق المصدر غير موجود أو لا ينتمي إلى المكتب الحالي.',
            'to_safe_id.exists' => 'صندوق الوجهة غير موجود أو لا ينتمي إلى المكتب الحالي.',
        ];
    }

    /** @return array<string, string> */
    public static function commonValidationMessages(): array
    {
        return array_merge(self::scopedExistsMessages(), [
            'required' => 'حقل :attribute مطلوب.',
            'numeric' => 'حقل :attribute يجب أن يكون رقماً.',
            'integer' => 'حقل :attribute يجب أن يكون رقماً صحيحاً.',
            'email' => 'يرجى إدخال بريد إلكتروني صحيح.',
            'date' => 'يرجى إدخال تاريخ صحيح.',
            'boolean' => 'قيمة :attribute غير صالحة.',
            'max.string' => 'حقل :attribute طويل جداً.',
            'min.numeric' => 'قيمة :attribute أقل من الحد المسموح.',
            'max.numeric' => 'قيمة :attribute أكبر من الحد المسموح.',
            'gt.numeric' => 'قيمة :attribute يجب أن تكون أكبر من صفر.',
            'gte.numeric' => 'قيمة :attribute لا يمكن أن تكون سالبة.',
            'lte.numeric' => 'قيمة :attribute تتجاوز الحد المسموح.',
            'decimal' => 'يرجى إدخال مبلغ بحد أقصى 3 خانات عشرية.',
            'in' => 'القيمة المحددة في :attribute غير مسموحة.',
            'different' => 'يجب أن يختلف :attribute عن الحقل المرتبط.',
            'unique' => 'قيمة :attribute مسجلة مسبقاً.',
        ]);
    }

    /** @return array<string, string> */
    public static function operationMessages(): array
    {
        return array_merge(self::commonValidationMessages(), [
            'client_id.required' => 'يرجى اختيار العميل.',
            'vendor_id.required' => 'يرجى اختيار المورد.',
            'service_id.required' => 'يرجى اختيار الخدمة.',
            'service_id.exists' => 'الخدمة غير موجودة أو غير مفعّلة.',
            'client_price.required' => 'يرجى إدخال سعر العميل.',
            'vendor_cost.required' => 'يرجى إدخال تكلفة المورد.',
            'initial_payment.lte' => 'الدفعة الأولى لا يمكن أن تتجاوز سعر العميل.',
            'vendor_cost.lte' => 'تكلفة المورد لا يمكن أن تتجاوز سعر العميل.',
            'date.before_or_equal' => 'تاريخ العملية لا يمكن أن يكون في المستقبل.',
            'currency.exists' => 'العملة المحددة غير مفعلة أو غير موجودة.',
            'client_price.min' => 'الحد الأدنى لسعر العميل 1.',
            'client_price.max' => 'الحد الأقصى للمبلغ 99,999.999.',
            'vendor_cost.max' => 'الحد الأقصى للتكلفة 99,999.999.',
            'initial_payment.gte' => 'الدفعة الأولى لا يمكن أن تكون سالبة.',
            'payment_method.in' => 'طريقة الدفع المحددة غير مسموحة.',
        ]);
    }

    public static function translateApiMessage(?string $message): string
    {
        if (! $message) {
            return self::VALIDATION_FAILED;
        }

        return match (strtolower(trim($message))) {
            'forbidden', 'this action is unauthorized.' => self::FORBIDDEN,
            'unauthorized', 'unauthenticated.' => self::UNAUTHORIZED,
            'not found', 'not found.' => self::NOT_FOUND,
            'server error', 'internal server error' => self::SERVER_ERROR,
            'the selected client id is invalid.' => 'العميل المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
            'the selected vendor id is invalid.' => 'المورد المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
            'the selected service id is invalid.' => 'الخدمة غير موجودة أو غير مفعّلة.',
            'the selected operation id is invalid.' => 'العملية المحددة غير موجودة أو لا تنتمي إلى المكتب الحالي.',
            'the selected safe id is invalid.' => 'الصندوق المحدد غير موجود أو لا ينتمي إلى المكتب الحالي.',
            'office context is required.' => 'لم يتم تحديد المكتب الحالي. يرجى إعادة تسجيل الدخول.',
            'validation failed.' => 'يرجى التحقق من البيانات المدخلة.',
            'logged out' => 'تم تسجيل الخروج بنجاح.',
            default => self::translateGenericValidationMessage($message),
        };
    }

    private static function translateGenericValidationMessage(string $message): string
    {
        $normalized = strtolower(trim($message));

        if (preg_match('/^the selected .+ is invalid\.?$/', $normalized)) {
            return 'القيمة المحددة غير صالحة أو لا تنتمي إلى المكتب الحالي.';
        }

        if (preg_match('/^the .+ field is required\.?$/', $normalized)) {
            return 'يرجى تعبئة جميع الحقول المطلوبة.';
        }

        return $message;
    }

    private static function humanizeActionKey(?string $action): string
    {
        if (! $action) {
            return '—';
        }

        return str_replace(['.', '_'], ' ', $action);
    }

    /** @param array<string, string> $map */
    private static function translateToken(string $value, array $map): string
    {
        return $map[$value] ?? $value;
    }

    private static function translateFieldKey(string $key): string
    {
        return self::validationAttributes()[$key] ?? str_replace('_', ' ', $key);
    }
}
