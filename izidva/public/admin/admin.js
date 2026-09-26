"use strict";
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const usd = (v, sign = false) => (v == null ? "—" : (sign && v > 0 ? "+" : "") + Number(v).toLocaleString("ru-RU", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " $");
const cls = (v) => (v > 0 ? "pos" : v < 0 ? "neg" : "");
const dt = (s) => (s ? new Date(s + (s.endsWith("Z") ? "" : "Z")).toLocaleString("ru-RU", { day: "2-digit", month: "2-digit", year: "2-digit", hour: "2-digit", minute: "2-digit" }) : "—");
const STRAT = { grid: "Сетка", trend: "Тренд", liquidation: "Ликвидации" };
const PROFILE = { conservative: "Консерв.", balanced: "Сбаланс.", aggressive: "Агрес." };
const REGIME = { trend_up: "Тренд ↑", trend_down: "Тренд ↓", range: "Боковик", high_volatility: "Волатильно" };

async function api(path, opts = {}) {
  const res = await fetch("api/index.php" + path, {
    credentials: "same-origin", ...opts,
    headers: { "Content-Type": "application/json", "X-Admin": "1", ...(opts.headers || {}) },
  });
  if (res.status === 401 && path !== "/login") { showLogin(); throw new Error("Требуется вход"); }
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(typeof data.detail === "string" ? data.detail : (data.detail || []).map((d) => d.msg).join("; ") || "Ошибка " + res.status);
  return data;
}
const post = (p, b) => api(p, { method: "POST", body: JSON.stringify(b || {}) });
function toast(t) { const el = $("toast"); el.textContent = t; el.hidden = false; clearTimeout(toast.t); toast.t = setTimeout(() => (el.hidden = true), 3500); }
function kpi(label, value, sub = "", klass = "") { return `<div class="kpi"><div class="l">${label}</div><div class="v num ${klass}">${value}</div><div class="s">${sub}</div></div>`; }
function table(cols, rows, onRow) {
  return `<div class="tbl-wrap"><table><thead><tr>${cols.map((c) => `<th>${c}</th>`).join("")}</tr></thead><tbody>${
    rows.length ? rows.join("") : `<tr><td colspan="${cols.length}" class="muted">Нет данных</td></tr>`}</tbody></table></div>`;
}

// ───────────── вход ─────────────
function showLogin() { $("appView").hidden = true; $("loginView").hidden = false; }
$("lBtn").onclick = async () => {
  try { const r = await post("/login", { username: $("lUser").value, password: $("lPass").value }); $("lPass").value = ""; start(r.username); }
  catch (e) { $("lErr").textContent = e.message; }
};
$("lPass").addEventListener("keydown", (e) => e.key === "Enter" && $("lBtn").click());
$("logout").onclick = async (e) => { e.preventDefault(); await post("/logout"); showLogin(); };
$("menuBtn").onclick = () => $("side").classList.toggle("open");

// ───────────── навигация ─────────────
const VIEWS = {};
let current = "overview";
document.querySelectorAll("#nav button").forEach((b) => (b.onclick = () => go(b.dataset.v)));
function go(v) {
  current = v;
  document.querySelectorAll("#nav button").forEach((b) => b.classList.toggle("on", b.dataset.v === v));
  $("title").textContent = document.querySelector(`#nav button[data-v="${v}"]`).textContent.slice(3);
  $("headActions").innerHTML = "";
  $("view").innerHTML = `<div class="muted">Загрузка…</div>`;
  $("side").classList.remove("open");
  location.hash = v;
  VIEWS[v]().catch((e) => ($("view").innerHTML = `<div class="card note err">${esc(e.message)}</div>`));
}
function start(name) {
  $("meName").textContent = "👤 " + name; $("loginView").hidden = true; $("appView").hidden = false;
  const v = location.hash.slice(1);
  go(v in VIEWS ? v : "overview");
}

// ───────────── график доходов/расходов ─────────────
function barsChart(el, rows) {
  const W = el.clientWidth || 800, H = 220, pl = 48, pr = 8, pt = 10, pb = 24;
  const max = Math.max(1, ...rows.map((r) => Math.max(r.income, r.expense)));
  const n = rows.length, band = (W - pl - pr) / n, bw = Math.max(2, Math.min(14, band / 2 - 2));
  const y = (v) => pt + (1 - v / max) * (H - pt - pb);
  const ticks = [0, 0.5, 1].map((k) => max * k);
  let bars = "";
  rows.forEach((r, i) => {
    const x = pl + i * band + band / 2;
    bars += `<rect x="${x - bw - 1}" y="${y(r.income)}" width="${bw}" height="${H - pb - y(r.income)}" rx="2" fill="#3987e5"/>`;
    bars += `<rect x="${x + 1}" y="${y(r.expense)}" width="${bw}" height="${H - pb - y(r.expense)}" rx="2" fill="#d95926"/>`;
    bars += `<rect x="${pl + i * band}" y="0" width="${band}" height="${H}" fill="transparent" data-i="${i}"/>`;
  });
  const every = Math.ceil(n / 8);
  el.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="Доходы и расходы по дням">
    ${ticks.map((t) => `<line x1="${pl}" x2="${W - pr}" y1="${y(t)}" y2="${y(t)}" stroke="#262a33"/><text x="${pl - 6}" y="${y(t) + 4}" fill="#7d8290" font-size="10" text-anchor="end">${max < 10 ? t.toFixed(1) : Math.round(t)}$</text>`).join("")}
    ${rows.map((r, i) => (i % every ? "" : `<text x="${pl + i * band + band / 2}" y="${H - 6}" fill="#7d8290" font-size="10" text-anchor="middle">${r.date.slice(8)}.${r.date.slice(5, 7)}</text>`)).join("")}
    ${bars}</svg>`;
  const tip = $("tip");
  el.querySelectorAll("rect[data-i]").forEach((r) => {
    r.onmousemove = (e) => {
      const d = rows[r.dataset.i];
      tip.innerHTML = `<b>${d.date}</b><br>Доход: ${usd(d.income)} <span class="muted">(подписки ${usd(d.subs)})</span><br>Расход: ${usd(d.expense)} <span class="muted">(ИИ ${usd(d.ai)})</span><br>Прибыль: <b class="${cls(d.profit)}">${usd(d.profit, true)}</b><br>Новых клиентов: ${d.new_users} · сделок: ${d.trades}`;
      tip.hidden = false; tip.style.left = Math.min(innerWidth - 260, e.clientX + 14) + "px"; tip.style.top = e.clientY - 20 + "px";
    };
    r.onmouseleave = () => (tip.hidden = true);
  });
}

// ───────────── обзор ─────────────
function engineBlock(e) {
  if (!e.alive) {
    return `<div class="note err">⛔ Движок не запущен${e.heartbeat_age_sec != null ? ` (последний сигнал ${Math.round(e.heartbeat_age_sec / 60)} мин назад)` : ""}.</div>
      <p class="small">Торговля не идёт. Запустите его один раз в терминале сервера:</p>
      <pre class="log">${esc(e.service_command)}</pre>
      <p class="muted small">Проверка: <code>systemctl status izidva-engine</code> · логи: storage/logs/app.log</p>`;
  }
  return `<div class="muted small">✅ работает · клиентов: ${e.workers} · ИИ: ${e.ai_enabled ? "подключён" : "нет ключа — алгоритм"} · ликвидации: ${e.ws ? "поток подключён" : "нет потока"}</div>` +
    table(["Монета", "Цена", "Режим", "Источник", "Ликвидации 1ч (лонг/шорт)"], e.symbols.map((s) =>
      `<tr><td>${s.symbol}</td><td class="num">${s.price ?? "—"}</td><td>${REGIME[s.regime] || "—"}</td><td>${s.source === "ai" ? "ИИ" : s.source ? "алгоритм" : "—"}</td><td class="num">${Math.round(s.liq_long_1h / 1000)}k / ${Math.round(s.liq_short_1h / 1000)}k $</td></tr>`));
}
VIEWS.overview = async () => {
  const days = Number(localStorage.getItem("ovDays") || 30);
  const d = await api("/overview?days=" + days);
  const k = d.kpi;
  $("headActions").innerHTML = `<select id="ovDays">${[7, 30, 90, 365].map((x) => `<option value="${x}" ${x === days ? "selected" : ""}>${x} дней</option>`).join("")}</select>`;
  $("ovDays").onchange = (e) => { localStorage.setItem("ovDays", e.target.value); go("overview"); };
  $("view").innerHTML = `
    <div class="grid kpis">
      ${kpi("Клиенты", k.users_total, `+${k.users_new_7d} за 7 дней`)}
      ${kpi("Активные подписки", k.subs_active, `рефералов: ${k.referrals}`)}
      ${kpi("Боты работают", k.bots_running, `на бирже: ${k.bots_on_exchange}`)}
      ${kpi("Подключили Bybit", k.exchange_connected)}
      ${kpi("Доход за период", usd(k.income_period), `сегодня ${usd(k.income_today)}`)}
      ${kpi("Расход за период", usd(k.expense_period), `ИИ ${usd(k.ai_cost_period)}`)}
      ${kpi("Прибыль за период", usd(k.profit_period, true), "доход − расход", cls(k.profit_period))}
      ${kpi("Всего выручка", usd(k.revenue_all), `⭐ ${k.stars_all}`)}
      ${kpi("PnL клиентов", usd(k.clients_pnl_period, true), `сделок: ${k.trades_period}`, cls(k.clients_pnl_period))}
      ${kpi("ИИ всего", usd(k.ai_cost_all), "расход на Claude")}
    </div>
    <div class="card"><h3>Доходы и расходы по дням</h3><div class="chart" id="finChart"></div>
      <div class="legend"><span><i style="background:#3987e5"></i>Доход</span><span><i style="background:#d95926"></i>Расход</span></div></div>
    <div class="grid cols2">
      <div class="card"><h3>Торговый движок</h3>${engineBlock(d.engine)}</div>
      <div class="card"><h3>Последние оплаты</h3>${table(["Клиент", "Тариф", "⭐", "$", "Когда"], d.recent_payments.map((p) => `<tr><td>${esc(p.user)}</td><td>${p.plan}</td><td>${p.stars}</td><td>${usd(p.usd)}</td><td>${dt(p.at)}</td></tr>`))}
        <h3 style="margin-top:14px">Действия администраторов</h3>${table(["Кто", "Действие", "Когда"], d.recent_audit.map((a) => `<tr><td>${esc(a.actor)}</td><td>${esc(a.action)} <span class="muted small">${esc(a.details || "")}</span></td><td>${dt(a.at)}</td></tr>`))}</div>
    </div>`;
  barsChart($("finChart"), d.series);
};

// ───────────── клиенты ─────────────
VIEWS.users = async () => {
  $("headActions").innerHTML = `<a class="btn ghost" href="api/index.php/export/users.csv">⬇ CSV</a>`;
  $("view").innerHTML = `<div class="card"><div class="row">
      <input class="inp" id="uq" placeholder="Поиск: имя, @username или ID">
      <select id="uf"><option value="all">Все</option><option value="subscribed">С подпиской</option><option value="running">Бот работает</option><option value="exchange">Подключили Bybit</option><option value="blocked">Заблокированные</option></select>
    </div></div><div class="card" id="ulist">Загрузка…</div>`;
  const load = async () => {
    const rows = await api(`/users?q=${encodeURIComponent($("uq").value)}&filter=${$("uf").value}`);
    $("ulist").innerHTML = `<div class="muted small" style="margin-bottom:8px">Найдено: ${rows.length}</div>` + table(
      ["ID", "Клиент", "Подписка", "Бот", "Режим", "Профиль", "Bybit", "PnL 30д", "Оплачено", "Был"],
      rows.map((u) => `<tr class="click" data-id="${u.id}"><td class="num">${u.id}</td>
        <td>${esc(u.name || "")} ${u.username ? `<span class="muted">@${esc(u.username)}</span>` : ""} ${u.blocked ? '<span class="tag bad">блок</span>' : ""}</td>
        <td>${u.has_sub ? `<span class="tag ok">до ${dt(u.sub_until).slice(0, 8)}</span>` : '<span class="tag">нет</span>'}</td>
        <td>${u.running ? '<span class="tag ok">работает</span>' : '<span class="tag">стоп</span>'}</td>
        <td>${u.mode === "exchange" ? "биржа" : "демо"}</td><td>${PROFILE[u.profile] || ""}</td>
        <td>${u.exchange ? `<span class="tag ${u.referral ? "ok" : "warn"}">${u.exchange}${u.referral ? " · реф" : ""}</span>` : "—"}</td>
        <td class="num ${cls(u.pnl_30d)}">${usd(u.pnl_30d, true)}</td><td class="num">${usd(u.paid_usd)}</td><td>${dt(u.last_seen)}</td></tr>`));
    $("ulist").querySelectorAll("tr.click").forEach((tr) => (tr.onclick = () => openUser(tr.dataset.id)));
  };
  let t; $("uq").oninput = () => { clearTimeout(t); t = setTimeout(load, 300); };
  $("uf").onchange = load;
  await load();
};

function closeModal() { $("modal").hidden = true; $("modalBg").hidden = true; }
$("modalBg").onclick = closeModal;
async function openUser(id) {
  const d = await api("/users/" + id);
  const u = d.user;
  const act = async (action, extra = {}) => {
    try { await post(`/users/${id}/action`, { action, ...extra }); toast("Готово"); openUser(id); if (current === "users") VIEWS.users(); }
    catch (e) { toast(e.message); }
  };
  $("modal").innerHTML = `
    <div class="head"><h2>${esc(u.name || "")} ${u.username ? `<span class="muted">@${esc(u.username)}</span>` : ""} <span class="muted small">ID ${u.id}</span></h2><button class="btn" id="mClose">✕</button></div>
    <div class="grid kpis">
      ${kpi("Подписка", u.has_sub ? "до " + dt(u.sub_until).slice(0, 8) : "нет")}
      ${kpi("Бот", u.running ? "работает" : "остановлен", esc(d.live_status || ""))}
      ${kpi("Режим", u.mode === "exchange" ? "Bybit" : "Демо", PROFILE[u.profile] || "")}
      ${kpi("PnL всего", usd(d.totals.pnl, true), `${d.totals.trades} сделок · ${d.totals.trades ? Math.round(d.totals.wins / d.totals.trades * 100) : 0}% прибыльных`, cls(d.totals.pnl))}
      ${kpi("Баланс", d.totals.equity != null ? usd(d.totals.equity) : (d.settings ? usd(d.settings.paper_balance) : "—"))}
      ${kpi("Оплачено", usd(u.paid_usd))}
    </div>
    <div class="grid cols2">
      <div class="card"><h3>Управление</h3>
        <div class="row"><input class="inp" id="mDays" type="number" value="30" style="max-width:90px"><button class="btn primary" id="mExtend">+ дней подписки</button><button class="btn danger" id="mCancel">Отменить подписку</button></div>
        <div class="row" style="margin-top:8px">
          <button class="btn" id="mRun">${u.running ? "⏹ Остановить бота" : "▶ Запустить бота"}</button>
          <button class="btn ${u.blocked ? "" : "danger"}" id="mBlock">${u.blocked ? "Разблокировать" : "Заблокировать"}</button>
          <button class="btn ghost" id="mReset">Сбросить демо-счёт</button>
        </div>
        <label>Риск-профиль<select id="mProfile">${Object.entries(PROFILE).map(([k, v]) => `<option value="${k}" ${k === u.profile ? "selected" : ""}>${v}</option>`).join("")}</select></label>
        <label>Сообщение клиенту в Telegram<textarea id="mMsg" rows="2"></textarea></label><button class="btn" id="mSend" style="margin-top:6px">Отправить</button>
        <label>Заметки (видны только админам)<textarea id="mNotes" rows="2">${esc(u.notes || "")}</textarea></label><button class="btn ghost" id="mSaveNotes" style="margin-top:6px">Сохранить заметку</button>
      </div>
      <div class="card"><h3>Биржа и настройки</h3>
        ${d.exchange ? `<div>Bybit UID <b>${esc(d.exchange.uid)}</b> · ${d.exchange.mode === "live" ? "реальный счёт" : "демо"} · ${d.exchange.referral_ok ? '<span class="tag ok">реферал</span>' : '<span class="tag warn">не реферал</span>'}</div>
          <div class="muted small">подключён ${dt(d.exchange.connected_at)} ${d.exchange.last_error ? "· ошибка: " + esc(d.exchange.last_error) : ""}</div>
          <button class="btn danger" id="mDisconnect" style="margin-top:8px">Отключить биржу</button>` : '<div class="muted">Bybit не подключён. API-ключи клиентов хранятся зашифрованными и в панели не показываются.</div>'}
        ${d.settings ? `<div style="margin-top:12px">Монеты: ${d.settings.symbols.map((s) => `<span class="tag">${s}</span>`).join(" ")}</div>
          <div style="margin-top:6px">Стратегии: ${Object.entries(d.settings.strategies || {}).map(([k, v]) => `<span class="tag ${v ? "ok" : ""}">${STRAT[k]}</span>`).join(" ")}</div>` : ""}
        ${d.live && (d.live.grids.length || d.live.directional.length) ? `<h3 style="margin-top:14px">Сейчас в работе</h3>` +
          d.live.grids.map((g) => `<div>${g.symbol} · сетка ${g.mode} · ${g.filled}/${g.levels}</div>`).join("") +
          d.live.directional.map((x) => `<div>${x.symbol} · ${STRAT[x.strategy]} · ${x.side} от ${x.entry}</div>`).join("") : ""}
      </div>
    </div>
    <div class="card"><h3>Сделки</h3>${table(["Когда", "Монета", "Стратегия", "Сторона", "Вход → выход", "PnL", "Режим"],
      d.trades.map((t) => `<tr><td>${dt(t.at)}</td><td>${t.symbol}</td><td>${STRAT[t.strategy] || t.strategy}</td><td>${t.side}</td><td class="num">${t.entry} → ${t.exit}</td><td class="num ${cls(t.pnl)}">${usd(t.pnl, true)}</td><td>${t.mode}</td></tr>`))}</div>
    <div class="card"><h3>Платежи</h3>${table(["Когда", "Тариф", "⭐", "$"], d.payments.map((p) => `<tr><td>${dt(p.at)}</td><td>${p.plan}</td><td>${p.stars}</td><td>${usd(p.usd)}</td></tr>`))}</div>`;
  $("modal").hidden = false; $("modalBg").hidden = false;
  $("mClose").onclick = closeModal;
  $("mExtend").onclick = () => act("extend", { days: Number($("mDays").value) });
  $("mCancel").onclick = () => confirm("Отменить подписку клиента?") && act("cancel_sub");
  $("mRun").onclick = () => act(u.running ? "stop" : "start");
  $("mBlock").onclick = () => act(u.blocked ? "unblock" : "block");
  $("mReset").onclick = () => act("reset_paper");
  $("mProfile").onchange = (e) => act("profile", { value: e.target.value });
  $("mSend").onclick = () => act("message", { value: $("mMsg").value });
  $("mSaveNotes").onclick = () => act("notes", { value: $("mNotes").value });
  if ($("mDisconnect")) $("mDisconnect").onclick = () => confirm("Отключить Bybit у клиента? Бот на бирже остановится.") && act("disconnect_exchange");
}

// ───────────── платежи ─────────────
VIEWS.payments = async () => {
  $("headActions").innerHTML = `<a class="btn ghost" href="api/index.php/export/payments.csv">⬇ CSV</a>`;
  const rows = await api("/payments");
  const total = rows.reduce((a, p) => a + p.usd, 0), stars = rows.reduce((a, p) => a + p.stars, 0);
  $("view").innerHTML = `<div class="grid kpis">${kpi("Платежей", rows.length)}${kpi("Звёзд", stars)}${kpi("Сумма", usd(total))}</div>
    <div class="card">${table(["Когда", "Клиент", "Тариф", "⭐", "$", "ID платежа"], rows.map((p) =>
      `<tr class="click" data-id="${p.user_id}"><td>${dt(p.at)}</td><td>${esc(p.user)}</td><td>${p.plan}</td><td>${p.stars}</td><td>${usd(p.usd)}</td><td class="muted small">${esc(p.charge_id)}</td></tr>`))}</div>`;
  $("view").querySelectorAll("tr.click").forEach((tr) => (tr.onclick = () => openUser(tr.dataset.id)));
};

// ───────────── доходы и расходы ─────────────
VIEWS.finance = async () => {
  const days = Number(localStorage.getItem("finDays") || 30);
  const d = await api("/finance?days=" + days);
  const t = d.totals;
  $("headActions").innerHTML = `<select id="finDays">${[7, 30, 90, 365].map((x) => `<option value="${x}" ${x === days ? "selected" : ""}>${x} дней</option>`).join("")}</select><a class="btn ghost" href="api/index.php/export/finance.csv">⬇ CSV</a>`;
  $("finDays").onchange = (e) => { localStorage.setItem("finDays", e.target.value); go("finance"); };
  $("view").innerHTML = `
    <div class="grid kpis">
      ${kpi("Подписки", usd(t.subs))}${kpi("Другие доходы", usd(t.income_other))}${kpi("Итого доход", usd(t.income))}
      ${kpi("ИИ (Claude)", usd(t.ai))}${kpi("Другие расходы", usd(t.expense_other))}${kpi("Итого расход", usd(t.expense))}
      ${kpi("Прибыль", usd(t.profit, true), "", cls(t.profit))}
    </div>
    <div class="card"><h3>По дням</h3><div class="chart" id="finChart"></div>
      <div class="legend"><span><i style="background:#3987e5"></i>Доход</span><span><i style="background:#d95926"></i>Расход</span></div></div>
    <div class="grid cols2">
      <div class="card"><h3>Добавить запись</h3>
        <div class="grid2">
          <label>Тип<select id="fKind"><option value="expense">Расход</option><option value="income">Доход</option></select></label>
          <label>Категория<input class="inp" id="fCat" list="cats" placeholder="Сервер"><datalist id="cats"><option>Сервер</option><option>Реклама</option><option>Домен</option><option>Реферальные Bybit</option><option>Зарплата</option><option>Прочее</option></datalist></label>
          <label>Сумма, $<input class="inp" id="fAmt" type="number" step="0.01" min="0"></label>
          <label>Дата<input class="inp" id="fDate" type="date"></label>
        </div>
        <label>Комментарий<input class="inp" id="fNote"></label>
        <button class="btn primary" id="fAdd" style="margin-top:10px">Добавить</button>
      </div>
      <div class="card"><h3>Расход на ИИ по моделям</h3>${table(["Модель", "Тип", "Запросов", "Токены вход/выход", "$"], d.ai_usage.map((a) =>
        `<tr><td>${esc(a.model)}</td><td>${a.kind === "review" ? "разбор дня" : "анализ"}</td><td>${a.calls}</td><td class="num">${a.input_tokens.toLocaleString("ru-RU")} / ${a.output_tokens.toLocaleString("ru-RU")}</td><td class="num">${usd(a.cost_usd)}</td></tr>`))}</div>
    </div>
    <div class="card"><h3>Записи</h3>${table(["Дата", "Тип", "Категория", "Сумма", "Комментарий", "Кто", ""], d.entries.map((e) =>
      `<tr><td>${dt(e.date).slice(0, 8)}</td><td>${e.kind === "income" ? '<span class="tag ok">доход</span>' : '<span class="tag bad">расход</span>'}</td><td>${esc(e.category)}</td><td class="num">${usd(e.amount_usd)}</td><td>${esc(e.note || "")}</td><td class="muted">${esc(e.by || "")}</td><td><button class="btn danger" data-del="${e.id}">✕</button></td></tr>`))}</div>`;
  barsChart($("finChart"), d.series);
  $("fDate").valueAsDate = new Date();
  $("fAdd").onclick = async () => {
    try {
      await post("/finance", { kind: $("fKind").value, category: $("fCat").value || "Прочее", amount_usd: Number($("fAmt").value), note: $("fNote").value, date: $("fDate").value ? $("fDate").value + "T12:00:00" : null });
      toast("Запись добавлена"); go("finance");
    } catch (e) { toast(e.message); }
  };
  $("view").querySelectorAll("[data-del]").forEach((b) => (b.onclick = async () => { if (!confirm("Удалить запись?")) return; await api("/finance/" + b.dataset.del, { method: "DELETE" }); go("finance"); }));
};

// ───────────── сделки ─────────────
VIEWS.trades = async () => {
  $("headActions").innerHTML = `<a class="btn ghost" href="api/index.php/export/trades.csv">⬇ CSV</a>`;
  $("view").innerHTML = `<div class="card"><div class="row">
    <input class="inp" id="tUser" placeholder="ID клиента"><input class="inp" id="tSym" placeholder="Монета (BTCUSDT)">
    <select id="tStrat"><option value="">Все стратегии</option>${Object.entries(STRAT).map(([k, v]) => `<option value="${k}">${v}</option>`).join("")}</select>
    <select id="tMode"><option value="">Все режимы</option><option value="paper">демо</option><option value="demo">Bybit демо</option><option value="live">Bybit реальный</option></select>
    <button class="btn primary" id="tGo">Показать</button></div></div><div class="card" id="tList"></div>`;
  const load = async () => {
    const q = new URLSearchParams({ symbol: $("tSym").value, strategy: $("tStrat").value, mode: $("tMode").value });
    if ($("tUser").value) q.set("user_id", $("tUser").value);
    const rows = await api("/trades?" + q);
    const pnl = rows.reduce((a, t) => a + t.pnl, 0), wins = rows.filter((t) => t.pnl > 0).length;
    $("tList").innerHTML = `<div class="muted small" style="margin-bottom:8px">Сделок: ${rows.length} · PnL: <span class="${cls(pnl)}">${usd(pnl, true)}</span> · прибыльных ${rows.length ? Math.round(wins / rows.length * 100) : 0}%</div>` +
      table(["Когда", "Клиент", "Монета", "Стратегия", "Сторона", "Вход → выход", "PnL", "R", "Режим рынка", "Счёт"], rows.map((t) =>
        `<tr class="click" data-id="${t.user_id}"><td>${dt(t.at)}</td><td class="num">${t.user_id}</td><td>${t.symbol}</td><td>${STRAT[t.strategy] || t.strategy}</td><td>${t.side}</td><td class="num">${t.entry} → ${t.exit}</td><td class="num ${cls(t.pnl)}">${usd(t.pnl, true)}</td><td class="num">${t.r}</td><td>${REGIME[t.regime] || t.regime || ""}</td><td>${t.mode}</td></tr>`));
    $("tList").querySelectorAll("tr.click").forEach((tr) => (tr.onclick = () => openUser(tr.dataset.id)));
  };
  $("tGo").onclick = load;
  await load();
};

// ───────────── ИИ и рынок ─────────────
VIEWS.ai = async () => {
  const d = await api("/ai");
  $("headActions").innerHTML = `<button class="btn primary" id="aiRun">🧠 Запустить анализ сейчас</button>`;
  $("aiRun").onclick = async () => { try { await post("/ai/run"); toast("Анализ запущен — обновится в течение минуты"); } catch (e) { toast(e.message); } };
  const tun = Object.fromEntries(d.tuning.map((t) => [t.symbol, t.params]));
  const syms = d.symbols;
  $("view").innerHTML = `
    <div class="card"><h3>Рынок сейчас <span class="muted small">· ИИ ${d.engine.ai_enabled ? "подключён" : "не подключён (нужен ключ Anthropic)"}${d.engine.alive ? "" : " · ⛔ движок не запущен"}</span></h3>
      ${table(["Монета", "Цена", "Режим", "Сетка", "Шаг, ATR", "Веса сетка/тренд/ликв.", "Риск", "Источник", "Вывод"], d.market.map((m) =>
        `<tr><td>${m.symbol}</td><td class="num">${m.price}</td><td>${REGIME[m.regime] || m.regime}</td><td>${m.grid_mode}</td><td>${Number(m.grid_step_atr).toFixed(2)}</td><td class="num">${[m.w_grid, m.w_trend, m.w_liquidation].map((w) => Math.round(w * 100)).join(" / ")}</td><td>×${Number(m.risk_mult).toFixed(2)}</td><td>${m.source === "ai" ? "ИИ" : "алгоритм"}</td><td class="small">${esc(m.summary)}</td></tr>`))}</div>
    <div class="grid cols2">
      <div class="card"><h3>Уроки (память ИИ)</h3>
        <div class="row"><input class="inp" id="lText" placeholder="Добавить свой урок / правило для ИИ"><button class="btn primary" id="lAdd">Добавить</button></div>
        ${table(["Урок", "Когда", ""], d.lessons.map((l) => `<tr><td>${esc(l.text)}</td><td class="muted small">${dt(l.at)}</td><td><button class="btn danger" data-del="${l.id}">✕</button></td></tr>`))}</div>
      <div class="card"><h3>Подстройка параметров</h3>
        <div class="muted small">ИИ меняет их раз в сутки. Можно задать вручную.</div>
        ${table(["Монета", ...Object.keys(d.tunable), ""], syms.map((s) => `<tr><td>${s}</td>${Object.entries(d.tunable).map(([k, b]) =>
          `<td><input class="inp" style="width:80px" type="number" step="0.05" min="${b.min}" max="${b.max}" data-s="${s}" data-k="${k}" value="${(tun[s] || {})[k] ?? b.default}"></td>`).join("")}<td><button class="btn" data-save="${s}">💾</button></td></tr>`))}</div>
    </div>
    <div class="card"><h3>Статистика обучения</h3><div class="muted small">Результат стратегий по связкам «монета | стратегия | режим» по всем клиентам.</div>
      ${table(["Связка", "Сделок", "Винрейт", "Средний R"], d.stats.map((x) => `<tr><td>${esc(x.key)}</td><td>${x.n}</td><td>${x.winrate}%</td><td class="num ${cls(x.ewma_r)}">${x.ewma_r}</td></tr>`))}</div>
    <div class="card"><h3>История анализов</h3>${table(["Когда", "Монета", "Режим", "Источник", "Вывод"], d.history.map((h) =>
      `<tr><td>${dt(h.at)}</td><td>${h.symbol}</td><td>${REGIME[h.regime] || h.regime}</td><td>${h.source === "ai" ? "ИИ" : "алгоритм"}</td><td class="small">${esc(h.summary)}</td></tr>`))}</div>`;
  $("lAdd").onclick = async () => { try { await post("/ai/lessons", { text: $("lText").value }); go("ai"); } catch (e) { toast(e.message); } };
  $("view").querySelectorAll("[data-del]").forEach((b) => (b.onclick = async () => { await api("/ai/lessons/" + b.dataset.del, { method: "DELETE" }); go("ai"); }));
  $("view").querySelectorAll("[data-save]").forEach((b) => (b.onclick = async () => {
    const s = b.dataset.save, body = {};
    $("view").querySelectorAll(`input[data-s="${s}"]`).forEach((i) => (body[i.dataset.k] = Number(i.value)));
    try { await api("/ai/tuning/" + s, { method: "PUT", body: JSON.stringify(body) }); toast(s + ": сохранено"); } catch (e) { toast(e.message); }
  }));
};

// ───────────── рассылка ─────────────
VIEWS.broadcast = async () => {
  $("view").innerHTML = `<div class="card" style="max-width:640px"><h3>Сообщение клиентам в Telegram</h3>
    <label>Кому<select id="bAud"><option value="all">Всем клиентам</option><option value="subscribers">С активной подпиской</option><option value="no_subscription">Без подписки</option><option value="running">У кого работает бот</option></select></label>
    <label>Текст<textarea id="bText" rows="6" placeholder="Например: 🔥 Скидка 20% на подписку до конца недели"></textarea></label>
    <button class="btn primary" id="bSend" style="margin-top:10px">Отправить</button><div class="note" id="bRes"></div></div>`;
  $("bSend").onclick = async () => {
    if (!confirm("Отправить сообщение?")) return;
    try { const r = await post("/broadcast", { text: $("bText").value, audience: $("bAud").value }); $("bRes").className = "note ok"; $("bRes").textContent = `Отправка ${r.recipients} клиентам запущена. Итог появится в журнале.`; }
    catch (e) { $("bRes").className = "note err"; $("bRes").textContent = e.message; }
  };
};

// ───────────── настройки ─────────────
VIEWS.settings = async () => {
  const fields = await api("/settings");
  $("headActions").innerHTML = `<button class="btn danger" id="restart">↻ Перезапустить торговый движок</button>`;
  $("restart").onclick = async () => {
    if (!confirm("Перезапустить движок? Сетки клиентов будут закрыты и через ~10 секунд запущены заново.")) return;
    await post("/system/restart"); toast("Команда отправлена — движок перезапустится в течение нескольких секунд");
  };
  const groups = {};
  fields.forEach((f) => (groups[f.group] = groups[f.group] || []).push(f));
  $("view").innerHTML = Object.entries(groups).map(([g, fs]) => `<div class="card set-group"><h3>${g}</h3>${fs.map((f) => {
    const id = "set_" + f.key;
    let input;
    if (f.type === "bool") input = `<select id="${id}"><option value="true" ${f.value ? "selected" : ""}>Да</option><option value="false" ${!f.value ? "selected" : ""}>Нет</option></select>`;
    else if (f.secret) input = `<input class="inp" id="${id}" type="password" value="${esc(f.value)}" placeholder="${f.is_set ? "" : "не задано"}" autocomplete="new-password">`;
    else input = `<input class="inp" id="${id}" value="${esc(f.value)}" ${f.type === "int" || f.type === "float" ? 'type="number" step="any"' : ""}>`;
    return `<div class="set-row"><div class="lbl"><b>${esc(f.label)}</b>${f.secret ? ` <span class="tag ${f.is_set ? "ok" : "warn"}">${f.is_set ? "задан" : "не задан"}</span>` : ""}
      ${f.restart ? ' <span class="tag">нужен перезапуск</span>' : ""}<div>${esc(f.help)}</div></div>${input}</div>`;
  }).join("")}</div>`).join("") + `<button class="btn primary big" id="saveSet" style="max-width:320px">💾 Сохранить настройки</button><div class="note" id="setRes"></div>
  <p class="muted small">Секретные ключи хранятся в базе в зашифрованном виде и показываются маской. Чтобы заменить ключ — сотрите маску и вставьте новый. Настройки применяются сразу, движок подхватывает их в течение минуты.</p>`;
  $("saveSet").onclick = async () => {
    const body = {};
    fields.forEach((f) => {
      const v = $("set_" + f.key).value;
      body[f.key] = f.type === "bool" ? v === "true" : f.type === "int" ? parseInt(v, 10) : f.type === "float" ? parseFloat(v) : v;
    });
    try {
      const r = await api("/settings", { method: "PUT", body: JSON.stringify(body) });
      $("setRes").className = "note ok";
      $("setRes").textContent = "Сохранено. " + (r.notes || []).join(". ") + (r.changed && r.changed.length ? "" : " Изменений нет.");
    } catch (e) { $("setRes").className = "note err"; $("setRes").textContent = e.message; }
  };
};

// ───────────── журнал ─────────────
VIEWS.logs = async () => {
  const d = await api("/logs?lines=300");
  $("headActions").innerHTML = `<button class="btn" id="lRef">↻ Обновить</button>`;
  $("lRef").onclick = () => go("logs");
  $("view").innerHTML = `<div class="card"><h3>Действия администраторов</h3>${table(["Когда", "Кто", "Действие", "Детали"], d.audit.map((a) =>
    `<tr><td>${dt(a.at)}</td><td>${esc(a.actor)}</td><td>${esc(a.action)}</td><td class="small muted">${esc(a.details || "")}</td></tr>`))}</div>
    <div class="card"><h3>Лог приложения (logs/app.log)</h3><pre class="log" id="appLog">${esc(d.app_log || "пусто")}</pre></div>`;
  const pre = $("appLog"); pre.scrollTop = pre.scrollHeight;
};

// ───────────── администраторы ─────────────
VIEWS.admins = async () => {
  const rows = await api("/admins");
  $("view").innerHTML = `<div class="grid cols2">
    <div class="card"><h3>Администраторы</h3>${table(["Логин", "Создан", "Последний вход", ""], rows.map((a) =>
      `<tr><td>${esc(a.username)}</td><td>${dt(a.created_at)}</td><td>${dt(a.last_login)}</td><td><button class="btn danger" data-del="${a.id}">✕</button></td></tr>`))}
      <h3 style="margin-top:14px">Добавить</h3><div class="grid2"><input class="inp" id="aUser" placeholder="логин"><input class="inp" id="aPass" type="password" placeholder="пароль от 8 символов"></div>
      <button class="btn primary" id="aAdd" style="margin-top:8px">Добавить администратора</button></div>
    <div class="card"><h3>Сменить мой пароль</h3>
      <label>Текущий пароль<input class="inp" id="pOld" type="password"></label><label>Новый пароль<input class="inp" id="pNew" type="password"></label>
      <button class="btn primary" id="pSave" style="margin-top:10px">Сменить</button></div></div>`;
  $("aAdd").onclick = async () => { try { await post("/admins", { username: $("aUser").value, password: $("aPass").value }); toast("Добавлен"); go("admins"); } catch (e) { toast(e.message); } };
  $("pSave").onclick = async () => { try { await post("/admins/password", { old_password: $("pOld").value, new_password: $("pNew").value }); toast("Пароль изменён"); $("pOld").value = $("pNew").value = ""; } catch (e) { toast(e.message); } };
  $("view").querySelectorAll("[data-del]").forEach((b) => (b.onclick = async () => { if (!confirm("Удалить администратора?")) return; try { await api("/admins/" + b.dataset.del, { method: "DELETE" }); go("admins"); } catch (e) { toast(e.message); } }));
};

// ───────────── старт ─────────────
api("/me").then((r) => start(r.username)).catch(() => showLogin());
