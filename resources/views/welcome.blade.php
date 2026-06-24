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

                <form action="#" method="POST" enctype="multipart/form-data" onsubmit="syncQuillData()">
                    @csrf
                    <input type="hidden" name="template_id" value="{{ $selectedTemplate->id }}">

                    <div class="space-y-6">
                        @foreach ($selectedTemplate->fields as $field)
                            <div>
                                <label class="block text-sm font-medium text-gray-700 mb-2">
                                    {{ $field->field_label ?? ucwords(str_replace('_', ' ', $field->field_name)) }}
                                </label>

                                @if ($field->field_type == 'text')
                                    <div class="relative flex items-center">
                                        <input type="text" name="fields[{{ $field->field_name }}]"
                                            id="input-{{ $field->field_name }}"
                                            class="w-full pl-4 pr-12 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                            required>
                                        <button type="button" id="btn-mic-input-{{ $field->field_name }}"
                                            onclick="toggleSpeechToText('input-{{ $field->field_name }}', 'input')"
                                            class="absolute right-3 bg-gray-100 hover:bg-gray-200 text-gray-600 p-2 rounded-xl transition">
                                            🎤
                                        </button>
                                    </div>
                                @elseif($field->field_type == 'long_text')
                                    @php
                                        // Membuat ID yang aman untuk JavaScript (tanpa spasi dan karakter aneh)
                                        $safeId = preg_replace('/[^A-Za-z0-9\-]/', '', $field->field_name);
                                    @endphp
                                    <div class="relative mb-2">
                                        <button type="button" id="btn-mic-editor-{{ $safeId }}"
                                            onclick="toggleSpeechToText('editor-{{ $safeId }}', 'quill')"
                                            class="mb-2 inline-flex items-center gap-2 bg-gray-100 hover:bg-gray-200 text-gray-700 text-xs px-3 py-1.5 rounded-lg transition">
                                            <span id="status-mic-editor-{{ $safeId }}">🎤 Gunakan
                                                Voice-to-Text</span>
                                        </button>
                                        <div id="editor-{{ $safeId }}" class="h-48 bg-white"></div>
                                        <input type="hidden" name="fields[{{ $field->field_name }}]"
                                            id="hidden-editor-{{ $safeId }}">
                                    </div>
                                @elseif($field->field_type == 'date')
                                    <input type="date" name="fields[{{ $field->field_name }}]"
                                        class="w-full px-4 py-2.5 border border-gray-300 rounded-xl text-sm focus:ring-2 focus:ring-blue-500 focus:outline-none"
                                        required>
                                @elseif($field->field_type == 'image')
                                    <input type="file" name="fields[{{ $field->field_name }}][]" accept="image/*"
                                        class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-blue-50 file:text-blue-700 hover:file:bg-blue-100"
                                        multiple required
                                        onchange="previewImages(this, 'preview-{{ $field->field_name }}')">
                                    <p class="text-xs text-gray-400 mt-1 mb-2">Bisa pilih beberapa gambar sekaligus.</p>
                                    <div id="preview-{{ $field->field_name }}" class="flex flex-wrap gap-2 mt-2"></div>
                                @elseif($field->field_type == 'signature')
                                    <input type="file" name="fields[{{ $field->field_name }}]" accept="image/*"
                                        class="w-full text-sm text-gray-500 file:mr-4 file:py-2 file:px-4 file:rounded-xl file:border-0 file:text-sm file:font-semibold file:bg-amber-50 file:text-amber-700 hover:file:bg-amber-100"
                                        required
                                        onchange="previewImages(this, 'preview-{{ $field->field_name }}', true)">
                                    <p class="text-xs text-gray-400 mt-1 mb-2">Hanya diperbolehkan 1 file tanda tangan.
                                    </p>
                                    <div id="preview-{{ $field->field_name }}" class="mt-2"></div>
                                @endif
                            </div>
                        @endforeach
                    </div>

                    <div class="mt-8 pt-4 border-t flex gap-4">
                        <button type="submit" name="format" value="docx"
                            class="flex-1 bg-blue-600 text-white font-semibold py-3 px-4 rounded-xl hover:bg-blue-700 transition text-center text-sm">
                            Ekspor ke DOCX
                        </button>
                        <button type="submit" name="format" value="pdf"
                            class="flex-1 bg-gray-800 text-white font-semibold py-3 px-4 rounded-xl hover:bg-gray-900 transition text-center text-sm">
                            Ekspor ke PDF
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
                            $safeId = preg_replace('/[^A-Za-z0-9\-]/', '', $field->field_name);
                        @endphp
                        quillInstances['editor-{{ $safeId }}'] = new Quill('#editor-{{ $safeId }}', {
                            theme: 'snow',
                            modules: {
                                toolbar: [
                                    ['bold', 'italic', 'underline'],
                                    [{
                                        'list': 'ordered'
                                    }, {
                                        'list': 'bullet'
                                    }]
                                ]
                            }
                        });
                    @endif
                @endforeach
            @endif
        });

        function syncQuillData() {
            for (const key in quillInstances) {
                document.getElementById('hidden-' + key).value = quillInstances[key].root.innerHTML;
            }
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
