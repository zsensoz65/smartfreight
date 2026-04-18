document.addEventListener("DOMContentLoaded", function () {
  const isLoginPage = window.location.pathname.includes("login");
  if (isLoginPage) return;

  sessionStorage.removeItem("tickerStarted");
  window.tickerInitialized = false;

  console.log("Viewport:", window.innerWidth);
  console.log("User Agent:", navigator.userAgent);

  const tickerHeight = 30;
  const scrollDuration = 60;
  const rotationInterval = 90 * 1000; // 90 seconds = 90,000 ms
  const localKey = "hideNewsTicker";
  let tickerSources = [];

  if (localStorage.getItem(localKey) === "true") return;

  const ticker = document.createElement("div");
  ticker.id = "custom-rss-ticker";
  Object.assign(ticker.style, {
    position: "fixed",
    top: "0",
    left: "0",
    width: "100%",
    zIndex: "100000",
    background: "#004080",
    color: "#fff",
    fontSize: "13px",
    lineHeight: tickerHeight + "px",
    whiteSpace: "nowrap",
    overflow: "hidden",
    padding: "0 10px",
    boxShadow: "0 2px 4px rgba(0,0,0,0.2)"
  });

  const label = document.createElement("div");
  label.textContent = "📢 Latest Logistics News:";
  Object.assign(label.style, {
    fontWeight: "bold",
    marginRight: "20px",
    color: "rgb(255, 215, 0)"
  });

  const wrapper = document.createElement("div");
  wrapper.id = "ticker-wrapper";
  Object.assign(wrapper.style, {
    flex: "1 1 auto",
    overflow: "hidden"
  });

  const span = document.createElement("span");
  span.id = "ticker-text";
  span.style.whiteSpace = "nowrap";
  wrapper.appendChild(span);
  wrapper.appendChild(span.cloneNode(true));

  const toggle = document.createElement("span");
  toggle.innerHTML = "❌ Hide Ticker";
  Object.assign(toggle.style, {
    color: "rgb(255, 215, 0)",
    fontWeight: "bold",
    fontSize: "13px",
    cursor: "pointer",
    padding: "4px 8px",
    userSelect: "none"
  });
  toggle.onclick = function () {
    localStorage.setItem(localKey, "true");
    const el = document.querySelector("#custom-rss-ticker");
    if (el) el.remove();
    location.reload();
  };

  const tickerContent = document.createElement("div");
  Object.assign(tickerContent.style, {
    display: "flex",
    alignItems: "center",
    width: "100%"
  });
  tickerContent.appendChild(label);
  tickerContent.appendChild(wrapper);
  tickerContent.appendChild(toggle);
  ticker.appendChild(tickerContent);
  document.body.appendChild(ticker);

  const header = document.querySelector(".navbar-fixed-top");
  const container = document.querySelector(".page-container");

  if (header && container) {
    header.style.setProperty("position", "fixed", "important");
    header.style.setProperty("top", tickerHeight + "px", "important");
    header.style.setProperty("z-index", "9999", "important");
    header.style.setProperty("padding-top", "4px", "important");

    const totalOffset = tickerHeight + header.offsetHeight;
    container.style.setProperty("margin-top", totalOffset + "px", "important");
  }

  const scrollStyle = document.createElement("style");
  scrollStyle.textContent = `
    @keyframes scrollTickerLoop {
      0% { transform: translateX(-0); }
      100% { transform: translateX(-50%); }
    }
    #ticker-text {
      display: inline-block;
      animation: scrollTickerLoop ${scrollDuration}s linear infinite;
    }
    #custom-rss-ticker:hover #ticker-text {
      animation-play-state: paused;
    }
    #custom-rss-ticker span:hover {
      opacity: 0.8;
    }
    #custom-rss-ticker a {
      color: #fff;
      text-decoration: none;
    }
    #custom-rss-ticker a:hover {
      text-decoration: underline;
    }
  `;
  document.head.appendChild(scrollStyle);

  function startTicker(subjects) {
    span.textContent = "";

    subjects.forEach((html, i) => {
      const label = document.createElement("span");
      label.innerHTML = html;
      Object.assign(label.style, {
        marginRight: "20px"
      });
      span.appendChild(label);

      if (i < subjects.length - 1) {
        const separator = document.createElement("span");
        separator.textContent = " | ";
        Object.assign(separator.style, {
          color: "#fff",
          fontWeight: "bold",
          fontSize: "16px",
          marginRight: "20px"
        });
        span.appendChild(separator);
      }
    });
  }

  window.startTicker = startTicker;

  window.refreshTicker = function () {
    const el = document.querySelector("#custom-rss-ticker");
    if (el) el.remove();
    localStorage.setItem("tickerFeedIndex", "0");
    fetch("https://www.smartfreight.net/config/news_ticker/proxy.php?ts=" + Date.now())
      .then(res => res.json())
      .then(data => {
        tickerSources = data.data
          .filter(item =>
            item["6031_db_value"] === "684" && item["6035"] && item["6033"]
          )
          .sort((a, b) => parseInt(a["6032"], 10) - parseInt(b["6032"], 10));
        if (tickerSources.length === 0) throw new Error("No active sources found");
        rotateFeed();
      })
      .catch(err => {
        console.error("Ticker source reload failed:", err);
        window.startTicker(["[System] Failed to reload ticker sources."]);
      });
  };

  function loadFeed(feedUrl, sourceLabel) {
    console.log("Fetching feed:", feedUrl);
    fetch(`https://api.rss2json.com/v1/api.json?rss_url=${encodeURIComponent(feedUrl)}`)
      .then(res => res.json())
      .then(data => {
        console.log("Feed response:", data);
        if (!data.items || data.items.length === 0) throw new Error("No headlines found");

        const subjects = data.items.map(item => {
          return `<a href="${item.link}" target="_blank">[${sourceLabel}] ${item.title}</a>`;
        });

        window.startTicker(subjects);
      })
      .catch(error => {
        console.error("Ticker error:", error);
        window.startTicker(["[System] No headlines available."]);
      });
  }

  function rotateFeed() {
    if (!tickerSources || tickerSources.length === 0) {
      console.warn("No sources available for rotation.");
      return;
    }

    let index = parseInt(localStorage.getItem("tickerFeedIndex") || "0", 10);
    index = (index + 1) % tickerSources.length;
    localStorage.setItem("tickerFeedIndex", index);

    const source = tickerSources[index];
    if (!source || !source["6035"] || !source["6033"]) {
      console.warn("Invalid source data:", source);
      window.startTicker(["[System] Invalid feed source."]);
      return;
    }

    console.log("Rotating to source:", source["6033"]);
    loadFeed(source["6035"], source["6033"]);
  }

  console.time("Ticker Load");

  let tickerStarted = false;

  function startTickerInit() {
    if (tickerStarted || window.tickerInitialized || sessionStorage.getItem("tickerStarted")) return;
    tickerStarted = true;
    window.tickerInitialized = true;
    sessionStorage.setItem("tickerStarted", "true");

    try {
      fetch("https://www.smartfreight.net/config/news_ticker/proxy.php?ts=" + Date.now())
        .then(res => res.json())
        .then(data => {
          tickerSources = data.data
            .filter(item =>
              item["6031_db_value"] === "684" && item["6035"] && item["6033"]
            )
            .sort((a, b) => parseInt(a["6032"], 10) - parseInt(b["6032"], 10));
          console.log("Valid sources:", tickerSources);
          if (tickerSources.length === 0) throw new Error("No active sources found");
          rotateFeed();
          setInterval(rotateFeed, rotationInterval);
        })
        .catch(err => {
          console.error("Ticker source load failed:", err);
          window.startTicker(["[System] No headlines available."]);
        })
        .finally(() => {
          console.timeEnd("Ticker Load");
        });
    } catch (e) {
      console.error("Ticker script failed to initialize:", e);
    }
  }

  if ('requestIdleCallback' in window) {
    requestIdleCallback(startTickerInit);
  }

  setTimeout(() => {
    if (!tickerStarted) {
      console.warn("Fallback ticker init triggered.");
      startTickerInit();
    }
  }, 1000);

  window.addEventListener("load", () => {
    if (!tickerStarted) {
      console.warn("Load-triggered ticker init.");
      startTickerInit();
    }

    // Optional layout reflow nudge to fix Chrome refresh quirks
    document.body.style.zoom = "1";
  });
});
