const state = {
    configuration: null,
    status: null,
    overview: null,
    scenarios: [],
    scenario: 'Baseline',
    page: 1,
    rankingPage: 1,
    detailId: '',
    chartData: null
};

const criterionCodes = ['ACH', 'AKC', 'PBR', 'PGP', 'ITB'];
const criterionMeta = [
    { code: 'C1', key: 'ACH' },
    { code: 'C2', key: 'AKC' },
    { code: 'C3', key: 'PBR' },
    { code: 'C4', key: 'PGP' },
    { code: 'C5', key: 'ITB' }
];

const numberFormat = new Intl.NumberFormat('id-ID', { maximumFractionDigits: 2 });
const scoreFormat = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 4, maximumFractionDigits: 6 });
const percentFormat = new Intl.NumberFormat('id-ID', { minimumFractionDigits: 2, maximumFractionDigits: 2 });

function escapeHTML(value) {
    return String(value ?? '').replace(/[&<>"']/g, character => ({
        '&': '&amp;',
        '<': '&lt;',
        '>': '&gt;',
        '"': '&quot;',
        "'": '&#39;'
    })[character]);
}

function formatNumber(value) {
    return value === null || value === undefined || value === '' || !Number.isFinite(Number(value))
        ? 'Data tidak tersedia'
        : numberFormat.format(Number(value));
}

function formatScore(value) {
    return value === null || value === undefined || !Number.isFinite(Number(value))
        ? 'Data tidak tersedia'
        : scoreFormat.format(Number(value));
}

function formatPercent(value) {
    return value === null || value === undefined || !Number.isFinite(Number(value))
        ? 'Data tidak tersedia'
        : `${percentFormat.format(Number(value))}%`;
}

async function api(action, options = {}) {
    const url = new URL('/api.php', window.location.origin);
    url.searchParams.set('action', action);
    for (const [key, value] of Object.entries(options.query ?? {})) {
        if (value !== null && value !== undefined && value !== '') {
            url.searchParams.set(key, String(value));
        }
    }
    const response = await fetch(url, options.request ?? {});
    const contentType = response.headers.get('content-type') ?? '';
    if (!response.ok) {
        const body = contentType.includes('application/json') ? await response.json() : null;
        throw new Error(body?.error ?? `Permintaan gagal (${response.status}).`);
    }
    if (options.binary) {
        return { blob: await response.blob(), response };
    }
    if (!contentType.includes('application/json')) {
        throw new Error('Server mengembalikan respons yang tidak dapat dibaca.');
    }
    return response.json();
}

function showToast(message, type = 'success') {
    const toast = document.createElement('div');
    toast.className = `toast toast-${type}`;
    toast.textContent = message;
    document.getElementById('toast-region').append(toast);
    window.setTimeout(() => toast.remove(), 4500);
}

function setScenarioOptions(names, selected = state.scenario) {
    state.scenarios = names;
    document.querySelectorAll('.scenario-select').forEach(select => {
        select.innerHTML = names.map(name => `<option value="${escapeHTML(name)}">${escapeHTML(name)}</option>`).join('');
        select.value = names.includes(selected) ? selected : (names[0] ?? '');
    });
}

function setPage(page) {
    const target = document.getElementById(`page-${page}`);
    if (!target) return;
    document.querySelectorAll('.page').forEach(section => section.classList.toggle('active', section === target));
    document.querySelectorAll('.nav-item').forEach(button => button.classList.toggle('active', button.dataset.page === page));
    document.getElementById('breadcrumb-title').textContent = target.dataset.title ?? 'Dashboard';
    document.getElementById('sidebar').classList.remove('sidebar-open');
    void loadPage(page);
}

function showDatasetStatus() {
    const status = document.getElementById('dataset-status');
    if (!state.status?.hasDataset) {
        status.textContent = 'Dataset belum dimuat';
        status.classList.add('status-muted');
        return;
    }
    status.textContent = `${formatNumber(state.status.dataset.rows)} guru dimuat`;
    status.classList.remove('status-muted');
}

function renderMetadata(targetId) {
    const dataset = state.status?.dataset ?? {};
    const items = [
        ['Dataset', dataset.name || 'Data tidak tersedia'],
        ['Sheet sumber', dataset.sheet || 'Data tidak tersedia'],
        ['SHA-256', dataset.hash || 'Data tidak tersedia'],
        ['Tanggal pemrosesan (UTC)', dataset.processedAt || 'Data tidak tersedia'],
        ['Strategi missing values', state.configuration?.strategy ?? 'Data tidak tersedia'],
        ['Normalisasi', 'Benefit: x/max(x); Cost: min(x)/x']
    ];
    document.getElementById(targetId).innerHTML = items.map(([label, value]) =>
        `<div><dt>${escapeHTML(label)}</dt><dd>${escapeHTML(value)}</dd></div>`).join('');
}

