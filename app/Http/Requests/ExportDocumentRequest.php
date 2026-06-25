<?php

namespace App\Http\Requests;

use App\Models\Template;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Validator;

class ExportDocumentRequest extends FormRequest
{
    /**
     * Template yang sedang diproses, dimuat sekali dan dipakai ulang
     * baik oleh rules() maupun oleh controller (lewat getTemplate()).
     */
    private ?Template $template = null;

    public function authorize(): bool
    {
        // Otorisasi spesifik (ownership, dsb) belum diterapkan di sini.
        // Sesuaikan jika aplikasi nanti punya konsep kepemilikan template per-user.
        return true;
    }

    /**
     * Aturan dasar yang selalu berlaku, di luar field dinamis milik template.
     */
    public function rules(): array
    {
        $rules = [
            'template_id' => ['required', 'integer', 'exists:templates,id'],
            'submit_format' => ['nullable', 'in:pdf,docx'],
        ];

        $template = $this->getTemplate();

        if ($template === null) {
            // Kalau template_id tidak valid, biarkan rule 'exists' di atas
            // yang menangkap errornya — tidak perlu bangun rule field dinamis.
            return $rules;
        }

        foreach ($template->fields as $field) {
            $fieldName = $field->field_name;
            $key = "fields.{$fieldName}";

            // PRINSIP UTAMA: semua field bersifat opsional (boleh dikosongkan).
            // 'nullable' dipakai di setiap rule, bukan 'required', sesuai
            // permintaan bahwa user tidak wajib mengisi semua isian.
            switch ($field->field_type) {
                case 'image':
                    // Mendukung upload tunggal maupun multiple (array of files).
                    // Tidak ada batas mimes/ukuran di sini secara sengaja —
                    // akan ditangani oleh fitur kompresi foto terpisah nanti.
                    $rules[$key] = ['nullable'];
                    $rules[$key . '.*'] = ['file', 'image'];
                    break;

                case 'signature':
                    $rules[$key] = ['nullable', 'file', 'image'];
                    break;

                case 'long_text':
                    $rules[$key] = ['nullable', 'string'];
                    break;

                default:
                    // Teks pendek & tipe lain yang belum dikenal: tetap nullable,
                    // hanya dipastikan bertipe string supaya tidak ada payload aneh
                    // (misalnya array tak terduga) yang lolos ke TemplateProcessor.
                    $rules[$key] = ['nullable', 'string'];
                    break;
            }
        }

        return $rules;
    }

    public function messages(): array
    {
        return [
            'template_id.required' => 'Template wajib dipilih.',
            'template_id.exists' => 'Template yang dipilih tidak ditemukan.',
            'fields.*.image' => 'Berkas pada field :attribute harus berupa gambar yang valid.',
        ];
    }

    /**
     * Override supaya error validasi dikembalikan sebagai JSON terstruktur
     * saat request berupa AJAX/expects JSON, dan redirect-with-errors
     * seperti perilaku default Laravel untuk request form biasa.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        if ($this->expectsJson()) {
            throw new HttpResponseException(
                response()->json([
                    'message' => 'Data yang dikirim tidak valid.',
                    'errors' => $validator->errors(),
                ], 422)
            );
        }

        parent::failedValidation($validator);
    }

    /**
     * Helper agar controller tidak perlu query ulang Template setelah
     * Form Request ini selesai memvalidasi — menghindari query N+1 duplikat.
     */
    public function getTemplate(): ?Template
    {
        if ($this->template !== null) {
            return $this->template;
        }

        $templateId = $this->input('template_id');
        if (empty($templateId)) {
            return null;
        }

        $this->template = Template::with('fields')->find($templateId);

        return $this->template;
    }
}
