<?php

namespace App\Http\Controllers;

use App\Models\Template;
use Illuminate\Http\Request;

class HomeController extends Controller
{
    // Halaman utama: menampilkan pilihan template
    public function index(Request $request)
    {
        $templates = Template::all();
        $selectedTemplate = null;

        // Jika pengguna sudah memilih template dari dropdown
        if ($request->has('template_id') && $request->template_id != '') {
            $selectedTemplate = Template::with('fields')->find($request->template_id);
        }

        return view('welcome', compact('templates', 'selectedTemplate'));
    }
}
