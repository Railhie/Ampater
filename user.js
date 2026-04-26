// ═══════════════════════════════════════════════════════════════
//  user.js  —  SuffraTech Student Portal
// ═══════════════════════════════════════════════════════════════

"use strict";

// ── Page navigation ─────────────────────────────────────────────
let resultsInterval = null;

function showPage(page, btn) {
  document.querySelectorAll(".section").forEach((s) => (s.style.display = "none"));
  document.querySelectorAll(".sf-nav-btn").forEach((n) => n.classList.remove("active"));

  const sec = document.getElementById(page);
  if (sec) sec.style.display = "";

  const navBtn = btn || document.querySelector(`.sf-nav-btn[data-page="${page}"]`);
  if (navBtn) navBtn.classList.add("active");

  if (page === "vote") {
    const picker = document.getElementById("electionPickerScreen");
    const voting  = document.getElementById("votingScreen");
    if (picker) picker.style.display = "";
    if (voting)  voting.style.display  = "none";
  }

  if (page === "feedback") {
    loadReviews();  
    applyReviewedState();
  }

  if (page === "results") {
    clearInterval(resultsInterval);
    loadResults();
    resultsInterval = setInterval(loadResults, 5000);
  } else {
    clearInterval(resultsInterval);
  }
}

function navTo(page) {
  const btn = document.querySelector(`.sf-nav-btn[data-page="${page}"]`);
  showPage(page, btn);
}

// ── Modal helpers ───────────────────────────────────────────────
function openModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.add("active");
}
function closeModal(id) {
  const m = document.getElementById(id);
  if (m) m.classList.remove("active");
}

document.addEventListener("DOMContentLoaded", () => {
  document.querySelectorAll(".modal-overlay").forEach((overlay) => {
    overlay.addEventListener("click", function (e) {
      if (e.target === this) closeModal(this.id);
    });
  });
});

// ── Toast ───────────────────────────────────────────────────────
let _toastTimer = null;
function showToast(icon, msg) {
  const t = document.getElementById("toast");
  if (!t) return;
  const ti = document.getElementById("toastIcon");
  const tm = document.getElementById("toastMsg");
  if (ti) ti.textContent = icon;
  if (tm) tm.textContent = msg;
  t.classList.add("show");
  clearTimeout(_toastTimer);
  _toastTimer = setTimeout(() => t.classList.remove("show"), 4000);
}

// ── Logout ──────────────────────────────────────────────────────
function confirmLogout() { openModal("logoutModal"); }
function doLogout() {
  const f = document.createElement("form");
  f.method = "POST"; f.action = "user.php";
  const i = document.createElement("input");
  i.type = "hidden"; i.name = "action"; i.value = "logout";
  f.appendChild(i); document.body.appendChild(f); f.submit();
}

// ── Election notification modal ─────────────────────────────────
function closeNotifModal(page) {
  const m = document.getElementById("electionNotifModal");
  if (m) m.classList.remove("active");
  if (page && page !== "home") navTo(page);
}

document.addEventListener("DOMContentLoaded", () => {
  const m = document.getElementById("electionNotifModal");
  if (m) m.addEventListener("click", (e) => { if (e.target === m) closeNotifModal("home"); });
});

// ── Election picker ─────────────────────────────────────────────
function enterVoting(type) {
  const picker = document.getElementById("electionPickerScreen");
  const voting  = document.getElementById("votingScreen");
  if (picker) picker.style.display = "none";
  if (voting)  voting.style.display  = "";

  document.querySelectorAll(".vote-panel").forEach((p) => p.classList.remove("active"));
  const panel = document.getElementById("panel-" + type);
  if (panel) panel.classList.add("active");

  currentVoteType = type;
}

function backToElectionPicker() {
  const voting  = document.getElementById("votingScreen");
  const picker  = document.getElementById("electionPickerScreen");
  if (voting)  voting.style.display  = "none";
  if (picker)  picker.style.display  = "";
}

// ── Vote tab switching ──────────────────────────────────────────
let currentVoteType  = "general";
let pendingVoteType  = "general";

