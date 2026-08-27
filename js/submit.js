(function () {
  var form = document.getElementById("question-form");
  var statusEl = document.getElementById("status");
  var submitBtn = document.getElementById("submit-btn");
  var nameInput = document.getElementById("name");
  var bodyInput = document.getElementById("body");

  function showStatus(message, kind) {
    statusEl.hidden = false;
    statusEl.className = "banner " + kind;
    statusEl.textContent = message;
  }

  form.addEventListener("submit", function (event) {
    event.preventDefault();

    var name = nameInput.value.trim();
    var body = bodyInput.value.trim();

    if (!body) {
      showStatus("Please enter a question.", "err");
      bodyInput.focus();
      return;
    }

    submitBtn.disabled = true;

    fetch("api/submit.php", {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({
        name: name || null,
        body: body,
      }),
    })
      .then(function (response) {
        return response.text().then(function (text) {
          var data;
          try {
            data = JSON.parse(text);
          } catch (err) {
            data = { error: "Invalid response from server." };
          }
          return { ok: response.ok, data: data };
        });
      })
      .then(function (result) {
        if (!result.ok) {
          showStatus(result.data.error || "Could not send your question.", "err");
          return;
        }

        bodyInput.value = "";
        showStatus("Question sent. The moderator has it in the private queue.", "ok");
      })
      .catch(function () {
        showStatus("Network error. Check your connection and try again.", "err");
      })
      .then(function () {
        submitBtn.disabled = false;
      });
  });
})();
