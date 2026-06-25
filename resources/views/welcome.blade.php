<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>TuturTulis - Otomatisasi Dokumen</title>
    @vite(['resources/css/app.css', 'resources/js/app.js'])

    <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet" />
    <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.js"></script>

    <style>
        .ql-container {
            border-bottom-left-radius: 0.75rem;
            border-bottom-right-radius: 0.75rem;
            font-family: inherit;
        }

        .ql-toolbar {
            border-top-left-radius: 0.75rem;
            border-top-right-radius: 0.75rem;
            background-color: #f9fafb;
        }

        /*
         * PENTING: Tailwind Preflight mereset semua <ol>/<ul> menjadi
         * list-style: none; margin: 0; padding: 0; secara global.
         * Tanpa override ini, numbering/bullet dari Quill TIDAK akan
         * tampak walau struktur HTML list-nya sudah benar.
         * Selector ini sengaja dibuat spesifik (.ql-editor ol/ul) agar
         * tidak mempengaruhi list lain di luar editor Quill.
         */
        .ql-editor ol,
        .ql-editor ul {
            list-style: revert;
            margin: revert;
            padding-left: 1.5em;
        }

        .ql-editor li {
            margin: revert;
            padding: revert;
        }
    </style>
</head>

<body class="bg-gray-50 min-h-screen font-sans">

    <nav class="bg-white shadow-sm border-b border-gray-200 px-6 py-4 flex justify-between items-center">
        <h1 class="text-xl font-bold text-blue-600 tracking-wide">TuturTulis</h1>
        <a href="{{ route('login') }}" class="text-sm font-medium text-gray-600 hover:text-blue-600 transition">Login
            Admin</a>
    </nav>

    <div class="max-w-3xl mx-auto px-4 py-10">
        <div class="text-center mb-8">
            <h2 class="text-3xl font-extrabold text-gray-900 tracking-tight">Otomatisasi Dokumen Anda</h2>
            <p class="text-gray-500 mt-2">Pilih template, isi formulir secara dinamis, dan ekspor hasilnya.</p>
        </div>

        <div class="bg-white p-6 rounded-2xl shadow-sm border border-gray-200 mb-6">
            <form action="{{ route('home') }}" method="GET" id="templateForm">
                <label for="template_id" class="block text-sm font-semibold text-gray-700 mb-2">Pilih Template
                    Dokumen</label>
                <select name="template_id" id="template_id" onchange="document.getElementById('templateForm').submit()"
                    class="w-full px-4 py-3 border border-gray-300 rounded-xl bg-white shadow-sm focus:outline-none focus:ring-2 focus:ring-blue-500 text-sm">
                    <option value="">-- Pilih Template Terlebih Dahulu --</option>
                    @foreach ($templates as $template)
                        <option value="{{ $template->id }}"
                            {{ request('template_id') == $template->id ? 'selected' : '' }}>
                            {{ $template->title }}
                        </option>
                    @endforeach
                </select>
            </form>
        </div>

        @if ($selectedTemplate)
            <div class="bg-white p-8 rounded-2xl shadow-sm border border-gray-200">
                <h3 class="text-lg font-bold text-gray-800 mb-6 border-b pb-3">Formulir Isian:
                    {{ $selectedTemplate->title }}</h3>

                <!--
                    Area pesan sukses & error untuk proses ekspor.
                    Disembunyikan (hidden) secara default, ditampilkan via JS
                    saat fetch() ke endpoint export selesai (baik sukses maupun gagal).
                -->
                <div id="export-success-message"
                    class="hidden mb-6 flex items-start gap-3 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
                    <span class="text-lg leading-none">✅</span>
                    <div>
                        <p class="font-semibold">Dokumen berhasil diekspor!</p>
                        <p id="export-success-detail" class="text-green-700"></p>
                    </div>
                </div>

                <div id="export-error-message"
                    class="hidden mb-6 flex items-start gap-3 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-800">
                    <span class="text-lg leading-none">⚠️</span>
                    <div>
                        <p class="font-semibold">Gagal mengekspor dokumen</p>
                        <p id="export-error-detail" class="text-red-700"></p>
                    </div>
                </div>

                <form action="{{ route('home.export') }}" method="POST" enctype="multipart/form-data"
                    id="exportForm">
                    @csrf
                    <input type="hidden" name="template_id" value="{{ $selectedTemplate->id }}">

                    <div class="space-y-6">
                        @foreach ($selectedTemplate->fields as $field)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    @php
                                        // Bersihkan karakter kurung kurawal atau dollar yang tersisa khusus untuk tampilan Label Form
                                        $cleanLabel = str_replace(
                                            ['{', '}', '$', '_'],
                                            ['', '', '', ' '],
                                            $field->field_label ?? $field->field_name,
                                        );
                                    @endphp
                                    {{ ucwords($cleanLabel) }}
                                </label>

                                @if ($field->field_type == 'text')
                                    <div class="relative flex items-center">
                                        <input type="text" name="fields[{{ $field->field_name }}]"
                                            id="input-{{ $field->field_name }}"
                                            class="w-full pl-4 pr-12 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                        <button type="button" id="btn-mic-input-{{ $field->field_name }}"
                                            onclick="toggleSpeechToText('input-{{ $field->field_name }}', 'input')"
                                            class="absolute right-3 bg-gray-100 hover:bg-gray-200 text-gray-600 p-2 rounded-xl transition">
                                            🎤
                                        </button>
                                    </div>
                                @elseif($field->field_type == 'long_text')
                                    @php
                                        /*
                                         * PENTING: $safeId sebelumnya hanya dibentuk dari nama
                                         * field yang disanitasi. Jika ada dua field long_text
                                         * dengan nama berbeda yang setelah disanitasi menghasilkan
                                         * string sama (misalnya beda kapitalisasi/spasi), ID HTML-nya
                                         * akan duplikat dan Quill akan gagal menempel dengan benar
                                         * pada instance kedua (silently, tanpa error di console).
                                         *
                                         * Menambahkan $loop->index sebagai prefix memastikan ID
                                         * SELALU unik per field, apa pun nama field aslinya.
                                         */
                                        $safeId = 'f' . $loop->index . '-' . preg_replace('/[^A-Za-z0-9\-]/', '', $field->field_name);
                                    @endphp
                                    <div class="relative mb-2">
                                        <button type="button" id="btn-mic-editor-{{ $safeId }}"
                                            onclick="toggleSpeechToText('editor-{{ $safeId }}', 'quill')"
                                            class="mb-2 inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs px-3 py-1.5 rounded-lg transition">
                                            <span id="status-mic-editor-{{ $safeId }}">🎤 Gunakan
                                                Voice-to-Text</span>
                                        </button>
                                        <div id="editor-{{ $safeId }}" class="h-48 bg-white"
                                            data-field-name="{{ $field->field_name }}"></div>
                                        <input type="hidden" name="fields[{{ $field->field_name }}]"
                                            id="hidden-editor-{{ $safeId }}">
                                    </div>
                                @elseif($field->field_type == 'date')
                                    <input type="date" name="fields[{{ $field->field_name }}]"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none">
                                @elseif($field->field_type == 'image')
                                    <input type="file" name="fields[{{ $field->field_name }}][]" accept="image/*"
                                        class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                        multiple onchange="previewImages(this, 'preview-{{ $field->field_name }}')">
                                    <p class="text-xs text-gray-400 mt-1 mb-2">Bisa pilih beberapa gambar sekaligus.</p>
                                    <div id="preview-{{ $field->field_name }}" class="flex flex-wrap gap-2 mt-2"></div>
                                @elseif($field->field_type == 'signature')
                                    <input type="file" name="fields[{{ $field->field_name }}]" accept="image/*"
                                        class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100"
                                        onchange="previewImages(this, 'preview-{{ $field->field_name }}', true)">
                                    <p class="text-xs text-gray-400 mt-1 mb-2">Hanya diperbolehkan 1 file tanda tangan.
                                    </p>
                                    <div id="preview-{{ $field->field_name }}" class="mt-2"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-8 pt-4 border-t flex gap-4">
                        <button type="submit" name="submit_format" value="docx" id="btn-export-docx"
                            class="flex-1 bg-blue-600 text-white font-semibold py-3 px-4 rounded-xl hover:bg-blue-700 transition text-center text-sm disabled:opacity-60 disabled:cursor-not-allowed">
                            <span class="btn-label">Ekspor ke DOCX</span>
                        </button>
                        <button type="submit" name="submit_format" value="pdf" id="btn-export-pdf"
                            class="flex-1 bg-gray-800 text-white font-semibold py-3 px-4 rounded-xl hover:bg-gray-900 transition text-center text-sm disabled:opacity-60 disabled:cursor-not-allowed">
                            <span class="btn-label">Ekspor ke PDF</span>
                        </button>
                    </div>
                </form>
            </div>
        @endif
    </div>

    <script>
        const quillInstances = {};
        const activeRecognitions = {};

        document.addEventListener("DOMContentLoaded", function() {
            @if ($selectedTemplate)
                @foreach ($selectedTemplate->fields as $field)
                    @if ($field->field_type == 'long_text')
                        @php
                            $safeId = 'f' . $loop->index . '-' . preg_replace('/[^A-Za-z0-9\-]/', '', $field->field_name);
                        @endphp
                        (function() {
                            const editorId = 'editor-{{ $safeId }}';
                            const editorEl = document.getElementById(editorId);

                            // Defensive guard: kalau elemen tidak ditemukan atau instance
                            // sudah pernah dibuat untuk ID ini, jangan inisialisasi ulang.
                            // Ini mencegah error/duplikasi toolbar yang bisa bikin tombol
                            // list (atau tombol lain) berhenti merespons klik.
                            if (!editorEl) {
                                console.warn('[Quill] Elemen editor tidak ditemukan:', editorId);
                                return;
                            }
                            if (quillInstances[editorId]) {
                                console.warn('[Quill] Instance sudah ada untuk:', editorId);
                                return;
                            }

                            quillInstances[editorId] = new Quill('#' + editorId, {
                                theme: 'snow',
                                modules: {
                                    toolbar: [
                                        ['bold', 'italic', 'underline'],
                                        [{ 'list': 'ordered' }, { 'list': 'bullet' }]
                                    ]
                                }
                            });
                        })();
                    @endif
                @endforeach
            @endif
        });

        function syncQuillData() {
            for (const key in quillInstances) {
                const hiddenInput = document.getElementById('hidden-' + key);
                if (hiddenInput) {
                    hiddenInput.value = quillInstances[key].root.innerHTML;
                }
            }
        }

        // ===================================================================
        // PENANGANAN SUBMIT FORM EKSPOR VIA FETCH
        //
        // Form ini tidak disubmit secara native karena dua alasan:
        // 1. Respons SUKSES berupa file (.docx/.pdf) — kita perlu tahu dengan
        //    pasti kapan file itu siap, supaya bisa tampilkan pesan sukses
        //    tanpa reload halaman.
        // 2. Respons GAGAL perlu ditampilkan sebagai pesan yang jelas di
        //    halaman yang sama, bukan halaman error bawaan Laravel.
        //
        // Strategi: fetch() dengan header Accept: application/json (agar
        // ExportDocumentRequest mengembalikan JSON terstruktur saat validasi
        // gagal), lalu cek Content-Type respons:
        // - Jika JSON -> pasti error, tampilkan message dari body JSON.
        // - Jika bukan JSON (octet-stream/pdf/docx) -> sukses, ambil sebagai
        //   Blob dan trigger download manual via elemen <a> sementara.
        // ===================================================================
        const exportForm = document.getElementById('exportForm');
        const successBox = document.getElementById('export-success-message');
        const successDetail = document.getElementById('export-success-detail');
        const errorBox = document.getElementById('export-error-message');
        const errorDetail = document.getElementById('export-error-detail');
        const exportButtons = [document.getElementById('btn-export-docx'), document.getElementById('btn-export-pdf')];

        function hideExportMessages() {
            successBox.classList.add('hidden');
            errorBox.classList.add('hidden');
        }

        function showExportSuccess(fileName) {
            hideExportMessages();
            successDetail.textContent = fileName
                ? `Berkas "${fileName}" sudah mulai diunduh oleh browser Anda.`
                : 'Berkas sudah mulai diunduh oleh browser Anda.';
            successBox.classList.remove('hidden');
            successBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function showExportError(message) {
            hideExportMessages();
            errorDetail.textContent = message || 'Terjadi kesalahan yang tidak diketahui. Coba lagi beberapa saat.';
            errorBox.classList.remove('hidden');
            errorBox.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
        }

        function setExportButtonsLoading(isLoading, activeButton) {
            exportButtons.forEach(btn => {
                if (!btn) return;
                btn.disabled = isLoading;
                const label = btn.querySelector('.btn-label');
                if (btn === activeButton) {
                    label.textContent = isLoading
                        ? 'Memproses...'
                        : (btn.value === 'pdf' ? 'Ekspor ke PDF' : 'Ekspor ke DOCX');
                }
            });
        }

        // Ekstrak nama file dari header Content-Disposition, jika ada.
        // Format umum: attachment; filename="Hasil_12345.docx"
        function extractFileNameFromHeader(contentDisposition) {
            if (!contentDisposition) return null;
            const match = contentDisposition.match(/filename="?([^";]+)"?/i);
            return match ? match[1] : null;
        }

        if (exportForm) {
            let clickedSubmitButton = null;

            // Catat tombol mana yang diklik (docx/pdf), karena fetch() tidak
            // otomatis menyertakan info "tombol submit mana yang dipakai"
            // seperti form submission native.
            exportButtons.forEach(btn => {
                if (!btn) return;
                btn.addEventListener('click', function() {
                    clickedSubmitButton = btn;
                });
            });

            exportForm.addEventListener('submit', async function(event) {
                event.preventDefault();
                hideExportMessages();
                syncQuillData();

                const formData = new FormData(exportForm);
                // Sisipkan submit_format secara manual, karena FormData dari
                // form saja tidak menyertakan name/value tombol yang diklik.
                if (clickedSubmitButton) {
                    formData.set('submit_format', clickedSubmitButton.value);
                }

                setExportButtonsLoading(true, clickedSubmitButton);

                try {
                    const response = await fetch(exportForm.action, {
                        method: 'POST',
                        headers: {
                            'Accept': 'application/json',
                            // X-Requested-With membantu Laravel mengenali ini
                            // sebagai request AJAX (memengaruhi expectsJson()).
                            'X-Requested-With': 'XMLHttpRequest',
                        },
                        body: formData,
                    });

                    const contentType = response.headers.get('Content-Type') || '';

                    // ---------------------------------------------------------
                    // KASUS RESPONS JSON: selalu berarti error pada flow ini,
                    // karena respons sukses selalu berupa file binary, bukan JSON.
                    // Mencakup error validasi (422), error proses (500), dan
                    // error tak terduga lain yang dikembalikan sebagai JSON.
                    // ---------------------------------------------------------
                    if (contentType.includes('application/json')) {
                        const data = await response.json();

                        // Error validasi (422) dari ExportDocumentRequest:
                        // tampilkan pesan validasi pertama yang ditemukan, jika ada.
                        if (data.errors) {
                            const firstField = Object.keys(data.errors)[0];
                            const firstMessage = firstField ? data.errors[firstField][0] : null;
                            showExportError(firstMessage || data.message);
                        } else {
                            // Error proses (500) dari HomeController::export(),
                            // sudah berupa pesan yang dapat dipahami user.
                            showExportError(data.message);
                        }
                        return;
                    }

                    // ---------------------------------------------------------
                    // KASUS RESPONS BUKAN JSON: response sukses berupa file.
                    // response.ok seharusnya true di sini; jika tidak (misalnya
                    // 500 tanpa Content-Type json karena sebab tak terduga),
                    // tetap tampilkan sebagai error generik.
                    // ---------------------------------------------------------
                    if (!response.ok) {
                        showExportError('Terjadi kesalahan tak terduga saat mengunduh dokumen. Coba lagi beberapa saat.');
                        return;
                    }

                    const blob = await response.blob();
                    const fileName = extractFileNameFromHeader(response.headers.get('Content-Disposition'));

                    // Trigger download manual via elemen <a> sementara,
                    // karena fetch() tidak memicu dialog "Save As" otomatis.
                    const downloadUrl = window.URL.createObjectURL(blob);
                    const link = document.createElement('a');
                    link.href = downloadUrl;
                    link.download = fileName || 'dokumen';
                    document.body.appendChild(link);
                    link.click();
                    document.body.removeChild(link);
                    window.URL.revokeObjectURL(downloadUrl);

                    showExportSuccess(fileName);
                } catch (networkError) {
                    // Gagal terhubung ke server (offline, timeout, dsb).
                    console.error('[Export] Network error:', networkError);
                    showExportError('Tidak dapat terhubung ke server. Periksa koneksi internet Anda dan coba lagi.');
                } finally {
                    setExportButtonsLoading(false, clickedSubmitButton);
                }
            });
        }

        // FUNGSI BARU: Live Preview Gambar & Tanda Tangan
        function previewImages(input, previewContainerId, isSignature = false) {
            const container = document.getElementById(previewContainerId);
            container.innerHTML = ""; // Bersihkan preview lama

            if (input.files) {
                Array.from(input.files).forEach(file => {
                    const reader = new FileReader();
                    reader.onload = function(e) {
                        const imgWrapper = document.createElement("div");
                        imgWrapper.className = isSignature ?
                            "relative w-40 h-24 border rounded-xl overflow-hidden bg-white p-1 shadow-sm" :
                            "relative w-20 h-20 border rounded-xl overflow-hidden bg-white p-1 shadow-sm";

                        const img = document.createElement("img");
                        img.src = e.target.result;
                        img.className = "w-full h-full object-contain";

                        imgWrapper.appendChild(img);
                        container.appendChild(imgWrapper);
                    }
                    reader.readAsDataURL(file);
                });
            }
        }

        // Voice to Text Toggle
        function toggleSpeechToText(targetId, type) {
            const SpeechRecognition = window.SpeechRecognition || window.webkitSpeechRecognition;
            if (!SpeechRecognition) {
                alert("Browser tidak mendukung Speech Recognition.");
                return;
            }

            if (activeRecognitions[targetId]) {
                activeRecognitions[targetId].stop();
                return;
            }

            const recognition = new SpeechRecognition();
            recognition.lang = 'id-ID';
            recognition.continuous = true;
            recognition.interimResults = false;

            const btnMic = document.getElementById(type === 'input' ? 'btn-mic-' + targetId : 'btn-mic-' + targetId);

            recognition.onstart = function() {
                activeRecognitions[targetId] = recognition;
                if (type === 'input') {
                    btnMic.className = "absolute right-3 bg-red-500 text-white p-2 rounded-xl transition";
                } else {
                    document.getElementById('status-mic-' + targetId).innerText = "🛑 Berhenti Merekam...";
                    btnMic.className =
                        "mb-2 inline-flex items-center gap-2 bg-red-500 text-white text-xs px-3 py-1.5 rounded-lg transition";
                }
            };

            recognition.onresult = function(event) {
                let currentText = "";
                for (let i = event.resultIndex; i < event.results.length; ++i) {
                    if (event.results[i].isFinal) {
                        currentText += event.results[i][0].transcript;
                    }
                }
                if (type === 'input') {
                    const inputField = document.getElementById(targetId);
                    inputField.value += (inputField.value ? " " : "") + currentText;
                } else if (type === 'quill') {
                    const editor = quillInstances[targetId];
                    const length = editor.getLength();
                    editor.insertText(length - 1, (length > 1 ? " " : "") + currentText);
                }
            };

            recognition.onend = function() {
                delete activeRecognitions[targetId];
                if (type === 'input') {
                    btnMic.className =
                        "absolute right-3 bg-gray-100 hover:bg-gray-200 text-gray-600 p-2 rounded-xl transition";
                } else {
                    document.getElementById('status-mic-' + targetId).innerText = "🎤 Gunakan Voice-to-Text";
                    btnMic.className =
                        "mb-2 inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs px-3 py-1.5 rounded-lg transition";
                }
            };

            recognition.onerror = function() {
                recognition.stop();
            };
            recognition.start();
        }
    </script>
</body>

</html>
