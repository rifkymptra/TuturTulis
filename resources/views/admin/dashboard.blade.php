<!DOCTYPE html>
<html lang="en">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard Admin - TuturTulis</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])
</head>

<body class="bg-gray-50 font-sans">

    <nav class="bg-white shadow-sm border-b border-gray-200 px-6 py-4 flex justify-between items-center">
        <h1 class="text-xl font-bold text-blue-600 tracking-wide">TuturTulis <span
                class="text-gray-500 font-normal text-sm">| Admin Panel</span></h1>
        <form action="{{ route('logout') }}" method="POST">
            @csrf
            <button type="submit"
                class="text-sm font-semibold text-red-600 hover:text-red-700 bg-red-50 hover:bg-red-100 px-4 py-2 rounded-lg transition">Logout</button>
        </form>
    </nav>

    <div class="max-w-6xl mx-auto px-4 py-8">

        @if (session('success'))
            <div class="bg-emerald-50 border border-emerald-400 text-emerald-700 px-4 py-3 rounded-xl mb-6 text-sm">
                {{ session('success') }}
            </div>
        @endif

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-8">
            <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 h-fit">
                <h2 class="text-lg font-bold text-gray-800 mb-4">Unggah Template Baru</h2>
                <form action="{{ route('admin.templates.store') }}" method="POST" enctype="multipart/form-data">
                    @csrf
                    <div class="mb-4">
                        <label class="block text-gray-700 text-sm font-medium mb-2">Nama Template</label>
                        <input type="text" name="title" placeholder="Contoh: Surat Keterangan Kerja"
                            class="w-full px-3 py-2 border border-gray-300 rounded-lg focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm"
                            required>
                    </div>
                    <div class="mb-5">
                        <label class="block text-gray-700 text-sm font-medium mb-2">File Template (.docx)</label>
                        <input type="file" name="file" accept=".docx"
                            class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-lg file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                            required>
                        <p class="text-xs text-gray-400 mt-2">Pastikan file Word berisi tag seperti ${nama_variabel}</p>
                    </div>
                    <button type="submit"
                        class="w-full bg-blue-600 text-white font-semibold py-2 px-4 rounded-lg hover:bg-blue-700 transition text-sm">Unggah
                        & Ekstrak Tag</button>
                </form>
            </div>

            <div class="lg:col-span-2 space-y-6">
                <h2 class="text-xl font-bold text-gray-800">Daftar Template Tersedia</h2>

                @if ($templates->isEmpty())
                    <div class="bg-white p-8 text-center text-gray-500 rounded-2xl border border-gray-200 shadow-sm">
                        Belum ada template yang diunggah. Silakan unggah template di menu sebelah kiri.
                    </div>
                @endif

                @foreach ($templates as $template)
                    <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200">
                        <div class="flex justify-between items-start border-b border-gray-100 pb-4 mb-4">
                            <div>
                                <h3 class="text-lg font-bold text-gray-800">{{ $template->title }}</h3>
                                <p class="text-xs text-gray-400 mt-1">File Path: <span
                                        class="font-mono bg-gray-100 px-1 py-0.5 rounded">{{ $template->file_path }}</span>
                                </p>
                            </div>
                            <form action="{{ route('admin.templates.destroy', $template->id) }}" method="POST"
                                onsubmit="return confirm('Apakah Anda yakin ingin menghapus template ini?')">
                                @csrf
                                @method('DELETE')
                                <button type="submit"
                                    class="text-xs text-red-500 hover:text-red-700 border border-red-200 bg-red-50 px-3 py-1.5 rounded-lg font-medium transition">Hapus
                                    Template</button>
                            </form>
                        </div>

                        <form action="{{ route('admin.templates.update_fields', $template->id) }}" method="POST">
                            @csrf
                            @method('PUT')
                            <h4 class="text-sm font-semibold text-gray-700 mb-3">Konfigurasi Kolom Isian Form
                                (Masing-masing Tag):</h4>
                            <div class="space-y-3">
                                @foreach ($template->fields as $field)
                                    <div
                                        class="flex flex-col sm:flex-row gap-3 items-center bg-gray-50 p-3 rounded-xl border border-gray-100">
                                        <span
                                            class="text-xs font-mono bg-blue-50 text-blue-700 px-2 py-1 rounded w-full sm:w-1/4 truncate"
                                            title="${{ $field->field_name }}">
                                            ${{ $field->field_name }}
                                        </span>
                                        <div class="w-full sm:w-2/4">
                                            <input type="text" name="fields[{{ $field->id }}][label]"
                                                value="{{ $field->field_label }}"
                                                class="w-full px-3 py-1.5 border border-gray-300 rounded-lg text-xs focus:ring-1 focus:ring-blue-500"
                                                placeholder="Label Input" required>
                                        </div>
                                        <div class="w-full sm:w-1/4">
                                            <select name="fields[{{ $field->id }}][type]"
                                                class="w-full px-3 py-1.5 border border-gray-300 rounded-lg text-xs bg-white focus:ring-1 focus:ring-blue-500">
                                                <option value="text"
                                                    {{ $field->field_type == 'text' ? 'selected' : '' }}>Teks Pendek
                                                </option>
                                                <option value="long_text"
                                                    {{ $field->field_type == 'long_text' ? 'selected' : '' }}>Teks
                                                    Panjang</option>
                                                <option value="image"
                                                    {{ $field->field_type == 'image' ? 'selected' : '' }}>Gambar
                                                </option>
                                                <option value="signature"
                                                    {{ $field->field_type == 'signature' ? 'selected' : '' }}>Tanda
                                                    Tangan</option>
                                                <option value="date"
                                                    {{ $field->field_type == 'date' ? 'selected' : '' }}>Tanggal
                                                </option>
                                            </select>
                                        </div>
                                    </div>
                                @endforeach
                            </div>
                            <div class="mt-4 flex justify-end">
                                <button type="submit"
                                    class="bg-gray-800 text-white font-medium text-xs py-2 px-4 rounded-lg hover:bg-gray-900 transition">Simpan
                                    Perubahan Tipe</button>
                            </div>
                        </form>
                    </div>
                @endforeach
            </div>
        </div>

    </div>

</body>

</html>