function drawBars(canvasId, labels, values, options = {}) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !canvas.isConnected || !canvas.getContext) return;
    const bounds = canvas.getBoundingClientRect();
    const width = Math.max(280, Math.floor(bounds.width));
    const height = options.height ?? Math.max(170, Math.floor(bounds.height));
    const ratio = window.devicePixelRatio || 1;
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    const context = canvas.getContext('2d');
    context.scale(ratio, ratio);
    context.clearRect(0, 0, width, height);

    const usableValues = values.map(value => Number(value) || 0);
    const maxValue = Math.max(...usableValues, 1);
    const left = options.horizontal ? Math.min(144, width * 0.42) : 40;
    const right = 14;
    const top = 16;
    const bottom = options.horizontal ? 8 : 33;
    const plotWidth = Math.max(40, width - left - right);
    const plotHeight = Math.max(40, height - top - bottom);
    context.font = '11px Inter, Segoe UI, sans-serif';
    context.lineWidth = 1;
    context.strokeStyle = '#e4eaf0';
    context.fillStyle = '#7b8b9e';

    if (options.horizontal) {
        const rowHeight = plotHeight / Math.max(1, labels.length);
        labels.forEach((label, index) => {
            const y = top + index * rowHeight;
            const barWidth = (usableValues[index] / maxValue) * plotWidth;
            context.fillStyle = '#7288a2';
            context.beginPath();
            context.roundRect(left, y + rowHeight * 0.22, barWidth, Math.max(4, rowHeight * 0.56), 5);
            context.fill();
            context.fillStyle = '#56677b';
            context.textAlign = 'right';
            context.textBaseline = 'middle';
            context.fillText(label, left - 8, y + rowHeight / 2, left - 12);
            context.textAlign = 'left';
            if (barWidth > 24) context.fillText(options.valueFormat?.(usableValues[index]) ?? formatNumber(usableValues[index]), left + barWidth + 5, y + rowHeight / 2);
        });
        return;
    }

    const rowWidth = plotWidth / Math.max(1, labels.length);
    labels.forEach((label, index) => {
        const barHeight = (usableValues[index] / maxValue) * plotHeight;
        const x = left + index * rowWidth + rowWidth * 0.17;
        const barWidth = rowWidth * 0.66;
        context.fillStyle = '#4d7394';
        context.beginPath();
        context.roundRect(x, top + plotHeight - barHeight, barWidth, barHeight, [5, 5, 0, 0]);
        context.fill();
        context.strokeStyle = '#edf1f5';
        context.beginPath();
        context.moveTo(left, top + plotHeight);
        context.lineTo(left + plotWidth, top + plotHeight);
        context.stroke();
        context.fillStyle = '#75869a';
        context.textAlign = 'center';
        context.textBaseline = 'top';
        context.fillText(String(label), x + barWidth / 2, top + plotHeight + 9, rowWidth);
    });
}

function drawComparisonBars(canvasId, labels, values, means) {
    const canvas = document.getElementById(canvasId);
    if (!canvas || !canvas.isConnected || !canvas.getContext) return;
    const bounds = canvas.getBoundingClientRect();
    const width = Math.max(280, Math.floor(bounds.width));
    const height = Math.max(150, Math.floor(bounds.height));
    const ratio = window.devicePixelRatio || 1;
    canvas.width = width * ratio;
    canvas.height = height * ratio;
    const context = canvas.getContext('2d');
    context.scale(ratio, ratio);
    context.clearRect(0, 0, width, height);
    const left = 38;
    const right = 15;
    const top = 22;
    const bottom = 28;
    const plotWidth = width - left - right;
    const plotHeight = height - top - bottom;
    const maximum = Math.max(...values, ...means, 1);
    const groupWidth = plotWidth / Math.max(1, labels.length);
    context.font = '10px Inter, Segoe UI, sans-serif';
    context.textBaseline = 'middle';
    labels.forEach((label, index) => {
        const barWidth = Math.min(17, groupWidth * 0.27);
        const firstHeight = (values[index] / maximum) * plotHeight;
        const meanHeight = (means[index] / maximum) * plotHeight;
        const center = left + groupWidth * (index + 0.5);
        context.fillStyle = '#4d7394';
        context.fillRect(center - barWidth - 2, top + plotHeight - firstHeight, barWidth, firstHeight);
        context.fillStyle = '#c1cbd3';
        context.fillRect(center + 2, top + plotHeight - meanHeight, barWidth, meanHeight);
        context.fillStyle = '#75869a';
        context.textAlign = 'center';
        context.fillText(label, center, height - 11, groupWidth);
    });
    context.textAlign = 'left';
    context.fillStyle = '#4d7394';
    context.fillRect(left, 5, 8, 8);
    context.fillStyle = '#75869a';
    context.fillText('Guru', left + 12, 9);
    context.fillStyle = '#c1cbd3';
    context.fillRect(left + 53, 5, 8, 8);
    context.fillStyle = '#75869a';
    context.fillText('Rata-rata', left + 65, 9);
}

