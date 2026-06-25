<?php

namespace App\Http\Controllers;

use App\Http\Requests\ExportDocumentRequest;
use App\Models\Template;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;
use PhpOffice\PhpWord\IOFactory;
use Barryvdh\DomPDF\Facade\Pdf;

class HomeController extends Controller
{
    // Ukuran default gambar & signature saat disisipkan ke Word
    private const IMAGE_WIDTH = 150;
    private const IMAGE_HEIGHT = 150;
    private const SIGNATURE_WIDTH = 120;
    private const SIGNATURE_HEIGHT = 80;

    public function index(Request $request)
    {
        $templates = Template::all();
        $selectedTemplate = null;

        if ($request->has('template_id') && $request->template_id != '') {
            $selectedTemplate = Template::with('fields')->find($request->template_id);
        }

        return view('welcome', compact('templates', 'selectedTemplate'));
    }

    // Prosedur Inti: Proses data Form dan Ekspor Dokumen
    public function export(ExportDocumentRequest $request)
    {
        // ExportDocumentRequest sudah memvalidasi:
        // - template_id wajib ada & valid
        // - setiap "fields.*" bersifat NULLABLE (boleh dikosongkan user),
        //   hanya divalidasi format/tipe-nya jika memang diisi.
        // Template juga sudah pernah dimuat oleh getTemplate() di dalam
        // request ini, jadi kita pakai ulang supaya tidak query dua kali.
        $template = $request->getTemplate() ?? Template::with('fields')->findOrFail($request->template_id);

        // Path template asli
        $templatePath = $this->resolveStoragePath($template->file_path);

        $templateProcessor = new TemplateProcessor($templatePath);
        $uploadedImages = []; // Penampung path gambar sementara untuk dihapus nanti

        // tempDocxOutput dideklarasikan di luar try supaya tetap bisa
        // diakses (untuk cleanup) walau terjadi exception sebelum di-assign.
        $tempDocxOutput = null;

        try {
            // ===================================================================
            // TAHAP 1: Isi semua field (teks, gambar, signature) ke template.
            // Setiap field dicoba dengan SEMUA variasi penulisan tag yang mungkin
            // dipakai di dokumen Word: nama_field, {nama_field}, ${nama_field},
            // serta versi dengan spasi (nama field, {nama field}, ${nama field}).
            // Ini menggabungkan jalur yang sebelumnya terpisah jadi dua loop
            // berbeda menjadi satu loop, tanpa mengurangi variasi tag yang dicoba.
            //
            // Catatan: field yang dikosongkan user (tidak diupload / string kosong)
            // tetap diproses seperti biasa — image/signature yang tidak ada filenya
            // akan dilewati (lihat pengecekan hasFile di bawah), dan teks kosong
            // akan disisipkan sebagai string kosong, sama seperti perilaku asli.
            // ===================================================================
            foreach ($template->fields as $field) {
                $fieldName = $field->field_name;
                $searchTags = $this->buildSearchTags($fieldName);

                if ($field->field_type === 'image' && $request->hasFile("fields.{$fieldName}")) {
                    $uploadedImages = array_merge(
                        $uploadedImages,
                        $this->handleImageField($templateProcessor, $request, $fieldName, $searchTags)
                    );
                } elseif ($field->field_type === 'signature' && $request->hasFile("fields.{$fieldName}")) {
                    $uploadedImages = array_merge(
                        $uploadedImages,
                        $this->handleSignatureField($templateProcessor, $request, $fieldName, $searchTags)
                    );
                } else {
                    $this->handleTextField($templateProcessor, $request, $field, $fieldName, $searchTags);
                }
            }

            // ===================================================================
            // TAHAP 2: Bersihkan kemungkinan placeholder yang "bocor" di dokumen,
            // yaitu placeholder yang isinya kebetulan sama dengan value milik field
            // lain. Perilaku ini dipertahankan persis seperti kode asli meskipun
            // tampak tidak umum, karena ada kemungkinan template tertentu
            // mengandalkannya.
            // ===================================================================
            foreach ($template->fields as $field) {
                $fieldName = $field->field_name;
                $userValue = $request->input("fields.{$fieldName}") ?? '';

                if ($field->field_type !== 'image' && $field->field_type !== 'signature') {
                    $this->trySetValue($templateProcessor, '${' . $userValue . '}', $userValue, $fieldName);
                    $this->trySetValue($templateProcessor, '{' . $userValue . '}', $userValue, $fieldName);
                }
            }

            // Simpan hasil pemrosesan .docx ke berkas sementara
            $fileName = 'Hasil_' . time();
            $tempDocxOutput = storage_path('app/' . $fileName . '.docx');
            $templateProcessor->saveAs($tempDocxOutput);

            // RESPONS BERDASARKAN FORMAT YANG DIPILIH USER
            if ($request->submit_format == 'pdf') {
                return $this->respondAsPdf($tempDocxOutput, $fileName, $uploadedImages);
            } else {
                // Unduh langsung sebagai file .docx.
                // Cleanup gambar dilakukan di sini secara eksplisit (bukan di finally)
                // karena file .docx-nya sendiri TIDAK dihapus sebelum terkirim —
                // deleteFileAfterSend(true) akan menghapusnya setelah response selesai.
                $this->cleanupImages($uploadedImages);
                return response()->download($tempDocxOutput)->deleteFileAfterSend(true);
            }
        } catch (\Throwable $e) {
            // Apa pun yang gagal di luar try-catch internal (misalnya saveAs()
            // gagal karena disk penuh, atau IOFactory::load() gagal saat
            // konversi PDF), pastikan file gambar sementara tetap dibersihkan
            // supaya tidak menumpuk sebagai sampah di storage.
            Log::error('Gagal memproses export dokumen: ' . $e->getMessage(), [
                'template_id' => $template->id,
                'exception' => $e,
            ]);

            $this->cleanupImages($uploadedImages);

            // .docx sementara mungkin sudah terbentuk sebelum error terjadi
            // (misalnya error terjadi saat konversi ke PDF) — bersihkan juga.
            if ($tempDocxOutput !== null && file_exists($tempDocxOutput)) {
                @unlink($tempDocxOutput);
            }

            throw $e;
        }
    }