function switchVoteTab(type) {
  if (currentVoteType !== type) {
    if (type === "general") selectedVotes     = {};
    else                    selectedClasVotes = {};
  }
  currentVoteType = type;

  document.querySelectorAll("#voteTabNav .nav-link").forEach((t) => {
    t.classList.remove("active");
    t.style.background = "transparent";
    t.style.color  = t.id === "tab-clas" ? "#6366f1" : "#198754";
    t.style.border = "1.5px solid #e2e8f0";
  });
  const activeTab = document.getElementById("tab-" + type);
  if (activeTab) { activeTab.classList.add("active"); activeTab.removeAttribute("style"); }

  document.querySelectorAll(".vote-panel").forEach((p) => p.classList.remove("active"));
  const panel = document.getElementById("panel-" + type);
  if (panel) panel.classList.add("active");
}

// ── Vote selection ──────────────────────────────────────────────
let selectedVotes     = {};
let selectedClasVotes = {};

function selectCandidate(card) {
  const posId  = card.dataset.positionId;
  const candId = card.dataset.candidateId;
  document.querySelectorAll(`#pos-group-${posId} .candidate-card`).forEach((c) => c.classList.remove("selected"));
  card.classList.add("selected");
  selectedVotes[posId] = candId;
}

function selectClasCandidate(card) {
  const posId  = card.dataset.positionId;
  const candId = card.dataset.candidateId;
  document.querySelectorAll(`#clas-pos-group-${posId} .candidate-card`).forEach((c) => c.classList.remove("selected"));
  card.classList.add("selected");
  selectedClasVotes[posId] = candId;
}

// ── Submit vote ─────────────────────────────────────────────────
function submitVote(type) {
  pendingVoteType = type;
  const isClas = type === "clas";
  const votes  = isClas ? selectedClasVotes : selectedVotes;

  // Collect all unique position IDs from rendered candidate cards
  const allCards    = document.querySelectorAll(`#panel-${type} .candidate-card`);
  const positionIds = [...new Set([...allCards].map((c) => c.dataset.positionId))];

  if (!positionIds.length) {
    showToast("⚠️", "No candidates found. Please refresh the page.");
    return;
  }

  // Validate every position has a selection
  let missingPosId = null;
  for (const pid of positionIds) {
    if (!votes[pid]) { missingPosId = pid; break; }
  }

  if (missingPosId !== null) {
    const grpId   = isClas ? `clas-pos-group-${missingPosId}` : `pos-group-${missingPosId}`;
    const grpEl   = document.getElementById(grpId);
    const section = grpEl ? grpEl.closest(".position-section") : null;
    const header  = section ? section.querySelector(".pos-header") : null;
    let posName   = "a position";
    if (header) {
      posName = [...header.childNodes]
        .filter((n) => n.nodeType === Node.TEXT_NODE)
        .map((n) => n.textContent.trim())
        .filter(Boolean)
        .join(" ") || header.textContent.replace(/Select\s*\d+/gi, "").trim() || "a position";
    }
    showToast("⚠️", `Please select a candidate for: ${posName}`);
    return;
  }

  // Build summary
  let summary = '<ul style="text-align:left;margin:12px 0 0;padding-left:18px">';
  for (const pid of positionIds) {
    const grpId    = isClas ? `clas-pos-group-${pid}` : `pos-group-${pid}`;
    const grpEl    = document.getElementById(grpId);
    const section  = grpEl ? grpEl.closest(".position-section") : null;
    const header   = section ? section.querySelector(".pos-header") : null;
    let posName    = "Position";
    if (header) {
      posName = [...header.childNodes]
        .filter((n) => n.nodeType === Node.TEXT_NODE)
        .map((n) => n.textContent.trim())
        .filter(Boolean)
        .join(" ") || header.textContent.replace(/Select\s*\d+/gi, "").trim() || "Position";
    }
    const selCard  = grpEl ? grpEl.querySelector(".candidate-card.selected") : null;
    const candName = selCard ? (selCard.querySelector(".candidate-name")?.textContent.trim() || "—") : "—";
    summary += `<li><strong>${posName}:</strong> ${candName}</li>`;
  }
  summary += "</ul>";

  document.getElementById("voteConfirmIcon").textContent  = isClas ? "🎓" : "🗳️";
  document.getElementById("voteConfirmTitle").textContent = isClas ? "Confirm CLAS Ballot" : "Confirm SSC Ballot";
  document.getElementById("voteConfirmText").innerHTML    =
    "Please review your selections. Once submitted, your vote cannot be changed." + summary;
  openModal("voteConfirmModal");
}

