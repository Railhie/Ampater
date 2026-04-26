function showToast(msg) {
  const toast = document.getElementById("toast");
  document.getElementById("toast-msg").textContent = msg;
  toast.classList.add("show");
  clearTimeout(toastTimer);
  toastTimer = setTimeout(() => toast.classList.remove("show"), 3500);
}

/* ══ DATA STORES ══ */
let voters = [
  {
    id: "20250001-S",
    name: "Juan dela Cruz",
    email: "juan@mail.com",
    birthday: "2003-06-15",
    status: "Approved",
  },
  {
    id: "20250002-S",
    name: "Ana Reyes",
    email: "ana@mail.com",
    birthday: "2004-02-28",
    status: "Approved",
  },
  {
    id: "20250003-S",
    name: "Carlo Mendoza",
    email: "carlo@mail.com",
    birthday: "2002-11-03",
    status: "Rejected",
  },
];

let candidates = [
  {
    id: "C-001",
    name: "Maria Santos",
    party: "Green Alliance",
    position: "Mayor",
    Description: "Champion of the environment.",
  },
  {
    id: "C-002",
    name: "Pedro Lim",
    party: "People's Front",
    position: "Mayor",
    Description: "Veteran public servant.",
  },
  {
    id: "C-003",
    name: "Rosa Villanueva",
    party: "United Progress",
    position: "Councilor",
    Description: "Advocate for education.",
  },
  {
    id: "C-004",
    name: "Marco Torres",
    party: "Reform Party",
    position: "Councilor",
    Description: "Infrastructure & roads.",
  },
  {
    id: "C-005",
    name: "Lena Gomez",
    party: "Green Alliance",
    position: "Vice Mayor",
    Description: "Healthcare for all.",
  },
];

let voterEditId = null;
let candidateEditId = null;
let voterIdCounter = voters.length + 1;
let candidateIdCounter = candidates.length + 1;

/* ══ NAVIGATION ══ */
function adminSection(sectionId, btn) {
  document
    .querySelectorAll(".admin-section")
    .forEach((s) => s.classList.remove("visible"));
  const target = document.getElementById(sectionId);
  if (target) target.classList.add("visible");
  if (btn) {
    document
      .querySelectorAll(".admin-nav-item")
      .forEach((b) => b.classList.remove("active"));
    btn.classList.add("active");
  }
  if (sectionId === "voters") renderVoters();
  if (sectionId === "candidates") renderCandidates();
  if (sectionId === "live-results") renderLiveResults();
  if (sectionId === "audit-log") renderAuditLog();
  if (sectionId === "feedback") {
    fbFilter = "all";
    renderFeedback();
    document.getElementById("fb-pill-all").classList.add("active");
  }
  updateDashStats();
}

function goToPage(url) {
  window.location.href = url;
}

/* ══ DASHBOARD STATS ══ */
function updateDashStats() {
  document.getElementById("dash-voters").textContent = voters.filter(
    (v) => v.status === "Approved",
  ).length;
  document.getElementById("dash-candidates").textContent = candidates.length;
}

/* ══ VOTERS — RENDER ══ */
function calcAge(birthday) {
  if (!birthday) return "—";
  const today = new Date();
  const dob = new Date(birthday);
  let age = today.getFullYear() - dob.getFullYear();
  const m = today.getMonth() - dob.getMonth();
  if (m < 0 || (m === 0 && today.getDate() < dob.getDate())) age--;
  return age;
}

function formatBirthday(birthday) {
  if (!birthday) return "—";
  const d = new Date(birthday);
  return d.toLocaleDateString("en-PH", {
    year: "numeric",
    month: "short",
    day: "numeric",
  });
}

function updateAgePreview() {
  const bday = document.getElementById("v-birthday").value;
  document.getElementById("v-age-preview").value = bday
    ? calcAge(bday) + " years old"
    : "";
}

