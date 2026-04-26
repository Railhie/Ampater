// ── TAB SWITCHER ──
function switchTab(name, event) {
  if (event) {
    event.preventDefault();
    event.stopPropagation();
  }

  // Hide all tab panels
  document.querySelectorAll(".tab-panel").forEach(function (p) {
    p.classList.remove("active");
  });

  // Remove active highlight from all nav buttons
  document.querySelectorAll(".nav-item").forEach(function (n) {
    n.classList.remove("active");
  });

  // Show the selected panel
  var panel = document.getElementById("tab-" + name);
  if (panel) {
    panel.classList.add("active");
    // Re-trigger animations only on animated cards
    panel.querySelectorAll(".section-card, .quick-card").forEach(function (c) {
      c.style.animation = "none";
      void c.offsetHeight;
      c.style.animation = "";
    });
  }

  // Highlight the matching nav button
  document.querySelectorAll(".nav-item").forEach(function (btn) {
    if (btn.textContent.trim().toLowerCase() === name.toLowerCase()) {
      btn.classList.add("active");
    }
  });

  window.scrollTo({ top: 0, behavior: "smooth" });
}

// ── STAR RATING ──
let selectedRating = 0;
const hints = ["", "Terrible", "Poor", "Okay", "Good", "Excellent!"];
const stars = document.querySelectorAll(".star");

stars.forEach((s) => {
  s.addEventListener("mouseenter", () => highlightStars(+s.dataset.v));
  s.addEventListener("mouseleave", () => highlightStars(selectedRating));
  s.addEventListener("click", () => {
    selectedRating = +s.dataset.v;
    highlightStars(selectedRating);
    document.getElementById("ratingHint").textContent = hints[selectedRating];
  });
});

function highlightStars(n) {
  stars.forEach((s) => s.classList.toggle("active", +s.dataset.v <= n));
}

// ── CATEGORY PILLS ──
let selectedCat = "";
document.querySelectorAll(".pill").forEach((p) => {
  p.addEventListener("click", () => {
    document
      .querySelectorAll(".pill")
      .forEach((x) => x.classList.remove("pill-active"));
    p.classList.add("pill-active");
    selectedCat = p.dataset.cat;
  });
});

// ── SUBMIT FEEDBACK ──
function submitFeedback() {
  const msg = document.getElementById("fbMessage").value.trim();
  const name = document.getElementById("fbName").value.trim() || "Anonymous";

  if (!selectedRating) {
    alert("Please select a star rating.");
    return;
  }
  if (!selectedCat) {
    alert("Please select a category.");
    return;
  }
  if (!msg) {
    alert("Please write your feedback.");
    return;
  }

  const parts = name.split(" ");
  const initials =
    parts.length >= 2
      ? parts[0][0] + parts[parts.length - 1][0]
      : name.slice(0, 2);
  const colors = [
    "#1e5c27",
    "#3a7d44",
    "#b45309",
    "#7c3aed",
    "#0e7490",
    "#c9a84c",
    "#dc2626",
  ];
  const color = colors[Math.floor(Math.random() * colors.length)];

  const filledStar = "★",
    emptyStar = "☆";
  const starsStr =
    filledStar.repeat(selectedRating) + emptyStar.repeat(5 - selectedRating);

  const catIcons = {
    "Login Issue": "🔓",
    "Ballot Error": "🗳️",
    "Technical Glitch": "⚙️",
    Suggestion: "💡",
    Compliment: "🌟",
    Other: "💬",
  };
  const catClasses = {
    "Login Issue": "login-cat",
    "Ballot Error": "ballot-cat",
    "Technical Glitch": "glitch-cat",
    Suggestion: "suggestion-cat",
    Compliment: "compliment-cat",
    Other: "other-cat",
  };

  const item = document.createElement("div");
  item.className = "review-item review-new";
  item.innerHTML = `
        <div class="review-top">
          <div class="reviewer-avatar" style="background:${color}">${initials.toUpperCase()}</div>
          <div>
            <div class="reviewer-name">${name}</div>
            <div class="review-meta"><span class="review-stars">${starsStr}</span> <span class="review-cat ${catClasses[selectedCat]}">${catIcons[selectedCat]} ${selectedCat}</span></div>
          </div>
        </div>
        <p class="review-text">${msg}</p>`;

  const list = document.getElementById("reviewsList");
  list.insertBefore(item, list.firstChild);

  document.getElementById("fbMessage").value = "";
  document.getElementById("fbName").value = "";
  selectedRating = 0;
  highlightStars(0);
  document.getElementById("ratingHint").textContent = "Click a star to rate";
  document
    .querySelectorAll(".pill")
    .forEach((x) => x.classList.remove("pill-active"));
  selectedCat = "";

  const count = list.querySelectorAll(".review-item").length;
  document.getElementById("avgCount").textContent = `Based on ${count} reviews`;

  const btn = document.getElementById("fbSubmit");
  const origText = btn.textContent;
  btn.textContent = "✓ Feedback Submitted!";
  btn.style.background = "#3a7d44";
  setTimeout(() => {
    btn.textContent = origText;
    btn.style.background = "";
  }, 2500);
}
function validatePassword(input) {
  const val = input.value;
  let msg = input.nextElementSibling;
  if (!msg || !msg.classList.contains("pw-msg")) {
    msg = document.createElement("div");
    msg.className = "pw-msg";
    msg.style.cssText = "font-size:0.78rem;margin-top:5px;font-weight:500;";
    input.parentNode.insertBefore(msg, input.nextSibling);
  }
  const hasSpecial = /[^a-zA-Z0-9]/.test(val);
  const tooShort = val.length > 0 && val.length < 8;
  if (hasSpecial) {
    msg.textContent =
      "⚠ Special characters are not allowed. Use letters (A–Z, a–z) and numbers (0–9) only.";
    msg.style.color = "#c0392b";
    input.style.borderColor = "#c0392b";
  } else if (tooShort) {
    msg.textContent = "⚠ Password must be at least 8 characters.";
    msg.style.color = "#e67e22";
    input.style.borderColor = "#e67e22";
  } else if (val.length >= 8) {
    msg.textContent = "✓ Password looks good.";
    msg.style.color = "#2d7a34";
    input.style.borderColor = "#2d7a34";
  } else {
    msg.textContent = "";
    input.style.borderColor = "";
  }
}

// Blocking special characters for inputting it into the password allowing the user that use only letter and numbers under minumum 8 characters and show the error message if the user input special characters or less than 8 characters.
function blockSpecialChars(e) {
  const char = e.key;
  if (char.length === 1 && /[^a-zA-Z0-9]/.test(char)) {
    e.preventDefault();
    const input = e.target;
    let notice = input.nextElementSibling;
    if (!notice || !notice.classList.contains("pw-msg")) {
      notice = document.createElement("div");
      notice.className = "pw-msg";
      notice.style.cssText =
        "font-size:0.78rem;margin-top:5px;font-weight:500;";
      input.parentNode.insertBefore(notice, input.nextSibling);
    }
    notice.textContent =
      "⚠ Special characters are not allowed. Use letters and numbers only.";
    notice.style.color = "#c0392b";
    input.style.borderColor = "#c0392b";
  }
}

document.addEventListener("DOMContentLoaded", function () {
  document.querySelectorAll('input[type="password"]').forEach(function (pw) {
    pw.addEventListener("input", function () {
      validatePassword(pw);
    });
    pw.addEventListener("keydown", blockSpecialChars);
  });
});

function goToPage(page, element) {
  window.location.href = page;
}