    /**
     * Membangun semua variasi tag yang mungkin dipakai di dokumen Word
     * untuk satu nama field. Menggabungkan variasi yang sebelumnya
     * tersebar di dua tempat berbeda pada kode asli.
     */
    private function buildSearchTags(string $fieldName): array
    {
        $withSpace = str_replace('_', ' ', $fieldName);

        return [
            $fieldName,
            $withSpace,
            '{' . $fieldName . '}',
            '{' . $withSpace . '}',
            '${' . $fieldName . '}',
            '${' . $withSpace . '}',
        ];
    }

    /**
     * Menangani field bertipe image: bisa lebih dari satu file,
     * akan clone row tabel jika perlu, lalu sisipkan tiap gambar
     * ke semua variasi tag.
     *
     * @return array Daftar path gambar sementara yang perlu dibersihkan
     */
    private function handleImageField(
        TemplateProcessor $templateProcessor,
        Request $request,
        string $fieldName,
        array $searchTags
    ): array {
        $uploadedImages = [];

        $imageFiles = $request->file("fields.{$fieldName}");
        if (!is_array($imageFiles)) {
            $imageFiles = [$imageFiles];
        }

        $totalImages = count($imageFiles);

        // Jika user mengunggah lebih dari 1 gambar, klon baris tabelnya di Word
        // untuk setiap kemungkinan variasi tag.
        if ($totalImages > 1) {
            foreach ($searchTags as $tag) {
                try {
                    $templateProcessor->cloneRow($tag, $totalImages);
                } catch (\Exception $e) {
                    Log::debug("cloneRow gagal untuk tag '{$tag}' (field: {$fieldName}): " . $e->getMessage());
                }
            }
        }

        foreach ($imageFiles as $index => $imageFile) {
            if (!$imageFile->isValid()) {
                continue;
            }

            $tempPath = $imageFile->store('temp_images');
            $fullImgPath = $this->resolveStoragePath($tempPath);
            $uploadedImages[] = $tempPath;

            $suffix = '#' . ($index + 1);

            // Jika baris di-clone, PhpWord otomatis mengubah nama tag menjadi
            // nama_tag#1, nama_tag#2, dst. Jika tidak di-clone (hanya 1 gambar),
            // nama tag tetap nama_tag asli.
            foreach ($searchTags as $tag) {
                $targetTag = $totalImages > 1 ? $tag . $suffix : $tag;
                try {
                    $templateProcessor->setImageValue($targetTag, [
                        'path' => $fullImgPath,
                        'width' => self::IMAGE_WIDTH,
                        'height' => self::IMAGE_HEIGHT,
                        'preserveAspectRatio' => true,
                    ]);
                } catch (\Exception $e) {
                    Log::debug("setImageValue gagal untuk tag '{$targetTag}' (field: {$fieldName}): " . $e->getMessage());
                }
            }
        }

        // Bersihkan sisa tag gambar yang meleset (misalnya tag #2 tanpa gambar kedua)
        foreach ($searchTags as $tag) {
            $this->trySetValue($templateProcessor, $tag, '', $fieldName);
            $this->trySetValue($templateProcessor, $tag . '#2', '', $fieldName);
        }

        return $uploadedImages;
    }