function renderVoters() {
  const q = document.getElementById("voter-search").value.toLowerCase();
  const tbody = document.getElementById("voter-tbody");
  const empty = document.getElementById("voter-empty");
  const list = voters.filter(
    (v) => v.name.toLowerCase().includes(q) || v.id.toLowerCase().includes(q),
  );

  if (list.length === 0) {
    tbody.innerHTML = "";
    empty.style.display = "block";
    return;
  }
  empty.style.display = "none";

  tbody.innerHTML = list
    .map(
      (v) => `
          <tr>
            <td>${v.id}</td>
            <td>${v.name}</td>
            <td>${v.email}</td>
            <td>${formatBirthday(v.birthday)}</td>
            <td>${calcAge(v.birthday)}</td>
            <td><span class="badge badge-${v.status.toLowerCase()}">${v.status}</span></td>
            <td>
              <div class="td-actions">
                ${
                  v.status === "Pending"
                    ? `
                  <button class="btn btn-approve btn-sm" onclick="setVoterStatus('${v.id}','Approved')">Approve</button>
                  <button class="btn btn-reject  btn-sm" onclick="setVoterStatus('${v.id}','Rejected')">Reject</button>
                `
                    : ""
                }
                <button class="btn btn-sm" style="background:#e8f5e9;color:#1a6b3c;" onclick="openVoterModal('${v.id}')">Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteVoter('${v.id}')">Delete</button>
              </div>
            </td>
          </tr>
        `,
    )
    .join("");
}

function setVoterStatus(id, status) {
  const v = voters.find((x) => x.id === id);
  if (v) {
    v.status = status;
    addLog("Voters", `Voter ${status}`, `${v.name} (${v.id})`);
    renderVoters();
    updateDashStats();
  }
}

function deleteVoter(id) {
  if (!confirm("Delete this voter?")) return;
  const v = voters.find((x) => x.id === id);
  addLog("Voters", "Voter Deleted", `${v.name} (${v.id})`);
  voters = voters.filter((v) => v.id !== id);
  renderVoters();
  updateDashStats();
}

function openVoterModal(id = null) {
  voterEditId = id;
  document.getElementById("voter-modal-title").textContent = id
    ? "Edit Student Voter"
    : "Add Student Voter";
  if (id) {
    const v = voters.find((x) => x.id === id);
    document.getElementById("v-sid").value = v.id;
    document.getElementById("v-name").value = v.name;
    document.getElementById("v-email").value = v.email;
    document.getElementById("v-birthday").value = v.birthday || "";
    document.getElementById("v-status").value = v.status;
    updateAgePreview();
  } else {
    document.getElementById("v-sid").value = "2025";
    document.getElementById("v-name").value = "";
    document.getElementById("v-email").value = "";
    document.getElementById("v-birthday").value = "";
    document.getElementById("v-age-preview").value = "";
    document.getElementById("v-status").value = "Pending";
  }
  document.getElementById("voter-modal").classList.add("open");
}

function closeVoterModal() {
  document.getElementById("voter-modal").classList.remove("open");
  voterEditId = null;
}

function formatStudentId(input) {
  // Strip everything except digits
  let digits = input.value.replace(/\D/g, "");

  // Lock the first 4 digits to 2025
  if (digits.length < 4) {
    digits = "2025".slice(0, digits.length);
  } else {
    digits = "2025" + digits.slice(4, 8);
  }

  // Build display value
  if (digits.length <= 8) {
    input.value = digits.length === 8 ? digits + "-S" : digits;
  }
}

