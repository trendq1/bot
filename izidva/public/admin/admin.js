"use strict";
const $ = (id) => document.getElementById(id);
const esc = (s) => String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c]));
const usd = (v, sign = false) => (v == null ? "—" : (sign && v > 0 ? "+" : "") + Number(v).toLocaleString("ru-RU", { minimumFractionDigits: 2, maximumFractionDigits: 2 }) + " $");
const cls = (v) => (v > 0 ? "pos" : v < 0 ? "neg" : "");
const dt = (s) => (s ? new Date(s + (s.endsWith("Z") ? "" : "Z")).toLocaleString("ru-RU", { day: "2-digit", month: "2-digit", year: "2-digit", hour: "2-digit", minute: "2-digit" }) : "—");
const STRAT = { grid: "Сетка", trend: "Тренд", liquidation: "Ликвидации", breakout: "Пробой 4h", signal: "Сигнал", manual: "Вручную" };
const PROFILE = { conservative: "Консерв.", balanced: "Сбаланс.", aggressive: "Агрес." };
const REGIME = { trend_up: "Тренд ↑", trend_down: "Тренд ↓", range: "Боковик", high_volatility: "Волатильно",
  strong_up: "Сильный тренд ↑", strong_down: "Сильный тренд ↓", weak_trend: "Неясно (не торгуем)", breakout: "Пробой",
  no_trade: "Нет сделки", manual: "Вручную", external: "Вне бота" };

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
  try { const r = await post("/login", { username: $("lUser").value, password: $("lPass").value }); $("lPass").value = ""; start(r.username, r.role); }
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
let myRole = "admin";
function start(name, role) {
  myRole = role || "admin";
  $("meName").textContent = "👤 " + name + (myRole === "trader" ? " · трейдер" : "");
  $("loginView").hidden = true; $("appView").hidden = false;
  if (myRole === "trader") {
    document.querySelectorAll("#nav button").forEach((b) => (b.hidden = !["manual", "orders"].includes(b.dataset.v)));
    go("manual");
    return;
  }
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
  analysisToggle().catch(() => {});
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
    <div class="card"><h3>Trade Analytics <span class="muted small">— не Win Rate, а реальная математика сделок</span></h3><div class="grid kpis" id="taKpis">Загрузка…</div><div id="taBreak"></div></div>
    <div class="grid cols2">
      <div class="card"><h3>Торговый движок</h3>${engineBlock(d.engine)}</div>
      <div class="card"><h3>Последние оплаты</h3>${table(["Клиент", "Тариф", "⭐", "$", "Когда"], d.recent_payments.map((p) => `<tr><td>${esc(p.user)}</td><td>${p.plan}</td><td>${p.stars}</td><td>${usd(p.usd)}</td><td>${dt(p.at)}</td></tr>`))}
        <h3 style="margin-top:14px">Действия администраторов</h3>${table(["Кто", "Действие", "Когда"], d.recent_audit.map((a) => `<tr><td>${esc(a.actor)}</td><td>${esc(a.action)} <span class="muted small">${esc(a.details || "")}</span></td><td>${dt(a.at)}</td></tr>`))}</div>
    </div>`;
  barsChart($("finChart"), d.series);
  api("/analytics?days=" + days).then((t) => {
    const nz = (v) => (v == null ? "—" : v);
    const g = t.grid_sessions || {};
    $("taKpis").innerHTML = `
      ${kpi("Profit Factor", nz(t.profit_factor), t.profit_factor != null && t.profit_factor < 1 ? "< 1 — убыточно" : "", t.profit_factor != null ? cls(t.profit_factor - 1) : "")}
      ${kpi("Expectancy / сделку", usd(t.expectancy_usd, true), `${t.trades} сделок · ${nz(t.expectancy_r)}R`, cls(t.expectancy_usd))}
      ${kpi("Net PnL за период", usd(t.net_pnl, true), `Win Rate ${t.win_rate_pct}% (не главное)`, cls(t.net_pnl))}
      ${kpi("Ср. плюс / ср. минус", `${usd(t.avg_win)} / ${usd(t.avg_loss)}`, `отношение ${nz(t.win_loss_ratio)}`)}
      ${kpi("Крупнейший убыток", usd(t.largest_loss), `крупнейший плюс ${usd(t.largest_win)}`)}
      ${kpi("Хвост убытков", `${t.tail_loss_share_pct.top5}%`, `топ-1%: ${t.tail_loss_share_pct.top1}% · топ-10%: ${t.tail_loss_share_pct.top10}% всех убытков`)}
      ${kpi("Просадка (реал. PnL)", usd(t.max_drawdown), `Recovery factor ${nz(t.recovery_factor)}`)}
      ${kpi("Сессии сетки", `${g.sessions || 0} · PF ${nz(g.profit_factor)}`, `1 стоп = ${nz(g.cycles_per_stop)} циклов · худшая ${usd(g.worst_session || 0)}`, g.profit_factor != null ? cls(g.profit_factor - 1) : "")}
    `;
    const rows = (obj, label) => Object.entries(obj || {}).map(([k, v]) => `<tr><td>${esc(label(k))}</td><td class="num">${v.trades}</td>
      <td class="num ${cls(v.net_pnl)}">${usd(v.net_pnl, true)}</td><td class="num">${nz(v.profit_factor)}</td><td class="num">${usd(v.expectancy_usd, true)}</td>
      <td class="num">${v.win_rate_pct}%</td><td class="num">${usd(v.largest_loss)}</td></tr>`);
    const head = ["", "Сделок", "Net", "PF", "Expectancy", "Win Rate", "Худшая"];
    $("taBreak").innerHTML = `<div class="grid cols2">
      <div><h4>По стратегиям</h4>${table(head, rows(t.by_strategy, (k) => STRAT[k] || k))}</div>
      <div><h4>По режимам рынка</h4>${table(head, rows(t.by_regime, (k) => REGIME[k] || k))}</div>
      <div><h4>По монетам</h4>${table(head, rows(t.by_symbol, (k) => k))}</div>
      <div><h4>По часам (UTC)</h4>${table(head, rows(t.by_hour_utc, (k) => k + ":00"))}</div></div>`;
  }).catch(() => { $("taKpis").innerHTML = '<div class="muted">Нет данных</div>'; });
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
    <div class="card">${table(["Когда", "Клиент", "Тариф", "Способ", "⭐", "$", "ID платежа"], rows.map((p) =>
      `<tr class="click" data-id="${p.user_id}"><td>${dt(p.at)}</td><td>${esc(p.user)}</td><td>${p.plan}</td>
        <td><span class="tag ${p.method === "crypto" ? "ok" : ""}">${p.method === "crypto" ? "₿ крипта" : "⭐ звёзды"}</span></td>
        <td>${p.stars || "—"}</td><td>${usd(p.usd)}</td><td class="muted small">${esc(p.charge_id)}</td></tr>`))}</div>`;
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
  const analysisOn = await analysisToggle();
  if (!analysisOn) $("aiRun").disabled = true;
  $("aiRun").onclick = async () => { try { await post("/ai/run"); toast("Анализ запущен — обновится в течение минуты"); } catch (e) { toast(e.message); } };
  const tun = Object.fromEntries(d.tuning.map((t) => [t.symbol, t.params]));
  const syms = d.symbols;
  $("view").innerHTML = `
    <div class="card"><h3>Рынок сейчас <span class="muted small">· ИИ ${d.engine.ai_enabled ? "подключён" : "не подключён (нужен ключ Anthropic)"}${d.engine.alive ? "" : " · ⛔ движок не запущен"}</span></h3>
      ${table(["Монета", "Цена", "Режим (жёсткий, 1h)", "Режим ИИ", "Сетка", "Шаг, ATR", "Веса сетка/тренд/ликв.", "Риск", "Источник", "Вывод"], d.market.map((m) =>
        `<tr><td>${m.symbol}</td><td class="num">${m.price}</td><td><b>${REGIME[m.hard_regime] || m.hard_regime}</b></td><td>${REGIME[m.regime] || m.regime}</td><td>${m.grid_mode}</td><td>${Number(m.grid_step_atr).toFixed(2)}</td><td class="num">${[m.w_grid, m.w_trend, m.w_liquidation].map((w) => Math.round(w * 100)).join(" / ")}</td><td>×${Number(m.risk_mult).toFixed(2)}</td><td>${m.source === "ai" ? "ИИ" : "алгоритм"}</td><td class="small">${esc(m.summary)}</td></tr>`))}</div>
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
function wrapSelection(ta, open, close) {
  const s = ta.selectionStart, e = ta.selectionEnd;
  const before = ta.value.slice(0, s), sel = ta.value.slice(s, e) || "текст", after = ta.value.slice(e);
  ta.value = before + open + sel + close + after;
  ta.focus();
  ta.selectionStart = s + open.length;
  ta.selectionEnd = s + open.length + sel.length;
}
let broadcastImage = null;
VIEWS.broadcast = async () => {
  broadcastImage = null;
  $("view").innerHTML = `<div class="card" style="max-width:640px"><h3>Сообщение клиентам в Telegram</h3>
    <label>Кому<select id="bAud"><option value="all">Всем клиентам</option><option value="subscribers">С активной подпиской</option><option value="no_subscription">Без подписки</option><option value="running">У кого работает бот</option></select></label>
    <label>Картинка (необязательно)<input class="inp" id="bImg" type="file" accept="image/png,image/jpeg,image/webp,image/gif"></label>
    <div id="bImgWrap" hidden style="margin-top:8px"><img id="bImgPreview" style="max-width:100%;max-height:220px;border-radius:10px;display:block"><button class="btn ghost small-btn" id="bImgClear" style="margin-top:6px">✕ Убрать картинку</button></div>
    <label>Текст<span class="muted small"> — выделите слово и нажмите кнопку форматирования</span></label>
    <div class="row" style="margin:4px 0 6px">
      <button class="btn" type="button" data-tag="b" title="Жирный"><b>Ж</b></button>
      <button class="btn" type="button" data-tag="i" title="Курсив"><i>К</i></button>
      <button class="btn" type="button" data-tag="u" title="Подчёркнутый"><u>Ч</u></button>
      <button class="btn" type="button" data-tag="s" title="Зачёркнутый"><s>С</s></button>
      <button class="btn" type="button" data-tag="code" title="Моноширинный">&lt;/&gt;</button>
      <button class="btn" type="button" data-tag="tg-spoiler" title="Спойлер">🙈</button>
      <button class="btn" type="button" id="bLink" title="Ссылка в тексте">🔗</button>
    </div>
    <textarea id="bText" rows="6" placeholder="Например: 🔥 Скидка 20% на подписку до конца недели"></textarea>
    <label>Кнопка под сообщением (необязательно)</label>
    <div class="grid2"><input class="inp" id="bBtnText" placeholder="Текст кнопки, например «Открыть»"><input class="inp" id="bBtnUrl" placeholder="https://..."></div>
    <button class="btn primary" id="bSend" style="margin-top:10px">Отправить</button><div class="note" id="bRes"></div></div>`;
  $("view").querySelectorAll("[data-tag]").forEach((b) => (b.onclick = () => wrapSelection($("bText"), `<${b.dataset.tag}>`, `</${b.dataset.tag}>`)));
  $("bLink").onclick = () => {
    const url = prompt("Ссылка (https://...)"); if (!url) return;
    wrapSelection($("bText"), `<a href="${url}">`, `</a>`);
  };
  $("bImg").onchange = () => {
    const f = $("bImg").files[0]; if (!f) return;
    if (f.size > 8 * 1024 * 1024) { toast("Картинка слишком большая (макс. 8 МБ)"); $("bImg").value = ""; return; }
    const r = new FileReader();
    r.onload = () => { broadcastImage = r.result; $("bImgPreview").src = r.result; $("bImgWrap").hidden = false; };
    r.readAsDataURL(f);
  };
  $("bImgClear").onclick = () => { broadcastImage = null; $("bImg").value = ""; $("bImgWrap").hidden = true; };
  $("bSend").onclick = async () => {
    if (!confirm("Отправить сообщение?")) return;
    try {
      const r = await post("/broadcast", { text: $("bText").value, audience: $("bAud").value, image: broadcastImage,
        button_text: $("bBtnText").value, button_url: $("bBtnUrl").value });
      $("bRes").className = "note ok"; $("bRes").textContent = `Отправка ${r.recipients} клиентам запущена. Итог появится в журнале.`;
    } catch (e) { $("bRes").className = "note err"; $("bRes").textContent = e.message; }
  };
};

// ───────────── меню бота ─────────────
VIEWS.menu = async () => {
  const rows = await api("/menu");
  $("view").innerHTML = `<div class="card" style="max-width:720px">
      <h3>Кнопки нижнего меню бота</h3>
      <p class="muted small">Показываются под сообщением /start и по команде /menu в боте. Открываются как ссылка.</p>
      ${table(["Название", "Ссылка", "Вкл.", ""], rows.map((m) => `<tr data-id="${m.id}">
        <td><input class="inp" data-f="title" value="${esc(m.title)}"></td>
        <td><input class="inp" data-f="url" value="${esc(m.url)}"></td>
        <td><input type="checkbox" data-f="enabled" ${m.enabled ? "checked" : ""}></td>
        <td class="row"><button class="btn" data-save="${m.id}">💾</button><button class="btn danger" data-del="${m.id}">✕</button></td></tr>`))}
      <h3 style="margin-top:14px">Добавить кнопку</h3>
      <div class="grid2"><input class="inp" id="mTitle" placeholder="Название, например «Наш канал»"><input class="inp" id="mUrl" placeholder="https://t.me/..."></div>
      <button class="btn primary" id="mAdd" style="margin-top:8px">Добавить</button><div class="note" id="mRes"></div>
    </div>`;
  $("mAdd").onclick = async () => {
    try { await post("/menu", { title: $("mTitle").value, url: $("mUrl").value }); toast("Кнопка добавлена"); go("menu"); }
    catch (e) { $("mRes").className = "note err"; $("mRes").textContent = e.message; }
  };
  $("view").querySelectorAll("[data-save]").forEach((b) => (b.onclick = async () => {
    const tr = b.closest("tr");
    const body = {};
    tr.querySelectorAll("[data-f]").forEach((i) => (body[i.dataset.f] = i.type === "checkbox" ? i.checked : i.value));
    try { await api("/menu/" + b.dataset.save, { method: "PUT", body: JSON.stringify(body) }); toast("Сохранено"); }
    catch (e) { toast(e.message); }
  }));
  $("view").querySelectorAll("[data-del]").forEach((b) => (b.onclick = async () => {
    if (!confirm("Удалить кнопку?")) return;
    try { await api("/menu/" + b.dataset.del, { method: "DELETE" }); go("menu"); } catch (e) { toast(e.message); }
  }));
};


// ───────────── ордера и позиции ─────────────
let ordTimer = null;
const ORD_ORIGIN = { target: "цель сигнала", signal: "вход по сигналу", manual: "ручной вход", grid: "сетка", other: "прочее" };
const POS_KIND = { grid: "сетка", trend: "тренд", breakout: "пробой", liquidation: "ликвидации", signal: "сигнал", manual: "вручную", external: "вне бота" };
const pct = (v, sign = true) => (v == null ? "—" : (sign && v > 0 ? "+" : "") + Number(v).toFixed(2) + " %");
const px = (v) => (v == null || v === 0 ? "—" : String(Number(Number(v).toPrecision(7))));
VIEWS.orders = async () => {
  clearInterval(ordTimer);
  const clients = await api("/manual/clients");
  $("view").innerHTML = `
    <div class="card"><div class="row"><label style="flex:1">Клиент<select id="odClient"><option value="0">Все клиенты</option>${clients.map((c) => `<option value="${c.id}">${esc(c.name)} · ${c.trading_mode === "exchange" ? (c.exchange || "биржа") : "демо"}${c.running ? "" : " · бот остановлен"}</option>`).join("")}</select></label>
      <span class="muted small" id="odStamp" style="align-self:flex-end">обновляется каждые 5 с</span></div></div>
    <div class="grid kpis" id="odKpis"></div>
    <div class="card"><h3>Открытые позиции</h3><div id="odPos">Загрузка…</div></div>
    <div class="card"><h3>Открытые ордера</h3><div id="odOrd"></div></div>
    <div class="card" id="odCliCard"><h3>Доходность по клиентам</h3><div id="odCli"></div></div>
    <div class="card"><h3>Последние закрытые сделки</h3><div id="odHist"></div></div>`;
  let data = null;
  const uid = () => Number($("odClient").value);
  const names = () => Object.fromEntries((data?.clients || []).map((c) => [c.id, c.name]));
  async function waitCommand(id) {
    for (let i = 0; i < 12; i++) {
      await new Promise((r) => setTimeout(r, 1000));
      const c = await api("/orders/command?id=" + id);
      if (c.done) { toast(c.result.startsWith("ok") ? "Готово: " + c.result.slice(4) : c.result.replace(/^error: /, "Ошибка: ")); load(); return; }
    }
    toast("Команда отправлена, движок ещё не ответил (бот остановлен?)");
  }
  async function send(path, body) {
    try { const r = await post(path, body); toast("Отправлено движку…"); waitCommand(r.command_id); } catch (e) { toast(e.message); }
  }
  function editDialog(p) {
    $("modal").innerHTML = `<div class="head"><h2>${esc(p.symbol)} · ${p.side === "Buy" ? "LONG" : "SHORT"} <span class="muted small">${esc(p.client)}</span></h2><button class="btn" id="mClose">✕</button></div>
      <div class="muted small" style="margin-bottom:10px">Вход ${px(p.entry)} · сейчас ${px(p.mark)}. Пустое поле — снять уровень.</div>
      <div class="grid2"><label>Стоп-лосс<input class="inp" id="edSl" type="number" step="any" value="${p.stop ?? ""}"></label>
      <label>Тейк-профит<input class="inp" id="edTp" type="number" step="any" value="${p.take ?? ""}"></label></div>
      <button class="btn primary big" id="edSave">Сохранить</button>`;
    $("modal").hidden = false; $("modalBg").hidden = false;
    $("mClose").onclick = closeModal;
    $("edSave").onclick = () => { closeModal(); send("/orders/edit", { user_id: p.user_id, symbol: p.symbol, stop_loss: $("edSl").value, take_profit: $("edTp").value }); };
  }
  function draw() {
    const s = data.summary, one = uid() !== 0;
    $("odKpis").innerHTML = `
      ${kpi("Баланс", usd(s.equity), one ? "" : `клиентов: ${s.clients}`)}
      ${kpi("Плавающий PnL", usd(s.upnl, true), pct(s.upnl_pct) + " от баланса", cls(s.upnl))}
      ${kpi("Реализовано сегодня", usd(s.realized_today, true), "", cls(s.realized_today))}
      ${kpi("Реализовано 7 дней", usd(s.realized_7d, true), "", cls(s.realized_7d))}
      ${kpi("Реализовано 30 дней", usd(s.realized_30d, true), pct(s.realized_30d_pct) + " от баланса", cls(s.realized_30d))}
      ${kpi("Win Rate 30 дней", s.win_rate_30d == null ? "—" : s.win_rate_30d + " %", `сделок: ${s.trades_30d}`)}
      ${kpi("Открыто", `${s.positions} поз. / ${s.orders} орд.`, "")}`;
    $("odPos").innerHTML = table(["Клиент", "Монета", "Сторона", "Стратегия", "Объём", "Вход", "Цена", "SL", "TP", "PnL $", "PnL %", "% баланса", ""],
      data.positions.map((p, i) => `<tr><td>${esc(p.client)}</td><td><b>${esc(p.symbol)}</b></td><td>${p.side === "Buy" ? "LONG" : "SHORT"}</td><td><span class="tag">${POS_KIND[p.strategy] || esc(p.strategy)}</span></td>
        <td class="num">${p.qty}</td><td class="num">${px(p.entry)}</td><td class="num">${px(p.mark)}</td><td class="num">${px(p.stop)}</td><td class="num">${px(p.take)}</td>
        <td class="num ${cls(p.upnl)}">${usd(p.upnl, true)}</td><td class="num ${cls(p.pnl_pct)}">${pct(p.pnl_pct)}</td><td class="num ${cls(p.pct_equity)}">${pct(p.pct_equity)}</td>
        <td style="white-space:nowrap">${p.editable ? `<button class="btn" data-edit="${i}">✎ SL/TP</button> ` : ""}<button class="btn danger" data-close="${i}">Закрыть</button></td></tr>`));
    $("odOrd").innerHTML = table(["Клиент", "Монета", "Сторона", "Тип", "Объём", "Цена", "Откуда", ""],
      data.orders.map((o, i) => `<tr><td>${esc(o.client)}</td><td><b>${esc(o.symbol)}</b></td><td>${o.side === "Buy" ? "Buy" : "Sell"}</td><td>${o.reduce ? "закрывающий" : "вход"}</td>
        <td class="num">${o.qty}</td><td class="num">${px(o.price)}</td><td><span class="tag">${ORD_ORIGIN[o.origin] || o.origin}</span></td>
        <td>${o.cancelable ? `<button class="btn danger" data-cancel="${i}">Снять</button>` : '<span class="muted small">ведёт сетка</span>'}</td></tr>`));
    $("odCliCard").hidden = one;
    $("odCli").innerHTML = table(["Клиент", "Счёт", "Бот", "Баланс", "Плавающий", "%", "День %", "Реализ. 7д", "Реализ. 30д", "% за 30д", "С начала учёта %", "Поз./орд."],
      data.clients.map((c) => `<tr class="click" data-cli="${c.id}"><td>${esc(c.name)}</td><td>${c.account}</td><td>${c.online ? '<span class="tag ok">онлайн</span>' : '<span class="tag">офлайн</span>'}</td>
        <td class="num">${usd(c.equity)}</td><td class="num ${cls(c.upnl)}">${usd(c.upnl, true)}</td><td class="num ${cls(c.upnl_pct)}">${pct(c.upnl_pct)}</td>
        <td class="num ${cls(c.day_pct)}">${pct(c.day_pct)}</td><td class="num ${cls(c.realized_7d)}">${usd(c.realized_7d, true)}</td><td class="num ${cls(c.realized_30d)}">${usd(c.realized_30d, true)}</td>
        <td class="num ${cls(c.realized_30d_pct)}">${pct(c.realized_30d_pct)}</td><td class="num ${cls(c.roi_pct)}">${pct(c.roi_pct)}</td><td class="num">${c.positions} / ${c.orders}</td></tr>`));
    $("odCli").querySelectorAll("tr.click").forEach((tr) => (tr.onclick = () => { $("odClient").value = tr.dataset.cli; load(); }));
    const nm = names();
    $("odHist").innerHTML = table(["Когда", "Клиент", "Монета", "Стратегия", "Сторона", "Вход → выход", "PnL"], data.recent_trades.map((t) => `<tr><td>${dt(t.at)}</td><td>${esc(nm[t.user_id] || t.user_id)}</td><td>${esc(t.symbol)}</td>
      <td><span class="tag">${STRAT[t.strategy] || esc(t.strategy)}</span></td><td>${t.side === "Buy" ? "LONG" : "SHORT"}</td><td class="num">${px(t.entry)} → ${px(t.exit)}</td><td class="num ${cls(t.pnl)}">${usd(t.pnl, true)}</td></tr>`));
    $("odPos").querySelectorAll("[data-edit]").forEach((b) => (b.onclick = () => editDialog(data.positions[b.dataset.edit])));
    $("odPos").querySelectorAll("[data-close]").forEach((b) => (b.onclick = () => {
      const p = data.positions[b.dataset.close];
      if (confirm(`Закрыть ${p.symbol} у клиента ${p.client} по рынку?${p.strategy === "grid" ? " Сетка будет свёрнута." : ""}`)) send("/manual/close", { user_id: p.user_id, symbol: p.symbol });
    }));
    $("odOrd").querySelectorAll("[data-cancel]").forEach((b) => (b.onclick = () => {
      const o = data.orders[b.dataset.cancel];
      if (confirm(`Снять ордер ${o.symbol} ${o.side} ${o.qty} @ ${px(o.price)}?`)) send("/orders/cancel", { user_id: o.user_id, symbol: o.symbol, link: o.link });
    }));
    $("odStamp").textContent = "обновлено " + new Date().toLocaleTimeString("ru-RU");
  }
  async function load() { data = await api("/orders/overview?user_id=" + uid()); draw(); }
  $("odClient").onchange = load;
  await load();
  ordTimer = setInterval(() => { if (current === "orders" && $("modal").hidden) load().catch(() => {}); }, 5000);
};

// ───────────── сигналы из Telegram ─────────────
const SIG_STATUS = { new: ["в очереди", "warn"], processed: ["исполнен", "ok"], rejected: ["отклонён", "bad"], duplicate: ["дубль", ""], ignored: ["не распознан", ""] };
const PARSER_FIELDS = [["long_words", "Слова «лонг»"], ["short_words", "Слова «шорт»"], ["entry_words", "Слова «вход/диапазон»"], ["target_words", "Слова «цель/тейк»"],
  ["stop_words", "Слова «стоп»"], ["close_words", "Обновление: «закрыть»"], ["be_words", "Обновление: «безубыток»"]];
const EX_KIND = { signal: "сигнал", update: "обновление", noise: "не сигнал" };
VIEWS.signals = async () => {
  let d = await api("/signals/overview");
  const fmtRes = (r) => {
    if (r.type === "signal") {
      const s = r.signal;
      return `<div class="note ${r.ok ? "" : "err"}"><b>${r.ok ? "✅ Сигнал распознан" : "⚠️ Распознан, но не пройдёт проверку"}${r.via === "ai" ? " (ИИ)" : ""}</b>${r.error ? ": " + esc(r.error) : ""}<br>
        ${esc(s.symbol)} · ${s.side === "Buy" ? "LONG" : "SHORT"} · вход ${s.entry_lo > 0 ? s.entry_lo + '–' + s.entry_hi : 'по рынку'} · стоп ${s.stop} · цели: ${s.targets.map(esc).join(", ") || "—"}${s.leverage ? " · плечо канала X" + s.leverage + " (не используется)" : ""}</div>`;
    }
    if (r.type === "update") return `<div class="note"><b>📝 Обновление${r.via === "ai" ? " (ИИ)" : ""}</b>: ${esc(r.update.symbol)} → ${r.update.action === "close" ? "закрыть сделку" : "стоп в безубыток"}</div>`;
    return `<div class="note err"><b>Не распознано</b>: ${esc(r.error)}${r.symbol ? " (монета найдена: " + esc(r.symbol) + ", не хватает остального)" : " (монета не найдена)"}</div>`;
  };
  const chLabel = (c) => c.pending ? `<span class="tag warn">ожидает подтверждения</span>` : c.enabled ? `<span class="tag ok">включён</span>` : `<span class="tag">выключен</span>`;
  const draw = () => {
    const s = d.settings;
    $("view").innerHTML = `
      <div class="card">
        <h3>Приём сигналов</h3>
        <div class="muted small" style="margin-bottom:10px">Как сигнал попадает в бота: ① админ пересылает пост боту в личку; ② бот добавлен админом в ваш канал и видит посты; ③ внешний ридер (отдельный аккаунт, читающий каналы) шлёт посты на адрес ниже. Каждый источник — «канал» со своими настройками.</div>
        <div class="row" style="gap:18px;flex-wrap:wrap">
          <label class="row" style="gap:8px"><input type="checkbox" id="sgEn" style="width:auto" ${s.signal_enabled ? "checked" : ""}> <b>Приём сигналов включён</b></label>
          <label class="row" style="gap:8px"><input type="checkbox" id="sgReal" style="width:auto" ${s.signal_real_enabled ? "checked" : ""}> Разрешить РЕАЛЬНЫЕ счета <span class="muted small">(ещё и в настройках канала)</span></label>
          <label style="max-width:220px">Допуск ухода цены от входа, %<input class="inp" id="sgChase" type="number" step="0.05" min="0" max="5" value="${s.signal_max_chase_pct}"></label>
          <button class="btn primary" id="sgSave">Сохранить</button>
        </div>
        ${d.legacy_ids.length ? `<div class="muted small" style="margin-top:8px">Старый список разрешённых ID из «Настроек»: ${d.legacy_ids.map(esc).join(", ")} — продолжает работать.</div>` : ""}
      </div>
      <div class="card">
        <div class="row" style="justify-content:space-between"><h3 style="margin:0">Каналы-источники</h3><button class="btn primary" id="chNew">+ Добавить канал</button></div>
        ${table(["Канал", "Ключ", "Статус", "Режим", "Риск ×", "Постов", "Последний", "Сигналы", "Сделки клиентов", ""], d.channels.map((c) => {
          const n = c.counts;
          return `<tr class="${c.pending ? "hl" : ""}"><td><b>${esc(c.name)}</b>${c.notes ? `<div class="muted small">${esc(c.notes)}</div>` : ""}</td><td class="small">${esc(c.source_key)}</td><td>${chLabel(c)}</td>
            <td>${c.mode === "real" ? '<span class="tag bad">реал</span>' : '<span class="tag">демо</span>'}</td><td class="num">${c.risk_mult}</td><td class="num">${c.posts_seen}</td><td>${dt(c.last_post_at)}</td>
            <td class="small" style="white-space:nowrap" title="исполнено / отклонено / дубли">✓ ${n.processed || 0} · ✗ ${n.rejected || 0} · ♻ ${n.duplicate || 0}</td>
            <td class="small">${c.stats.trades ? `${c.stats.trades} сд. · <span class="${cls(c.stats.net_pnl)}">${usd(c.stats.net_pnl, true)}</span> · PF ${c.stats.profit_factor ?? "—"}` : "—"}</td>
            <td style="white-space:nowrap"><button class="btn" data-edit="${c.id}">⚙ Настроить</button> <button class="btn" data-tog="${c.id}">${c.enabled ? "Выключить" : "Включить"}</button></td></tr>`;
        }))}
        <div class="muted small" style="margin-top:8px">Новый канал из пересылки или поста появляется здесь <b>выключенным</b> — проверьте разбор на примерах и включите. Режим «демо» — сигналы идут только на демо-счета клиентов.</div>
      </div>
      <div class="card">
        <h3>Админы сигналов</h3>
        <div class="muted small" style="margin-bottom:8px">Кому разрешено пересылать посты боту. Человек узнаёт свой Telegram ID, написав боту команду <code>/id</code>.</div>
        ${table(["Telegram ID", "Имя", "Статус", ""], d.admins.map((a) => `<tr><td class="num">${esc(a.tg_id)}</td><td>${esc(a.name)}</td><td>${a.enabled ? '<span class="tag ok">активен</span>' : '<span class="tag">выключен</span>'}</td>
          <td style="white-space:nowrap"><button class="btn" data-atog="${a.id}" data-en="${a.enabled ? 0 : 1}">${a.enabled ? "Выключить" : "Включить"}</button> <button class="btn danger" data-adel="${a.id}">✕</button></td></tr>`))}
        <div class="row" style="margin-top:10px"><input class="inp" id="adTg" placeholder="Telegram ID (число)" inputmode="numeric"><input class="inp" id="adName" placeholder="Имя (для себя)"><button class="btn primary" id="adAdd">Добавить админа</button></div>
      </div>
      <div class="card">
        <h3>Проверка разбора</h3>
        <div class="muted small" style="margin-bottom:8px">Вставьте пост — бот покажет, что он в нём увидит, и ничего не откроет.</div>
        <div class="row"><select id="pgCh" style="max-width:320px"><option value="0">Настройки по умолчанию</option>${d.channels.map((c) => `<option value="${c.id}">${esc(c.name)}</option>`).join("")}</select></div>
        <textarea id="pgText" rows="6" placeholder="Вставьте текст поста" style="margin-top:8px"></textarea>
        <button class="btn primary" id="pgGo" style="margin-top:8px">Проверить</button><div id="pgOut" style="margin-top:8px"></div>
      </div>
      <div class="card">
        <h3>Внешний ридер (отдельный аккаунт в каналах)</h3>
        <div class="muted small" style="margin-bottom:8px">Скрипт на отдельном аккаунте читает посты каналов и шлёт их сюда. Канал определяется по <code>channel_id</code>; неизвестный создаётся выключенным. Работает, когда приём включён.</div>
        <div class="kv small"><div>Адрес: <code>POST ${esc(location.origin)}${esc(d.ingest.path)}</code></div><div>Заголовок: <code>X-Signal-Token: <span id="igTok">${esc(d.ingest.token)}</span></code></div>
        <div>Тело JSON: <code>{"channel_id":"-1001234567890","channel_name":"Название","text":"…пост…"}</code></div></div>
        <button class="btn" id="igRot" style="margin-top:8px">Сменить токен</button>
      </div>
      <div class="card">
        <h3>Журнал сигналов</h3>
        ${table(["#", "Когда", "Канал", "Что", "Статус", "Итог"], d.recent.map((r) => {
          const st = SIG_STATUS[r.status] || [r.status, ""];
          const what = r.kind === "update" ? `📝 ${esc(r.symbol)} → ${r.action === "close" ? "закрыть" : "безубыток"}` : r.symbol === "?" ? `<span class="muted">${esc((r.text || "").slice(0, 80))}</span>` : `<b>${esc(r.symbol)}</b> ${r.side === "Buy" ? "LONG" : "SHORT"} · ${esc(r.entry)} · стоп ${r.stop} · целей ${r.targets.length}`;
          return `<tr><td>${r.id}</td><td>${dt(r.at)}</td><td>${esc(r.channel)}</td><td>${what}</td><td><span class="tag ${st[1]}">${st[0]}</span></td><td class="small">${esc(r.summary || "")}</td></tr>`;
        }))}
      </div>`;
    bind();
  };
  function bind() {
    $("sgSave").onclick = async () => {
      try { await post("/signals/settings", { signal_enabled: $("sgEn").checked, signal_real_enabled: $("sgReal").checked, signal_max_chase_pct: $("sgChase").value }); toast("Сохранено"); reload(); } catch (e) { toast(e.message); }
    };
    $("chNew").onclick = () => chDialog(null);
    $("view").querySelectorAll("[data-edit]").forEach((b) => (b.onclick = () => chDialog(d.channels.find((c) => c.id == b.dataset.edit))));
    $("view").querySelectorAll("[data-tog]").forEach((b) => (b.onclick = async () => {
      const c = d.channels.find((x) => x.id == b.dataset.tog);
      try { await post("/signals/channels", chBody(c, { enabled: !c.enabled })); reload(); } catch (e) { toast(e.message); }
    }));
    $("adAdd").onclick = async () => { try { await post("/signals/admins", { tg_id: $("adTg").value.trim(), name: $("adName").value }); toast("Добавлен"); reload(); } catch (e) { toast(e.message); } };
    $("view").querySelectorAll("[data-atog]").forEach((b) => (b.onclick = async () => { await api("/signals/admins/" + b.dataset.atog, { method: "PUT", body: JSON.stringify({ enabled: b.dataset.en === "1" }) }); reload(); }));
    $("view").querySelectorAll("[data-adel]").forEach((b) => (b.onclick = async () => { if (confirm("Удалить админа?")) { await api("/signals/admins/" + b.dataset.adel, { method: "DELETE" }); reload(); } }));
    $("pgGo").onclick = async () => {
      try { $("pgOut").innerHTML = fmtRes(await post("/signals/parse", { text: $("pgText").value, channel_id: Number($("pgCh").value) })); } catch (e) { toast(e.message); }
    };
    $("igRot").onclick = async () => { if (!confirm("Старый токен перестанет работать. Сменить?")) return; const r = await post("/signals/token/rotate"); $("igTok").textContent = r.token; toast("Токен изменён"); };
  }
  const chBody = (c, over = {}) => ({ id: c.id, name: c.name, source_key: c.source_key, enabled: c.enabled, mode: c.mode, risk_mult: c.risk_mult, max_chase_pct: c.max_chase_pct ?? "", parser: c.parser, notes: c.notes, ...over });
  async function reload() { d = await api("/signals/overview"); draw(); }
  function chDialog(c) {
    const isNew = !c;
    c = c || { id: 0, name: "", source_key: "", enabled: false, mode: "demo", risk_mult: 1, max_chase_pct: null, parser: {}, notes: "", examples: [] };
    const p = c.parser || {};
    const dis = isNew ? "" : "";
    $("modal").innerHTML = `<div class="head"><h2>${isNew ? "Новый канал" : "Канал: " + esc(c.name)}</h2><button class="btn" id="mClose">✕</button></div>
      <div class="grid2"><label>Название<input class="inp" id="cName" value="${esc(c.name)}"></label>
      <label>Ключ источника<input class="inp" id="cKey" value="${esc(c.source_key)}" placeholder="-1001234567890 / manual / ext:имя"></label></div>
      <div class="muted small">Ключ: id канала (число вида -100…; его показывает бот при первой пересылке), <code>manual</code> — пересылки без канала, <code>ext:имя</code> — внешний ридер.</div>
      <div class="grid2" style="margin-top:8px"><label>Режим<select id="cMode"><option value="demo" ${c.mode === "demo" ? "selected" : ""}>Демо-счета</option><option value="real" ${c.mode === "real" ? "selected" : ""}>Демо + реальные (нужно глобальное разрешение)</option></select></label>
      <label>Множитель риска (0.1–3)<input class="inp" id="cRisk" type="number" step="0.1" value="${c.risk_mult}"></label></div>
      <div class="grid2"><label>Допуск ухода цены, % (пусто = общий)<input class="inp" id="cChase" type="number" step="0.05" value="${c.max_chase_pct ?? ""}"></label>
      <label class="row" style="gap:8px;align-self:end"><input type="checkbox" id="cEn" style="width:auto" ${c.enabled ? "checked" : ""}> Канал включён</label></div>
      <label>Заметка<input class="inp" id="cNotes" value="${esc(c.notes)}"></label>
      <h3 style="margin-top:14px">Как разбирать посты этого канала</h3>
      <div class="muted small">Слова через запятую. Ваши слова ДОБАВЛЯЮТСЯ к стандартным (серые подсказки в полях) — стандартные работают всегда. Регистр не важен, пробел и дефис внутри слова необязательны.</div>
      <div class="grid2">${PARSER_FIELDS.map(([k, l]) => `<label>${l}<input class="inp" data-pf="${k}" value="${esc(p[k] || "")}" placeholder="${esc(d.defaults[k])}"></label>`).join("")}
      <label>Как записана монета<select id="cStyle"><option value="hash" ${p.symbol_style !== "any" ? "selected" : ""}>#COIN/USDT (строго)</option><option value="any" ${p.symbol_style === "any" ? "selected" : ""}>любая: COINUSDT, COIN/USDT, #COIN</option></select></label></div>
      <label class="row" style="gap:8px;margin-top:10px"><input type="checkbox" id="cAi" style="width:auto" ${p.ai ? "checked" : ""}> <b>ИИ-разбор, если шаблон не подошёл</b></label>
      <div class="muted small">Claude переписывает поля из поста; каждое число и монета обязаны буквально стоять в тексте, затем действуют те же проверки (стоп, порядок целей, диапазон). Тратит токены (учитывается в расходах на ИИ), не более 60 постов в час. Нужен ключ Anthropic в настройках.</div>
      <button class="btn primary big" id="cSave" style="margin-top:12px">Сохранить канал</button>
      <h3 style="margin-top:18px">Примеры постов</h3>
      ${isNew ? '<div class="muted small">Сохраните канал — тогда можно добавлять примеры.</div>' : `
      <div class="muted small" style="margin-bottom:6px">Реальные посты канала с ожидаемым результатом. Галочка — разбор даёт то, что ожидается. После смены слов сразу видно, ничего ли не сломалось.</div>
      <div id="exList">${c.examples.map((e) => `<div class="note ${e.pass ? "" : "err"}" style="margin-bottom:6px"><div class="row" style="justify-content:space-between"><span><b>${e.pass ? "✓" : "✗"}</b> ${EX_KIND[e.kind]}</span><button class="btn danger" data-exdel="${e.id}">✕</button></div>
        <pre style="white-space:pre-wrap;margin:6px 0 0;font:inherit;font-size:12px;color:var(--text)">${esc(e.text)}</pre>${e.pass ? "" : `<div class="small" style="margin-top:4px">Сейчас: ${e.result.type === "signal" ? (e.result.error ? "сигнал с ошибкой — " + esc(e.result.error) : "сигнал") : e.result.type === "update" ? "обновление" : "не распознан"}</div>`}</div>`).join("") || '<div class="muted small">Примеров пока нет.</div>'}</div>
      <div class="row" style="margin-top:8px"><select id="exKind" style="max-width:200px"><option value="signal">Это сигнал</option><option value="update">Это обновление (закрыть/безубыток)</option><option value="noise">Это НЕ сигнал</option></select></div>
      <textarea id="exText" rows="5" placeholder="Вставьте пост канала"></textarea>
      <div class="row" style="margin-top:8px"><button class="btn" id="exAdd">Добавить пример</button><button class="btn primary" id="exTest">Проверить текст (с текущими словами)</button></div><div id="exOut" style="margin-top:8px"></div>
      <div style="margin-top:14px"><button class="btn danger" id="cDel">Удалить канал</button></div>`}`;
    $("modal").hidden = false; $("modalBg").hidden = false;
    $("mClose").onclick = closeModal;
    const parser = () => { const o = { symbol_style: $("cStyle").value }; if ($("cAi").checked) o.ai = 1; $("modal").querySelectorAll("[data-pf]").forEach((i) => { if (i.value.trim()) o[i.dataset.pf] = i.value.trim(); }); return o; };
    $("cSave").onclick = async () => {
      try {
        const r = await post("/signals/channels", { id: c.id, name: $("cName").value, source_key: $("cKey").value.trim(), enabled: $("cEn").checked, mode: $("cMode").value,
          risk_mult: $("cRisk").value, max_chase_pct: $("cChase").value, parser: parser(), notes: $("cNotes").value });
        toast("Канал сохранён"); await reload();
        if (isNew) chDialog(d.channels.find((x) => x.id === r.id)); else chDialog(d.channels.find((x) => x.id === c.id));
      } catch (e) { toast(e.message); }
    };
    if (isNew) return;
    $("exAdd").onclick = async () => { try { await post("/signals/examples", { channel_id: c.id, kind: $("exKind").value, text: $("exText").value }); await reload(); chDialog(d.channels.find((x) => x.id === c.id)); } catch (e) { toast(e.message); } };
    $("exTest").onclick = async () => { try { $("exOut").innerHTML = fmtRes(await post("/signals/parse", { text: $("exText").value, parser: parser() })); } catch (e) { toast(e.message); } };
    $("modal").querySelectorAll("[data-exdel]").forEach((b) => (b.onclick = async () => { await api("/signals/examples/" + b.dataset.exdel, { method: "DELETE" }); await reload(); chDialog(d.channels.find((x) => x.id === c.id)); }));
    $("cDel").onclick = async () => { if (!confirm("Удалить канал вместе с примерами? Журнал сигналов и сделки останутся.")) return; await api("/signals/channels/" + c.id, { method: "DELETE" }); closeModal(); reload(); };
  }
  draw();
};

// ───────────── ручная торговля ─────────────
const MN_STATUS = { pending: "в очереди", open: "лимит выставлен", filled: "исполнен", done: "исполнен", cancelled: "отменён",
  cancel_requested: "отмена…", error: "ошибка" };
const MN_CLASS = { filled: "ok", done: "ok", error: "bad", cancelled: "warn", cancel_requested: "warn" };
let mnClients = [], mnTimer = null;
VIEWS.manual = async () => {
  clearInterval(mnTimer);
  mnClients = await api("/manual/clients");
  if (!mnClients.length) { $("view").innerHTML = `<div class="card muted">Пока нет ни одного клиента.</div>`; return; }
  $("view").innerHTML = `
    <div class="card">
      <h3>Клиент</h3>
      <select id="mnClient">${mnClients.map((c) => `<option value="${c.id}">${esc(c.name)}${c.username ? " · @" + esc(c.username) : ""} — ${
        c.trading_mode === "exchange" ? (c.exchange || "биржа не подключена") : "демо"}${c.running ? "" : " · бот остановлен"}</option>`).join("")}</select>
      <div id="mnInfo" class="muted small" style="margin-top:8px"></div>
      <button class="btn" id="mnToggle" style="margin-top:10px"></button>
    </div>
    <div class="card"><h3>Открыто у клиента сейчас</h3><div id="mnPositions">—</div></div>
    <div class="card" style="max-width:680px">
      <h3>Новый ордер</h3>
      <label class="row" style="gap:8px"><input type="checkbox" id="mnAll" style="width:auto"> <b>Открыть сразу всем клиентам</b> <span class="muted small">(один и тот же ордер каждому)</span></label>
      <div class="grid2">
        <label>Монета<input class="inp" id="mnSymbol" placeholder="BTCUSDT" style="text-transform:uppercase" autocomplete="off">
          <div id="mnSymbolChips" class="row" style="gap:6px;flex-wrap:wrap;margin-top:6px"></div>
        </label>
        <label>Текущая цена<div class="row"><input class="inp" id="mnPrice0" readonly placeholder="—"><button class="btn" type="button" id="mnRefreshPrice">↻</button></div></label>
      </div>
      <div class="seg" id="mnSide"><button data-v="Buy">🟢 Buy / Long</button><button data-v="Sell">🔴 Sell / Short</button></div>
      <div class="seg" id="mnType"><button data-v="market">По рынку</button><button data-v="limit">Лимит</button></div>
      <div class="grid2">
        <label id="mnPriceWrap" hidden>Цена лимитного ордера<input class="inp" id="mnLimitPrice" type="number" step="any"></label>
        <label>Объём, монета<input class="inp" id="mnQty" type="number" step="any" placeholder="0.01"></label>
        <label>Стоп-лосс (необязательно)<input class="inp" id="mnSl" type="number" step="any"></label>
        <label>Тейк-профит (необязательно)<input class="inp" id="mnTp" type="number" step="any"></label>
        <label>Плечо (необязательно)<input class="inp" id="mnLev" type="number" min="1" max="125" placeholder="как сейчас у клиента"></label>
      </div>
      <button class="btn primary big" id="mnSend" style="margin-top:10px">Отправить ордер</button>
      <div class="note" id="mnRes"></div>
    </div>
    <div class="card"><h3>Ордера этого клиента</h3><div id="mnOrders">—</div></div>
    <div class="card" id="mnSigCard" hidden><h3>📡 Сигналы из Telegram <span class="muted small">— включаются в Настройки → «Сигналы»; по умолчанию исполняются только на демо</span></h3><div id="mnSignals">—</div></div>`;
  const client = () => mnClients.find((c) => c.id === Number($("mnClient").value));
  const POS_LABEL = { grid: "Сетка", position: "Позиция", pending: "Лимит (ждёт)" };
  function renderInfo() {
    const c = client();
    $("mnSymbolChips").innerHTML = c.symbols.map((s) => `<button type="button" class="btn small" data-sym="${s}">${s}</button>`).join("");
    $("mnSymbolChips").querySelectorAll("button").forEach((b) => { b.onclick = () => { $("mnSymbol").value = b.dataset.sym; $("mnPrice0").value = ""; }; });
    if (!$("mnSymbol").value) { $("mnSymbol").value = c.symbols[0] || ""; }
    $("mnInfo").innerHTML = `Режим: <b>${c.manual_mode ? "ручной (автотрейдинг выключен)" : "автотрейдинг"}</b> · бот ${c.running ? "работает" : "остановлен"}` +
      (c.equity != null ? ` · баланс ${usd(c.equity)}` : "") + (c.status ? ` · ${esc(c.status)}` : "");
    $("mnToggle").textContent = c.manual_mode ? "▶ Включить автотрейдинг" : "✋ Выключить автотрейдинг (ручной режим)";
    $("mnToggle").className = "btn " + (c.manual_mode ? "primary" : "danger");
    $("mnPrice0").value = "";
    refresh();
  }
  $("mnClient").onchange = renderInfo;
  $("mnToggle").onclick = async () => {
    const c = client();
    if (!confirm(c.manual_mode ? "Включить автотрейдинг для этого клиента?" : "Выключить автотрейдинг? Сделки будет открывать только трейдер.")) return;
    try { await post(`/manual/mode/${c.id}`, { manual: !c.manual_mode }); toast("Готово"); await VIEWS.manual(); }
    catch (e) { toast(e.message); }
  };
  $("mnSide").querySelectorAll("button").forEach((b, i) => { b.classList.toggle("on", i === 0); b.onclick = () => $("mnSide").querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b)); });
  $("mnType").querySelectorAll("button").forEach((b, i) => {
    b.classList.toggle("on", i === 0);
    b.onclick = () => { $("mnType").querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b)); $("mnPriceWrap").hidden = b.dataset.v !== "limit"; };
  });
  $("mnRefreshPrice").onclick = async () => {
    try { const r = await api("/manual/price?symbol=" + $("mnSymbol").value.trim().toUpperCase()); $("mnPrice0").value = r.price; } catch (e) { toast(e.message); }
  };
  $("mnSend").onclick = async () => {
    const c = client();
    const all = $("mnAll").checked;
    const side = $("mnSide").querySelector("button.on").dataset.v;
    const type = $("mnType").querySelector("button.on").dataset.v;
    const qty = Number($("mnQty").value);
    if (!qty || qty <= 0) { toast("Укажите объём"); return; }
    if (type === "limit" && !Number($("mnLimitPrice").value)) { toast("Укажите цену лимитного ордера"); return; }
    const body = { symbol: $("mnSymbol").value.trim().toUpperCase(), side, order_type: type, qty,
      price: type === "limit" ? Number($("mnLimitPrice").value) : null,
      stop_loss: $("mnSl").value || null, take_profit: $("mnTp").value || null, leverage: $("mnLev").value || null };
    const question = all
      ? `Отправить ${type === "market" ? "рыночный" : "лимитный"} ордер ${side} по ${body.symbol} СРАЗУ ВСЕМ клиентам?`
      : `Отправить ${type === "market" ? "рыночный" : "лимитный"} ордер ${side} по ${body.symbol} клиенту «${c.name}»?`;
    if (!confirm(question)) return;
    try {
      if (all) {
        const r = await post("/manual/order/all", body);
        $("mnRes").className = "note ok"; $("mnRes").textContent = `Ордер поставлен в очередь для ${r.count} клиентов.`;
      } else {
        await post("/manual/order", { ...body, user_id: c.id });
        $("mnRes").className = "note ok"; $("mnRes").textContent = "Ордер поставлен в очередь — движок исполнит его в течение нескольких секунд.";
      }
      $("mnQty").value = ""; $("mnSl").value = ""; $("mnTp").value = ""; $("mnLev").value = ""; $("mnLimitPrice").value = "";
      refresh();
    } catch (e) { $("mnRes").className = "note err"; $("mnRes").textContent = e.message; }
  };
  async function loadPositions() {
    const c = client();
    const p = await api("/manual/positions?user_id=" + c.id);
    const rows = [
      ...p.grids.map((g) => ({ kind: "grid", symbol: g.symbol, info: `${g.mode === "long" ? "лонг" : "шорт"} · заполнено ${g.filled}/${g.levels}` })),
      ...p.directional.map((d) => ({ kind: "position", symbol: d.symbol, info: `${d.side === "Buy" ? "лонг" : "шорт"} · вход ${d.entry} · стоп ${d.stop}` })),
      ...p.pending_manual.map((m) => ({ kind: "pending", symbol: m.symbol, info: `${m.side === "Buy" ? "лонг" : "шорт"} · объём ${m.qty} · ждёт исполнения` })),
    ];
    $("mnPositions").innerHTML = rows.length
      ? table(["Монета", "Что", "Подробности", ""], rows.map((r) =>
          `<tr><td>${r.symbol}</td><td>${POS_LABEL[r.kind]}</td><td class="small">${esc(r.info)}</td>
            <td><button class="btn danger" data-close="${r.symbol}">✕ Закрыть</button></td></tr>`))
      : `<div class="muted small">Открытых позиций, сеток и неисполненных ордеров нет.</div>`;
    $("mnPositions").querySelectorAll("[data-close]").forEach((b) => (b.onclick = async () => {
      if (!confirm(`Закрыть/отменить всё по ${b.dataset.close}?`)) return;
      try { await post("/manual/close", { user_id: c.id, symbol: b.dataset.close }); toast("Команда отправлена"); setTimeout(loadPositions, 3000); }
      catch (e) { toast(e.message); }
    }));
  }
  async function loadOrders() {
    const c = client();
    const rows = await api("/manual/orders?user_id=" + c.id);
    $("mnOrders").innerHTML = table(["Когда", "Монета", "Сторона", "Тип", "Объём", "Цена / SL / TP", "Плечо", "Статус", "Кто", ""], rows.map((o) =>
      `<tr><td>${dt(o.created_at)}</td><td>${o.symbol}</td><td class="${o.side === "Buy" ? "pos" : "neg"}">${o.side}</td><td>${o.order_type === "limit" ? "лимит" : "рынок"}</td>
        <td class="num">${o.qty}</td><td class="num small">${o.price ?? "рынок"}${o.stop_loss ? " · SL " + o.stop_loss : ""}${o.take_profit ? " · TP " + o.take_profit : ""}</td>
        <td class="num">${o.leverage ? "×" + o.leverage : "—"}</td>
        <td><span class="tag ${MN_CLASS[o.status] || ""}">${MN_STATUS[o.status] || o.status}</span>${o.status === "error" && o.error ? `<div class="muted small">${esc(o.error)}</div>` : ""}</td>
        <td class="muted small">${esc(o.created_by)}</td>
        <td>${["pending", "open"].includes(o.status) ? `<button class="btn danger" data-cancel="${o.id}">✕</button>` : ""}</td></tr>`));
    $("mnOrders").querySelectorAll("[data-cancel]").forEach((b) => (b.onclick = async () => {
      if (!confirm("Отменить ордер?")) return;
      try { await post(`/manual/orders/${b.dataset.cancel}/cancel`); loadOrders(); } catch (e) { toast(e.message); }
    }));
  }
  async function loadSignals() {
    const rows = await api("/signals");                      // роль «трейдер» получает 403 — карточка остаётся скрытой
    $("mnSigCard").hidden = false;
    const st = { new: "в очереди", processed: "исполнен", rejected: "отклонён", duplicate: "дубль" };
    $("mnSignals").innerHTML = table(["#", "Монета", "Сторона", "Вход", "Стоп", "Цели", "Статус", "Итог", "Когда"], rows.map((r) => `<tr><td>${r.id}</td><td>${esc(r.symbol)}</td>
      <td>${r.side === "Buy" ? "LONG" : "SHORT"}</td><td class="num">${esc(r.entry)}</td><td class="num">${r.stop}</td><td class="small">${r.targets.join(" · ")}</td>
      <td><span class="tag ${r.status === "processed" ? "ok" : r.status === "rejected" ? "bad" : ""}">${st[r.status] || r.status}</span></td><td class="small">${esc(r.summary || "")}</td><td>${dt(r.at)}</td></tr>`));
  }
  function refresh() { loadOrders().catch(() => {}); loadPositions().catch(() => {}); loadSignals().catch(() => {}); }
  renderInfo();
  mnTimer = setInterval(() => { if (current === "manual") refresh(); }, 4000);
};

// ───────────── настройки ─────────────
// Включатель анализа рынка: выключено — ИИ не вызывается и новых автосделок нет (ручная торговля и сигналы работают)
async function analysisToggle() {
  const fields = await api("/settings");
  const on = fields.find((f) => f.key === "analysis_enabled")?.value !== false;
  const btn = document.createElement("button");
  btn.className = "btn " + (on ? "danger" : "primary");
  btn.textContent = on ? "⏸ Отключить анализ" : "▶ Включить анализ";
  btn.onclick = async () => {
    if (on && !confirm("Отключить анализ рынка? ИИ перестанет вызываться, новых автоматических сделок не будет. Открытые позиции сопровождаются как обычно; ручная торговля и сигналы работают.")) return;
    await api("/settings", { method: "PUT", body: JSON.stringify({ analysis_enabled: !on }) });
    toast(on ? "Анализ отключён" : "Анализ включён"); go(current);
  };
  $("headActions").prepend(btn);
  return on;
}
function resetDialog() {
  $("modal").innerHTML = `<div class="head"><h2>Обнулить показатели</h2><button class="btn" id="mClose">✕</button></div>
    <div class="muted small" style="margin-bottom:10px">Расходы на ИИ сохраняются всегда. Действие необратимо — сделайте резервную копию базы, если нужна история.</div>
    <label class="row" style="gap:8px"><input type="checkbox" id="rsTrading" style="width:auto" checked> <b>Торговля</b></label>
    <div class="muted small" style="margin:0 0 10px 26px">Сделки и PnL, обучение стратегий, история баланса, сигналы, ручные ордера, состояние риска; демо-балансы возвращаются к стартовому, демо-позиции закрываются. Позиции на биржах Bybit не трогаются.</div>
    <label class="row" style="gap:8px"><input type="checkbox" id="rsFinance" style="width:auto"> <b>Финансы</b></label>
    <div class="muted small" style="margin:0 0 10px 26px">Платежи за подписки (доход) и ручные записи доходов/расходов. Подписки клиентов не отменяются. Не сработает, пока есть неоплаченные реферальные начисления.</div>
    <label>Для подтверждения введите ОБНУЛИТЬ<input class="inp" id="rsConfirm" autocomplete="off"></label>
    <button class="btn danger big" id="rsGo">Обнулить</button>`;
  $("modal").hidden = false; $("modalBg").hidden = false;
  $("mClose").onclick = closeModal;
  $("rsGo").onclick = async () => {
    try {
      const r = await post("/maintenance/reset", { trading: $("rsTrading").checked, finance: $("rsFinance").checked, confirm: $("rsConfirm").value });
      closeModal(); toast("Отправлено движку…");
      for (let i = 0; i < 30; i++) {
        await new Promise((x) => setTimeout(x, 1000));
        const c = await api("/orders/command?id=" + r.command_id);
        if (c.done) { alert(c.result.startsWith("ok") ? "Готово: " + c.result.slice(4) : "Ошибка: " + c.result.replace(/^error: /, "")); go(current); return; }
      }
      toast("Команда отправлена, но движок не ответил за 30 секунд (остановлен?). Она выполнится при запуске, если пройдёт не более 10 минут.");
    } catch (e) { toast(e.message); }
  };
}
VIEWS.settings = async () => {
  const fields = await api("/settings");
  $("headActions").innerHTML = `<button class="btn danger" id="resetStats">🗑 Обнулить показатели</button> <button class="btn danger" id="restart">↻ Перезапустить торговый движок</button>`;
  $("resetStats").onclick = resetDialog;
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
  const ROLE = { admin: "администратор", trader: "трейдер" };
  $("view").innerHTML = `<div class="grid cols2">
    <div class="card"><h3>Администраторы</h3>${table(["Логин", "Роль", "Создан", "Последний вход", ""], rows.map((a) =>
      `<tr><td>${esc(a.username)}</td><td><span class="tag ${a.role === "trader" ? "warn" : "ok"}">${ROLE[a.role] || a.role}</span></td><td>${dt(a.created_at)}</td><td>${dt(a.last_login)}</td><td><button class="btn danger" data-del="${a.id}">✕</button></td></tr>`))}
      <h3 style="margin-top:14px">Добавить</h3>
      <div class="grid2"><input class="inp" id="aUser" placeholder="логин"><input class="inp" id="aPass" type="password" placeholder="пароль от 8 символов"></div>
      <label>Роль<select id="aRole"><option value="admin">Администратор — полный доступ</option><option value="trader">Трейдер — только ручная торговля</option></select></label>
      <button class="btn primary" id="aAdd" style="margin-top:8px">Добавить</button></div>
    <div class="card"><h3>Сменить мой пароль</h3>
      <label>Текущий пароль<input class="inp" id="pOld" type="password"></label><label>Новый пароль<input class="inp" id="pNew" type="password"></label>
      <button class="btn primary" id="pSave" style="margin-top:10px">Сменить</button></div></div>`;
  $("aAdd").onclick = async () => { try { await post("/admins", { username: $("aUser").value, password: $("aPass").value, role: $("aRole").value }); toast("Добавлен"); go("admins"); } catch (e) { toast(e.message); } };
  $("pSave").onclick = async () => { try { await post("/admins/password", { old_password: $("pOld").value, new_password: $("pNew").value }); toast("Пароль изменён"); $("pOld").value = $("pNew").value = ""; } catch (e) { toast(e.message); } };
  $("view").querySelectorAll("[data-del]").forEach((b) => (b.onclick = async () => { if (!confirm("Удалить администратора?")) return; try { await api("/admins/" + b.dataset.del, { method: "DELETE" }); go("admins"); } catch (e) { toast(e.message); } }));
};

// ───────────── старт ─────────────
api("/me").then((r) => start(r.username, r.role)).catch(() => showLogin());
