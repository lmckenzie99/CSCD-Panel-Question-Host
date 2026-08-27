(function () {
  var POLL_MS = 2000;
  var emptyEl = document.getElementById("display-empty");
  var cardEl = document.getElementById("display-card");
  var bodyEl = document.getElementById("display-body");
  var whoEl = document.getElementById("display-who");

  function displayName(question) {
    if (question.name && String(question.name).trim()) {
      return String(question.name).trim();
    }
    return "Anonymous";
  }

  function loadCurrent() {
    fetch("api/current.php", { credentials: "same-origin" })
      .then(function (response) {
        return response.json();
      })
      .then(function (data) {
        var question = data && data.question;
        if (!question) {
          emptyEl.hidden = false;
          cardEl.hidden = true;
          return;
        }
        emptyEl.hidden = true;
        cardEl.hidden = false;
        bodyEl.textContent = question.body;
        whoEl.textContent = displayName(question);
      })
      .catch(function () {
        emptyEl.hidden = false;
        cardEl.hidden = true;
      });
  }

  loadCurrent();
  setInterval(loadCurrent, POLL_MS);
})();
