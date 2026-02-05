<!DOCTYPE html>
<html>
<head><title>Dashboard - Absen Pintar</title></head>
<body>
    <h2>Dashboard</h2>

    <div class="max-w-xl mx-auto py-10">
        <p>Dashboard</p>
        @if (session('success'))
            <div style="color: green;">{{ session('success') }}</div>
        @endif
    </div>
</body>
</html>
