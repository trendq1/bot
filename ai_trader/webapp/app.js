"use strict";
const tg = window.Telegram ? window.Telegram.WebApp : null;
if (tg) { tg.ready(); tg.expand(); try { tg.setHeaderColor("#0d0f13"); tg.setBackgroundColor("#0d0f13"); } catch (e) {} }

const TZ = -new Date().getTimezoneOffset();
const $ = (id) => document.getElementById(id);
const state = { me: null, month: new Date(), exMode: "demo", draft: null };
const STRAT = { grid: "Сетка", trend: "Тренд", liquidation: "Ликвидации" };
const REGIME = { trend_up: "Тренд ↑", trend_down: "Тренд ↓", range: "Боковик", high_volatility: "Волатильно" };
const MONTHS = ["январь", "февраль", "март", "апрель", "май", "июнь", "июль", "август", "сентябрь", "октябрь", "ноябрь", "декабрь"];

// ───────────── утилиты ─────────────
async function api(path, opts = {}) {
  const res = await fetch("/api" + path, {
    ...opts,
    headers: { "Content-Type": "application/json", "X-Init-Data": tg ? tg.initData : "", ...(opts.headers || {}) },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.detail || "Ошибка " + res.status);
  return data;
}
function toast(text) {
  const t = $("toast"); t.textContent = text; t.hidden = false;
  clearTimeout(toast._t); toast._t = setTimeout(() => (t.hidden = true), 3200);
}
function haptic(kind = "light") { try { tg && tg.HapticFeedback.impactOccurred(kind); } catch (e) {} }
const money = (v, sign = true) => v == null ? "—" : (sign && v > 0 ? "+" : "") + Number(v).toLocaleString("ru-RU", { minimumFractionDigits: 2, maximumFractionDigits: 2 });
const compact = (v) => { const a = Math.abs(v); const s = v > 0 ? "+" : v < 0 ? "−" : ""; return s + (a >= 1000 ? (a / 1000).toFixed(1) + "k" : a >= 10 ? a.toFixed(0) : a.toFixed(1)); };
const cls = (v) => v > 0 ? "pos" : v < 0 ? "neg" : "";
function setNum(el, v, suffix = "") { el.textContent = money(v) + suffix; el.className = el.className.replace(/\b(pos|neg)\b/g, "").trim() + " " + cls(v); }
function esc(s) { return String(s ?? "").replace(/[&<>"']/g, (c) => ({ "&": "&amp;", "<": "&lt;", ">": "&gt;", '"': "&quot;", "'": "&#39;" }[c])); }

// ───────────── навигация ─────────────
document.querySelectorAll(".tabs button").forEach((b) => b.addEventListener("click", () => {
  document.querySelectorAll(".tabs button").forEach((x) => x.classList.toggle("active", x === b));
  document.querySelectorAll(".screen").forEach((s) => s.classList.toggle("active", s.id === "s-" + b.dataset.t));
  haptic();
  ({ home: loadHome, cal: loadCalendar, trades: loadTrades, ai: loadAI, set: renderSettings })[b.dataset.t]();
  window.scrollTo(0, 0);
}));

// ───────────── профиль и статус ─────────────
async function loadMe() {
  state.me = await api("/me");
  const { user, settings, status, live } = state.me;
  $("hello").textContent = user.name ? `Привет, ${user.name}` : "AI Trader";
  const paper = settings.trading_mode === "paper";
  $("modeLine").textContent = paper ? "Демо-счёт · виртуальные деньги" : `Bybit ${state.me.exchange ? (state.me.exchange.mode === "live" ? "· реальный счёт" : "· демо") : ""}`;
  const pill = $("statusPill");
  pill.classList.toggle("on", settings.running && !String(status).startsWith("⏸"));
  pill.classList.toggle("warn", String(status).startsWith("⏸") || String(status).startsWith("ошибка"));
  $("statusText").textContent = settings.running ? "Работает" : "Остановлен";
  $("statusDetail").textContent = settings.running ? status : "";
  const btn = $("toggleBtn");
  btn.textContent = settings.running ? "Остановить бота" : "Запустить бота";
  btn.className = "btn big " + (settings.running ? "danger" : "primary");
  const eq = live && live.equity != null ? live.equity : (paper ? settings.paper_balance : null);
  $("equity").textContent = eq == null ? "—" : Number(eq).toLocaleString("ru-RU", { maximumFractionDigits: 2 }) + " $";
  renderLive(live);
}

$("toggleBtn").addEventListener("click", async () => {
  const running = state.me.settings.running;
  if (!running && state.me.settings.trading_mode === "exchange" && tg && tg.showConfirm) {
    const ok = await new Promise((r) => tg.showConfirm("Бот начнёт торговать на вашем счёте Bybit реальными ордерами. Продолжить?", r));
    if (!ok) return;
  }
  try { await api("/bot/" + (running ? "stop" : "start"), { method: "POST" }); haptic("medium"); await loadMe(); }
  catch (e) { toast(e.message); }
});

function renderLive(live) {
  const rows = [];
  (live?.grids || []).forEach((g) => rows.push(`<div class="row"><div class="l"><b>${esc(g.symbol)}</b><span class="tag">сетка ${g.mode === "long" ? "лонг" : "шорт"}</span><div class="muted">шаг ${g.step_pct}% · заполнено ${g.filled}/${g.levels}</div></div><div class="r muted">сетка</div></div>`));
  (live?.directional || []).forEach((d) => rows.push(`<div class="row"><div class="l"><b>${esc(d.symbol)}</b><span class="tag">${STRAT[d.strategy] || d.strategy}</span><div class="muted">вход ${d.entry} · стоп ${d.stop}</div></div><div class="r ${d.side === "Buy" ? "pos" : "neg"}">${d.side === "Buy" ? "LONG" : "SHORT"}</div></div>`));
  $("liveList").innerHTML = rows.join("") || `<div class="empty">Открытых позиций и сеток нет</div>`;
}

// ───────────── главная ─────────────
async function loadHome() {
  try {
    await loadMe();
    const d = await api("/dashboard?tz=" + TZ);
    setNum($("todayPnl"), d.today.pnl, " $");
    [["kToday", d.today], ["kWeek", d.week], ["kMonth", d.month]].forEach(([id, p]) => {
      setNum($(id), p.pnl); $(id + "N").textContent = `${p.trades} сделок`;
    });
    $("kWin").textContent = d.month.trades ? d.month.winrate + "%" : "—";
    drawEquity(d.equity_curve);
    drawStrategies(d.by_strategy);
  } catch (e) { toast(e.message); }
}

function drawEquity(points) {
  const box = $("equityChart");
  if (!points || points.length < 2) { box.innerHTML = `<div class="empty">График появится после первых часов работы бота</div>`; return; }
  const W = box.clientWidth || 320, H = 180, pl = 44, pr = 8, pt = 10, pb = 22;
  const vs = points.map((p) => p.v);
  let min = Math.min(...vs), max = Math.max(...vs);
  if (max === min) { max += 1; min -= 1; }
  const pad = (max - min) * 0.1; min -= pad; max += pad;
  const x = (i) => pl + (i / (points.length - 1)) * (W - pl - pr);
  const y = (v) => pt + (1 - (v - min) / (max - min)) * (H - pt - pb);
  const line = points.map((p, i) => `${i ? "L" : "M"}${x(i).toFixed(1)},${y(p.v).toFixed(1)}`).join("");
  const ticks = [0, 0.5, 1].map((k) => min + (max - min) * k);
  const up = vs[vs.length - 1] >= vs[0];
  box.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="График баланса за 30 дней">
    <defs><linearGradient id="eqf" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#3987e5" stop-opacity=".28"/><stop offset="1" stop-color="#3987e5" stop-opacity="0"/></linearGradient></defs>
    ${ticks.map((t) => `<line x1="${pl}" x2="${W - pr}" y1="${y(t)}" y2="${y(t)}" stroke="#262a33" stroke-width="1"/><text x="${pl - 6}" y="${y(t) + 4}" fill="#7d8290" font-size="10" text-anchor="end">${Math.round(t).toLocaleString("ru-RU")}</text>`).join("")}
    <text x="${pl}" y="${H - 6}" fill="#7d8290" font-size="10">${esc(points[0].t)}</text>
    <text x="${W - pr}" y="${H - 6}" fill="#7d8290" font-size="10" text-anchor="end">${esc(points[points.length - 1].t)}</text>
    <path d="${line}L${x(points.length - 1)},${H - pb}L${pl},${H - pb}Z" fill="url(#eqf)"/>
    <path d="${line}" fill="none" stroke="#3987e5" stroke-width="2" stroke-linejoin="round"/>
    <line id="eqX" y1="${pt}" y2="${H - pb}" stroke="#b4b8c2" stroke-width="1" stroke-dasharray="3 3" visibility="hidden"/>
    <circle id="eqDot" r="4" fill="#3987e5" stroke="#15171c" stroke-width="2" visibility="hidden"/>
    <rect x="${pl}" y="0" width="${W - pl - pr}" height="${H}" fill="transparent" id="eqHit"/>
  </svg>`;
  const svg = box.querySelector("svg"), tip = $("tip");
  const move = (ev) => {
    const r = svg.getBoundingClientRect();
    const px = (ev.clientX - r.left) * (W / r.width);
    const i = Math.max(0, Math.min(points.length - 1, Math.round(((px - pl) / (W - pl - pr)) * (points.length - 1))));
    const p = points[i];
    ["eqX", "eqDot"].forEach((id) => svg.getElementById(id).setAttribute("visibility", "visible"));
    svg.getElementById("eqX").setAttribute("x1", x(i)); svg.getElementById("eqX").setAttribute("x2", x(i));
    svg.getElementById("eqDot").setAttribute("cx", x(i)); svg.getElementById("eqDot").setAttribute("cy", y(p.v));
    const d = p.v - vs[0];
    tip.innerHTML = `${esc(p.t)}<br><b>${money(p.v, false)} $</b> <span class="${cls(d)}">${money(d)}</span>`;
    tip.hidden = false;
    tip.style.left = Math.min(window.innerWidth - 150, ev.clientX + 12) + "px";
    tip.style.top = (ev.clientY - 50) + "px";
  };
  const leave = () => { tip.hidden = true; ["eqX", "eqDot"].forEach((id) => svg.getElementById(id).setAttribute("visibility", "hidden")); };
  svg.addEventListener("pointermove", move); svg.addEventListener("pointerdown", move); svg.addEventListener("pointerleave", leave);
  box.dataset.trend = up ? "up" : "down";
}

function drawStrategies(by) {
  const keys = Object.keys(STRAT);
  const maxAbs = Math.max(1e-9, ...keys.map((k) => Math.abs(by[k]?.pnl || 0)));
  $("stratBars").innerHTML = keys.map((k) => {
    const s = by[k] || { pnl: 0, trades: 0, wins: 0 };
    const w = (Math.abs(s.pnl) / maxAbs) * 50;
    const left = s.pnl >= 0 ? 50 : 50 - w;
    const wr = s.trades ? Math.round((s.wins / s.trades) * 100) + "%" : "—";
    return `<div class="bar-row"><div>${STRAT[k]}<div class="muted small">${s.trades} сд. · ${wr}</div></div>
      <div class="bar-track"><span class="axis"></span><span class="bar" style="left:${left}%;width:${Math.max(w, s.pnl ? 1 : 0)}%;background:${s.pnl >= 0 ? "var(--win)" : "var(--loss)"}"></span></div>
      <div class="bar-val num ${cls(s.pnl)}">${money(s.pnl)}</div></div>`;
  }).join("");
}

// ───────────── календарь ─────────────
function mix(a, b, t) {
  const h = (c) => [1, 3, 5].map((i) => parseInt(c.slice(i, i + 2), 16));
  const [x, y] = [h(a), h(b)];
  return "rgb(" + x.map((v, i) => Math.round(v + (y[i] - v) * t)).join(",") + ")";
}
$("prevM").addEventListener("click", () => { state.month.setMonth(state.month.getMonth() - 1); loadCalendar(); });
$("nextM").addEventListener("click", () => { state.month.setMonth(state.month.getMonth() + 1); loadCalendar(); });

async function loadCalendar() {
  const y = state.month.getFullYear(), m = state.month.getMonth();
  $("calTitle").textContent = `${MONTHS[m]} ${y}`;
  let data;
  try { data = await api(`/calendar?month=${y}-${String(m + 1).padStart(2, "0")}&tz=${TZ}`); } catch (e) { toast(e.message); return; }
  const first = (new Date(y, m, 1).getDay() + 6) % 7;
  const days = new Date(y, m + 1, 0).getDate();
  const vals = Object.values(data.days).map((d) => Math.abs(d.pnl));
  const maxAbs = Math.max(1e-9, ...vals);
  const today = new Date(); const todayKey = `${today.getFullYear()}-${String(today.getMonth() + 1).padStart(2, "0")}-${String(today.getDate()).padStart(2, "0")}`;
  let html = "";
  for (let i = 0; i < first; i++) html += `<div class="day blank"></div>`;
  for (let d = 1; d <= days; d++) {
    const key = `${y}-${String(m + 1).padStart(2, "0")}-${String(d).padStart(2, "0")}`;
    const v = data.days[key];
    let bg = "", label = "";
    if (v && v.trades) {
      const t = 0.25 + 0.75 * Math.min(1, Math.abs(v.pnl) / maxAbs);
      bg = v.pnl === 0 ? "" : `background:${mix("#2a2d35", v.pnl > 0 ? "#26a69a" : "#ef5350", t)}`;
      label = compact(v.pnl);
    }
    html += `<div class="day ${key === todayKey ? "today" : ""}" style="${bg}" data-k="${key}" role="button" aria-label="${d} ${MONTHS[m]}: ${v ? money(v.pnl) + " USDT" : "нет сделок"}"><span class="d">${d}</span><span class="p num">${label}</span></div>`;
  }
  $("calGrid").innerHTML = html;
  $("calGrid").querySelectorAll(".day[data-k]").forEach((el) => el.addEventListener("click", () => openDay(el.dataset.k, data.days[el.dataset.k])));
  const t = data.total;
  setNum($("mTotal"), t.pnl, " $");
  $("mTrades").textContent = t.trades; $("mWin").textContent = t.trades ? `винрейт ${t.winrate}%` : "";
  setNum($("mBest"), t.best_day);
  $("mDays").innerHTML = `<span class="pos">${t.green_days}</span> / <span class="neg">${t.red_days}</span>`;
}

async function openDay(key, summary) {
  haptic();
  const [y, m, d] = key.split("-");
  $("sheetTitle").textContent = `${Number(d)} ${MONTHS[Number(m) - 1]} ${y}`;
  const sum = $("sheetSum"); sum.textContent = summary ? money(summary.pnl) + " $" : ""; sum.className = "num " + (summary ? cls(summary.pnl) : "");
  $("sheetBody").innerHTML = `<div class="empty">Загрузка…</div>`;
  $("sheet").hidden = false; $("sheetBg").hidden = false;
  try {
    const r = await api(`/day?d=${key}&tz=${TZ}`);
    $("sheetBody").innerHTML = r.trades.map(tradeRow).join("") || `<div class="empty">В этот день сделок не было</div>`;
  } catch (e) { $("sheetBody").innerHTML = `<div class="empty">${esc(e.message)}</div>`; }
}
$("sheetBg").addEventListener("click", () => { $("sheet").hidden = true; $("sheetBg").hidden = true; });

// ───────────── сделки ─────────────
function tradeRow(t) {
  return `<div class="row"><div class="l"><b>${esc(t.symbol)}</b><span class="tag">${STRAT[t.strategy] || esc(t.strategy)}</span>
    <div class="muted">${t.date ? esc(t.date) + " " : ""}${esc(t.time)} · ${t.side === "Buy" ? "лонг" : "шорт"} ${t.entry} → ${t.exit}</div></div>
    <div class="r num ${cls(t.pnl)}">${money(t.pnl)}</div></div>`;
}
async function loadTrades() {
  try {
    const rows = await api("/trades?tz=" + TZ);
    $("tradeList").innerHTML = rows.map(tradeRow).join("") || `<div class="empty">Сделок пока нет. Запустите бота на главной.</div>`;
  } catch (e) { toast(e.message); }
}

// ───────────── ИИ ─────────────
async function loadAI() {
  try {
    const r = await api("/market");
    if (!r.ai_enabled) $("aiNote").textContent = "ИИ-ключ не подключён: режим рынка определяется алгоритмом. Бот всё равно учится на результатах сделок.";
    $("marketList").innerHTML = r.market.map((m) => `<div class="card market">
      <div class="card-h"><div><b>${esc(m.symbol)}</b> <span class="muted small num">${m.price ?? ""}</span>
        ${m.change_24h != null ? `<span class="small num ${cls(m.change_24h)}">${m.change_24h > 0 ? "+" : ""}${m.change_24h}%</span>` : ""}</div>
        <span class="regime ${esc(m.regime)}">${REGIME[m.regime] || esc(m.regime)}</span></div>
      <div class="small">${esc(m.summary)}</div>
      <div class="weights">${[["Сетка", m.w_grid], ["Тренд", m.w_trend], ["Ликвид.", m.w_liquidation]].map(([n, v]) =>
        `<span>${n}</span><span class="wbar"><i style="width:${Math.round((v || 0) * 100)}%"></i></span><span class="num">${Math.round((v || 0) * 100)}</span>`).join("")}</div>
      <div class="muted small" style="margin-top:8px">сетка: ${m.grid_mode === "off" ? "выкл" : m.grid_mode === "long" ? "лонг" : "шорт"} · риск ×${Number(m.risk_mult).toFixed(2)} · ${m.source === "ai" ? "анализ ИИ" : "алгоритм"}</div>
    </div>`).join("") || `<div class="card empty">Данные рынка загружаются…</div>`;
    $("lessons").innerHTML = r.lessons.map((l) => `<li>${esc(l)}</li>`).join("") || `<li class="muted">Уроки появятся после первого дня торговли</li>`;
  } catch (e) { toast(e.message); }
}

// ───────────── настройки ─────────────
function renderSettings() {
  const me = state.me; if (!me) return;
  const s = me.settings;
  state.draft = { trading_mode: s.trading_mode, risk_profile: s.risk_profile, symbols: [...s.symbols], strategies: { ...s.strategies } };
  const d = state.draft;
  const seg = (id, val, attr = "v") => $(id).querySelectorAll("button").forEach((b) => b.classList.toggle("on", b.dataset[attr] === val));
  seg("modeSeg", d.trading_mode);
  $("modeHint").textContent = d.trading_mode === "paper"
    ? `Виртуальный счёт ${money(s.paper_balance, false)} $ на реальных ценах Bybit. Бесплатно.`
    : "Реальные ордера на подключённом аккаунте Bybit. Нужна подписка.";
  $("profiles").innerHTML = me.options.profiles.map((p) => `<div class="profile ${p.code === d.risk_profile ? "on" : ""}" data-p="${p.code}">
    <b>${esc(p.title)}</b><div class="muted">риск ${p.risk_pct}% на сделку · плечо до ${p.leverage}x · стоп дня −${p.daily_loss_pct}% · сетка ${p.grid_levels} уровней</div></div>`).join("");
  $("profiles").querySelectorAll(".profile").forEach((el) => el.addEventListener("click", () => {
    d.risk_profile = el.dataset.p; $("profiles").querySelectorAll(".profile").forEach((x) => x.classList.toggle("on", x === el)); haptic();
  }));
  $("coins").innerHTML = me.options.symbols.map((c) => `<button class="chip ${d.symbols.includes(c) ? "on" : ""}" data-c="${c}">${c.replace("USDT", "")}</button>`).join("");
  $("coins").querySelectorAll(".chip").forEach((el) => el.addEventListener("click", () => {
    const c = el.dataset.c;
    if (d.symbols.includes(c)) d.symbols = d.symbols.filter((x) => x !== c);
    else if (d.symbols.length < 8) d.symbols.push(c); else return toast("Максимум 8 монет");
    el.classList.toggle("on", d.symbols.includes(c)); haptic();
  }));
  document.querySelectorAll("[data-s]").forEach((el) => { el.checked = !!d.strategies[el.dataset.s]; el.onchange = () => (d.strategies[el.dataset.s] = el.checked); });

  const ex = me.exchange;
  $("exBadge").textContent = ex ? `UID ${ex.uid} · ${ex.mode === "live" ? "реальный" : "демо"}` : "не подключено";
  $("exBadge").className = "badge" + (ex ? " ok" : "");
  $("disconnectBtn").hidden = !ex;
  $("refLink").href = me.options.referral_link;
  seg("exModeSeg", state.exMode);
  const u = me.user;
  $("subBadge").textContent = u.has_subscription ? "до " + new Date(u.subscription_until).toLocaleDateString("ru-RU") : "нет";
  $("subBadge").className = "badge" + (u.has_subscription ? " ok" : "");
  $("plans").innerHTML = me.options.plans.map((p) => `<button class="plan" data-plan="${p.code}"><b>${esc(p.title)}</b><span>⭐ ${p.stars}</span></button>`).join("");
  $("plans").querySelectorAll(".plan").forEach((el) => el.addEventListener("click", () => buy(el.dataset.plan)));
}

$("modeSeg").querySelectorAll("button").forEach((b) => b.addEventListener("click", () => {
  state.draft.trading_mode = b.dataset.v;
  $("modeSeg").querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b));
}));
$("exModeSeg").querySelectorAll("button").forEach((b) => b.addEventListener("click", () => {
  state.exMode = b.dataset.v;
  $("exModeSeg").querySelectorAll("button").forEach((x) => x.classList.toggle("on", x === b));
}));

$("saveSettings").addEventListener("click", async () => {
  try {
    await api("/settings", { method: "PUT", body: JSON.stringify(state.draft) });
    toast("Сохранено"); haptic("medium"); await loadMe(); renderSettings();
  } catch (e) { toast(e.message); }
});
$("paperReset").addEventListener("click", async () => {
  try { await api("/paper/reset", { method: "POST" }); toast("Демо-счёт сброшен"); await loadMe(); renderSettings(); } catch (e) { toast(e.message); }
});
$("connectBtn").addEventListener("click", async () => {
  const btn = $("connectBtn"); btn.disabled = true; btn.textContent = "Проверяем ключ…";
  try {
    const r = await api("/exchange", { method: "POST", body: JSON.stringify({ api_key: $("apiKey").value, api_secret: $("apiSecret").value, mode: state.exMode }) });
    $("apiKey").value = ""; $("apiSecret").value = "";
    toast(`Bybit подключён (UID ${r.uid})`); haptic("medium"); await loadMe(); renderSettings();
  } catch (e) { toast(e.message); }
  btn.disabled = false; btn.textContent = "Подключить";
});
$("disconnectBtn").addEventListener("click", async () => {
  try { await api("/exchange", { method: "DELETE" }); toast("Биржа отключена"); await loadMe(); renderSettings(); } catch (e) { toast(e.message); }
});

async function buy(plan) {
  try {
    const { link } = await api("/pay", { method: "POST", body: JSON.stringify({ plan }) });
    if (tg && tg.openInvoice) {
      tg.openInvoice(link, async (status) => {
        if (status === "paid") { toast("Оплачено! Подписка активирована"); setTimeout(async () => { await loadMe(); renderSettings(); }, 1500); }
      });
    } else window.open(link, "_blank");
  } catch (e) { toast(e.message); }
}

// ───────────── старт ─────────────
loadHome();
setInterval(() => { if ($("s-home").classList.contains("active")) loadMe().catch(() => {}); }, 15000);
