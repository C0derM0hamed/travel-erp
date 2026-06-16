<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\HasArabicValidation;
use App\Http\Requests\Concerns\ValidatesOfficeScope;
use App\Models\Client;
use App\Support\PhoneNormalizer;
use Illuminate\Foundation\Http\FormRequest;

class UpdateClientRequest extends FormRequest
{
    use HasArabicValidation, ValidatesOfficeScope;

    public function authorize(): bool
    {
        $client = $this->route('client');

        return $client instanceof Client && $this->user()?->can('update', $client);
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('phone')) {
            $this->merge(['phone' => PhoneNormalizer::normalize($this->input('phone'))]);
        }
        if ($this->has('alt_phone')) {
            $this->merge([
                'alt_phone' => $this->filled('alt_phone') ? PhoneNormalizer::normalize($this->input('alt_phone')) : null,
            ]);
        }
        if ($this->has('civil_id')) {
            $civilId = trim((string) $this->input('civil_id', ''));
            $this->merge(['civil_id' => $civilId === '' ? null : $civilId]);
        }
    }

    public function rules(): array
    {
        /** @var Client $client */
        $client = $this->route('client');

        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'phone' => ['sometimes', 'required', 'string', 'max:50', $this->scopedUnique('clients', 'phone', $client->id)],
            'alt_phone' => ['nullable', 'string', 'max:50'],
            'civil_id' => ['nullable', 'string', 'max:50', $this->scopedUnique('clients', 'civil_id', $client->id)],
            'email' => ['nullable', 'email', 'max:255'],
            'nationality' => ['nullable', 'string', 'max:100'],
            'notes' => ['nullable', 'string', 'max:2000'],
            'opening_balance_amount' => ['nullable', 'numeric', 'decimal:0,3', 'gte:0', 'max:9999999.999'],
            'opening_balance_currency' => ['nullable', \Illuminate\Validation\Rule::exists('currencies', 'code')->where('is_active', true)],
            'opening_balance_type' => ['nullable', \Illuminate\Validation\Rule::in(['receivable', 'payable'])],
        ];
    }

    public function messages(): array
    {
        return $this->arabicMessages([
            'name.required' => 'اسم العميل مطلوب.',
            'phone.required' => 'رقم هاتف العميل مطلوب.',
            'phone.unique' => 'رقم الهاتف مسجل مسبقاً لعميل آخر.',
            'civil_id.unique' => 'الرقم المدني مسجل مسبقاً لعميل آخر.',
        ]);
    }
}
