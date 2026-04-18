document.addEventListener("DOMContentLoaded", function () {
  const localKey = "hideNewsTicker";

  if (localStorage.getItem(localKey) === "true") {
    const showBtn = document.createElement("button");
    showBtn.textContent = "📢 Show News Ticker";
    Object.assign(showBtn.style, {
      position: "absolute",
      top: "8px",
      right: "400px", // ← Adjust this value to move it away from username
      zIndex: "10000",
      background: "rgb(0, 0, 0)",
      color: "rgb(255, 255, 255)",
      border: "none",
      padding: "6px 12px",
      fontSize: "13px",
      fontWeight: "bold",
      cursor: "pointer",
      borderRadius: "4px",
      boxShadow: "0 2px 4px rgba(0,0,0,0.2)"
    });

    showBtn.onclick = function () {
      localStorage.removeItem(localKey);
      location.reload();
    };

    const topBar = document.querySelector(".navbar-fixed-top");
    if (topBar) {
      topBar.appendChild(showBtn);
    }
  }
});
