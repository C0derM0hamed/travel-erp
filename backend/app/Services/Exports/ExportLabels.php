<?php

namespace App\Services\Exports;

use App\Models\Client;
use App\Models\JournalEntry;
use App\Models\Operation;
use App\Models\Safe;
use App\Models\Vendor;
use App\Models\Voucher;
use App\Support\ArabicMessages;

class ExportLabels
{
    public static function activityAction(?string $action): string
    {
        return ArabicMessages::activityAction($action);
    }

    public static function activityDetails(?string $action, ?array $payload): string
    {
        return ArabicMessages::activityDetails($action, $payload);
    }

    public static function operationStatus(?string $status): string
    {
        return match ($status) {
            'new' => 'جديدة',
            'processing' => 'قيد التنفيذ',
            'completed' => 'مكتملة',
            'cancelled' => 'ملغاة',
            default => $status ?? '—',
        };
    }

    public static function voucherType(?string $type): string
    {
        return match ($type) {
            'receipt' => 'قبض',
            'payment' => 'صرف',
            default => $type ?? '—',
        };
    }

    public static function method(?string $method): string
    {
        return match ($method) {
            'cash' => 'نقد',
            'bank' => 'تحويل بنكي',
            'knet' => 'كي-نت',
            'check', 'cheque' => 'شيك',
            default => $method ?? '—',
        };
    }

    public static function vendorCategory(?string $category): string
    {
        return match ($category) {
            'airline' => 'طيران',
            'hotel' => 'فنادق',
            'transport' => 'نقل',
            'visa' => 'تأشيرات',
            'insurance' => 'تأمين',
            'other' => 'أخرى',
            default => $category ?? '—',
        };
    }

    public static function partyName(Voucher $voucher): string
    {
        if ($voucher->party_type === 'client' && $voucher->party_id) {
            return Client::find($voucher->party_id)?->name ?? '—';
        }
        if ($voucher->party_type === 'vendor' && $voucher->party_id) {
            return Vendor::find($voucher->party_id)?->name ?? '—';
        }

        return '—';
    }

    public static function safeName(?int $safeId): string
    {
        if (! $safeId) {
            return '—';
        }

        return Safe::find($safeId)?->name ?? '—';
    }

    public static function formatAmount(float $value): string
    {
        if (abs($value) < 0.0005) {
            return '—';
        }

        return number_format($value, 3, '.', ',');
    }

    public static function journalPayload(JournalEntry $journal): array
    {
        return [
            'date' => $journal->entry_date?->toDateString() ?? '',
            'ref' => $journal->ref,
            'account' => $journal->account?->name ?? '',
            'debit' => (float) $journal->debit,
            'credit' => (float) $journal->credit,
            'desc' => $journal->description ?? '',
        ];
    }

    public static function operationDetailFields(Operation $operation): array
    {
        return [
            ['label' => 'رقم العملية', 'value' => $operation->ref],
            ['label' => 'التاريخ', 'value' => $operation->op_date?->toDateString() ?? ''],
            ['label' => 'العميل', 'value' => $operation->client?->name ?? ''],
            ['label' => 'الخدمة', 'value' => $operation->service?->name ?? ''],
            ['label' => 'المورد', 'value' => $operation->vendor?->name ?? ''],
            ['label' => 'الحالة', 'value' => self::operationStatus($operation->status)],
            ['label' => 'سعر العميل', 'value' => self::formatAmount((float) $operation->client_price)],
            ['label' => 'تكلفة المورد', 'value' => self::formatAmount((float) $operation->vendor_cost)],
            ['label' => 'الربح', 'value' => self::formatAmount((float) $operation->profit)],
            ['label' => 'ملاحظات', 'value' => $operation->notes ?? ''],
        ];
    }
}