function drawDashboardCharts(data) {
    const distribution = data.distribution ?? [];
    drawBars('score-chart', distribution.map(bin => bin.label), distribution.map(bin => bin.count), { height: 220 });
    const nonBaseline = (data.sensitivity ?? []).filter(row => row.name !== 'Baseline');
    drawBars(
        'change-chart',
        nonBaseline.map(row => row.name.replace('Bobot ', '').replace(' ditingkatkan', '')),
        nonBaseline.map(row => row.rankChanges ?? 0),
        { height: 200, horizontal: true }
    );
    drawBars(
        'sensitivity-chart',
        nonBaseline.map(row => row.name.replace('Bobot ', '').replace(' ditingkatkan', '')),
        nonBaseline.map(row => (row.meanAbsoluteScoreChange ?? 0) * 100),
        { height: 200, horizontal: true, valueFormat: value => `${numberFormat.format(value)}%` }
    );
    state.chartData = data;
}

function renderDashboard(data) {
    state.overview = data;
    document.getElementById('metric-total').textContent = formatNumber(data.totalTeachers);
    document.getElementById('metric-complete').textContent = formatNumber(data.completeTeachers);
    document.getElementById('metric-missing').textContent = formatNumber(data.teachersWithMissing);
    document.getElementById('metric-criteria').textContent = formatNumber(data.criteriaCount);
    document.getElementById('metric-complete-pct').textContent = `${formatPercent(data.totalTeachers ? data.completeTeachers * 100 / data.totalTeachers : null)} dari alternatif memiliki 5 nilai`;
    document.getElementById('dashboard-weights').innerHTML = criterionMeta.map(item =>
        `<span><b>${item.code}</b>${formatPercent((data.weights?.[item.key] ?? 0) * 100)}</span>`).join('');
    document.getElementById('winner-scenario').textContent = data.activeScenario;

    const winner = data.winner;
    document.getElementById('winner-content').innerHTML = winner
        ? `<div class="winner-id">${escapeHTML(winner.id)}</div><div class="winner-score">${formatScore(winner.score)}</div><div class="winner-caption">Skor SAW · peringkat 1 dari ${formatNumber(data.rankedTeachers)} guru yang dapat diranking</div>`
        : '<span class="empty-state">Data tidak tersedia</span>';
    document.getElementById('top-ten-body').innerHTML = (data.topTen ?? []).filter(Boolean).map(row => `
        <tr><td><span class="rank-pill">${formatNumber(row.rank)}</span></td>
        <td><button class="link-button" data-detail-id="${escapeHTML(row.id)}">${escapeHTML(row.id)}</button></td>
        <td class="score-cell">${formatScore(row.score)}</td>
        <td>${formatNumber(row.ach)}</td><td>${formatNumber(row.akc)}</td><td>${formatNumber(row.pbr)}</td><td>${formatNumber(row.pgp)}</td><td>${formatNumber(row.itb)}</td></tr>`).join('') || '<tr><td colspan="8" class="empty-cell">Data tidak tersedia</td></tr>';

    const notice = document.getElementById('dashboard-notice');
    if (data.rankedTeachers < data.totalTeachers) {
        notice.hidden = false;
        notice.textContent = `Strategi ${data.strategy} memeringkat ${formatNumber(data.rankedTeachers)} dari ${formatNumber(data.totalTeachers)} guru; baris tidak lengkap dipertahankan pada data dan dikecualikan dari perankingan.`;
    } else {
        notice.hidden = true;
    }

    setScenarioOptions((data.sensitivity ?? []).map(row => row.name), state.scenario);
    drawDashboardCharts(data);
}

function renderSensitivity(rows) {
    const baseline = rows.find(row => row.name === 'Baseline');
    const active = rows.find(row => row.name === state.scenario) ?? baseline;
    document.getElementById('sensitivity-body').innerHTML = rows.map(row => `
        <tr><td><strong>${escapeHTML(row.name)}</strong></td>
        ${criterionCodes.map(key => `<td>${formatPercent((row.weights?.[key] ?? 0) * 100)}</td>`).join('')}
        <td>${row.winner ? `${escapeHTML(row.winner.id)} · ${formatScore(row.winner.score)}` : 'Data tidak tersedia'}</td>
        <td>${formatNumber(row.rankChanges)}</td><td>${formatScore(row.meanAbsoluteScoreChange)}</td>
        <td>${row.spearman === null ? 'Data tidak tersedia' : formatScore(row.spearman)}</td>
        <td>${row.kendallTau === null ? 'Data tidak tersedia' : formatScore(row.kendallTau)}</td>
        ${[5, 10, 20].map(k => {
            const overlap = row.topK?.[String(k)];
            return `<td>${overlap ? `${formatNumber(overlap.overlap)} / ${formatNumber(overlap.k)} (${formatPercent(overlap.percentage)})` : 'Data tidak tersedia'}</td>`;
        }).join('')}</tr>`).join('');

    document.getElementById('mean-displacement').textContent = formatNumber(active?.meanRankDisplacement);
    document.getElementById('max-displacement').textContent = formatNumber(active?.maximumRankDisplacement);
    document.getElementById('mean-score-change').textContent = formatScore(active?.meanAbsoluteScoreChange);
    document.getElementById('top-entered').innerHTML = renderChangeList(active?.enteredTopTen);
    document.getElementById('top-exited').innerHTML = renderChangeList(active?.exitedTopTen);
    const chartRows = rows.filter(row => row.name !== 'Baseline');
    drawBars('sensitivity-detail-chart', chartRows.map(row => row.name.replace('Bobot ', '').replace(' ditingkatkan', '')), chartRows.map(row => row.meanRankDisplacement ?? 0), { height: 280, horizontal: true });
}