function saveVoter() {
  const rawSid = document.getElementById("v-sid").value.trim();
  const name = document.getElementById("v-name").value.trim();
  const email = document.getElementById("v-email").value.trim();
  const birthday = document.getElementById("v-birthday").value;
  const status = document.getElementById("v-status").value;

  // Expect format YYYYNNNN-S
  const match = rawSid.match(/^(\d{4})(\d{4})-S$/);
  if (!match) {
    alert(
      "Student ID must be in the format 20250001-S (year + 4 digits + -S).",
    );
    return;
  }
  if (!name || !email || !birthday) {
    alert("All fields marked with * are required.");
    return;
  }

  const sid = rawSid;
  const duplicate = voters.find((x) => x.id === sid && x.id !== voterEditId);
  if (duplicate) {
    alert("That Student ID already exists.");
    return;
  }

  if (voterEditId) {
    const v = voters.find((x) => x.id === voterEditId);
    v.id = sid;
    v.name = name;
    v.email = email;
    v.birthday = birthday;
    v.status = status;
    addLog("Voters", "Voter Updated", `${name} (${sid})`);
  } else {
    voters.push({ id: sid, name, email, birthday, status });
    addLog("Voters", "Voter Added", `${name} (${sid})`);
    showToast("A new student voter has been added!");
  }
  closeVoterModal();
  renderVoters();
  updateDashStats();
}

/* ══ CANDIDATES — RENDER ══ */
function renderCandidates() {
  const q = document.getElementById("candidate-search").value.toLowerCase();
  const tbody = document.getElementById("candidate-tbody");
  const empty = document.getElementById("candidate-empty");
  const list = candidates.filter(
    (c) =>
      c.name.toLowerCase().includes(q) || c.party.toLowerCase().includes(q),
  );

  if (list.length === 0) {
    tbody.innerHTML = "";
    empty.style.display = "block";
    return;
  }
  empty.style.display = "none";

  tbody.innerHTML = list
    .map(
      (c) => `
          <tr>
            <td>${c.id}</td>
            <td>${c.name}</td>
            <td>${c.party}</td>
            <td>${c.position}</td>
            <td>
              <div class="td-actions">
                <button class="btn btn-sm" style="background:#e3f2fd;color:#1565c0;" onclick="viewProfile('${c.id}')">Profile</button>
                <button class="btn btn-sm" style="background:#e8f5e9;color:#1a6b3c;" onclick="openCandidateModal('${c.id}')">Edit</button>
                <button class="btn btn-danger btn-sm" onclick="deleteCandidate('${c.id}')">Delete</button>
              </div>
            </td>
          </tr>
        `,
    )
    .join("");
}

function deleteCandidate(id) {
  if (!confirm("Delete this candidate?")) return;
  const c = candidates.find((x) => x.id === id);
  addLog("Candidates", "Candidate Deleted", `${c.name} — ${c.party}`);
  candidates = candidates.filter((c) => c.id !== id);
  renderCandidates();
  updateDashStats();
}

function openCandidateModal(id = null) {
  candidateEditId = id;
  document.getElementById("candidate-modal-title").textContent = id
    ? "Edit Candidate"
    : "Add Candidate";
  if (id) {
    const c = candidates.find((x) => x.id === id);
    document.getElementById("c-name").value = c.name;
    document.getElementById("c-party").value = c.party;
    document.getElementById("c-position").value = c.position;
    document.getElementById("c-bio").value = c.bio;
  } else {
    document.getElementById("c-name").value = "";
    document.getElementById("c-party").value = "";
    document.getElementById("c-position").value = "";
    document.getElementById("c-bio").value = "";
  }
  document.getElementById("candidate-modal").classList.add("open");
}

function closeCandidateModal() {
  document.getElementById("candidate-modal").classList.remove("open");
  candidateEditId = null;
}

function saveCandidate() {
  const name = document.getElementById("c-name").value.trim();
  const party = document.getElementById("c-party").value.trim();
  const position = document.getElementById("c-position").value.trim();
  const bio = document.getElementById("c-bio").value.trim();
  if (!name || !party || !position) {
    alert("Please fill in Name, Party and Position.");
    return;
  }

  if (candidateEditId) {
    const c = candidates.find((x) => x.id === candidateEditId);
    c.name = name;
    c.party = party;
    c.position = position;
    c.bio = bio;
    addLog("Candidates", "Candidate Updated", `${name} — ${party}`);
  } else {
    const newId = "C-" + String(candidateIdCounter++).padStart(3, "0");
    candidates.push({ id: newId, name, party, position, bio });
    addLog("Candidates", "Candidate Added", `${name} — ${party}`);
  }
  closeCandidateModal();
  renderCandidates();
  updateDashStats();
}

