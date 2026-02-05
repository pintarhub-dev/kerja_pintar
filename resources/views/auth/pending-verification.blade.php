<!DOCTYPE html>
<html lang="id">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Verifikasi Email - Absen Pintar</title>
    <script src="https://cdn.tailwindcss.com"></script>
</head>
<body class="bg-gray-100 h-screen flex items-center justify-center">
    <div class="bg-white p-8 rounded-lg shadow-lg max-w-md text-center">
        <div class="mb-4 text-green-500 mx-auto">
            <svg xmlns="http://www.w3.org/2000/svg" class="h-16 w-16 mx-auto" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 8l7.89 5.26a2 2 0 002.22 0L21 8M5 19h14a2 2 0 002-2V7a2 2 0 00-2-2H5a2 2 0 00-2 2v10a2 2 0 002 2z" />
            </svg>
        </div>
        <h2 class="text-2xl font-bold mb-2">Registrasi Berhasil!</h2>
        <p class="text-gray-600 mb-6">
            Akun Anda saat ini berstatus <b>Tidak Aktif</b>. <br>
            Silakan cek email Anda (inbox/spam) untuk memverifikasi akun sebelum melanjutkan pembuatan perusahaan.
        </p>
        <a href="{{ route('filament.admin.auth.login') }}" class="text-indigo-600 hover:text-indigo-800 font-semibold">
            Kembali ke Login
        </a>
    </div>
</body>
</html>