async function finalizeVote() {
  closeModal("voteConfirmModal");
  const confirmBtn = document.getElementById("confirmVoteBtn");
  if (confirmBtn) { confirmBtn.disabled = true; confirmBtn.textContent = "Submitting…"; }

  const isClas = pendingVoteType === "clas";
  const votes  = isClas ? selectedClasVotes : selectedVotes;
  const apiUrl = isClas ? "vote_submit_clas.php" : "vote_submit.php";

  try {
    const res  = await fetch(apiUrl, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ votes }),
    });
    const data = await res.json();
    if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = "Confirm & Submit"; }

    if (data.ok) {
      if (isClas) {
        const vc = document.getElementById("clasVoteContent");
        const vs = document.getElementById("clasVoteSuccess");
        if (vc) vc.style.display = "none";
        if (vs) vs.style.display = "";
        showToast("✅", "CLAS vote submitted successfully!");
        setTimeout(() => navToClasResults(), 2200);
      } else {
        const vc = document.getElementById("voteContent");
        const vs = document.getElementById("voteSuccess");
        if (vc) vc.style.display = "none";
        if (vs) vs.style.display = "";
        showToast("✅", "SSC vote submitted successfully!");
      }
      const statusBox = document.querySelector("#home .status-dynamic");
      if (statusBox) statusBox.innerHTML = "✅ Vote cast! <strong>Thank you for participating.</strong>";
    } else {
      showToast("⚠️", data.error || "Failed to submit vote. Please try again.");
    }
  } catch (err) {
    if (confirmBtn) { confirmBtn.disabled = false; confirmBtn.textContent = "Confirm & Submit"; }
    showToast("⚠️", "Network error. Please check your connection and try again.");
  }
}

// ═══════════════════════════════════════════════════════════════
//  LIVE RESULTS
// ═══════════════════════════════════════════════════════════════
let currentResultTab          = null;
let currentClasResultTab      = null;
let currentResultsElectionTab = "general";

function switchResultsElectionTab(type) {
  currentResultsElectionTab = type;

  document.querySelectorAll("#resultsTabNav .nav-link").forEach((t) => {
    t.classList.remove("active");
    t.style.background = "transparent";
    t.style.border     = "1.5px solid #e2e8f0";
  });
  const activeTab = document.getElementById("results-tab-" + type);
  if (activeTab) { activeTab.classList.add("active"); activeTab.removeAttribute("style"); }

  document.querySelectorAll(".results-election-panel").forEach((p) => (p.style.display = "none"));
  const panel = document.getElementById("results-panel-" + type);
  if (panel) panel.style.display = "";

  if (type === "general") loadGeneralResults();
  else                    loadClasResults();
}

async function loadGeneralResults() {
  const container = document.getElementById("generalResultsContainer");
  try {
    const res  = await fetch("results_api.php");
    const data = await res.json();

    if (!data.ok) {
      if (container) container.innerHTML =
        `<div style="text-align:center;padding:40px;color:#ef4444">⚠️ ${data.error || "Could not load results."}</div>`;
      return;
    }

    const positions = data.positions || [];
    if (!positions.length) {
      if (container) container.innerHTML =
        '<div style="text-align:center;padding:40px;color:#94a3b8">No General SSC results yet.</div>';
      return;
    }

    renderElectionResults("generalResultsContainer", positions, "general", currentResultTab);
    if (!currentResultTab && positions.length) currentResultTab = positions[0].position;
    updateStatsBadge(data);
  } catch (e) {
    if (container) container.innerHTML =
      '<div style="text-align:center;padding:40px;color:#ef4444">⚠️ Could not load SSC results. Please refresh.</div>';
  }
}