function viewProfile(id) {
  const c = candidates.find((x) => x.id === id);
  document.getElementById("profile-name").textContent = c.name;
  document.getElementById("profile-party").textContent = c.party;
  document.getElementById("profile-details").innerHTML = `
          <div class="profile-detail-row"><span class="lbl">ID</span><span class="val">${c.id}</span></div>
          <div class="profile-detail-row"><span class="lbl">Position</span><span class="val">${c.position}</span></div>
          <div class="profile-detail-row"><span class="lbl">Platform</span><span class="val">${c.bio || "—"}</span></div>
        `;
  document.getElementById("profile-overlay").classList.add("open");
}

function closeProfile() {
  document.getElementById("profile-overlay").classList.remove("open");
}

/* ── Close modals on backdrop click ── */
document.getElementById("voter-modal").addEventListener("click", function (e) {
  if (e.target === this) closeVoterModal();
});
document
  .getElementById("candidate-modal")
  .addEventListener("click", function (e) {
    if (e.target === this) closeCandidateModal();
  });
document
  .getElementById("profile-overlay")
  .addEventListener("click", function (e) {
    if (e.target === this) closeProfile();
  });

/* ══ FEEDBACK ══ */
let feedbackList = [
  {
    id: "FB-001",
    author: "Ana Reyes",
    sid: "20250002-S",
    type: "Suggestion",
    message:
      "It would be nice to see a confirmation screen before finalizing the vote.",
    time: new Date(Date.now() - 3600000),
    read: false,
    reply: "",
  },
  {
    id: "FB-002",
    author: "Carlo Mendoza",
    sid: "20250003-S",
    type: "Bug Report",
    message:
      "The page sometimes freezes when I try to load the candidates list on mobile.",
    time: new Date(Date.now() - 7200000),
    read: true,
    reply:
      "Thank you for reporting this! We are looking into the mobile performance issue.",
  },
  {
    id: "FB-003",
    author: "Juan dela Cruz",
    sid: "20250001-S",
    type: "General",
    message: "Overall the system is easy to use. Great work to the team!",
    time: new Date(Date.now() - 86400000),
    read: true,
    reply: "",
  },
];

let fbFilter = "all";
let replyTarget = null;

function filterFeedback(filter, el) {
  fbFilter = filter;
  document
    .querySelectorAll(".fb-pill")
    .forEach((p) => p.classList.remove("active"));
  if (el) el.classList.add("active");
  renderFeedback();
}

