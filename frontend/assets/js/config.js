/**
 * Konfigurasi URL Backend API untuk deployment terpisah
 * (mis. Frontend di Vercel + Backend PHP di Render / Railway / cPanel / VPS).
 *
 * - Biarkan "" (kosong) jika Frontend & Backend berjalan pada domain yang sama
 *   (XAMPP lokal, Docker All-in-One, cPanel satu domain, atau proxy rewrite vercel.json).
 * - Isi URL lengkap folder backend/api (tanpa "/" di akhir) jika Frontend di Vercel
 *   memanggil Backend PHP lintas domain, contoh:
 *   "https://penilaian360-api.onrender.com/backend/api"
 */
window.APP_CONFIG = window.APP_CONFIG || {
    BACKEND_API_URL: ""
};