async function loadClasResults() {
  const container = document.getElementById("clasResultsContainer");
  try {
    const res  = await fetch("result_api_clas.php");
    const data = await res.json();

    if (!data.ok) {
      if (container) container.innerHTML =
        `<div style="text-align:center;padding:40px;color:#ef4444">⚠️ ${data.error || "Could not load results."}</div>`;
      return;
    }

    const positions = data.positions || [];
    if (!positions.length) {
      if (container) container.innerHTML =
        '<div style="text-align:center;padding:40px;color:#94a3b8">No CLAS Council results yet.</div>';
      return;
    }

    if (data.votes_cast !== undefined) {
      const el = (id) => document.getElementById(id);
      if (el("clasVotesCast"))         el("clasVotesCast").textContent         = data.votes_cast.toLocaleString();
      if (el("clasResultVotesCast"))   el("clasResultVotesCast").textContent   = data.votes_cast.toLocaleString();
      if (el("clasResultTotalVoters")) el("clasResultTotalVoters").textContent = data.total_voters.toLocaleString();
      if (el("clasResultTurnout") && data.total_voters > 0) {
        const pct = ((data.votes_cast / data.total_voters) * 100).toFixed(1);
        el("clasResultTurnout").textContent = pct + "%";
        if (el("clasResultTurnoutBar")) el("clasResultTurnoutBar").style.width = pct + "%";
      }
    }

    renderElectionResults("clasResultsContainer", positions, "clas", currentClasResultTab);
    if (!currentClasResultTab && positions.length) currentClasResultTab = positions[0].position;
  } catch (e) {
    if (container) container.innerHTML =
      '<div style="text-align:center;padding:40px;color:#ef4444">⚠️ Could not load CLAS results. Please refresh.</div>';
  }
}

function navToClasResults() {
  navTo("results");
  setTimeout(() => switchResultsElectionTab("clas"), 100);
}

