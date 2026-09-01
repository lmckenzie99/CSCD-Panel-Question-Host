(function () {
  var POLL_MS = 2000;
  var loginPanel = document.getElementById("login-panel");
  var queuePanel = document.getElementById("queue-panel");
  var loginForm = document.getElementById("login-form");
  var loginBtn = document.getElementById("login-btn");
  var logoutBtn = document.getElementById("logout-btn");
  var statusEl = document.getElementById("status");
  var pendingList = document.getElementById("pending-list");
  var pendingEmpty = document.getElementById("pending-empty");
  var handledList = document.getElementById("handled-list");
  var handledEmpty = document.getElementById("handled-empty");
  var handledCount = document.getElementById("handled-count");
  var pollTimer = null;

  function showStatus(message, kind) {
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

  function formatTime(value) {
    var date = new Date(String(value).replace(" ", "T"));
    if (isNaN(date.getTime())) {
      return value;
    }
    return date.toLocaleTimeString([], { hour: "numeric", minute: "2-digit" });
  }

  function displayName(question) {
    if (question.name && String(question.name).trim()) {
      return String(question.name).trim();
    }
    return "Anonymous";
  }

  function renderPending(question) {
    var votes = Number(question.vote_count || 0);
    var badges =
      '<div class="badge-row">' +
      '<span class="vote-count">score ' +
      votes +
      "</span>" +
      (question.visible ? '<span class="pill on">On wall</span>' : "") +
      (question.is_current ? '<span class="pill live">Now reading</span>' : "") +
      "</div>";
    var wallLabel = question.visible ? "Hide from wall" : "Show on wall";
    var wallValue = question.visible ? "0" : "1";
    var currentLabel = question.is_current ? "Clear reading" : "Now reading";
    var currentValue = question.is_current ? "0" : "1";

    return (
      '<article class="question" data-id="' +
      question.id +
      '">' +
      "<header><span class=\"who\">" +
      escapeHtml(displayName(question)) +
      "</span><time>" +
      escapeHtml(formatTime(question.created_at)) +
      "</time></header>" +
      badges +
      '<p class="body">' +
      escapeHtml(question.body) +
      "</p>" +
      '<div class="actions">' +
      '<button type="button" data-status="asked">Asked</button>' +
      '<button type="button" class="secondary" data-status="dismissed">Dismiss</button>' +
      '<button type="button" class="secondary" data-visible="' +
      wallValue +
      '">' +
      wallLabel +
      "</button>" +
      '<button type="button" class="secondary" data-current="' +
      currentValue +
      '">' +
      currentLabel +
      "</button>" +
      "</div></article>"
    );
  }

  function renderHandled(question) {
    return (
      '<article class="question" data-id="' +
      question.id +
      '">' +
      "<header><span class=\"who\">" +
      escapeHtml(displayName(question)) +
      "</span><time>" +
      escapeHtml(formatTime(question.created_at)) +
      "</time></header>" +
      '<p class="body">' +
      escapeHtml(question.body) +
      "</p>" +
      '<p class="badge">' +
      escapeHtml(question.status) +
      "</p>" +
      '<div class="actions">' +
      '<button type="button" class="secondary" data-status="pending">Undo</button>' +
      "</div></article>"
    );
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

  function showLogin() {
    loginPanel.hidden = false;
    queuePanel.hidden = true;
    if (pollTimer) {
      clearInterval(pollTimer);
      pollTimer = null;
    }
  }

  function loadQuestions() {
    fetch("api/questions.php", { credentials: "same-origin" })
      .then(parseJson)
      .then(function (result) {
        if (result.status === 401) {
          showLogin();
          return;
        }
        if (!result.ok) {
          if (!queuePanel.hidden) {
            showStatus(result.data.error || "Could not load questions.", "err");
          }
          return;
        }

        loginPanel.hidden = true;
        queuePanel.hidden = false;

        if (!pollTimer) {
          pollTimer = setInterval(loadQuestions, POLL_MS);
        }

        var pending = result.data.pending || [];
        var handled = result.data.handled || [];

        pendingEmpty.hidden = pending.length > 0;
        pendingList.innerHTML = pending.map(renderPending).join("");

        handledCount.textContent = String(handled.length);
        handledEmpty.hidden = handled.length > 0;
        handledList.innerHTML = handled.map(renderHandled).join("");
      })
      .catch(function () {
        if (!queuePanel.hidden) {
          showStatus("Network error while refreshing the queue.", "err");
        }
      });
  }

  function postAction(payload) {
    fetch("api/status.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    })
      .then(parseJson)
      .then(function (result) {
        if (result.status === 401) {
          showLogin();
          return;
        }
        if (!result.ok) {
          showStatus(result.data.error || "Could not update that question.", "err");
          return;
        }
        showStatus("", "");
        loadQuestions();
      })
      .catch(function () {
        showStatus("Network error while updating the question.", "err");
      });
  }

  pendingList.addEventListener("click", onQuestionAction);
  handledList.addEventListener("click", onQuestionAction);

  function onQuestionAction(event) {
    var button = event.target.closest(".question button");
    if (!button) {
      return;
    }
    var article = button.closest(".question");
    if (!article) {
      return;
    }
    var payload = { id: Number(article.getAttribute("data-id")) };
    if (button.hasAttribute("data-status")) {
      payload.status = button.getAttribute("data-status");
    } else if (button.hasAttribute("data-visible")) {
      payload.visible = button.getAttribute("data-visible") === "1";
    } else if (button.hasAttribute("data-current")) {
      payload.current = button.getAttribute("data-current") === "1";
    } else {
      return;
    }
    postAction(payload);
  }

  loginForm.addEventListener("submit", function (event) {
    event.preventDefault();
    loginBtn.disabled = true;

    fetch("api/login.php", {
      method: "POST",
      credentials: "same-origin",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        password: document.getElementById("password").value,
      }),
    })
      .then(parseJson)
      .then(function (result) {
        if (!result.ok) {
          showStatus(result.data.error || "Could not sign in.", "err");
          return;
        }
        document.getElementById("password").value = "";
        showStatus("", "");
        loadQuestions();
      })
      .catch(function () {
        showStatus("Network error. Try again.", "err");
      })
      .then(function () {
        loginBtn.disabled = false;
      });
  });

  logoutBtn.addEventListener("click", function () {
    fetch("api/logout.php", {
      method: "POST",
      credentials: "same-origin",
    }).finally(function () {
      showLogin();
      showStatus("Signed out.", "ok");
    });
  });

  loadQuestions();
})();
