# SAW Guru — prototype penelitian

Aplikasi lokal untuk mendukung evaluasi guru SD berbasis kriteria dan metode Simple Additive Weighting (SAW). Hasilnya adalah rekomendasi berdasarkan parameter penelitian, bukan keputusan mutlak tentang kualitas seorang guru.

## Prasyarat

- PHP 8.2 atau lebih baru.
- Ekstensi PHP: `pdo_sqlite`, `sqlite3`, `zip`, `xmlreader`, `simplexml`, dan `xmlwriter`.
- Workbook `.xlsx` dengan kolom `kd_guru_an`, `ACH`, `AKC`, `PBR`, `PGP`, dan `ITB`.

## Menyiapkan dataset dan menjalankan

Dataset sumber tidak diedit. Untuk memuat workbook yang disediakan pada komputer lokal:

```powershell
New-Item -ItemType Directory -Force storage\app\datasets
Copy-Item C:\Users\HP\Downloads\kandidat_saw_evaluasi_guru_sdmi.xlsx storage\app\datasets\kandidat_saw_evaluasi_guru_sdmi.xlsx
php -d memory_limit=512M bin\import-dataset.php
php -d upload_max_filesize=50M -d post_max_size=52M -S 127.0.0.1:8080 router.php
```

Buka `http://127.0.0.1:8080`. Di Laragon, document root Apache dapat diarahkan ke folder `public`. Impor workbook lain juga tersedia dari tombol **Impor dataset**. Database SQLite dan salinan workbook tersimpan lokal di `storage/app` dan dikecualikan dari kontrol versi.

## Metode dan reproducibility

- Dataset diimpor dengan streaming dari worksheet XLSX; kolom, nilai numerik, ID, duplikasi, dan rentang 0–100 divalidasi. Satu baris dengan ID kosong, ID duplikat, tipe nonnumerik, atau nilai di luar rentang akan menggagalkan impor dan membatalkan transaksi.
- Nilai hilang disimpan sebagai `NULL`. Complete Case mengecualikan alternatif tidak lengkap hanya dari perhitungan/ranking; Median Imputation menggunakan median setiap kolom dari nilai tersedia. Dataset sumber tetap tersimpan tanpa perubahan.
- Bobot awal 20% per kriteria. Skenario sensitivitas menaikkan satu bobot (parameter awal 40%, dapat diatur) dan membagi sisa bobot secara merata ke empat kriteria lainnya. Bobot aktif wajib berjumlah 1.
- Semua kriteria awalnya Benefit. Cost memakai `min(x)/x` dan ditolak saat populasi analisis memiliki nilai nol karena rumus tersebut tidak terdefinisi untuk nol.
- Skor sama diurutkan berdasarkan `kd_guru_an` secara biner. SHA-256 dataset, waktu pemrosesan UTC, sheet, tipe kriteria, bobot, dan strategi missing values dicantumkan pada laporan.
- Kendall Tau dihitung atas seluruh pasangan alternatif dengan inversi peringkat; Spearman, rank displacement, dan overlap Top 5/10/20 dibandingkan terhadap baseline.
- Ekspor laporan Excel berisi parameter, dataset cleaning, matriks keputusan, matriks normalisasi, bobot, skor/ranking, sensitivitas, dan missing values. Ekspor PDF menggunakan fungsi cetak browser.

## Performa lokal

- Pagination membaca ranking berdasarkan indeks skenario/rank tanpa menggabungkan view ranking untuk setiap baris.
- Statistik normalisasi dihitung sekali dan digunakan bersama oleh semua skenario; median imputasi dan metrik perbandingan strategi dicache berdasarkan hash dataset dan parameter.
- Router PHP bawaan mengompresi HTML/CSS/JavaScript yang didukung gzip serta menyimpan aset statis di cache browser selama 5 menit.

## Codebook

Workbook yang tersedia hanya berisi sheet `Matriks_Kandidat`; codebook definisi indikator tidak tersedia. Antarmuka karenanya menampilkan “Data tidak tersedia — codebook indikator tidak disertakan” dan tidak menafsirkan definisi ACH, AKC, PBR, PGP, atau ITB.

## Pengujian

```powershell
php tests\run.php
php -l public\api.php
```