function renderElectionResults(containerId, positions, elType, activeTab) {
  const container = document.getElementById(containerId);
  if (!container) return;

  const isClas      = elType === "clas";
  const accentColor = isClas ? "#6366f1" : "#10b981";
  const prefix      = isClas ? "clas-res-" : "gen-res-";

  const styleId = `result-tab-style-${elType}`;
  if (!document.getElementById(styleId)) {
    const s = document.createElement("style");
    s.id = styleId;
    s.textContent = `
      .res-tab-${elType}{padding:9px 20px;border-radius:50px;border:1.5px solid #e2e8f0;background:#fff;
        font-family:'DM Sans',sans-serif;font-size:13.5px;font-weight:600;color:#64748b;cursor:pointer;
        transition:all .18s;white-space:nowrap;}
      .res-tab-${elType}:hover{border-color:${accentColor};color:${accentColor};}
      .res-tab-${elType}.active{background:${accentColor};border-color:${accentColor};color:#fff;
        box-shadow:0 4px 14px ${accentColor}44;}`;
    document.head.appendChild(s);
  }

  let html = `<div style="display:flex;flex-wrap:wrap;gap:8px;margin-bottom:22px">`;
  positions.forEach((pos, i) => {
    const isActive = activeTab === pos.position || (!activeTab && i === 0);
    const safe = pos.position.replace(/\\/g, "\\\\").replace(/'/g, "\\'");
    html += `<button class="res-tab-${elType}${isActive ? " active" : ""}"
      onclick="switchInnerResultTab('${containerId}','${prefix}','${safe}',this,'${elType}')">${pos.position}</button>`;
  });
  html += `</div>`;

  positions.forEach((pos, i) => {
    const visible = activeTab === pos.position || (!activeTab && i === 0);
    const safeId  = prefix + pos.position.replace(/[^a-zA-Z0-9]/g, "-");
    html += `<div class="card" id="${safeId}" style="${visible ? "" : "display:none"}">`;
    html += `<div class="card-title" style="margin-bottom:20px">${pos.position} Race</div>`;

    if (!pos.candidates || !pos.candidates.length) {
      html += '<p style="color:#94a3b8;padding:8px 0">No candidates yet.</p>';
    } else {
      const rankStyles = [
        "background:#fef9c3;color:#854d0e;border:1.5px solid #fde68a",
        "background:#f1f5f9;color:#475569;border:1.5px solid #cbd5e1",
        "background:#fff7ed;color:#9a3412;border:1.5px solid #fed7aa",
      ];
      pos.candidates.forEach((c, ci) => {
        const rs = rankStyles[ci] || "background:#f8fafc;color:#94a3b8;border:1.5px solid #e2e8f0";
        html += `
          <div style="display:flex;align-items:center;gap:14px;padding:13px 0;
            ${ci < pos.candidates.length - 1 ? "border-bottom:1px solid #f1f5f9" : ""}">
            <div style="width:32px;height:32px;border-radius:50%;display:flex;align-items:center;
              justify-content:center;font-size:13px;font-weight:700;flex-shrink:0;${rs}">${ci + 1}</div>
            <div style="min-width:150px;font-size:14.5px;font-weight:600;color:#0f172a;flex-shrink:0">${c.name}</div>
            <div style="flex:1;min-width:60px">
              <div style="height:10px;background:#f1f5f9;border-radius:10px;overflow:hidden">
                <div style="height:100%;width:${c.pct}%;background:${accentColor};
                  border-radius:10px;transition:width .6s ease"></div>
              </div>
            </div>
            <div style="min-width:46px;text-align:right;font-size:14px;font-weight:700;color:${accentColor}">${c.pct}%</div>
            <div style="min-width:64px;text-align:right;font-size:13px;color:#94a3b8;white-space:nowrap">
              ${c.votes} vote${c.votes !== 1 ? "s" : ""}</div>
          </div>`;
      });
    }
    html += "</div>";
  });

  container.innerHTML = html;
}

function switchInnerResultTab(containerId, prefix, posName, btn, elType) {
  if (elType === "clas") currentClasResultTab = posName;
  else                   currentResultTab     = posName;

  const container = document.getElementById(containerId);
  if (!container) return;
  container.querySelectorAll(`.res-tab-${elType}`).forEach((t) => t.classList.remove("active"));
  btn.classList.add("active");
  container.querySelectorAll(".card").forEach((c) => (c.style.display = "none"));
  const panel = document.getElementById(prefix + posName.replace(/[^a-zA-Z0-9]/g, "-"));
  if (panel) panel.style.display = "";
}

async function loadResults() {
  if (currentResultsElectionTab === "clas") loadClasResults();
  else                                       loadGeneralResults();
}

function updateStatsBadge(data) {
  const vc = document.getElementById("votesCast");
  if (vc) vc.textContent = (data.votes_cast || 0).toLocaleString();
  const tp = document.getElementById("turnoutPct");
  if (tp && data.total_voters > 0) {
    const pct = ((data.votes_cast / data.total_voters) * 100).toFixed(1);
    tp.textContent = pct + "%";
    const bar = document.getElementById("turnoutBar");
    if (bar) bar.style.width = pct + "%";
  }
}

async function fetchLiveStats() {
  try {
    const res  = await fetch("results_api.php");
    const data = await res.json();
    if (data.ok) updateStatsBadge(data);
  } catch (e) { /* silent */ }
}
setInterval(fetchLiveStats, 10000);

// ═══════════════════════════════════════════════════════════════
//  PROFILE
// ═══════════════════════════════════════════════════════════════
function saveProfile(e) { /* allow normal form POST */ }
function cancelProfile() { navTo("home"); }

function previewProfilePhoto(input) {
  if (!input.files || !input.files[0]) return;
  const file = input.files[0];
  if (file.size > 3 * 1024 * 1024) {
    showToast("⚠️", "Photo must be under 3 MB.");
    input.value = "";
    return;
  }
  const reader = new FileReader();
  reader.onload = (e) => {
    const initAvatar = document.getElementById("profileAvatar");
    const imgEl      = document.getElementById("profileAvatarImg");
    if (initAvatar) initAvatar.style.display = "none";
    if (imgEl) { imgEl.src = e.target.result; imgEl.style.display = ""; }
    const sideImg = document.getElementById("sidebarAvatarImg");
    if (sideImg) sideImg.src = e.target.result;
    showToast("📷", "Photo selected — click Save Changes to upload.");
  };
  reader.readAsDataURL(file);
}

// ═══════════════════════════════════════════════════════════════
//  FEEDBACK & REVIEWS
// ═══════════════════════════════════════════════════════════════
let currentRating     = 0;
let currentFbElection = "general";
let allReviewsCache   = [];
const ratingHints     = ["", "Terrible", "Poor", "Okay", "Good", "Excellent!"];

function setRating(val) {
  currentRating = val;
  document.querySelectorAll("#stars .star").forEach((s, i) => {
    s.style.opacity = i < val ? "1" : "0.3";
  });
  const hint = document.getElementById("ratingHint");
  if (hint) hint.textContent = ratingHints[val] || "";
}

function setFbElection(type, btn) {
  currentFbElection = type;
  const hidden = document.getElementById("fbElectionType");
  if (hidden) hidden.value = type;
  document.querySelectorAll("#fbElGenBtn, #fbElClasBtn").forEach((b) => b.classList.remove("active"));
  if (btn) btn.classList.add("active");
}

function toggleTag(btn) { btn.classList.toggle("active"); }

function resetFeedbackForm() {
  const ft = document.getElementById("feedbackText");
  if (ft) ft.value = "";
  document.querySelectorAll("#stars .star").forEach((s) => (s.style.opacity = "0.3"));
  document.querySelectorAll("#fbTagsWrap .ftag").forEach((t) => t.classList.remove("active"));
  const hint = document.getElementById("ratingHint");
  if (hint) hint.textContent = "Click a star to rate";
  currentRating = 0;
  const err = document.getElementById("fbErrorMsg");
  if (err) { err.style.display = "none"; err.textContent = ""; }
}

async function submitFeedback() {
  const reviewText = (document.getElementById("feedbackText")?.value || "").trim();
  const elType     = document.getElementById("fbElectionType")?.value || "general";
  const errEl      = document.getElementById("fbErrorMsg");
  const btnEl      = document.getElementById("fbSubmitBtn");

  const showErr = (msg) => {
    if (errEl) { errEl.textContent = msg; errEl.style.display = ""; }
    else showToast("⚠️", msg);
  };

  if (!currentRating)        { showErr("Please select a star rating."); return; }
  if (reviewText.length < 3) { showErr("Please write a comment before submitting."); return; }

  const activeTags = [...document.querySelectorAll("#fbTagsWrap .ftag.active")]
    .map((b) => b.dataset.tag || b.textContent.trim()).join(", ");

  if (btnEl) { btnEl.disabled = true; btnEl.innerHTML = '<span class="spinner-border spinner-border-sm me-2"></span>Submitting…'; }
  if (errEl) errEl.style.display = "none";

  try {
    const res  = await fetch("user_view.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ rating: currentRating, tags: activeTags, review_text: reviewText, election_type: elType }),
    });
    const data = await res.json();
    if (data.ok) {
      resetFeedbackForm();
      const banner = document.getElementById("fbSuccessBanner");
      if (banner) banner.style.display = "";
      if (btnEl) btnEl.style.display = "none";
      showToast("✅", "Thank you for your feedback!");
      loadReviews();
    } else {
      showErr(data.error || "Failed to submit. Please try again.");
      if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '<i class="bi bi-send me-2"></i>Submit Feedback'; }
    }
  } catch (err) {
    showErr("Network error. Please try again.");
    if (btnEl) { btnEl.disabled = false; btnEl.innerHTML = '<i class="bi bi-send me-2"></i>Submit Feedback'; }
  }
}