    /**
     * Menangani field bertipe signature: satu file gambar tanda tangan,
     * disisipkan ke semua variasi tag.
     *
     * @return array Daftar path gambar sementara yang perlu dibersihkan
     */
    private function handleSignatureField(
        TemplateProcessor $templateProcessor,
        Request $request,
        string $fieldName,
        array $searchTags
    ): array {
        $uploadedImages = [];

        $sigFile = $request->file("fields.{$fieldName}");
        if (!$sigFile->isValid()) {
            return $uploadedImages;
        }

        $tempPath = $sigFile->store('temp_images');
        $fullSigPath = $this->resolveStoragePath($tempPath);
        $uploadedImages[] = $tempPath;

        foreach ($searchTags as $tag) {
            try {
                $templateProcessor->setImageValue($tag, [
                    'path' => $fullSigPath,
                    'width' => self::SIGNATURE_WIDTH,
                    'height' => self::SIGNATURE_HEIGHT,
                    'preserveAspectRatio' => true,
                ]);
            } catch (\Exception $e) {
                Log::debug("setImageValue (signature) gagal untuk tag '{$tag}' (field: {$fieldName}): " . $e->getMessage());
            }
        }

        return $uploadedImages;
    }

    /**
     * Menangani field bertipe teks (long_text & teks pendek biasa),
     * termasuk pembersihan HTML dari rich text editor (Quill), pemisahan list,
     * dan konversi baris baru ke format yang dipahami Word.
     */
    private function handleTextField(
        TemplateProcessor $templateProcessor,
        Request $request,
        $field,
        string $fieldName,
        array $searchTags
    ): void {
        $value = $request->input("fields.{$fieldName}") ?? '';

        if ($field->field_type === 'long_text') {
            // 1. Trik Regex Dinamis: Ordered List (Penomoran Angka)
            // Menggunakan regex /is agar kebal terhadap class/attribute bawaan Quill (misal: <ol class="ql-list">)
            $value = preg_replace_callback('/<ol[^>]*>(.*?)<\/ol>/is', function ($matches) {
                $items = [];
                preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $matches[1], $liMatches);
                foreach ($liMatches[1] as $index => $liText) {
                    $items[] = ($index + 1) . '. ' . strip_tags($liText) . "\n";
                }
                return implode('', $items);
            }, $value);

            // 2. Trik Regex Dinamis: Unordered List (Bullet Points)
            $value = preg_replace_callback('/<ul[^>]*>(.*?)<\/ul>/is', function ($matches) {
                $items = [];
                preg_match_all('/<li[^>]*>(.*?)<\/li>/is', $matches[1], $liMatches);
                foreach ($liMatches[1] as $liText) {
                    $items[] = '• ' . strip_tags($liText) . "\n";
                }
                return implode('', $items);
            }, $value);

            // 3. Fallback Ekstra: Jika Quill versi ini memuntahkan <li> tanpa dibungkus <ol>/<ul> sama sekali
            $value = preg_replace_callback('/<li[^>]*>(.*?)<\/li>/is', function ($matches) {
                return '• ' . strip_tags($matches[1]) . "\n";
            }, $value);

            // 4. Ubah sisa tag paragraf penutup </p> atau <br> menjadi baris baru (\n)
            $cleanText = str_replace(['</p>', '<br>', '<br/>'], ["\n", "\n", "\n"], $value);

            // 5. Buang sisa tag HTML & decode entitas (ENT_QUOTES menjaga tanda kutip tetap aman)
            $cleanText = html_entity_decode(strip_tags($cleanText), ENT_QUOTES, 'UTF-8');

            // 6. Bersihkan spasi atau enter berlebih di awal dan akhir dokumen
            $cleanText = trim($cleanText);

            foreach ($searchTags as $tag) {
                try {
                    // Suntikkan trik XML break Word langsung ke dalam string
                    $templateProcessor->setValue($tag, str_replace("\n", '</w:t><w:br/><w:t>', $cleanText));
                } catch (\Exception $e) {
                    Log::debug("setValue (long_text, XML break) gagal untuk tag '{$tag}' (field: {$fieldName}): " . $e->getMessage());
                    try {
                        $templateProcessor->setValue($tag, strip_tags($value));
                    } catch (\Exception $ex) {
                        Log::debug("setValue fallback gagal untuk tag '{$tag}' (field: {$fieldName}): " . $ex->getMessage());
                    }
                }
            }
        } else {
            // Teks pendek biasa
            foreach ($searchTags as $tag) {
                $this->trySetValue($templateProcessor, $tag, $value, $fieldName);
            }
        }
    }

    /**
     * Helper setValue dengan try-catch + logging seragam, supaya tidak ada
     * blok catch kosong yang menyembunyikan error secara diam-diam.
     */
    private function trySetValue(TemplateProcessor $templateProcessor, string $tag, string $value, string $fieldName): void
    {
        try {
            $templateProcessor->setValue($tag, $value);
        } catch (\Exception $e) {
            Log::debug("setValue gagal untuk tag '{$tag}' (field: {$fieldName}): " . $e->getMessage());
        }
    }

    /**
     * Resolve path fisik di storage, dengan fallback dari disk 'private'
     * ke disk default — sama seperti logika asli yang berulang di banyak tempat.
     */
    private function resolveStoragePath(string $relativePath): string
    {
        $privatePath = storage_path('app/private/' . $relativePath);
        if (file_exists($privatePath)) {
            return $privatePath;
        }

        return storage_path('app/' . $relativePath);
    }

    /**
     * Konversi .docx hasil ke PDF lewat Dompdf (via HTML intermediate),
     * lalu kembalikan response download dan bersihkan file sementara.
     */
    private function respondAsPdf(string $tempDocxOutput, string $fileName, array $uploadedImages)
    {
        // Konversi .docx ke HTML, lalu cetak ke PDF lewat Dompdf
        $phpWord = IOFactory::load($tempDocxOutput);
        $htmlWriter = IOFactory::createWriter($phpWord, 'HTML');

        $tempHtmlPath = storage_path('app/' . $fileName . '.html');
        $htmlWriter->save($tempHtmlPath);

        $htmlContent = file_get_contents($tempHtmlPath);
        $pdf = Pdf::loadHTML($htmlContent);

        // Bersihkan file sementara
        @unlink($tempDocxOutput);
        @unlink($tempHtmlPath);
        $this->cleanupImages($uploadedImages);

        return $pdf->download($fileName . '.pdf');
    }

    // Fungsi otomatisasi pembersihan sisa berkas gambar di server
    private function cleanupImages(array $imagePaths)
    {
        foreach ($imagePaths as $path) {
            if (Storage::exists($path)) {
                Storage::delete($path);
            }
        }
    }
}