function renderChangeList(rows) {
    if (!rows?.length) return '<li class="empty-list">Tidak ada perubahan pada Top 10</li>';
    return rows.map(row => `<li><span>${escapeHTML(row.id)}</span><small>${row.rank ? `Peringkat ${formatNumber(row.rank)}` : ''}</small></li>`).join('');
}

function renderStrategyComparison(data) {
    const strategies = ['Complete Case', 'Median Imputation'];
    document.getElementById('strategy-summary-body').innerHTML = strategies.map(strategy => {
        const summary = data.summary[strategy];
        return `<tr><td><strong>${escapeHTML(strategy)}</strong></td>
            <td>${formatNumber(summary.rankedTeachers)}</td>
            <td>${summary.winner ? escapeHTML(summary.winner.id) : 'Data tidak tersedia'}</td>
            <td>${formatScore(summary.winner?.score)}</td>
            <td>${formatScore(summary.meanScore)}</td></tr>`;
    }).join('');
    document.getElementById('strategy-spearman').textContent = formatScore(data.metrics.spearman);
    document.getElementById('strategy-kendall').textContent = formatScore(data.metrics.kendallTau);
    document.getElementById('strategy-rank-changes').textContent = `${formatNumber(data.metrics.rankChanges)} / ${formatNumber(data.metrics.comparedCompleteTeachers)}`;
    for (const k of [5, 10, 20]) {
        const overlap = data.metrics.topK[String(k)];
        document.getElementById(`strategy-top-${k === 5 ? 'five' : k === 10 ? 'ten' : 'twenty'}`).textContent =
            `Top ${k}: ${formatNumber(overlap.overlap)} / ${formatNumber(overlap.k)} (${formatPercent(overlap.percentage)})`;
    }
    document.getElementById('strategy-entered').innerHTML = renderChangeList(data.metrics.enteredTopTen);
    document.getElementById('strategy-exited').innerHTML = renderChangeList(data.metrics.exitedTopTen);
    document.getElementById('strategy-comparison').hidden = false;
    document.getElementById('strategy-comparison-empty').hidden = true;
}

function renderTeachers(data, targetId = 'teacher-table-body') {
    const tbody = document.getElementById(targetId);
    tbody.innerHTML = data.rows.map(row => `
        <tr><td><span class="rank-pill">${formatNumber(row.rank)}</span></td>
        <td><button class="link-button" data-detail-id="${escapeHTML(row.id)}">${escapeHTML(row.id)}</button></td>
        <td>${formatNumber(row.ACH)}</td><td>${formatNumber(row.AKC)}</td><td>${formatNumber(row.PBR)}</td>
        <td>${formatNumber(row.PGP)}</td><td>${formatNumber(row.ITB)}</td><td class="score-cell">${formatScore(row.score)}</td></tr>`).join('')
        || '<tr><td colspan="8" class="empty-cell">Tidak ada alternatif yang cocok dengan filter.</td></tr>';

    if (targetId === 'teacher-table-body') {
        document.getElementById('teacher-count').textContent = `${formatNumber(data.total)} guru · halaman ${formatNumber(data.page)} dari ${formatNumber(data.pages) || '0'}`;
        document.getElementById('page-label').textContent = `${formatNumber(data.page)} / ${formatNumber(data.pages) || '0'}`;
        document.getElementById('previous-page').disabled = data.page <= 1;
        document.getElementById('next-page').disabled = data.page >= data.pages;
    }
}

function renderCriteria(data) {
    document.getElementById('criteria-body').innerHTML = data.criteria.map(item => `
        <tr><td><span class="tag tag-neutral">${escapeHTML(item.code)}</span></td><td><strong>${escapeHTML(item.key)}</strong></td>
        <td class="definition-cell">${escapeHTML(item.definition)}</td><td>${escapeHTML(item.type)}</td>
        <td>${formatPercent(item.weight * 100)}</td><td>${formatNumber(item.missing)}</td>
        <td>${formatPercent(item.missingPercentage)}</td><td>${formatNumber(item.minimum)}–${formatNumber(item.maximum)}</td></tr>`).join('');
}