async function loadReviews() {
  const listEl    = document.getElementById("reviewsList");
  const summaryEl = document.getElementById("reviewsSummary");
  if (!listEl) return;
  try {
    const res  = await fetch("user_view.php?api=reviews");
    const data = await res.json();
    if (!data.ok) {
      listEl.innerHTML = '<div class="text-muted text-center py-3">Could not load reviews.</div>';
      return;
    }
    allReviewsCache = data.reviews || [];
    if (summaryEl) {
      const stars = data.avg_rating ? "★".repeat(Math.round(data.avg_rating)) : "";
      summaryEl.textContent = data.total
        ? `${data.avg_rating}  ${stars}  ·  ${data.total} review${data.total !== 1 ? "s" : ""}`
        : "No reviews yet.";
    }
    renderReviews(allReviewsCache);
  } catch (e) {
    if (listEl) listEl.innerHTML = '<div class="text-muted text-center py-3">Could not load reviews.</div>';
  }
}

function renderReviews(reviews) {
  const listEl = document.getElementById("reviewsList");
  if (!listEl) return;
  if (!reviews.length) {
    listEl.innerHTML = '<div class="text-muted text-center py-4" style="font-size:14px">No reviews yet. Be the first! 🎉</div>';
    return;
  }
  const elColors  = { general: "#10b981", clas: "#6366f1" };
  const elLabels  = { general: "🗳️ SSC General", clas: "🎓 CLAS Council" };
  const avatarBgs = ["#1e5c27","#3a7d44","#0e7490","#7c3aed","#b45309","#c9a84c","#dc2626"];

  listEl.innerHTML = reviews.map((r, idx) => {
    const parts    = (r.student_name || "?").split(" ");
    const initials = parts.length >= 2
      ? (parts[0][0] + parts[parts.length - 1][0]).toUpperCase()
      : r.student_name.slice(0, 2).toUpperCase();
    const bg       = avatarBgs[initials.charCodeAt(0) % avatarBgs.length];
    const stars    = "★".repeat(r.rating) + "☆".repeat(5 - r.rating);
    const elColor  = elColors[r.election_type] || "#10b981";
    const elLabel  = elLabels[r.election_type] || r.election_type;
    const tagChips = r.tags
      ? r.tags.split(",").map((t) =>
          `<span class="ftag" style="font-size:11px;padding:2px 9px;pointer-events:none;border-color:#e2e8f0">${t.trim()}</span>`
        ).join(" ")
      : "";
    const date = r.created_at
      ? new Date(r.created_at).toLocaleDateString("en-PH", { month: "short", day: "numeric", year: "numeric" })
      : "";
    return `
      <div style="padding:14px 0;${idx < reviews.length - 1 ? "border-bottom:1px solid #f1f5f9" : ""}">
        <div class="d-flex align-items-start gap-3">
          <div style="width:38px;height:38px;border-radius:50%;background:${bg};color:#fff;
            font-family:'Playfair Display',serif;font-size:14px;font-weight:700;
            display:flex;align-items:center;justify-content:center;flex-shrink:0">${initials}</div>
          <div style="flex:1;min-width:0">
            <div class="d-flex align-items-center gap-2 flex-wrap mb-1">
              <span style="font-size:14px;font-weight:700;color:#0f172a">${r.student_name}</span>
              <span style="font-size:11.5px;font-weight:600;padding:2px 8px;border-radius:20px;
                background:${elColor}18;color:${elColor};border:1px solid ${elColor}44">${elLabel}</span>
              <span style="font-size:12px;color:#94a3b8;margin-left:auto">${date}</span>
            </div>
            <div style="font-size:16px;color:#f59e0b;letter-spacing:1px;margin-bottom:4px">${stars}</div>
            ${tagChips ? `<div class="d-flex flex-wrap gap-1 mb-2">${tagChips}</div>` : ""}
            <p style="font-size:13.5px;color:#475569;margin:0;line-height:1.55">${r.review_text}</p>
          </div>
        </div>
      </div>`;
  }).join("");
}