function renderFeedback() {
  const list = document.getElementById("fb-list");
  const empty = document.getElementById("fb-empty");

  // Update pill counts
  document.getElementById("fb-count-all").textContent = feedbackList.length;
  document.getElementById("fb-count-unread").textContent = feedbackList.filter(
    (f) => !f.read,
  ).length;
  document.getElementById("fb-count-read").textContent = feedbackList.filter(
    (f) => f.read,
  ).length;

  const filtered = feedbackList.filter((f) => {
    if (fbFilter === "unread") return !f.read;
    if (fbFilter === "read") return f.read;
    return true;
  });

  if (filtered.length === 0) {
    list.innerHTML = "";
    empty.style.display = "block";
    return;
  }
  empty.style.display = "none";

  list.innerHTML = filtered
    .map((f) => {
      const ts = f.time.toLocaleString("en-PH", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
      const replyHtml = f.reply
        ? `
            <div class="fb-reply-box">
              <div class="fb-reply-label">Admin Reply</div>
              ${f.reply}
            </div>`
        : "";

      return `
            <div class="fb-card ${f.read ? "" : "unread"}" id="fbcard-${f.id}">
              <div class="fb-card-header">
                <div class="fb-meta">
                  <span class="fb-author">${f.author} <span style="font-weight:400;color:#aaa;font-size:0.78rem;">${f.sid}</span></span>
                  <span class="fb-time">${ts}</span>
                </div>
                <span class="fb-type-badge">${f.type}</span>
              </div>
              <div class="fb-body">${f.message}</div>
              ${replyHtml}
              <div class="fb-actions">
                <button class="btn btn-sm" style="background:#e3f2fd;color:#1565c0;" onclick="openReplyModal('${f.id}')">
                  ${f.reply ? "✏️ Edit Reply" : "💬 Reply"}
                </button>
                <button class="btn btn-sm ${f.read ? "btn-approve" : ""}" style="${f.read ? "" : "background:#fff8e1;color:#f57f17;"}" onclick="toggleRead('${f.id}')">
                  ${f.read ? "✓ Mark Unread" : "👁 Mark as Read"}
                </button>
              </div>
            </div>`;
    })
    .join("");
}

function toggleRead(id) {
  const f = feedbackList.find((x) => x.id === id);
  if (f) {
    f.read = !f.read;
    renderFeedback();
  }
}

function openReplyModal(id) {
  replyTarget = id;
  const f = feedbackList.find((x) => x.id === id);
  document.getElementById("reply-original").innerHTML =
    `<strong>${f.author}:</strong> ${f.message}`;
  document.getElementById("reply-text").value = f.reply || "";
  document.getElementById("reply-modal").classList.add("open");
}

function closeReplyModal() {
  document.getElementById("reply-modal").classList.remove("open");
  replyTarget = null;
}

function submitReply() {
  const text = document.getElementById("reply-text").value.trim();
  if (!text) {
    alert("Please type a reply first.");
    return;
  }
  const f = feedbackList.find((x) => x.id === replyTarget);
  f.reply = text;
  f.read = true;
  addLog("Feedback", "Reply Sent", `To: ${f.author} — ${f.sid}`);
  closeReplyModal();
  renderFeedback();
  showToast("Reply sent successfully.");
}

/* ══ AUDIT LOG ══ */
let auditLogs = [
  {
    ts: new Date(Date.now() - 120000),
    category: "Voters",
    action: "Voter Approved",
    details: "Juan dela Cruz (S-00000001)",
  },
  {
    ts: new Date(Date.now() - 300000),
    category: "Candidates",
    action: "Candidate Added",
    details: "Lena Gomez — Green Alliance",
  },
  {
    ts: new Date(Date.now() - 600000),
    category: "Election",
    action: "Status Changed",
    details: "Set to: Not Started",
  },
];

function addLog(category, action, details) {
  auditLogs.unshift({ ts: new Date(), category, action, details });
}

function renderAuditLog() {
  const q = document.getElementById("al-search").value.toLowerCase();
  const filter = document.getElementById("al-filter").value;
  const tbody = document.getElementById("al-tbody");
  const empty = document.getElementById("al-empty");

  const list = auditLogs.filter((l) => {
    const matchFilter = filter === "all" || l.category === filter;
    const matchQ =
      !q ||
      l.action.toLowerCase().includes(q) ||
      l.details.toLowerCase().includes(q);
    return matchFilter && matchQ;
  });

  if (list.length === 0) {
    tbody.innerHTML = "";
    empty.style.display = "block";
    return;
  }
  empty.style.display = "none";

  tbody.innerHTML = list
    .map((l, i) => {
      const cat = l.category.toLowerCase();
      const ts = l.ts.toLocaleString("en-PH", {
        month: "short",
        day: "numeric",
        year: "numeric",
        hour: "2-digit",
        minute: "2-digit",
      });
      return `
            <tr>
              <td style="color:#aaa;font-size:0.8rem;">${list.length - i}</td>
              <td style="font-size:0.82rem;color:#555;white-space:nowrap;">${ts}</td>
              <td><span class="al-badge al-badge-${cat}">${l.category}</span></td>
              <td style="font-weight:500;">${l.action}</td>
              <td style="color:#555;font-size:0.85rem;">${l.details}</td>
            </tr>`;
    })
    .join("");
}

function confirmClearLogs() {
  if (!confirm("Clear all audit log entries? This cannot be undone.")) return;
  auditLogs = [];
  renderAuditLog();
  showToast("Audit log cleared.");
}

function exportLogs() {
  const header = "No.,Timestamp,Category,Action,Details\n";
  const rows = auditLogs
    .map((l, i) => {
      const ts = l.ts.toLocaleString("en-PH");
      return `${auditLogs.length - i},"${ts}",${l.category},"${l.action}","${l.details}"`;
    })
    .join("\n");
  const blob = new Blob([header + rows], { type: "text/csv" });
  const a = document.createElement("a");
  a.href = URL.createObjectURL(blob);
  a.download = "audit_log.csv";
  a.click();
}

/* ══ LIVE RESULTS ══ */

// Sample vote data — keyed by candidate id
let voteData = {
  "C-001": 142,
  "C-002": 98,
  "C-003": 76,
  "C-004": 54,
  "C-005": 110,
};

function renderLiveResults() {
  // Total votes
  const totalVotes = Object.values(voteData).reduce((a, b) => a + b, 0);
  const totalVoters = voters.filter((v) => v.status === "Approved").length;
  const turnout =
    totalVoters > 0 ? Math.round((totalVotes / totalVoters) * 100) : 0;

  document.getElementById("lr-total-votes").textContent = totalVotes;
  document.getElementById("lr-total-voters").textContent = totalVoters;
  document.getElementById("lr-turnout").textContent = turnout + "%";

  // Group candidates by position
  const positions = {};
  candidates.forEach((c) => {
    if (!positions[c.position]) positions[c.position] = [];
    positions[c.position].push(c);
  });

  const container = document.getElementById("lr-positions");
  container.innerHTML = "";

  Object.entries(positions).forEach(([position, group]) => {
    // Sort by votes descending
    group.sort((a, b) => (voteData[b.id] || 0) - (voteData[a.id] || 0));
    const maxVotes = voteData[group[0].id] || 0;
    const posTotal = group.reduce((s, c) => s + (voteData[c.id] || 0), 0);

    const rows = group
      .map((c, i) => {
        const votes = voteData[c.id] || 0;
        const pct = posTotal > 0 ? Math.round((votes / posTotal) * 100) : 0;
        const isWinner = i === 0 && votes > 0;
        return `
              <div class="lr-candidate-row">
                <div class="lr-candidate-name">
                  ${c.name}
                  <div class="lr-candidate-party">${c.party}</div>
                </div>
                <div class="lr-bar-wrap">
                  <div class="lr-bar ${isWinner ? "winner" : ""}" style="width:${pct}%"></div>
                </div>
                <div class="lr-votes">${votes}</div>
                ${isWinner ? '<span class="lr-winner-badge">🏆 Leading</span>' : '<span style="min-width:60px"></span>'}
              </div>`;
      })
      .join("");

    container.innerHTML += `
            <div class="lr-position-block">
              <div class="lr-position-title">📌 ${position}</div>
              ${rows}
            </div>`;
  });
}

/* ══ ELECTION CONTROL ══ */
let electionState = {
  status: "Not Started",
  title: "SSG Election 2025",
  start: "",
  end: "",
  locked: false,
  votes: 0,
};

function setElectionStatus(status) {
  electionState.status = status;
  // Update banner
  const dot = document.getElementById("ec-dot");
  const label = document.getElementById("ec-status-label");
  const banner = document.getElementById("ec-banner");
  dot.className = "ec-status-dot " + status.toLowerCase().replace(" ", "-");
  label.textContent = status;
  banner.style.borderLeft =
    status === "Ongoing"
      ? "4px solid #2e7d32"
      : status === "Ended"
        ? "4px solid #c62828"
        : "4px solid #bbb";
  // Highlight active button
  ["not-started", "ongoing", "ended"].forEach((s) => {
    const btn = document.getElementById("btn-" + s);
    btn.className = "ec-status-btn";
  });
  const key = status.toLowerCase().replace(" ", "-");
  document.getElementById("btn-" + key).classList.add("active-" + key);
  showToast("Election status set to: " + status);
  addLog("Election", "Status Changed", "Set to: " + status);
}

function saveElectionTitle() {
  const title = document.getElementById("ec-title").value.trim();
  if (!title) {
    alert("Please enter an election title.");
    return;
  }
  electionState.title = title;
  showToast("Election title saved: " + title);
  addLog("Election", "Title Updated", title);
}

function saveElectionPeriod() {
  const start = document.getElementById("ec-start").value;
  const end = document.getElementById("ec-end").value;
  if (!start || !end) {
    alert("Please set both start and end date/time.");
    return;
  }
  if (new Date(end) <= new Date(start)) {
    alert("End date must be after the start date.");
    return;
  }
  electionState.start = start;
  electionState.end = end;
  showToast("Election period saved successfully.");
  addLog("Election", "Period Set", `${start} → ${end}`);
}

function toggleBallotLock() {
  electionState.locked = !electionState.locked;
  const lockStatus = document.getElementById("ec-lock-status");
  const lockBtn = document.getElementById("ec-lock-btn");
  if (electionState.locked) {
    lockStatus.textContent = "Ballot is Locked";
    lockStatus.style.color = "#c62828";
    lockBtn.textContent = "🔒 Unlock Ballot";
    lockBtn.style.background = "#fce4ec";
    lockBtn.style.color = "#c62828";
    showToast("Ballot has been locked. No candidate changes allowed.");
    addLog("Election", "Ballot Locked", "Candidate list is now locked");
  } else {
    lockStatus.textContent = "Ballot is Unlocked";
    lockStatus.style.color = "#2e7d52";
    lockBtn.textContent = "🔓 Lock Ballot";
    lockBtn.style.background = "#e8f5e9";
    lockBtn.style.color = "#1a6b3c";
    showToast("Ballot has been unlocked.");
    addLog("Election", "Ballot Unlocked", "Candidate list is now editable");
  }
}

function confirmResetVotes() {
  if (
    !confirm(
      "⚠️ Are you sure you want to reset ALL votes? This cannot be undone.",
    )
  )
    return;
  electionState.votes = 0;
  setElectionStatus("Not Started");
  addLog("Election", "Votes Reset", "All votes cleared by admin");
  showToast("All votes have been reset. Election is back to Not Started.");
}

/* ── Init election control UI ── */
function initElectionControl() {
  setElectionStatus(electionState.status);
}

document.getElementById("reply-modal").addEventListener("click", function (e) {
  if (e.target === this) closeReplyModal();
});

/* ── Init ── */
updateDashStats();
initElectionControl();

document.getElementById("loginForm").addEventListener("submit", function (e) {
  e.preventDefault();

  const username = document.getElementById("username").value;
  const password = document.getElementById("password").value;

  fetch("login.php", {
    method: "POST",
    headers: {
      "Content-Type": "application/x-www-form-urlencoded",
    },
    body: `username=${encodeURIComponent(username)}&password=${encodeURIComponent(password)}`,
  })
    .then((response) => response.text())
    .then((data) => {
      document.getElementById("loginMessage").innerText = data;

      if (data.includes("success")) {
        window.location.href = "dashboard.php"; // redirect after login
      }
    })
    .catch((error) => console.error("Error:", error));
});