function renderMissing(data) {
    document.getElementById('quality-total').textContent = formatNumber(data.totalTeachers);
    document.getElementById('quality-complete').textContent = formatNumber(data.completeTeachers);
    document.getElementById('quality-missing').textContent = formatNumber(data.teachersWithMissing);
    document.getElementById('quality-strategy').textContent = data.strategy;
    document.getElementById('missing-body').innerHTML = criterionCodes.map(key => {
        const item = data.missingByCriterion[key];
        return `<tr><td><strong>${escapeHTML(key)}</strong></td><td>${formatNumber(item?.missing)}</td><td>${formatPercent(item?.percentage)}</td></tr>`;
    }).join('');
    const active = document.querySelector(`input[name="missing-strategy"][value="${CSS.escape(data.strategy)}"]`);
    if (active) active.checked = true;
}

function renderDetail(data) {
    state.detailId = data.id;
    document.getElementById('detail-content').hidden = false;
    document.getElementById('detail-heading').textContent = data.id;
    document.getElementById('detail-ranking').textContent = data.rank
        ? `Peringkat ${formatNumber(data.rank)}`
        : 'Tidak masuk pemeringkatan pada strategi ini';
    document.getElementById('detail-body').innerHTML = data.criteria.map(item => `
        <tr><td><strong>${escapeHTML(item.key)}</strong></td><td>${formatNumber(item.value)}</td>
        <td>${formatNumber(item.imputedValue)}</td><td>${formatScore(item.normalized)}</td>
        <td>${formatPercent(item.weight * 100)}</td><td>${formatScore(item.contribution)}</td>
        <td>${formatNumber(item.mean)}</td></tr>`).join('');
    document.getElementById('detail-score').textContent = formatScore(data.score ?? data.calculatedScore);
    document.getElementById('detail-change').textContent = data.comparison.rankChange === null
        ? 'Perbandingan baseline tidak tersedia'
        : `Perubahan ranking vs baseline: ${data.comparison.rankChange > 0 ? '+' : ''}${formatNumber(data.comparison.rankChange)}`;
    document.getElementById('detail-change').className = `tag ${data.comparison.rankChange === null ? 'tag-neutral' : 'tag-accent'}`;
    drawComparisonBars(
        'detail-chart',
        data.criteria.map(item => item.key),
        data.criteria.map(item => item.value ?? item.imputedValue ?? 0),
        data.criteria.map(item => item.mean ?? 0)
    );
}

async function loadOverview(scenario = state.scenario) {
    let data;
    try {
        data = await api('overview', { query: { scenario } });
    } catch (error) {
        clearOverview(error.message);
        throw error;
    }
    state.scenario = data.activeScenario;
    renderDashboard(data);
    renderSensitivity(data.sensitivity ?? []);
    renderMissing(data);
    renderMetadata('report-metadata');
    renderMetadata('settings-metadata');
    if (state.detailId) {
        try {
            renderDetail(await api('detail', { query: { id: state.detailId, scenario: state.scenario } }));
        } catch (error) {
            state.detailId = '';
            showToast(error.message, 'error');
        }
    }
}

function clearOverview(message) {
    state.overview = null;
    ['metric-total', 'metric-complete', 'metric-missing', 'metric-criteria', 'metric-complete-pct']
        .forEach(id => { document.getElementById(id).textContent = 'Data tidak tersedia'; });
    document.getElementById('dashboard-weights').textContent = 'Data tidak tersedia';
    document.getElementById('winner-content').innerHTML = '<span class="empty-state">Data tidak tersedia</span>';
    document.getElementById('top-ten-body').innerHTML = '<tr><td colspan="8" class="empty-cell">Data tidak tersedia</td></tr>';
    document.getElementById('dashboard-notice').hidden = false;
    document.getElementById('dashboard-notice').textContent = message;
    document.getElementById('winner-scenario').textContent = 'Tidak tersedia';
    drawBars('score-chart', [], []);
    drawBars('change-chart', [], [], { horizontal: true });
    drawBars('sensitivity-chart', [], [], { horizontal: true });
}

