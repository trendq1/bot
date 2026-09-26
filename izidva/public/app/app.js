"use strict";
const tg = window.Telegram ? window.Telegram.WebApp : null;
if (tg) {
  tg.ready(); tg.expand();
  try { tg.setHeaderColor("#0d0f13"); tg.setBackgroundColor("#0d0f13"); } catch (e) {}
  try { tg.disableVerticalSwipes && tg.disableVerticalSwipes(); } catch (e) {}   // не сворачивать приложение свайпом вниз
}
applyI18n();

const TZ = -new Date().getTimezoneOffset();
const $ = (id) => document.getElementById(id);
const state = { me: null, month: new Date(), exMode: "demo", draft: null };
const locale = () => (LANG === "ru" ? "ru-RU" : "uk-UA");
const STRAT = () => ({ grid: t("grid_title"), trend: t("trend_title"), liquidation: t("liquidation_title") });
const REGIME = () => ({ trend_up: t("regime_trend_up"), trend_down: t("regime_trend_down"), range: t("regime_range"), high_volatility: t("regime_high_volatility") });

// ───────────── утилиты ─────────────
async function api(path, opts = {}) {
  const res = await fetch("../api/index.php" + path, {
    ...opts,
    headers: { "Content-Type": "application/json", "X-Init-Data": tg ? tg.initData : "", ...(opts.headers || {}) },
  });
  const data = await res.json().catch(() => ({}));
  if (!res.ok) throw new Error(data.detail || "Error " + res.status);
  return data;
}
function toast(text) {
  const el = $("toast"); el.textContent = text; el.hidden = false;
  clearTimeout(toast._t); toast._t = setTimeout(() => (el.hidden = true), 3200);
}
function haptic(kind = "light") { try { tg && tg.HapticFeedback.impactOccurred(kind); } catch (e) {} }
const money = (v, sign = true) => v == null ? "—" : (sign && v > 0 ? "+" : "") + Number(v).toLocaleString(locale(), { minimumFractionDigits: 2, maximumFractionDigits: 2 });
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
  $("hello").textContent = user.name ? t("hello_user", { name: user.name }) : t("hello_default");
  const paper = settings.trading_mode === "paper";
  $("modeLine").textContent = paper ? t("mode_demo") : (state.me.exchange ? (state.me.exchange.mode === "live" ? t("mode_bybit_live") : t("mode_bybit_demo")) : t("mode_bybit"));
  const pill = $("statusPill");
  pill.classList.toggle("on", settings.running && !String(status).startsWith("⏸"));
  pill.classList.toggle("warn", String(status).startsWith("⏸") || /^(ошибка|помилка)/.test(String(status)));
  $("statusText").textContent = settings.running ? t("running") : t("stopped");
  $("statusDetail").textContent = settings.running ? status : "";
  const btn = $("toggleBtn");
  btn.textContent = settings.running ? t("stop_bot") : t("start_bot");
  btn.className = "btn big " + (settings.running ? "danger" : "primary");
  const eq = live && live.equity != null ? live.equity : (paper ? settings.paper_balance : null);
  $("equity").textContent = eq == null ? "—" : Number(eq).toLocaleString(locale(), { maximumFractionDigits: 2 }) + " $";
  renderLive(live);
}

$("toggleBtn").addEventListener("click", async () => {
  const running = state.me.settings.running;
  if (!running && state.me.settings.trading_mode === "exchange" && tg && tg.showConfirm) {
    const ok = await new Promise((r) => tg.showConfirm(t("confirm_live_trading"), r));
    if (!ok) return;
  }
  try { await api("/bot/" + (running ? "stop" : "start"), { method: "POST" }); haptic("medium"); await loadMe(); }
  catch (e) { toast(e.message); }
});

function renderLive(live) {
  const strat = STRAT();
  const rows = [];
  (live?.grids || []).forEach((g) => rows.push(`<div class="row"><div class="l"><b>${esc(g.symbol)}</b><span class="tag">${g.mode === "long" ? t("grid_long") : t("grid_short")}</span><div class="muted">${t("grid_step_filled", { step: g.step_pct, filled: g.filled, levels: g.levels })}</div></div><div class="r muted">${t("grid_tag")}</div></div>`));
  (live?.directional || []).forEach((d) => rows.push(`<div class="row"><div class="l"><b>${esc(d.symbol)}</b><span class="tag">${strat[d.strategy] || d.strategy}</span><div class="muted">${d.entry} · ${d.stop}</div></div><div class="r ${d.side === "Buy" ? "pos" : "neg"}">${d.side === "Buy" ? "LONG" : "SHORT"}</div></div>`));
  $("liveList").innerHTML = rows.join("") || `<div class="empty">${t("no_open_positions")}</div>`;
}

