<!doctype html>
<html lang="id">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="theme-color" content="#102a43">
    <title>SAW Guru · Evaluasi berbasis kriteria</title>
    <link rel="stylesheet" href="/assets/styles.css">
</head>
<body>
    <div class="app-shell">
        <aside class="sidebar" id="sidebar">
            <a class="brand" href="#" data-page="dashboard">
                <span class="brand-mark">S</span>
                <span><strong>SAW Guru</strong><small>Prototype penelitian</small></span>
            </a>
            <div class="sidebar-caption">WORKSPACE</div>
            <nav class="navigation" aria-label="Navigasi utama">
                <button class="nav-item active" data-page="dashboard"><span>⌂</span>Dashboard</button>
                <button class="nav-item" data-page="teachers"><span>▤</span>Data Guru</button>
                <button class="nav-item" data-page="criteria"><span>◎</span>Kriteria</button>
                <button class="nav-item" data-page="weights"><span>◉</span>Bobot Kriteria</button>
                <button class="nav-item" data-page="calculation"><span>∑</span>Perhitungan SAW</button>
                <button class="nav-item" data-page="ranking"><span>☷</span>Ranking Guru</button>
                <button class="nav-item" data-page="sensitivity"><span>↗</span>Analisis Sensitivitas</button>
                <button class="nav-item" data-page="quality"><span>◌</span>Missing Data</button>
                <button class="nav-item" data-page="detail"><span>◉</span>Detail Guru</button>
                <button class="nav-item" data-page="reports"><span>▧</span>Laporan</button>
                <button class="nav-item" data-page="settings"><span>⚙</span>Pengaturan</button>
            </nav>
            <div class="sidebar-note">
                <span class="status-dot"></span>
                <div><strong>Hasil berbasis kriteria</strong><small>Bukan keputusan mutlak tentang kualitas guru.</small></div>
            </div>
            <div class="sidebar-footer">SAW · Analisis yang transparan</div>
        </aside>

        <main class="main-area">
            <header class="topbar">
                <button class="mobile-menu" id="mobile-menu" aria-label="Buka navigasi">☰</button>
                <div class="breadcrumb"><span>Penelitian</span><b>/</b><strong id="breadcrumb-title">Dashboard</strong></div>
                <div class="topbar-actions">
                    <span class="dataset-status" id="dataset-status">Memeriksa dataset…</span>
                    <button class="button button-secondary button-small" data-action="import">Impor dataset</button>
                    <input id="dataset-file" type="file" accept=".xlsx,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet" hidden>
                </div>
            </header>

            <div class="content" id="content">
                <section class="page active" id="page-dashboard" data-title="Dashboard">
                    <div class="page-heading">
                        <div><div class="eyebrow">SISTEM PENUNJANG KEPUTUSAN · 2025</div><h1>Evaluasi Guru SD dengan SAW</h1>
                            <p>Ringkasan rekomendasi berbasis kriteria, bobot aktif, dan strategi data yang dipilih.</p></div>
                        <label class="inline-control">Skenario
                            <select class="scenario-select" aria-label="Skenario dashboard"></select>
                        </label>
                    </div>
                    <div class="callout callout-info" id="dashboard-notice" hidden></div>
                    <div class="metric-grid dashboard-metrics">
                        <article class="metric-card"><span>Total guru</span><strong id="metric-total">—</strong><small>Alternatif pada dataset</small></article>
                        <article class="metric-card"><span>Data lengkap</span><strong id="metric-complete">—</strong><small id="metric-complete-pct">Matriks 5 kriteria</small></article>
                        <article class="metric-card"><span>Dengan missing values</span><strong id="metric-missing">—</strong><small>Nilai NULL tidak diubah</small></article>
                        <article class="metric-card"><span>Kriteria</span><strong id="metric-criteria">—</strong><small>ACH · AKC · PBR · PGP · ITB</small></article>
                        <article class="metric-card metric-weights"><span>Bobot aktif</span><div id="dashboard-weights"></div><small>Total bobot 100%</small></article>
                    </div>
                    <div class="dashboard-grid">
                        <article class="panel winner-panel">
                            <div class="panel-heading"><div><div class="eyebrow">PERINGKAT 1</div><h2>Prioritas evaluasi tinggi</h2></div><span class="tag tag-accent" id="winner-scenario">Baseline</span></div>
                            <div class="winner-content" id="winner-content"><span class="empty-state">Data tidak tersedia</span></div>
                            <p class="fine-print">Peringkat merupakan rekomendasi sesuai kriteria dan bobot yang dipilih; bukan penilaian mutlak.</p>
                        </article>
                        <article class="panel">
                            <div class="panel-heading"><div><div class="eyebrow">SEBARAN ALTERNATIF</div><h2>Distribusi skor SAW</h2></div></div>
                            <div class="chart-wrap"><canvas id="score-chart" aria-label="Distribusi skor SAW"></canvas></div>
                        </article>
                    </div>
                    <article class="panel top-ten-panel">
                        <div class="panel-heading"><div><div class="eyebrow">HASIL EVALUASI BERBASIS KRITERIA</div><h2>Top 10 guru</h2></div>
                            <button class="button button-quiet" data-page="ranking">Lihat ranking <span>→</span></button></div>
                        <div class="table-scroll"><table><thead><tr><th>Rank</th><th>ID guru</th><th>Skor SAW</th><th>ACH</th><th>AKC</th><th>PBR</th><th>PGP</th><th>ITB</th></tr></thead><tbody id="top-ten-body"></tbody></table></div>
                    </article>
                    <div class="dashboard-grid dashboard-grid-bottom">
                        <article class="panel">
                            <div class="panel-heading"><div><div class="eyebrow">PERUBAHAN PEMERINGKATAN</div><h2>Dampak perubahan bobot</h2></div></div>
                            <div class="chart-wrap chart-wrap-short"><canvas id="change-chart" aria-label="Perubahan ranking akibat perubahan bobot"></canvas></div>
                        </article>
                        <article class="panel">
                            <div class="panel-heading"><div><div class="eyebrow">ANALISIS SENSITIVITAS</div><h2>Sensitivitas setiap kriteria</h2></div></div>
                            <div class="chart-wrap chart-wrap-short"><canvas id="sensitivity-chart" aria-label="Sensitivitas kriteria"></canvas></div>
                        </article>
                    </div>
                </section>

                <section class="page" id="page-teachers" data-title="Data Guru">
                    <div class="page-heading"><div><div class="eyebrow">ALTERNATIF KEPUTUSAN</div><h1>Data Guru</h1><p>Telusuri dan bandingkan hasil SAW. Pilih ID untuk membuka rincian perhitungan.</p></div>
                        <div class="button-group"><button class="button button-secondary" data-action="export-teachers">Ekspor Excel</button><button class="button button-secondary" data-action="print">Ekspor PDF</button></div></div>
                    <p class="fine-print">Ekspor PDF menggunakan dialog cetak browser untuk halaman tabel yang sedang ditampilkan.</p>
                    <article class="panel">
                        <div class="filter-bar">
                            <input class="text-input" id="teacher-search" placeholder="Cari ID guru…" type="search">
                            <select class="text-input" id="teacher-completeness"><option value="">Semua data</option><option value="yes">Data lengkap</option><option value="no">Ada missing</option></select>
                            <select class="scenario-select text-input" aria-label="Skenario ranking data guru"></select>
                            <select class="text-input" id="teacher-sort"><option value="rank">Urutkan: ranking</option><option value="score">Urutkan: skor SAW</option><option value="id">Urutkan: ID guru</option><option value="ACH">Urutkan: ACH</option><option value="AKC">Urutkan: AKC</option><option value="PBR">Urutkan: PBR</option><option value="PGP">Urutkan: PGP</option><option value="ITB">Urutkan: ITB</option></select>
                            <select class="text-input" id="teacher-direction"><option value="asc">Naik</option><option value="desc">Turun</option></select>
                        </div>
                        <div class="table-scroll"><table><thead><tr><th>Rank</th><th>ID guru</th><th>ACH</th><th>AKC</th><th>PBR</th><th>PGP</th><th>ITB</th><th>Skor SAW</th></tr></thead><tbody id="teacher-table-body"></tbody></table></div>
                        <div class="table-footer"><span id="teacher-count">Belum ada data</span><div class="pagination"><button class="button button-quiet" id="previous-page">Sebelumnya</button><span id="page-label">—</span><button class="button button-quiet" id="next-page">Berikutnya</button></div></div>
                    </article>
                </section>

                <section class="page" id="page-criteria" data-title="Kriteria">
                    <div class="page-heading"><div><div class="eyebrow">INDIKATOR EVALUASI</div><h1>Kriteria</h1><p>Definisi hanya ditampilkan bila tersedia di codebook. Tidak ada deskripsi yang dibuat berdasarkan asumsi.</p></div></div>
                    <article class="panel"><div class="table-scroll"><table><thead><tr><th>Kode</th><th>Indikator</th><th>Definisi dari codebook</th><th>Tipe</th><th>Bobot</th><th>Missing</th><th>Persentase</th><th>Rentang aktual</th></tr></thead><tbody id="criteria-body"></tbody></table></div></article>
                    <div class="callout callout-warning" id="codebook-notice">Codebook indikator tidak disertakan pada workbook. Definisi ACH, AKC, PBR, PGP, dan ITB ditampilkan sebagai “data tidak tersedia” hingga codebook diberikan.</div>
                </section>

                <section class="page" id="page-weights" data-title="Bobot Kriteria">
                    <div class="page-heading"><div><div class="eyebrow">PARAMETER MODEL</div><h1>Bobot Kriteria</h1><p>Atur bobot yang aktif. Total harus tepat 1 atau 100%.</p></div></div>
                    <div class="settings-grid"><article class="panel"><form id="weights-form"><div class="panel-heading"><div><div class="eyebrow">BOBOT AKTIF</div><h2>Masukkan nilai bobot</h2><p class="fine-print">Gunakan skala 0–1 (contoh: 0,20 = 20%); total bobot harus 1.</p></div><span class="tag tag-neutral">Baseline: 20% masing-masing</span></div>
                        <div class="weight-grid" id="weight-inputs"></div><div class="weight-total">Total bobot <strong id="weight-total">100,00%</strong></div>
                        <div class="form-actions"><button class="button button-primary" type="submit">Simpan bobot</button><button class="button button-secondary" type="button" id="reset-weights">Kembalikan ke baseline</button></div></form></article>
                        <article class="panel"><div class="eyebrow">SKENARIO UJI</div><h2>Bobot dinaikkan</h2><p>Naikkan bobot satu kriteria; empat bobot lain dinormalisasi sama rata agar total tetap 100%.</p>
                        <label class="form-label" for="increased-weight">Bobot kriteria yang diuji (%)</label><input class="text-input" id="increased-weight" type="number" min="20.01" max="99.99" step="0.01" value="40">
                        <p class="fine-print">Nilai awal 40% merupakan parameter skenario dan dapat diubah. Semua hasil dihitung ulang dari dataset aktif.</p>
                        <button class="button button-secondary" id="save-increased-weight">Simpan parameter skenario</button></article></div>
                    <div class="callout callout-warning">Bobot mencerminkan prioritas analitis peneliti, bukan bobot objektif yang ditentukan oleh data.</div>
                </section>

                <section class="page" id="page-calculation" data-title="Perhitungan SAW">
                    <div class="page-heading"><div><div class="eyebrow">METODE YANG DAPAT DIAUDIT</div><h1>Perhitungan SAW</h1><p>Contoh berikut dihitung dari alternatif peringkat 1 pada skenario yang sedang dipilih.</p></div><label class="inline-control">Skenario<select class="scenario-select"></select></label></div>
                    <article class="panel formula-panel"><div class="formula-step"><span>01</span><div><strong>Matriks keputusan X</strong><small>Nilai ACH, AKC, PBR, PGP, dan ITB pada dataset setelah strategi missing values diterapkan.</small></div></div><div class="formula-arrow">→</div><div class="formula-step"><span>02</span><div><strong>Normalisasi</strong><small>Benefit: rᵢⱼ = xᵢⱼ / max(xⱼ) · Cost: rᵢⱼ = min(xⱼ) / xᵢⱼ</small></div></div><div class="formula-arrow">→</div><div class="formula-step"><span>03</span><div><strong>Pembobotan &amp; ranking</strong><small>Vᵢ = Σ(wⱼ × rᵢⱼ), diurutkan dari skor terbesar.</small></div></div></article>
                    <article class="panel"><div class="panel-heading"><div><div class="eyebrow">CONTOH PERHITUNGAN LENGKAP</div><h2 id="example-heading">Data tidak tersedia</h2></div></div>
                        <div class="table-scroll"><table><thead><tr><th>Kriteria</th><th>Nilai awal</th><th>Nilai imputasi</th><th>Normalisasi</th><th>Bobot</th><th>Kontribusi</th><th>Rata-rata seluruh guru*</th></tr></thead><tbody id="example-body"></tbody></table></div>
                        <p class="fine-print">* Rata-rata memakai seluruh guru dan mengabaikan nilai NULL; median imputasi diterapkan hanya saat strategi imputasi dipilih.</p>
                        <div class="score-summary"><span>Total skor preferensi</span><strong id="example-score">—</strong><span id="example-rank" class="tag tag-accent"></span></div></article>
                    <div class="callout callout-info" id="calculation-note">Data tidak tersedia</div>
                </section>

                <section class="page" id="page-ranking" data-title="Ranking Guru">
                    <div class="page-heading"><div><div class="eyebrow">URUTAN ALTERNATIF</div><h1>Ranking Guru</h1><p>Pemeringkatan deterministik: skor SAW menurun; skor sama diurutkan berdasarkan ID guru.</p></div><label class="inline-control">Skenario<select class="scenario-select"></select></label></div>
                    <article class="panel"><div class="table-scroll"><table><thead><tr><th>Rank</th><th>ID guru</th><th>Skor SAW</th><th>Perubahan skor</th><th>Perubahan rank</th><th>Status data</th><th>Rincian</th></tr></thead><tbody id="ranking-body"></tbody></table></div><div class="table-footer"><span id="ranking-count">—</span><div class="pagination"><button class="button button-quiet" id="ranking-previous">Sebelumnya</button><span id="ranking-page-label">—</span><button class="button button-quiet" id="ranking-next">Berikutnya</button><button class="button button-secondary" data-action="export-teachers">Ekspor Excel</button></div></div></article>
                    <p class="fine-print">Perubahan skor dan rank dibandingkan terhadap baseline 20%.</p>
                </section>

                <section class="page" id="page-sensitivity" data-title="Analisis Sensitivitas">
                    <div class="page-heading"><div><div class="eyebrow">UJI STABILITAS</div><h1>Analisis Sensitivitas Bobot</h1><p>Bandingkan perubahan skor dan urutan alternatif terhadap baseline bobot 20%.</p></div><label class="inline-control">Skenario tampilan<select class="scenario-select"></select></label></div>
                    <article class="panel"><div class="table-scroll"><table><thead><tr><th>Skenario</th><th>C1</th><th>C2</th><th>C3</th><th>C4</th><th>C5</th><th>Peringkat 1 · skor</th><th>Perubahan ranking</th><th>Δ skor absolut</th><th>Spearman</th><th>Kendall Tau</th><th>Top-5 overlap</th><th>Top-10 overlap</th><th>Top-20 overlap</th></tr></thead><tbody id="sensitivity-body"></tbody></table></div></article>
                    <div class="metric-grid metric-grid-three"><article class="metric-card"><span>Mean rank displacement</span><strong id="mean-displacement">—</strong><small>Rata-rata perpindahan posisi</small></article><article class="metric-card"><span>Maximum rank displacement</span><strong id="max-displacement">—</strong><small>Perpindahan posisi terbesar</small></article><article class="metric-card"><span>Perubahan skor absolut rata-rata</span><strong id="mean-score-change">—</strong><small>Dibanding baseline</small></article></div>
                    <article class="panel"><div class="panel-heading"><div><div class="eyebrow">PERUBAHAN POSISI</div><h2>Mean rank displacement per skenario</h2></div></div><div class="chart-wrap chart-wrap-medium"><canvas id="sensitivity-detail-chart"></canvas></div></article>
                    <article class="panel"><div class="panel-heading"><div><div class="eyebrow">TOP-K OVERLAP</div><h2>Guru yang masuk dan keluar Top 10</h2></div></div><div class="top-change-grid"><div><h3>Masuk Top 10</h3><ul id="top-entered"></ul></div><div><h3>Keluar dari Top 10</h3><ul id="top-exited"></ul></div></div><p class="fine-print">Daftar ditampilkan untuk skenario yang dipilih dan dibandingkan dengan baseline.</p></article>
                </section>

                <section class="page" id="page-quality" data-title="Missing Data">
                    <div class="page-heading"><div><div class="eyebrow">KUALITAS DATA</div><h1>Missing Data</h1><p>Nilai kosong dipertahankan dan dilaporkan. Strategi yang dipilih tercatat pada hasil.</p></div></div>
                    <div class="metric-grid"><article class="metric-card"><span>Total guru</span><strong id="quality-total">—</strong></article><article class="metric-card"><span>Guru lengkap</span><strong id="quality-complete">—</strong></article><article class="metric-card"><span>Minimal satu missing</span><strong id="quality-missing">—</strong></article><article class="metric-card"><span>Strategi aktif</span><strong id="quality-strategy">—</strong></article></div>
                    <article class="panel"><div class="panel-heading"><div><div class="eyebrow">NULL PER KRITERIA</div><h2>Statistik missing values</h2></div></div><div class="table-scroll"><table><thead><tr><th>Kriteria</th><th>Jumlah missing</th><th>Persentase dataset</th></tr></thead><tbody id="missing-body"></tbody></table></div></article>
                    <article class="panel"><div class="panel-heading"><div><div class="eyebrow">PILIHAN PREPROCESSING</div><h2>Strategi untuk perhitungan SAW</h2></div></div><p>Dataset sumber tetap utuh. Complete Case hanya mengecualikan baris yang tidak lengkap dari pemeringkatan; Median Imputation mengisi nilai hilang dengan median tiap kolom yang dihitung dari nilai tersedia.</p><div class="strategy-control"><label><input type="radio" name="missing-strategy" value="Complete Case"> Complete Case</label><label><input type="radio" name="missing-strategy" value="Median Imputation"> Median Imputation</label><button class="button button-primary" id="save-strategy">Terapkan strategi</button></div></article>
                    <article class="panel"><div class="panel-heading"><div><div class="eyebrow">PERBANDINGAN PREPROCESSING</div><h2>Complete Case vs Median Imputation</h2></div><button class="button button-secondary" id="compare-strategies">Bandingkan strategi</button></div>
                        <p>Perbandingan memakai bobot aktif dan tipe kriteria yang sama. Korelasi serta perpindahan ranking dihitung pada cohort guru lengkap yang sama; Top-K membandingkan daftar peringkat kedua strategi.</p>
                        <div id="strategy-comparison" hidden><div class="table-scroll"><table><thead><tr><th>Strategi</th><th>Guru diranking</th><th>Peringkat 1</th><th>Skor peringkat 1</th><th>Rata-rata skor</th></tr></thead><tbody id="strategy-summary-body"></tbody></table></div>
                            <div class="metric-grid metric-grid-three comparison-metrics"><article class="metric-card"><span>Spearman · cohort lengkap</span><strong id="strategy-spearman">—</strong></article><article class="metric-card"><span>Kendall Tau · cohort lengkap</span><strong id="strategy-kendall">—</strong></article><article class="metric-card"><span>Perubahan ranking · cohort lengkap</span><strong id="strategy-rank-changes">—</strong></article></div>
                            <div class="strategy-overlap"><strong>Top-K overlap</strong><span id="strategy-top-five">Top 5: —</span><span id="strategy-top-ten">Top 10: —</span><span id="strategy-top-twenty">Top 20: —</span></div>
                            <div class="top-change-grid"><div><h3>Masuk Top 10 pada Median Imputation</h3><ul id="strategy-entered"></ul></div><div><h3>Keluar dari Top 10 pada Median Imputation</h3><ul id="strategy-exited"></ul></div></div>
                        </div>
                        <div class="callout callout-info strategy-comparison-empty" id="strategy-comparison-empty">Pilih “Bandingkan strategi” untuk menghitung hasil kedua strategi dengan data dan parameter yang sama.</div>
                    </article>
                </section>

                <section class="page" id="page-detail" data-title="Detail Guru">
                    <div class="page-heading"><div><div class="eyebrow">RINCIAN ALTERNATIF</div><h1>Detail Guru</h1><p>Telusuri ID guru untuk melihat nilai, normalisasi, kontribusi, dan perbandingan terhadap rata-rata.</p></div></div>
                    <form id="detail-form" class="search-detail"><input class="text-input" id="detail-id" placeholder="Masukkan kd_guru_an" required><select class="scenario-select text-input"></select><button class="button button-primary" type="submit">Lihat detail</button></form>
                    <div id="detail-content" hidden><article class="panel"><div class="panel-heading"><div><div class="eyebrow">IDENTITAS ALTERNATIF</div><h2 id="detail-heading">—</h2></div><span class="tag tag-accent" id="detail-ranking">—</span></div><div class="chart-wrap chart-wrap-short detail-chart-wrap"><canvas id="detail-chart" aria-label="Perbandingan nilai kriteria guru dan rata-rata seluruh guru"></canvas></div><div class="table-scroll"><table><thead><tr><th>Kriteria</th><th>Nilai awal</th><th>Nilai imputasi</th><th>Nilai normalisasi</th><th>Bobot</th><th>Kontribusi skor</th><th>Rata-rata seluruh guru*</th></tr></thead><tbody id="detail-body"></tbody></table></div><p class="fine-print">* Rata-rata setiap kriteria memakai nilai tersedia pada seluruh guru; nilai NULL tidak disamakan dengan nol.</p><div class="score-summary"><span>Total skor SAW</span><strong id="detail-score">—</strong><span id="detail-change" class="tag tag-neutral"></span></div></article></div>
                </section>

                <section class="page" id="page-reports" data-title="Laporan">
                    <div class="page-heading"><div><div class="eyebrow">DOKUMENTASI PENELITIAN</div><h1>Laporan &amp; Ekspor</h1><p>Ekspor workbook yang dapat diaudit dari data, konfigurasi, dan hasil pemrosesan aktual.</p></div></div>
                    <div class="reports-grid"><article class="panel report-card"><span class="report-icon">▦</span><h2>Workbook penelitian</h2><p>Dataset cleaning, matriks keputusan dan normalisasi, bobot, skor dan ranking, analisis sensitivitas, statistik missing, serta parameter proses.</p><button class="button button-primary" data-action="export-report">Unduh laporan Excel</button></article><article class="panel report-card"><span class="report-icon">▤</span><h2>Daftar data guru</h2><p>Nilai awal, skor SAW, dan ranking pada skenario yang dipilih.</p><button class="button button-secondary" data-action="export-teachers">Unduh Excel</button></article><article class="panel report-card"><span class="report-icon">▧</span><h2>Dokumen PDF</h2><p>Buka dialog cetak browser dan pilih “Save as PDF”.</p><button class="button button-secondary" data-action="print">Cetak / simpan PDF</button></article></div>
                    <article class="panel"><div class="panel-heading"><div><div class="eyebrow">JEJAK REPRODUKSIBILITAS</div><h2>Parameter pemrosesan</h2></div></div><dl class="metadata-list" id="report-metadata"></dl></article>
                    <div class="callout callout-warning">Tidak ada nilai penelitian yang dihasilkan secara dummy. Jika dataset belum diimpor, statistik dan hasil ditampilkan sebagai “data tidak tersedia”.</div>
                </section>

                <section class="page" id="page-settings" data-title="Pengaturan">
                    <div class="page-heading"><div><div class="eyebrow">KONFIGURASI ANALISIS</div><h1>Pengaturan</h1><p>Strategi missing, tipe kriteria, bobot, dan parameter normalisasi yang digunakan.</p></div></div>
                    <div class="settings-grid"><article class="panel"><div class="panel-heading"><div><div class="eyebrow">TIPE KRITERIA</div><h2>Benefit / Cost</h2></div></div><p>Asumsi awal semua kriteria adalah Benefit. Rumus Cost min(x)/x ditolak jika nilai nol ditemukan.</p><div id="type-inputs" class="type-inputs"></div><button class="button button-primary" id="save-types">Simpan tipe kriteria</button></article>
                        <article class="panel"><div class="panel-heading"><div><div class="eyebrow">METODE</div><h2>Parameter pemrosesan</h2></div></div><dl class="metadata-list" id="settings-metadata"></dl><p class="fine-print">Nama kolom dan tipe data divalidasi saat impor. Nilai kriteria di luar 0–100 dan ID kosong ditolak.</p></article></div>
                    <div class="callout callout-info">Workbook sumber tidak diubah. Hasil SAW adalah rekomendasi berbasis kriteria, bukan bukti objektif mengenai kualitas guru.</div>
                </section>
            </div>
        </main>
    </div>
    <div class="toast-region" id="toast-region" aria-live="polite"></div>
    <script src="/assets/app.js" defer></script>
</body>
</html>