async function loadTeachers(targetId = 'teacher-table-body', page = 1) {
    const query = {
        scenario: state.scenario,
        page,
        limit: targetId === 'ranking-body' ? 20 : 25,
        search: targetId === 'teacher-table-body' ? document.getElementById('teacher-search').value.trim() : '',
        complete: targetId === 'teacher-table-body' ? document.getElementById('teacher-completeness').value : '',
        sort: targetId === 'teacher-table-body' ? document.getElementById('teacher-sort').value : 'rank',
        direction: targetId === 'teacher-table-body' ? document.getElementById('teacher-direction').value : 'asc'
    };
    const data = await api('teachers', { query });
    renderTeachers(data, targetId);
    if (targetId === 'ranking-body') {
        state.rankingPage = data.page;
        document.getElementById('ranking-count').textContent = `${formatNumber(data.total)} guru · ${state.scenario}`;
        document.getElementById('ranking-page-label').textContent = `${formatNumber(data.page)} / ${formatNumber(data.pages) || '0'}`;
        document.getElementById('ranking-previous').disabled = data.page <= 1;
        document.getElementById('ranking-next').disabled = data.page >= data.pages;
        document.getElementById('ranking-body').innerHTML = data.rows.map(row => `
            <tr><td><span class="rank-pill">${formatNumber(row.rank)}</span></td>
            <td><button class="link-button" data-detail-id="${escapeHTML(row.id)}">${escapeHTML(row.id)}</button></td>
            <td class="score-cell">${formatScore(row.score)}</td>
            <td>${row.score_change === null ? 'Data tidak tersedia' : formatScore(row.score_change)}</td>
            <td>${row.rank_change === null ? 'Data tidak tersedia' : `${Number(row.rank_change) > 0 ? '+' : ''}${formatNumber(row.rank_change)}`}</td>
            <td>${Number(row.missing_mask) === 0 ? '<span class="tag tag-success">Lengkap</span>' : '<span class="tag tag-warning">Missing</span>'}</td>
            <td><button class="button button-quiet button-small" data-detail-id="${escapeHTML(row.id)}">Detail →</button></td></tr>`).join('');
    }
}

async function loadCriteria() {
    renderCriteria(await api('criteria'));
}

async function loadExample() {
    const example = await api('example', { query: { scenario: state.scenario } });
    document.getElementById('example-heading').textContent = `${example.id} · peringkat ${formatNumber(example.rank)}`;
    document.getElementById('example-body').innerHTML = example.criteria.map(item => `
        <tr><td>${escapeHTML(item.code)} · ${escapeHTML(item.key)}</td>
        <td>${formatNumber(item.value)}</td><td>${formatNumber(item.imputedValue)}</td>
        <td>${formatScore(item.normalized)}</td><td>${formatPercent(item.weight * 100)}</td>
        <td>${formatScore(item.contribution)}</td><td>${formatNumber(item.mean)}</td></tr>`).join('');
    document.getElementById('example-score').textContent = formatScore(example.score);
    document.getElementById('example-rank').textContent = `Peringkat ${formatNumber(example.rank)}`;
    document.getElementById('calculation-note').textContent = `Nilai normalisasi memakai nilai ekstrem pada populasi “${example.strategy}”. Ranking deterministik mengurutkan skor SAW menurun dan memecahkan skor sama dengan ID guru secara alfabetis.`;
}

async function loadPage(page) {
    if (!state.status?.hasDataset) return;
    try {
        if (page === 'dashboard') await loadOverview();
        if (page === 'teachers') await loadTeachers();
        if (page === 'criteria') await loadCriteria();
        if (page === 'calculation') await loadExample();
        if (page === 'ranking') await loadTeachers('ranking-body', 1);
        if (page === 'sensitivity') {
            const response = await api('sensitivity');
            renderSensitivity(response.scenarios);
        }
        if (page === 'quality') renderMissing(await api('overview', { query: { scenario: state.scenario } }));
        if (page === 'detail' && state.detailId) renderDetail(await api('detail', { query: { id: state.detailId, scenario: state.scenario } }));
        if (page === 'reports') renderMetadata('report-metadata');
        if (page === 'settings') renderMetadata('settings-metadata');
    } catch (error) {
        showToast(error.message, 'error');
    }
}

function renderWeightInputs(weights = {}) {
    document.getElementById('weight-inputs').innerHTML = criterionMeta.map(item => `
        <label class="weight-field"><span>${item.code} · ${item.key}</span>
        <div><input type="number" min="0" max="1" step="0.01" value="${Number(weights[item.key] ?? 0.2).toFixed(2)}" name="${item.key}" aria-label="Bobot ${item.key}"><small>0–1</small></div></label>`).join('');
    updateWeightTotal();
}

function updateWeightTotal() {
    const inputs = [...document.querySelectorAll('#weight-inputs input')];
    const total = inputs.reduce((sum, input) => sum + (Number(input.value) || 0), 0);
    const output = document.getElementById('weight-total');
    output.textContent = `${percentFormat.format(total * 100)}%`;
    output.classList.toggle('text-error', Math.abs(total - 1) > 0.000001);
    return total;
}