// ───────────── главная ─────────────
async function loadHome() {
  try {
    await loadMe();
    const d = await api("/dashboard?tz=" + TZ);
    setNum($("todayPnl"), d.today.pnl, " $");
    [["kToday", d.today], ["kWeek", d.week], ["kMonth", d.month]].forEach(([id, p]) => {
      setNum($(id), p.pnl); $(id + "N").textContent = t("n_trades", { n: p.trades });
    });
    $("kWin").textContent = d.month.trades ? d.month.winrate + "%" : "—";
    drawEquity(d.equity_curve);
    drawStrategies(d.by_strategy);
  } catch (e) { toast(e.message); }
}

function drawEquity(points) {
  const box = $("equityChart");
  if (!points || points.length < 2) { box.innerHTML = `<div class="empty">${t("chart_placeholder")}</div>`; return; }
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
  box.innerHTML = `<svg viewBox="0 0 ${W} ${H}" role="img" aria-label="${t("profitability")} 30d">
    <defs><linearGradient id="eqf" x1="0" x2="0" y1="0" y2="1"><stop offset="0" stop-color="#3987e5" stop-opacity=".28"/><stop offset="1" stop-color="#3987e5" stop-opacity="0"/></linearGradient></defs>
    ${ticks.map((tk) => `<line x1="${pl}" x2="${W - pr}" y1="${y(tk)}" y2="${y(tk)}" stroke="#262a33" stroke-width="1"/><text x="${pl - 6}" y="${y(tk) + 4}" fill="#7d8290" font-size="10" text-anchor="end">${Math.round(tk).toLocaleString(locale())}</text>`).join("")}
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
  const strat = STRAT();
  const keys = Object.keys(strat);
  const maxAbs = Math.max(1e-9, ...keys.map((k) => Math.abs(by[k]?.pnl || 0)));
  $("stratBars").innerHTML = keys.map((k) => {
    const s = by[k] || { pnl: 0, trades: 0, wins: 0 };
    const w = (Math.abs(s.pnl) / maxAbs) * 50;
    const left = s.pnl >= 0 ? 50 : 50 - w;
    const wr = s.trades ? Math.round((s.wins / s.trades) * 100) + "%" : "—";
    return `<div class="bar-row"><div>${strat[k]}<div class="muted small">${t("n_trades_short", { n: s.trades })} · ${wr}</div></div>
      <div class="bar-track"><span class="axis"></span><span class="bar" style="left:${left}%;width:${Math.max(w, s.pnl ? 1 : 0)}%;background:${s.pnl >= 0 ? "var(--win)" : "var(--loss)"}"></span></div>
      <div class="bar-val num ${cls(s.pnl)}">${money(s.pnl)}</div></div>`;
  }).join("");
}

// ───────────── календарь ─────────────
function mix(a, b, frac) {
  const h = (c) => [1, 3, 5].map((i) => parseInt(c.slice(i, i + 2), 16));
  const [x, y] = [h(a), h(b)];
  return "rgb(" + x.map((v, i) => Math.round(v + (y[i] - v) * frac)).join(",") + ")";
}
$("prevM").addEventListener("click", () => { state.month.setMonth(state.month.getMonth() - 1); loadCalendar(); });
$("nextM").addEventListener("click", () => { state.month.setMonth(state.month.getMonth() + 1); loadCalendar(); });

async function loadCalendar() {
  const months = t("months");
  const y = state.month.getFullYear(), m = state.month.getMonth();
  $("calTitle").textContent = `${months[m]} ${y}`;
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
      const frac = 0.25 + 0.75 * Math.min(1, Math.abs(v.pnl) / maxAbs);
      bg = v.pnl === 0 ? "" : `background:${mix("#2a2d35", v.pnl > 0 ? "#26a69a" : "#ef5350", frac)}`;
      label = compact(v.pnl);
    }
    html += `<div class="day ${key === todayKey ? "today" : ""}" style="${bg}" data-k="${key}" role="button" aria-label="${d} ${months[m]}: ${v ? money(v.pnl) + " USDT" : t("no_trades")}"><span class="d">${d}</span><span class="p num">${label}</span></div>`;
  }
  $("calGrid").innerHTML = html;
  $("calGrid").querySelectorAll(".day[data-k]").forEach((el) => el.addEventListener("click", () => openDay(el.dataset.k, data.days[el.dataset.k])));
  const tot = data.total;
  setNum($("mTotal"), tot.pnl, " $");
  $("mTrades").textContent = tot.trades; $("mWin").textContent = tot.trades ? `${t("winrate_label")} ${tot.winrate}%` : "";
  setNum($("mBest"), tot.best_day);
  $("mDays").innerHTML = `<span class="pos">${tot.green_days}</span> / <span class="neg">${tot.red_days}</span>`;
}

async function openDay(key, summary) {
  haptic();
  const months = t("months");
  const [y, m, d] = key.split("-");
  $("sheetTitle").textContent = `${Number(d)} ${months[Number(m) - 1]} ${y}`;
  const sum = $("sheetSum"); sum.textContent = summary ? money(summary.pnl) + " $" : ""; sum.className = "num " + (summary ? cls(summary.pnl) : "");
  $("sheetBody").innerHTML = `<div class="empty">${t("loading")}</div>`;
  $("sheet").hidden = false; $("sheetBg").hidden = false;
  try {
    const r = await api(`/day?d=${key}&tz=${TZ}`);
    $("sheetBody").innerHTML = r.trades.map(tradeRow).join("") || `<div class="empty">${t("no_trades_that_day")}</div>`;
  } catch (e) { $("sheetBody").innerHTML = `<div class="empty">${esc(e.message)}</div>`; }
}
$("sheetBg").addEventListener("click", () => { $("sheet").hidden = true; $("sheetBg").hidden = true; });

// ───────────── сделки ─────────────
function tradeRow(row) {
  const strat = STRAT();
  return `<div class="row"><div class="l"><b>${esc(row.symbol)}</b><span class="tag">${strat[row.strategy] || esc(row.strategy)}</span>
    <div class="muted">${row.date ? esc(row.date) + " " : ""}${esc(row.time)} · ${row.side === "Buy" ? t("long") : t("short")} ${row.entry} → ${row.exit}</div></div>
    <div class="r num ${cls(row.pnl)}">${money(row.pnl)}</div></div>`;
}
async function loadTrades() {
  try {
    const rows = await api("/trades?tz=" + TZ);
    $("tradeList").innerHTML = rows.map(tradeRow).join("") || `<div class="empty">${t("no_trades_yet")}</div>`;
  } catch (e) { toast(e.message); }
}

// ───────────── ИИ ─────────────
async function loadAI() {
  try {
    const r = await api("/market");
    $("aiNote").textContent = r.ai_enabled ? t("ai_note_default") : t("ai_no_key");
    const regime = REGIME();
    $("marketList").innerHTML = r.market.map((m) => `<div class="card market">
      <div class="card-h"><div><b>${esc(m.symbol)}</b> <span class="muted small num">${m.price ?? ""}</span>
        ${m.change_24h != null ? `<span class="small num ${cls(m.change_24h)}">${m.change_24h > 0 ? "+" : ""}${m.change_24h}%</span>` : ""}</div>
        <span class="regime ${esc(m.regime)}">${regime[m.regime] || esc(m.regime)}</span></div>
      <div class="small">${esc(m.summary)}</div>
      <div class="weights">${[[t("grid_title"), m.w_grid], [t("trend_title"), m.w_trend], [t("liquidation_title"), m.w_liquidation]].map(([n, v]) =>
        `<span>${n}</span><span class="wbar"><i style="width:${Math.round((v || 0) * 100)}%"></i></span><span class="num">${Math.round((v || 0) * 100)}</span>`).join("")}</div>
      <div class="muted small" style="margin-top:8px">${t("grid_word")}: ${m.grid_mode === "off" ? t("grid_state_off") : m.grid_mode === "long" ? t("grid_state_long") : t("grid_state_short")} · ${t("risk_word")} ×${Number(m.risk_mult).toFixed(2)} · ${m.source === "ai" ? t("ai_source") : t("algo_source")}</div>
    </div>`).join("") || `<div class="card empty">${t("market_loading")}</div>`;
    $("lessons").innerHTML = r.lessons.map((l) => `<li>${esc(l)}</li>`).join("") || `<li class="muted">${t("lessons_placeholder")}</li>`;
  } catch (e) { toast(e.message); }
}

// ───────────── настройки ─────────────
$("langSeg").querySelectorAll("button").forEach((b) => {
  b.classList.toggle("on", b.dataset.v === LANG);
  b.addEventListener("click", () => setLang(b.dataset.v));
});

function renderSettings() {
  const me = state.me; if (!me) return;
  const s = me.settings;
  const planTitle = { week: t("plan_week"), month: t("plan_month"), quarter: t("plan_quarter") };
  state.draft = { trading_mode: s.trading_mode, risk_profile: s.risk_profile, symbols: [...s.symbols], strategies: { ...s.strategies } };
  const d = state.draft;
  const seg = (id, val, attr = "v") => $(id).querySelectorAll("button").forEach((b) => b.classList.toggle("on", b.dataset[attr] === val));
  seg("modeSeg", d.trading_mode);
  $("modeHint").textContent = d.trading_mode === "paper" ? t("paper_mode_hint", { balance: money(s.paper_balance, false) }) : t("exchange_mode_hint");
  $("profiles").innerHTML = me.options.profiles.map((p) => `<div class="profile ${p.code === d.risk_profile ? "on" : ""}" data-p="${p.code}">
    <b>${esc(p.title)}</b><div class="muted">${t("profile_desc", { risk: p.risk_pct, leverage: p.leverage, daily_loss: p.daily_loss_pct, grid_levels: p.grid_levels })}</div></div>`).join("");
  $("profiles").querySelectorAll(".profile").forEach((el) => el.addEventListener("click", () => {
    d.risk_profile = el.dataset.p; $("profiles").querySelectorAll(".profile").forEach((x) => x.classList.toggle("on", x === el)); haptic();
  }));
  $("coins").innerHTML = me.options.symbols.map((c) => `<button class="chip ${d.symbols.includes(c) ? "on" : ""}" data-c="${c}">${c.replace("USDT", "")}</button>`).join("");
  $("coins").querySelectorAll(".chip").forEach((el) => el.addEventListener("click", () => {
    const c = el.dataset.c;
    if (d.symbols.includes(c)) d.symbols = d.symbols.filter((x) => x !== c);
    else if (d.symbols.length < 8) d.symbols.push(c); else return toast(t("max_8_coins"));
    el.classList.toggle("on", d.symbols.includes(c)); haptic();
  }));
  document.querySelectorAll("[data-s]").forEach((el) => { el.checked = !!d.strategies[el.dataset.s]; el.onchange = () => (d.strategies[el.dataset.s] = el.checked); });

  const ex = me.exchange;
  $("exBadge").textContent = ex ? t("uid_mode", { uid: ex.uid, mode: ex.mode === "live" ? t("mode_live") : t("mode_demo_short") }) : t("not_connected");
  $("exBadge").className = "badge" + (ex ? " ok" : "");
  $("disconnectBtn").hidden = !ex;
  $("refLink").href = me.options.referral_link;
  seg("exModeSeg", state.exMode);
  const u = me.user;
  $("subBadge").textContent = u.has_subscription ? t("sub_until", { date: new Date(u.subscription_until).toLocaleDateString(locale()) }) : t("none");
  $("subBadge").className = "badge" + (u.has_subscription ? " ok" : "");
  $("plans").innerHTML = me.options.plans.map((p) => `<button class="plan" data-plan="${p.code}"><b>${esc(planTitle[p.code] || p.title)}</b><span>⭐ ${p.stars}</span></button>`).join("");
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
    toast(t("saved")); haptic("medium"); await loadMe(); renderSettings();
  } catch (e) { toast(e.message); }
});
$("paperReset").addEventListener("click", async () => {
  try { await api("/paper/reset", { method: "POST" }); toast(t("saved")); await loadMe(); renderSettings(); } catch (e) { toast(e.message); }
});
$("connectBtn").addEventListener("click", async () => {
  const btn = $("connectBtn"); btn.disabled = true; btn.textContent = t("connecting");
  try {
    const r = await api("/exchange", { method: "POST", body: JSON.stringify({ api_key: $("apiKey").value, api_secret: $("apiSecret").value, mode: state.exMode }) });
    $("apiKey").value = ""; $("apiSecret").value = "";
    toast(t("bybit_connected", { uid: r.uid })); haptic("medium"); await loadMe(); renderSettings();
  } catch (e) { toast(e.message); }
  btn.disabled = false; btn.textContent = t("connect");
});
$("disconnectBtn").addEventListener("click", async () => {
  try { await api("/exchange", { method: "DELETE" }); toast(t("exchange_disconnected")); await loadMe(); renderSettings(); } catch (e) { toast(e.message); }
});

async function buy(plan) {
  try {
    const { link } = await api("/pay", { method: "POST", body: JSON.stringify({ plan }) });
    if (tg && tg.openInvoice) {
      tg.openInvoice(link, async (status) => {
        if (status === "paid") { toast(t("payment_success")); setTimeout(async () => { await loadMe(); renderSettings(); }, 1500); }
      });
    } else window.open(link, "_blank");
  } catch (e) { toast(e.message); }
}

// ───────────── старт ─────────────
loadHome();
setInterval(() => { if ($("s-home").classList.contains("active")) loadMe().catch(() => {}); }, 15000);
