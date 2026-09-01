(function () {
  var POLL_MS = 3000;
  var listEl = document.getElementById("wall-list");
  var emptyEl = document.getElementById("wall-empty");
  var statusEl = document.getElementById("wall-status");

  if (!listEl) {
    return;
  }

  function voterToken() {
    try {
      var existing = localStorage.getItem("panel_voter");
      if (existing && /^[a-f0-9]{64}$/.test(existing)) {
        return existing;
      }
      var bytes = new Uint8Array(32);
      crypto.getRandomValues(bytes);
      var token = Array.prototype.map
        .call(bytes, function (b) {
          return ("0" + b.toString(16)).slice(-2);
        })
        .join("");
      localStorage.setItem("panel_voter", token);
      return token;
    } catch (err) {
      return "";
    }
  }

  function showStatus(message, kind) {
    if (!statusEl) {
      return;
    }
    if (!message) {
      statusEl.hidden = true;
      statusEl.textContent = "";
      return;
    }
    statusEl.hidden = false;
    statusEl.className = "banner " + kind;
    statusEl.textContent = message;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, "&amp;")
      .replace(/</g, "&lt;")
      .replace(/>/g, "&gt;")
      .replace(/"/g, "&quot;");
  }

  function displayName(question) {
    if (question.name && String(question.name).trim()) {
      return String(question.name).trim();
    }
    return "Anonymous";
  }

  function parseJson(response) {
    return response.text().then(function (text) {
      try {
        return { status: response.status, ok: response.ok, data: JSON.parse(text) };
      } catch (err) {
        return {
          status: response.status,
          ok: false,
          data: { error: "Invalid response from server." },
        };
      }
    });
  }

  function headers() {
    var token = voterToken();
    var out = { "Content-Type": "application/json", Accept: "application/json" };
    if (token) {
      out["X-Voter-Token"] = token;
    }
    return out;
  }

  function renderQuestion(question) {
    var myVote = Number(question.my_vote || 0);
    var votedUp = myVote === 1;
    var votedDown = myVote === -1;
    var current = question.is_current ? '<span class="pill live">Now reading</span>' : "";
    return (
      '<article class="question q-row" data-id="' +
      question.id +
      '">' +
      '<div class="vote-box">' +
      '<button type="button" class="secondary vote-btn" data-vote="1" aria-label="Thumbs up" aria-pressed="' +
      (votedUp ? "true" : "false") +
      '">&#9650;</button>' +
      '<span class="count">' +
      escapeHtml(Number(question.vote_count || 0)) +
      "</span>" +
      '<button type="button" class="secondary vote-btn" data-vote="-1" aria-label="Thumbs down" aria-pressed="' +
      (votedDown ? "true" : "false") +
      '">&#9660;</button>' +
      "</div>" +
      "<div>" +
      "<header><span class=\"who\">" +
      escapeHtml(displayName(question)) +
      "</span>" +
      current +
      "</header>" +
      '<p class="body">' +
      escapeHtml(question.body) +
      "</p></div></article>"
    );
  }

  function loadWall() {
    fetch("api/wall.php", { credentials: "same-origin", headers: headers() })
      .then(parseJson)
      .then(function (result) {
        if (!result.ok) {
          showStatus(result.data.error || "Could not load the question list.", "err");
          return;
        }
        var questions = result.data.questions || [];
        emptyEl.hidden = questions.length > 0;
        listEl.innerHTML = questions.map(renderQuestion).join("");
      })
      .catch(function () {
        showStatus("Network error while loading questions.", "err");
      });
  }

  listEl.addEventListener("click", function (event) {
    var button = event.target.closest("button[data-vote]");
    if (!button) {
      return;
    }
    var article = button.closest(".question");
    if (!article) {
      return;
    }
    button.disabled = true;
    fetch("api/vote.php", {
      method: "POST",
      credentials: "same-origin",
      headers: headers(),
      body: JSON.stringify({
        id: Number(article.getAttribute("data-id")),
        value: Number(button.getAttribute("data-vote")),
      }),
    })
      .then(parseJson)
      .then(function (result) {
        if (!result.ok) {
          showStatus(result.data.error || "Could not record your vote.", "err");
          return;
        }
        showStatus("", "");
        loadWall();
      })
      .catch(function () {
        showStatus("Network error while voting.", "err");
      })
      .then(function () {
        button.disabled = false;
      });
  });

  loadWall();
  setInterval(loadWall, POLL_MS);
})();
