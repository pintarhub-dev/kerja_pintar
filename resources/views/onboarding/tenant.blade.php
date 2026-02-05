<!DOCTYPE html>
<html lang="id">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup Perusahaan - Absen Pintar</title>
    <style>
        body {
            font-family: sans-serif;
            background-color: #f3f4f6;
            display: flex;
            justify-content: center;
            align-items: center;
            height: 100vh;
            margin: 0;
        }

        .card {
            background: white;
            padding: 2rem;
            border-radius: 10px;
            box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
            width: 100%;
            max-width: 400px;
        }

        h1 {
            font-size: 1.5rem;
            margin-bottom: 0.5rem;
            color: #111827;
            text-align: center;
        }

        p {
            color: #6b7280;
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 0.9rem;
        }

        .form-group {
            margin-bottom: 1rem;
        }

        label {
            display: block;
            font-size: 0.875rem;
            font-weight: 500;
            color: #374151;
            margin-bottom: 0.5rem;
        }

        input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            box-sizing: border-box;
        }

        textarea {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #d1d5db;
            border-radius: 0.375rem;
            box-sizing: border-box;
        }

        button {
            width: 100%;
            background-color: #2563eb;
            color: white;
            padding: 0.75rem;
            border: none;
            border-radius: 0.375rem;
            font-weight: bold;
            cursor: pointer;
            transition: background 0.2s;
        }

        button:hover {
            background-color: #1d4ed8;
        }

        .error {
            color: #ef4444;
            font-size: 0.875rem;
            margin-top: 0.25rem;
        }
    </style>
</head>

<body>

    <div class="card">
        <h1>🚀 Mulai Perjalanan Anda</h1>
        <p>Beri nama ruang kerja untuk tim Anda.</p>

        <form action="{{ route('onboarding.tenant.store') }}" method="POST">
            @csrf

            <div class="form-group">
                <label for="code">Kode / Singkatan</label>
                <input type="text" id="code" name="code" placeholder="Contoh: KP" value="{{ old('code') }}">

                @error('code')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

             <div class="form-group">
                <label for="name">Nama Anda</label>
                <input type="text" id="full_name" name="full_name" placeholder="Contoh: Abdullah Al Jufri" value="{{ old('full_name') }}">

                @error('full_name')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="name">Nama Perusahaan / Organisasi</label>
                <input type="text" id="name" name="name" placeholder="Contoh: PT Absen Pintar" value="{{ old('name') }}">

                @error('name')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="phone">Nomor HP / WA</label>
                <input type="text" id="phone" name="phone" placeholder="Contoh: 085692726611" value="{{ old('phone') }}">

                @error('phone')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <div class="form-group">
                <label for="address">Alamat Perusahaan / Organisasi</label>
                <textarea id="address" name="address" placeholder="Contoh: Jl. Budaya 12 Blok TY">{{ old('address') }}</textarea>

                @error('address')
                    <div class="error">{{ $message }}</div>
                @enderror
            </div>

            <button type="submit">Buat Perusahaan & Masuk</button>
        </form>
    </div>

</body>

</html>