function renderTypeInputs(types = {}) {
    document.getElementById('type-inputs').innerHTML = criterionMeta.map(item => `
        <label class="type-row"><strong>${item.code} · ${item.key}</strong><select class="text-input" data-criterion-type="${item.key}">
            <option value="Benefit" ${types[item.key] === 'Benefit' ? 'selected' : ''}>Benefit</option>
            <option value="Cost" ${types[item.key] === 'Cost' ? 'selected' : ''}>Cost</option>
        </select></label>`).join('');
}

async function saveConfiguration(overrides = {}) {
    const response = await api('save-settings', {
        request: {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({
                weights: state.configuration.weights,
                types: state.configuration.types,
                strategy: state.configuration.strategy,
                increasedWeight: state.configuration.increasedWeight,
                ...overrides
            })
        }
    });
    state.configuration = response.configuration;
    state.status.configuration = response.configuration;
    state.scenario = 'Baseline';
    document.getElementById('strategy-comparison').hidden = true;
    document.getElementById('strategy-comparison-empty').hidden = false;
    await loadStatus();
    await loadOverview('Baseline');
    showToast('Pengaturan berhasil disimpan. Hasil akan dihitung ulang dari dataset aktif.');
}

async function loadStatus() {
    const status = await api('status');
    state.status = status;
    state.configuration = status.configuration;
    showDatasetStatus();
    renderWeightInputs(status.configuration.weights);
    renderTypeInputs(status.configuration.types);
    document.getElementById('increased-weight').value = (status.configuration.increasedWeight * 100).toFixed(2);
    if (!status.hasDataset) {
        document.getElementById('dashboard-notice').hidden = false;
        document.getElementById('dashboard-notice').textContent = status.sourceDatasetAvailable
            ? 'Dataset sumber ditemukan. Impor dataset untuk menghitung statistik dan ranking berdasarkan data aktual.'
            : 'Dataset tidak tersedia. Unggah workbook kandidat_saw_evaluasi_guru_sdmi.xlsx untuk memulai; tidak ada angka hasil yang dibuat.';
        document.getElementById('winner-content').innerHTML = '<span class="empty-state">Data tidak tersedia</span>';
        document.getElementById('top-ten-body').innerHTML = '<tr><td colspan="8" class="empty-cell">Data tidak tersedia</td></tr>';
        renderMetadata('report-metadata');
        renderMetadata('settings-metadata');
    }
    return status;
}

async function importDataset(file) {
    if (!file) return;
    const form = new FormData();
    form.append('dataset', file);
    showToast('Mengimpor dan memvalidasi workbook…', 'info');
    try {
        const result = await api('import', { request: { method: 'POST', body: form } });
        showToast(`Dataset berhasil dimuat: ${formatNumber(result.result.rows)} guru.`);
        state.detailId = '';
        state.scenario = 'Baseline';
        state.overview = null;
        await loadStatus();
        try {
            await loadOverview('Baseline');
        } catch (error) {
            document.getElementById('dashboard-notice').hidden = false;
            document.getElementById('dashboard-notice').textContent = error.message;
            showToast(error.message, 'error');
        }
        setPage('dashboard');
    } catch (error) {
        showToast(error.message, 'error');
    }
}

function downloadFile(blob, filename) {
    const url = URL.createObjectURL(blob);
    const link = document.createElement('a');
    link.href = url;
    link.download = filename;
    link.click();
    window.setTimeout(() => URL.revokeObjectURL(url), 1500);
}

async function exportExcel(format) {
    try {
        const { blob, response } = await api('export', {
            query: { format, scenario: state.scenario },
            binary: true
        });
        const disposition = response.headers.get('content-disposition') ?? '';
        const filename = disposition.match(/filename="([^"]+)"/)?.[1] ?? 'laporan-saw.xlsx';
        downloadFile(blob, filename);
    } catch (error) {
        showToast(error.message, 'error');
    }
}

let searchTimer;
document.addEventListener('click', event => {
    const pageButton = event.target.closest('[data-page]');
    if (pageButton) {
        event.preventDefault();
        setPage(pageButton.dataset.page);
        return;
    }
    const detailButton = event.target.closest('[data-detail-id]');
    if (detailButton) {
        const id = detailButton.dataset.detailId;
        document.getElementById('detail-id').value = id;
        setPage('detail');
        api('detail', { query: { id, scenario: state.scenario } }).then(renderDetail).catch(error => showToast(error.message, 'error'));
        return;
    }
    const actionButton = event.target.closest('[data-action]');
    if (!actionButton) return;
    if (actionButton.dataset.action === 'import') document.getElementById('dataset-file').click();
    if (actionButton.dataset.action === 'export-teachers') void exportExcel('teachers');
    if (actionButton.dataset.action === 'export-report') void exportExcel('report');
    if (actionButton.dataset.action === 'print') window.print();
});

document.querySelectorAll('.scenario-select').forEach(select => {
    select.addEventListener('change', () => {
        state.scenario = select.value;
        document.querySelectorAll('.scenario-select').forEach(other => { other.value = state.scenario; });
        const activePage = document.querySelector('.page.active')?.id.replace('page-', '') ?? 'dashboard';
        void loadPage(activePage);
    });
});