function filterReviews(type, btn) {
  document.querySelectorAll("#rvFilterAll, #rvFilterGeneral, #rvFilterClas").forEach((b) => b.classList.remove("active"));
  if (btn) btn.classList.add("active");
  renderReviews(type === "all" ? allReviewsCache : allReviewsCache.filter((r) => r.election_type === type));
}

function applyReviewedState() {
  const genReviewed  = window.SUFFRA_REVIEWED_GENERAL === true;
  const clasReviewed = window.SUFFRA_REVIEWED_CLAS    === true;
  const genBtn  = document.getElementById("fbElGenBtn");
  const clasBtn = document.getElementById("fbElClasBtn");
  if (genBtn  && genReviewed)  { genBtn.disabled  = true; genBtn.title  = "Already reviewed"; }
  if (clasBtn && clasReviewed) { clasBtn.disabled = true; clasBtn.title = "Already reviewed"; }
  if (genReviewed && !clasReviewed && clasBtn) setFbElection("clas", clasBtn);
  if (genReviewed && clasReviewed) {
    const btn    = document.getElementById("fbSubmitBtn");
    const banner = document.getElementById("fbSuccessBanner");
    const ft     = document.getElementById("feedbackText");
    if (btn)    { btn.disabled = true; btn.textContent = "✓ Already Submitted"; }
    if (banner)   banner.style.display = "";
    if (ft)       ft.disabled = true;
    document.querySelectorAll("#fbTagsWrap .ftag, #stars .star").forEach((el) => {
      el.style.pointerEvents = "none";
      el.style.opacity = "0.4";
    });
  }
}