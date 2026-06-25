<?php

namespace App\Http\Controllers;

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
    public function export(Request $request)
    {
        $template = Template::with('fields')->findOrFail($request->template_id);

        // Path template asli
        $templatePath = $this->resolveStoragePath($template->file_path);

        $templateProcessor = new TemplateProcessor($templatePath);
        $uploadedImages = []; // Penampung path gambar sementara untuk dihapus nanti

        // ===================================================================
        // TAHAP 1: Isi semua field (teks, gambar, signature) ke template.
        // Setiap field dicoba dengan SEMUA variasi penulisan tag yang mungkin
        // dipakai di dokumen Word: nama_field, {nama_field}, ${nama_field},
        // serta versi dengan spasi (nama field, {nama field}, ${nama field}).
        // Ini menggabungkan jalur yang sebelumnya terpisah jadi dua loop
        // berbeda menjadi satu loop, tanpa mengurangi variasi tag yang dicoba.
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
            // Unduh langsung sebagai file .docx
            $this->cleanupImages($uploadedImages);
            return response()->download($tempDocxOutput)->deleteFileAfterSend(true);
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
     * termasuk pembersihan HTML dari rich text editor (Quill) dan
     * konversi baris baru ke format yang dipahami Word.
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
            // 1. Ubah tag HTML penutup Quill (paragraf/list item) menjadi baris baru
            $cleanText = str_replace(['</p>', '</li>', '<br>', '<br/>'], ["\n", "\n", "\n", "\n"], $value);
            // 2. Ubah tag pembuka list menjadi simbol poin asli (•)
            $cleanText = str_replace(['<li>', '<ol>', '<ul>'], ["• ", "", ""], $cleanText);
            // 3. Bersihkan sisa tag HTML lainnya dan decode entitas teksnya
            $cleanText = html_entity_decode(strip_tags($cleanText));

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