document.getElementById('dataset-file').addEventListener('change', event => {
    void importDataset(event.target.files?.[0]);
    event.target.value = '';
});

document.getElementById('mobile-menu').addEventListener('click', () => {
    document.getElementById('sidebar').classList.toggle('sidebar-open');
});

document.getElementById('teacher-search').addEventListener('input', () => {
    window.clearTimeout(searchTimer);
    searchTimer = window.setTimeout(() => {
        state.page = 1;
        void loadTeachers('teacher-table-body', 1);
    }, 250);
});

['teacher-completeness', 'teacher-sort', 'teacher-direction'].forEach(id => {
    document.getElementById(id).addEventListener('change', () => {
        state.page = 1;
        void loadTeachers('teacher-table-body', 1);
    });
});

document.getElementById('previous-page').addEventListener('click', () => {
    state.page = Math.max(1, state.page - 1);
    void loadTeachers('teacher-table-body', state.page);
});
document.getElementById('next-page').addEventListener('click', () => {
    state.page++;
    void loadTeachers('teacher-table-body', state.page);
});
document.getElementById('ranking-previous').addEventListener('click', () => {
    state.rankingPage = Math.max(1, state.rankingPage - 1);
    void loadTeachers('ranking-body', state.rankingPage);
});
document.getElementById('ranking-next').addEventListener('click', () => {
    state.rankingPage++;
    void loadTeachers('ranking-body', state.rankingPage);
});

document.getElementById('weight-inputs').addEventListener('input', updateWeightTotal);
document.getElementById('weights-form').addEventListener('submit', async event => {
    event.preventDefault();
    const total = updateWeightTotal();
    if (Math.abs(total - 1) > 0.000001) {
        showToast('Total bobot harus sama dengan 1 (100%).', 'error');
        return;
    }
    const weights = {};
    document.querySelectorAll('#weight-inputs input').forEach(input => { weights[input.name] = Number(input.value); });
    try {
        await saveConfiguration({ weights });
    } catch (error) {
        showToast(error.message, 'error');
    }
});

document.getElementById('reset-weights').addEventListener('click', () => {
    const weights = Object.fromEntries(criterionCodes.map(key => [key, 0.2]));
    renderWeightInputs(weights);
});

document.getElementById('save-increased-weight').addEventListener('click', async () => {
    const increasedWeight = Number(document.getElementById('increased-weight').value) / 100;
    if (!Number.isFinite(increasedWeight) || increasedWeight <= 0.2 || increasedWeight >= 1) {
        showToast('Bobot skenario harus lebih besar dari 20% dan kurang dari 100%.', 'error');
        return;
    }
    try {
        await saveConfiguration({ increasedWeight });
    } catch (error) {
        showToast(error.message, 'error');
    }
});

document.getElementById('save-strategy').addEventListener('click', async () => {
    const strategy = document.querySelector('input[name="missing-strategy"]:checked')?.value;
    if (!strategy) return;
    try {
        await saveConfiguration({ strategy });
    } catch (error) {
        showToast(error.message, 'error');
    }
});

document.getElementById('save-types').addEventListener('click', async () => {
    const types = Object.fromEntries([...document.querySelectorAll('[data-criterion-type]')].map(select => [select.dataset.criterionType, select.value]));
    try {
        await saveConfiguration({ types });
    } catch (error) {
        showToast(error.message, 'error');
    }
});

document.getElementById('compare-strategies').addEventListener('click', async event => {
    const button = event.currentTarget;
    button.disabled = true;
    button.textContent = 'Menghitung…';
    try {
        renderStrategyComparison(await api('compare-strategies'));
    } catch (error) {
        document.getElementById('strategy-comparison').hidden = true;
        document.getElementById('strategy-comparison-empty').hidden = false;
        document.getElementById('strategy-comparison-empty').textContent = error.message;
        showToast(error.message, 'error');
    } finally {
        button.disabled = false;
        button.textContent = 'Bandingkan strategi';
    }
});

document.getElementById('detail-form').addEventListener('submit', async event => {
    event.preventDefault();
    const id = document.getElementById('detail-id').value.trim();
    const scenario = document.querySelector('#page-detail .scenario-select').value || state.scenario;
    try {
        renderDetail(await api('detail', { query: { id, scenario } }));
    } catch (error) {
        showToast(error.message, 'error');
    }
});

window.addEventListener('resize', () => {
    if (state.chartData) drawDashboardCharts(state.chartData);
});

loadStatus().then(async status => {
    if (!status.hasDataset) return;
    try {
        await loadOverview('Baseline');
    } catch (error) {
        showToast(error.message, 'error');
    }
}).catch(error => showToast(error.message, 'error'));
