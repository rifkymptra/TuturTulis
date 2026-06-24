<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Template;
use App\Models\TemplateField;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpWord\TemplateProcessor;

class TemplateController extends Controller
{
    // Tampilkan daftar template di dashboard admin
    public function index()
    {
        $templates = Template::with('fields')->latest()->get();
        return view('admin.dashboard', compact('templates'));
    }

    // Proses unggah & parsing template .docx
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255', // Sudah diperbaiki menggunakan titik dua (:)
            'file' => 'required|file|mimes:docx|max:5120', // Sudah diperbaiki menggunakan titik dua (:)
        ]);

        // 1. Simpan file dokumen ke folder storage
        $filePath = $request->file('file')->store('templates');

        // 2. Buat data Template di database
        $template = Template::create([
            'title' => $request->title,
            'file_path' => $filePath,
        ]);

        // 3. Baca file .docx untuk mencari placeholder ${...} secara aman
        $fullPath = storage_path('app/private/' . $filePath);
        if (!file_exists($fullPath)) {
            $fullPath = storage_path('app/' . $filePath);
        }

        // Trik aman: Baca langsung XML dokumen untuk membersihkan tag yang terpecah oleh Word
        $templateProcessor = new TemplateProcessor($fullPath);

        // Ambil variabel bawaan PhpWord
        $variables = $templateProcessor->getVariables();

        // JIKA variabel kosong, kita coba alternatif ekstraksi langsung dari XML murni dokumen
        if (empty($variables)) {
            $zip = new \ZipArchive();
            if ($zip->open($fullPath) === TRUE) {
                $documentXml = $zip->getFromName('word/document.xml');
                // Hilangkan tag XML di dalam struktur ${...} yang merusak pembacaan
                // Regex ini mencari teks yang rusak di antara ${ dan }
                $documentXml = preg_replace_callback('/\$\{([^\}]+)\}/', function ($matches) {
                    return '${' . strip_tags($matches[1]) . '}';
                }, $documentXml);

                // Cari semua tag setelah dibersihkan
                preg_match_all('/\$\{([^}]+)\}/', $documentXml, $matches);
                $variables = array_unique($matches[1] ?? []);
                $zip->close();
            }
        }

        // 4. Daftarkan variabel ke tabel template_fields
        foreach ($variables as $variable) {
            // Abaikan jika ada tag kosong atau aneh hasil pembersihan
            if (trim($variable) == '' || str_contains($variable, '<')) continue;

            TemplateField::create([
                'template_id' => $template->id,
                'field_name' => $variable,
                'field_label' => ucwords(str_replace('_', ' ', $variable)),
                'field_type' => 'text',
            ]);
        }

        return redirect()->route('admin.dashboard')->with('success', 'Template berhasil diunggah! Silakan sesuaikan tipe input fields di bawah jika diperlukan.');
    }

    // Perbarui tipe input (text, long_text, image, dll) dari variabel template
    public function updateFields(Request $request, $id)
    {
        $template = Template::findOrFail($id);

        foreach ($request->fields as $fieldId => $data) {
            TemplateField::where('id', $fieldId)
                ->where('template_id', $template->id)
                ->update([
                    'field_type' => $data['type'],
                    'field_label' => $data['label']
                ]);
        }

        return redirect()->route('admin.dashboard')->with('success', 'Tipe isian template berhasil diperbarui.');
    }

    // Hapus template dan file fisiknya
    public function destroy($id)
    {
        $template = Template::findOrFail($id);

        // Hapus file fisik
        if (Storage::exists($template->file_path)) {
            Storage::delete($template->file_path);
        }

        $template->delete(); // Ini otomatis menghapus fields karena cascade migration

        return redirect()->route('admin.dashboard')->with('success', 'Template berhasil dihapus.');
    }
}
