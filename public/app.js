const STORAGE_KEY = "trang-thu-reader-preferences";
const RANGE_SETTINGS = {
  fontSize: { min: 16, max: 30, step: 1, unit: " px", precision: 0 },
  lineHeight: { min: 1.5, max: 2.4, step: 0.1, unit: "", precision: 1 },
  padding: { min: 0, max: 32, step: 1, unit: " px", precision: 0 },
};
const DEFAULTS = {
  background: "#ffffff",
  textColor: "#343a40",
  fontFamily: "literata",
  fontSize: 19,
  lineHeight: 1.9,
  padding: 12,
  alignment: "left",
  shadow: false,
  theme: "system",
};
const PRESETS = {
  paper: { background: "#ffffff", textColor: "#343a40" },
  warm: { background: "#f4ecdf", textColor: "#463e34" },
  sage: { background: "#e5ece5", textColor: "#343e38" },
  night: { background: "#222725", textColor: "#e5e8e5" },
};

const body = document.body;
const views = {
  home: document.getElementById("home-view"),
  detail: document.getElementById("detail-view"),
  library: document.getElementById("library-view"),
  admin: document.getElementById("admin-view"),
  reader: document.getElementById("reader-view"),
};
const readerPage = document.getElementById("reader-view");
const readerArticle = document.getElementById("reader-article");
const settingsPanel = document.getElementById("reader-settings");
const settingsToggle = document.getElementById("settings-toggle");
const toast = document.getElementById("toast");
let toastTimer;
let currentPage = "home";
let selectedBook = null;
let currentChapter = null;
let reportChapterId = null;
let catalogIsLive = false;
let currentUser = null;
let csrfToken = "";
let sessionLoaded = false;
let pendingChapterId = null;
let pendingLibrarySlug = null;
let pendingFollowSlug = null;
let pendingReaction = null;
let progressSaveTimer = null;
let lastSavedProgress = -1;
let currentAdminQueue = "books";
let authMode = "login";
let realtimeSource = null;
let realtimeRetryTimer = null;
let realtimeRefreshTimer = null;
let realtimeRetryDelay = 5000;

function readPreferences() {
  try {
    const stored = JSON.parse(localStorage.getItem(STORAGE_KEY));
    if (!stored || typeof stored !== "object") return { ...DEFAULTS };
    return {
      ...DEFAULTS,
      background: validColor(stored.background, DEFAULTS.background),
      textColor: validColor(stored.textColor, DEFAULTS.textColor),
      fontFamily: ["literata", "be-vietnam", "serif", "sans"].includes(stored.fontFamily) ? stored.fontFamily : DEFAULTS.fontFamily,
      fontSize: clampRangeValue(stored.fontSize, "fontSize"),
      lineHeight: clampRangeValue(stored.lineHeight, "lineHeight"),
      padding: clampRangeValue(stored.padding, "padding"),
      alignment: ["left", "justify"].includes(stored.alignment) ? stored.alignment : DEFAULTS.alignment,
      shadow: typeof stored.shadow === "boolean" ? stored.shadow : DEFAULTS.shadow,
      theme: ["light", "dark", "system"].includes(stored.theme) ? stored.theme : DEFAULTS.theme,
    };
  } catch (error) {
    console.warn("[Reader preferences] Could not read saved preferences.", error);
    return { ...DEFAULTS };
  }
}

function renderImportJobs(jobs) {
  const list = document.getElementById("import-jobs");
  list.replaceChildren();
  const statuses = {
    queued: "Chờ xử lý",
    processing: "Đang xử lý",
    review: "Chờ kiểm duyệt",
    completed: "Hoàn tất",
    failed: "Cần kiểm tra",
  };
  jobs.forEach((job) => {
    const item = document.createElement("article");
    item.className = "import-job";
    item.append(
      createTextElement("strong", "", job.book_title || "Tác phẩm đã xóa"),
      createTextElement("small", "", `${job.source_type.toUpperCase()} · ${statuses[job.status] || job.status}`),
    );
    if (job.error_message) item.append(createTextElement("p", "import-error", job.error_message));
    list.append(item);
  });
  document.getElementById("chapter-submit-form").addEventListener("submit", async (event) => {
    event.preventDefault();
    if (!selectedBook) return;
    const form = event.currentTarget;
    const status = document.getElementById("chapter-submit-status");
    status.textContent = "Đang gửi chương...";
    try {
      const result = await apiRequest(`/api/me/books/${encodeURIComponent(selectedBook.slug)}/chapters`, {
        method: "POST",
        headers: { "Content-Type": "application/json" },
        body: JSON.stringify(Object.fromEntries(new FormData(form))),
      });
      form.reset();
      status.textContent = `Đã gửi ${result.parts_count} phần của chương ${result.chapter_number}. Nội dung sẽ hiển thị sau khi được duyệt.`;
    } catch (error) {
      status.textContent = error.message || "Không thể gửi chương.";
    }
  });
}

function renderNotifications(data) {
  const list = document.getElementById("notification-list");
  const badge = document.getElementById("notification-badge");
  list.replaceChildren();
  const unreadCount = Number(data.unread_count) || 0;
  badge.textContent = unreadCount > 99 ? "99+" : String(unreadCount);
  badge.hidden = unreadCount === 0;
  document.getElementById("notification-trigger").setAttribute(
    "aria-label",
    unreadCount ? `Thông báo, ${unreadCount} chưa đọc` : "Thông báo",
  );
  if (!data.items.length) {
    list.append(createTextElement("p", "notification-empty", "Bạn chưa có thông báo nào."));
    return;
  }
  const messages = {
    new_comment: ({ actor, book_title }) => `${actor} đã bình luận về truyện ${book_title}.`,
    new_follower: ({ actor, book_title }) => `${actor} đã theo dõi truyện ${book_title}.`,
    new_reaction: ({ actor, book_title }) => `${actor} đã bày tỏ cảm xúc với ${book_title}.`,
    book_available: ({ book_title }) => `${book_title} đã được xuất bản hoặc cập nhật.`,
    new_chapter: ({ book_title, chapter_title }) => `${book_title} có chương mới: ${chapter_title}.`,
  };
  data.items.forEach((notification) => {
    const item = document.createElement("button");
    item.type = "button";
    item.className = `notification-item${notification.read_at ? "" : " is-unread"}`;
    item.dataset.notificationId = notification.id;
    if (notification.payload.book_slug) item.dataset.bookSlug = notification.payload.book_slug;
    if (notification.payload.chapter_id) item.dataset.chapterId = notification.payload.chapter_id;
    const message = messages[notification.type]?.(notification.payload) || "Bạn có một thông báo mới.";
    item.append(
      createTextElement("strong", "", message),
      createTextElement("time", "", new Date(notification.created_at).toLocaleString("vi-VN")),
    );
    list.append(item);
  });
}

async function loadNotifications() {
  if (!currentUser) return;
  try {
    renderNotifications(await apiRequest("/api/me/notifications"));
  } catch (error) {
    const list = document.getElementById("notification-list");
    list.replaceChildren(createTextElement("p", "notification-empty", error.message || "Không thể tải thông báo."));
  }
}

function stopRealtimeNotifications() {
  clearTimeout(realtimeRetryTimer);
  clearTimeout(realtimeRefreshTimer);
  realtimeRetryTimer = null;
  realtimeRefreshTimer = null;
  if (realtimeSource) realtimeSource.close();
  realtimeSource = null;
}

function scheduleRealtimeReconnect() {
  if (!currentUser || realtimeRetryTimer) return;
  const delay = realtimeRetryDelay;
  realtimeRetryDelay = Math.min(60000, realtimeRetryDelay * 2);
  realtimeRetryTimer = setTimeout(() => {
    realtimeRetryTimer = null;
    connectRealtimeNotifications();
  }, delay);
}

async function connectRealtimeNotifications() {
  if (!currentUser) return;
  stopRealtimeNotifications();
  try {
    const connection = await apiRequest("/api/realtime-token");
    if (!currentUser) return;
    const endpoint = `${connection.realtime_url.replace(/\/$/, "")}/events?token=${encodeURIComponent(connection.token)}`;
    const source = new EventSource(endpoint);
    realtimeSource = source;
    source.addEventListener("open", () => { realtimeRetryDelay = 5000; });
    source.addEventListener("notification", () => {
      loadNotifications();
      showToast("Bạn vừa nhận được thông báo mới.");
    });
    source.onerror = () => {
      source.close();
      if (realtimeSource === source) realtimeSource = null;
      scheduleRealtimeReconnect();
    };
    realtimeRefreshTimer = setTimeout(() => {
      source.close();
      if (realtimeSource === source) realtimeSource = null;
      connectRealtimeNotifications();
    }, Math.max(1000, connection.expires_at * 1000 - Date.now() - 5000));
  } catch (error) {
    console.warn("[Notifications] Realtime connection is unavailable.", error);
    scheduleRealtimeReconnect();
  }
}

function updateSaveButton(saved) {
  const button = document.getElementById("save-story");
  button.dataset.saved = String(saved);
  button.querySelector("span").textContent = saved ? "Đã lưu · Gỡ khỏi kệ" : "Lưu vào kệ";
  button.setAttribute("aria-pressed", String(saved));
  document.getElementById("save-note").textContent = currentUser
    ? "Tủ sách được lưu và đồng bộ theo tài khoản của bạn."
    : "Đăng nhập để lưu truyện vào kệ cá nhân.";
}

async function setBookSaved(slug, saved) {
  if (!currentUser) {
    pendingLibrarySlug = slug;
    document.getElementById("account-dialog").showModal();
    showToast("Đăng nhập để lưu truyện vào kệ cá nhân.");
    return;
  }
  try {
    const method = saved ? "PUT" : "DELETE";
    await apiRequest(`/api/me/library/${encodeURIComponent(slug)}`, { method });
    if (selectedBook?.slug === slug) updateSaveButton(saved);
    showToast(saved ? "Đã lưu truyện vào kệ của bạn." : "Đã gỡ truyện khỏi kệ.");
    if (currentPage === "library") await loadLibraryPage();
  } catch (error) {
    if (error.status === 401) {
      currentUser = null;
      updateAccountUI();
      document.getElementById("account-dialog").showModal();
      return;
    }
    showToast(error.message || "Không thể cập nhật tủ truyện.");
  }
}

function toggleBookSaved() {
  if (!selectedBook) {
    showToast("Hãy mở một truyện trước khi lưu vào kệ.");
    return;
  }
  const saved = document.getElementById("save-story").dataset.saved === "true";
  setBookSaved(selectedBook.slug, !saved);
}

async function loadLibraryPage() {
  const signIn = document.getElementById("library-signin");
  const historyList = document.getElementById("reading-history-list");
  const libraryBooks = document.getElementById("library-books");
  const status = document.getElementById("library-status");
  const importSection = document.getElementById("import-section");
  historyList.replaceChildren();
  libraryBooks.replaceChildren();
  document.getElementById("history-empty").hidden = true;
  document.getElementById("library-empty").hidden = true;
  signIn.hidden = Boolean(currentUser);
  importSection.hidden = !currentUser;
  if (!currentUser) {
    status.textContent = "";
    return;
  }

  status.textContent = "Đang tải tủ truyện...";
  try {
    const [historyItems, books, jobs] = await Promise.all([
      apiRequest("/api/me/reading-history"),
      apiRequest("/api/me/library"),
      apiRequest("/api/me/imports"),
    ]);
    renderImportJobs(jobs);
    historyItems.forEach((item) => {
      const button = document.createElement("button");
      button.className = "history-item";
      button.type = "button";
      button.dataset.resumeSlug = item.book_slug;
      button.dataset.resumeChapterId = item.chapter_id;
      button.dataset.progressPercent = item.progress_percent;
      const details = document.createElement("span");
      details.append(
        createTextElement("strong", "", item.book_title),
        createTextElement("small", "", `Chương ${item.chapter_number} · ${item.chapter_title} · ${item.author}`),
      );
      const progress = document.createElement("progress");
      progress.className = "history-progress";
      progress.max = 100;
      progress.value = Number(item.progress_percent);
      progress.setAttribute("aria-label", `Đã đọc ${item.progress_percent}%`);
      button.append(details, progress, createTextElement("span", "history-percent", `${item.progress_percent}%`));
      historyList.append(button);
    });
    books.forEach((book, index) => libraryBooks.append(createBookCard(book, index)));
    document.getElementById("history-empty").hidden = historyItems.length > 0;
    document.getElementById("library-empty").hidden = books.length > 0;
    status.textContent = `${historyItems.length} mục lịch sử · ${books.length} truyện đã lưu.`;
  } catch (error) {
    status.textContent = error.message || "Không thể tải tủ truyện.";
  }
}

function renderCommunity(data) {
  document.getElementById("like-count").textContent = Number(data.likes).toLocaleString("vi");
  document.getElementById("love-count").textContent = Number(data.loves).toLocaleString("vi");
  const followButton = document.getElementById("follow-story");
  followButton.setAttribute("aria-pressed", String(data.is_following));
  followButton.textContent = `${data.is_following ? "Đang theo dõi" : "Theo dõi truyện"} · ${Number(data.followers).toLocaleString("vi")}`;
  document.querySelectorAll("[data-reaction]").forEach((button) => {
    button.setAttribute("aria-pressed", String(button.dataset.reaction === data.my_reaction));
  });
  const list = document.getElementById("comment-list");
  list.replaceChildren();
  data.comments.forEach((comment) => {
    const item = document.createElement("article");
    item.className = "comment-item";
    item.append(
      createTextElement("strong", "", comment.author),
      createTextElement("time", "", new Date(comment.created_at).toLocaleString("vi-VN")),
      createTextElement("p", "", comment.body),
    );
    list.append(item);
  });
  document.getElementById("comments-empty").hidden = data.comments.length > 0;
}

async function loadAdminPage() {
  const allowed = ["admin", "moderator"].includes(currentUser?.role);
  if (!allowed) {
    showToast("Bạn không có quyền truy cập khu vực quản trị.");
    navigateTo("home");
    return;
  }
  document.getElementById("admin-users-tab").hidden = currentUser.role !== "admin";
  try {
    const metrics = await apiRequest("/api/admin/overview");
    const labels = [
      ["pending_books", "Truyện chờ duyệt"],
      ["pending_chapters", "Chương chờ duyệt"],
      ["open_reports", "Báo cáo cần xử lý"],
      ["visible_comments", "Bình luận đang hiển thị"],
      ["active_users", "Tài khoản hoạt động"],
    ];
    const container = document.getElementById("admin-metrics");
    container.replaceChildren(...labels.map(([key, label]) => {
      const card = document.createElement("article");
      card.className = "admin-metric";
      card.append(
        createTextElement("strong", "", Number(metrics[key]).toLocaleString("vi")),
        createTextElement("span", "", label),
      );
      return card;
    }));
    await loadAdminQueue(currentAdminQueue);
  } catch (error) {
    document.getElementById("admin-queue-status").textContent = error.message || "Không thể tải dữ liệu quản trị.";
  }
}

async function loadAdminQueue(queue) {
  currentAdminQueue = queue;
  document.querySelectorAll("[data-admin-queue]").forEach((button) => {
    button.classList.toggle("is-selected", button.dataset.adminQueue === queue);
  });
  const endpoints = {
    books: "/api/admin/books?moderation_status=all",
    chapters: "/api/admin/chapters?moderation_status=all",
    reports: "/api/admin/reports?status=active",
    comments: "/api/admin/comments?status=all",
    users: "/api/admin/users",
  };
  const status = document.getElementById("admin-queue-status");
  const container = document.getElementById("admin-queue");
  status.textContent = "Đang tải hàng chờ...";
  container.replaceChildren();
  try {
    const items = await apiRequest(endpoints[queue]);
    items.forEach((item) => container.append(renderAdminItem(item, queue)));
    status.textContent = `${items.length} mục trong hàng chờ.`;
    if (items.length === 0) container.append(createTextElement("p", "library-empty", "Không có mục nào cần xử lý."));
  } catch (error) {
    status.textContent = error.message || "Không thể tải hàng chờ.";
  }
}

function renderAdminItem(item, queue) {
  const card = document.createElement("article");
  card.className = "admin-item";
  const main = document.createElement("div");
  main.className = "admin-item-main";
  const title = queue === "users" ? `${item.name} · ${item.email}` : queue === "books" ? item.title
    : queue === "chapters" ? `${item.book_title} · Chương ${item.chapter_number}${Number(item.part_number) > 1 ? `, phần ${item.part_number}` : ""} · ${item.title}`
      : queue === "reports" ? `${item.book_title || "Truyện đã gỡ"} · ${item.reason}` : `${item.book_title} · ${item.author}`;
  main.append(createTextElement("strong", "", title));
  if (queue === "books") {
    main.append(createTextElement("p", "", `${item.author} · ${item.genre} · ${item.uploader || "Biên tập viên"} · ${item.status} / ${item.visibility}`));
    main.append(createTextElement("p", "", item.description));
  } else if (queue === "reports") {
    const target = item.chapter_title
      ? `${item.book_title} · Chương ${item.chapter_number}${Number(item.part_number) > 1 ? `, phần ${item.part_number}` : ""}: ${item.chapter_title}`
      : item.book_title || "Truyện đã gỡ";
    main.append(createTextElement("p", "", target));
    main.append(createTextElement("p", "", `Người báo cáo: ${item.reporter} · ${item.details || "Không có thông tin bổ sung"}`));
  } else if (queue === "comments") {
    main.append(createTextElement("p", "", item.body));
  } else if (queue === "chapters") {
    main.append(createTextElement("p", "", `${item.author || "Tác giả"} · ${Number(item.word_count).toLocaleString("vi")} từ · ${item.status} / ${item.visibility} · Gửi lúc ${new Date(item.created_at).toLocaleString("vi-VN")}`));
    main.append(createTextElement("p", "", item.preview || ""));
  } else {
    main.append(createTextElement("p", "", `${item.role} · ${item.status} · ${new Date(item.created_at).toLocaleDateString("vi-VN")}`));
  }
  const actions = document.createElement("div");
  actions.className = "admin-item-actions";
  if (queue === "users") {
    const self = String(item.id) === String(currentUser?.id);
    const role = createUserSelect(item, "role", ["reader", "moderator", "admin"]);
    const status = createUserSelect(item, "status", ["active", "suspended", "deleted"]);
    role.disabled = self;
    status.disabled = self;
    actions.append(
      role,
      status,
      createAdminAction("Lưu", "user", item.id, "update_access"),
    );
    actions.lastElementChild.disabled = self;
  } else {
    const actionSets = {
      books: item.status === "pending" ? [["Duyệt", "approve"], ["Từ chối", "reject"]]
        : item.visibility === "public" ? [["Ẩn truyện", "hide"]] : [["Duyệt / xuất bản", "publish"]],
      chapters: item.status === "pending" ? [["Duyệt", "approve"], ["Từ chối", "reject"]]
        : item.status === "approved" && item.visibility === "public" ? [["Ẩn chương", "hide"]]
          : item.status === "approved" && item.visibility === "hidden" ? [["Khôi phục", "restore"]] : [],
      reports: [["Đang xem xét", "reviewing"], ["Đã xử lý", "resolved"], ["Bỏ qua", "dismissed"]],
      comments: item.status === "visible" ? [["Ẩn", "hidden"], ["Gỡ", "removed"]] : [["Khôi phục", "visible"], ["Gỡ", "removed"]],
    };
    actionSets[queue].forEach(([label, action]) => actions.append(createAdminAction(label, queue, item.id, action)));
  }
  card.append(main, actions);
  return card;
}

function createUserSelect(user, field, values) {
  const select = document.createElement("select");
  select.dataset.userId = user.id;
  select.dataset.userField = field;
  values.forEach((value) => select.add(new Option(value, value, value === user[field], value === user[field])));
  select.setAttribute("aria-label", `${field === "role" ? "Vai trò" : "Trạng thái"} của ${user.name}`);
  return select;
}

function createAdminAction(label, target, id, action) {
  const button = createTextElement("button", "", label);
  button.type = "button";
  button.dataset.adminTarget = target;
  button.dataset.adminId = id;
  button.dataset.adminAction = action;
  return button;
}

async function submitAdminAction(button) {
  const { adminTarget: target, adminId: id, adminAction: action } = button.dataset;
  const endpoints = { books: "books", chapters: "chapters", reports: "reports", comments: "comments", user: "users" };
  const payload = ["books", "chapters"].includes(target) ? { action } : target === "user"
    ? {
      role: document.querySelector(`[data-user-id="${id}"][data-user-field="role"]`).value,
      status: document.querySelector(`[data-user-id="${id}"][data-user-field="status"]`).value,
    }
    : { status: action };
  try {
    await apiRequest(`/api/admin/${endpoints[target]}/${id}`, {
      method: "PATCH",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    showToast("Đã cập nhật và ghi nhật ký quản trị.");
    await loadAdminPage();
  } catch (error) {
    showToast(error.message || "Không thể cập nhật mục quản trị.");
  }
}

async function loadCommunity(slug) {
  try {
    renderCommunity(await apiRequest(`/api/books/${encodeURIComponent(slug)}/community`));
  } catch (error) {
    showToast(error.message || "Không thể tải góc bạn đọc.");
  }
}

async function updateCommunity(path, options, successMessage) {
  if (!currentUser) {
    document.getElementById("account-dialog").showModal();
    showToast("Đăng nhập để tương tác với truyện.");
    return false;
  }
  try {
    await apiRequest(path, options);
    await loadCommunity(selectedBook.slug);
    if (successMessage) showToast(successMessage);
    return true;
  } catch (error) {
    showToast(error.message || "Không thể cập nhật tương tác.");
    return false;
  }
}

async function saveReadingProgress(progressPercent) {
  if (!currentUser || !currentChapter || lastSavedProgress === progressPercent) return;
  try {
    await apiRequest("/api/me/reading-history", {
      method: "PUT",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify({ chapter_id: Number(currentChapter.id), progress_percent: progressPercent }),
    });
    lastSavedProgress = progressPercent;
  } catch (error) {
    showToast(error.message || "Không thể lưu tiến độ đọc.");
  }
}

async function savedProgressFor(chapterId) {
  try {
    const historyItems = await apiRequest("/api/me/reading-history");
    const entry = historyItems.find((item) => String(item.chapter_id) === String(chapterId));
    return entry ? Number(entry.progress_percent) : 0;
  } catch (error) {
    if (error.status !== 401) showToast(error.message || "Không thể khôi phục tiến độ đọc.");
    return 0;
  }
}

function updateReadingProgress(progressPercent) {
  const value = Math.max(0, Math.min(100, Math.round(progressPercent)));
  document.querySelector(".reader-progress span").style.width = `${value}%`;
  document.querySelector(".reader-progress").setAttribute("aria-label", `Đã đọc ${value}% chương này`);
}

window.addEventListener("scroll", () => {
  if (currentPage !== "reader" || !currentUser || !currentChapter) return;
  const article = document.getElementById("reader-article");
  const articleTop = article.getBoundingClientRect().top + window.scrollY;
  const availableScroll = Math.max(1, article.offsetHeight - window.innerHeight);
  const progress = Math.round(Math.max(0, Math.min(100, (window.scrollY - articleTop) / availableScroll * 100)));
  updateReadingProgress(progress);
  window.clearTimeout(progressSaveTimer);
  progressSaveTimer = window.setTimeout(() => saveReadingProgress(progress), 1400);
}, { passive: true });

document.getElementById("library-signin-button").addEventListener("click", () => {
  document.getElementById("account-dialog").showModal();
});
document.getElementById("import-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = event.currentTarget;
  const errorOutput = document.getElementById("import-error");
  errorOutput.textContent = "";
  try {
    await apiRequest("/api/me/imports", { method: "POST", body: new FormData(form) });
    form.reset();
    showToast("Đã nhận tệp. Worker sẽ xử lý trước khi gửi kiểm duyệt.");
    await loadLibraryPage();
  } catch (error) {
    errorOutput.textContent = error.message || "Không thể tải tệp truyện.";
  }
});
document.querySelectorAll("[data-admin-queue]").forEach((button) => {
  button.addEventListener("click", () => loadAdminQueue(button.dataset.adminQueue));
});
document.getElementById("admin-queue").addEventListener("click", (event) => {
  const button = event.target.closest("[data-admin-action]");
  if (button) submitAdminAction(button);
});

document.getElementById("follow-story").addEventListener("click", async () => {
  if (!selectedBook) return;
  const following = document.getElementById("follow-story").getAttribute("aria-pressed") === "true";
  await updateCommunity(
    `/api/me/books/${encodeURIComponent(selectedBook.slug)}/follow`,
    { method: following ? "DELETE" : "PUT" },
    following ? "Đã bỏ theo dõi truyện." : "Bạn đang theo dõi truyện này.",
  );
});
document.querySelectorAll("[data-reaction]").forEach((button) => {
  button.addEventListener("click", async () => {
    if (!selectedBook) return;
    const selected = button.getAttribute("aria-pressed") === "true";
    const path = `/api/me/books/${encodeURIComponent(selectedBook.slug)}/reaction`;
    await updateCommunity(
      path,
      selected
        ? { method: "DELETE" }
        : { method: "PUT", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ reaction: button.dataset.reaction }) },
      selected ? "Đã gỡ cảm xúc." : "Đã ghi nhận cảm xúc của bạn.",
    );
  });
});
document.getElementById("comment-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!selectedBook) return;
  const body = document.getElementById("comment-body");
  const saved = await updateCommunity(
    `/api/books/${encodeURIComponent(selectedBook.slug)}/comments`,
    { method: "POST", headers: { "Content-Type": "application/json" }, body: JSON.stringify({ body: body.value }) },
    "Bình luận đã được đăng.",
  );
  if (saved) body.value = "";
});
document.getElementById("report-story").addEventListener("click", () => {
  reportChapterId = null;
  if (!currentUser) {
    document.getElementById("account-dialog").showModal();
    showToast("Đăng nhập để gửi báo cáo.");
    return;
  }
  document.getElementById("report-error").textContent = "";
  document.getElementById("report-title").textContent = "Báo cáo truyện";
  document.querySelector(".report-intro").textContent = "Báo cáo sẽ được gửi để đội ngũ quản trị xem xét.";
  document.getElementById("report-dialog").showModal();
});
document.getElementById("report-chapter").addEventListener("click", () => {
  if (!currentUser) {
    document.getElementById("account-dialog").showModal();
    showToast("Đăng nhập để gửi báo cáo.");
    return;
  }
  if (!currentChapter || !selectedBook) {
    showToast("Chưa có chương đang mở để báo cáo.");
    return;
  }
  reportChapterId = Number(currentChapter.id);
  document.getElementById("report-error").textContent = "";
  document.getElementById("report-title").textContent = "Báo cáo chương";
  document.querySelector(".report-intro").textContent = `Báo cáo chương ${currentChapter.chapter_number}: ${currentChapter.title}.`;
  document.getElementById("report-dialog").showModal();
});
document.getElementById("report-close").addEventListener("click", () => document.getElementById("report-dialog").close());
document.getElementById("report-cancel").addEventListener("click", () => document.getElementById("report-dialog").close());
document.getElementById("report-dialog").addEventListener("click", (event) => {
  if (event.target === event.currentTarget) event.currentTarget.close();
});
document.getElementById("report-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  if (!selectedBook) return;
  const form = new FormData(event.currentTarget);
  try {
    const payload = Object.fromEntries(form.entries());
    if (reportChapterId) payload.chapter_id = reportChapterId;
    await apiRequest(`/api/books/${encodeURIComponent(selectedBook.slug)}/reports`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(payload),
    });
    document.getElementById("report-dialog").close();
    event.currentTarget.reset();
    reportChapterId = null;
    showToast("Báo cáo đã được gửi để xem xét.");
  } catch (error) {
    document.getElementById("report-error").textContent = error.message || "Không thể gửi báo cáo.";
  }
});

function validColor(value, fallback) {
  return typeof value === "string" && /^#[\da-f]{6}$/i.test(value) ? value : fallback;
}

function boundedNumber(value, min, max, fallback) {
  const parsed = Number(value);
  return Number.isFinite(parsed) ? Math.min(max, Math.max(min, parsed)) : fallback;
}

function clampRangeValue(value, key) {
  const { min, max } = RANGE_SETTINGS[key];
  return boundedNumber(value, min, max, DEFAULTS[key]);
}

function syncRangeControl(key, value) {
  const ids = { fontSize: "font-size", lineHeight: "line-height", padding: "reading-width" };
  const range = RANGE_SETTINGS[key];
  const input = document.getElementById(ids[key]);
  const output = document.getElementById(`${ids[key]}-value`);
  input.min = range.min;
  input.max = range.max;
  input.step = range.step;
  input.value = value;
  output.value = `${Number(value).toFixed(range.precision)}${range.unit}`;
}

let preferences = readPreferences();

function persistPreferences() {
  try {
    localStorage.setItem(STORAGE_KEY, JSON.stringify(preferences));
  } catch (error) {
    showToast("Không thể lưu cài đặt trên thiết bị này.");
    console.error("[Reader preferences] Could not save preferences.", error);
  }
}

function setTheme(theme) {
  const prefersDark = window.matchMedia("(prefers-color-scheme: dark)").matches;
  const dark = theme === "dark" || (theme === "system" && prefersDark);
  body.dataset.theme = dark ? "dark" : "light";
  const useDefaultColors = preferences.background === DEFAULTS.background && preferences.textColor === DEFAULTS.textColor;
  const background = dark && useDefaultColors ? PRESETS.night.background : preferences.background;
  const textColor = dark && useDefaultColors ? PRESETS.night.textColor : preferences.textColor;
  readerPage.style.setProperty("--reader-background", background);
  readerPage.style.setProperty("--reader-ink", textColor);
}

function applyPreferences() {
  const fonts = {
    literata: '"Literata", Georgia, serif',
    "be-vietnam": '"Be Vietnam Pro", sans-serif',
    serif: "Georgia, serif",
    sans: "Arial, sans-serif",
  };
  readerArticle.style.setProperty("--reader-font", fonts[preferences.fontFamily]);
  readerArticle.style.setProperty("--reader-size", `${preferences.fontSize}px`);
  readerArticle.style.setProperty("--reader-line", preferences.lineHeight);
  readerArticle.style.setProperty("--reader-padding", `${preferences.padding}px`);
  readerArticle.style.setProperty("--reader-align", preferences.alignment);
  readerArticle.style.setProperty("--reader-shadow", preferences.shadow ? "0 0 .4px currentColor" : "none");
  setTheme(preferences.theme);

  document.getElementById("font-family").value = preferences.fontFamily;
  syncRangeControl("fontSize", preferences.fontSize);
  syncRangeControl("lineHeight", preferences.lineHeight);
  syncRangeControl("padding", preferences.padding);
  document.getElementById("text-shadow").checked = preferences.shadow;
  document.getElementById("text-color").value = preferences.textColor;
  document.getElementById("background-color").value = preferences.background;
  document.querySelectorAll("[data-align]").forEach((button) => {
    button.setAttribute("aria-pressed", String(button.dataset.align === preferences.alignment));
  });
  document.querySelectorAll("[data-theme]").forEach((button) => {
    button.setAttribute("aria-pressed", String(button.dataset.theme === preferences.theme));
  });
  document.querySelectorAll("[data-preset]").forEach((button) => {
    const preset = PRESETS[button.dataset.preset];
    button.setAttribute("aria-pressed", String(preset.background === preferences.background && preset.textColor === preferences.textColor));
  });
}

function showToast(message) {
  toast.textContent = message;
  toast.classList.add("is-visible");
  window.clearTimeout(toastTimer);
  toastTimer = window.setTimeout(() => toast.classList.remove("is-visible"), 2600);
}

function navigateTo(page) {
  if (!views[page]) return;
  currentPage = page;
  Object.entries(views).forEach(([name, view]) => {
    const active = name === page;
    view.hidden = !active;
    view.classList.toggle("is-visible", active);
  });
  document.querySelectorAll(".nav-link").forEach((button) => {
    button.classList.toggle("is-active", button.dataset.page === page);
  });
  closeSettings();
  history.replaceState(null, "", `#${page}`);
  window.scrollTo({ top: 0, behavior: "smooth" });
}

function closeSettings() {
  settingsPanel.hidden = true;
  settingsToggle.setAttribute("aria-expanded", "false");
}

function updatePreference(key, value) {
  preferences = { ...preferences, [key]: value };
  applyPreferences();
  persistPreferences();
}

async function apiRequest(path, options = {}) {
  const headers = { Accept: "application/json", ...options.headers };
  const method = (options.method || "GET").toUpperCase();
  if (!["GET", "HEAD", "OPTIONS"].includes(method) && csrfToken) headers["X-CSRF-Token"] = csrfToken;
  const response = await fetch(path, { credentials: "same-origin", ...options, headers });
  const result = await response.json();
  if (!response.ok) {
    const message = response.status >= 500 ? "Dịch vụ dữ liệu hiện chưa sẵn sàng." : result.error?.message || "Không thể tải dữ liệu.";
    const error = new Error(message);
    error.status = response.status;
    throw error;
  }
  return result.data;
}

function createTextElement(tag, className, text) {
  const element = document.createElement(tag);
  if (className) element.className = className;
  element.textContent = text;
  return element;
}

function createBookCard(book, index) {
  const palettes = ["cover-sage", "cover-rose", "cover-blue"];
  const card = document.createElement("article");
  card.className = "book-card";
  const cover = document.createElement("button");
  cover.className = `book-cover ${palettes[index % palettes.length]}`;
  cover.dataset.page = "detail";
  cover.dataset.bookSlug = book.slug;
  cover.setAttribute("aria-label", `Xem truyện ${book.title}`);
  cover.append(createTextElement("span", "cover-kicker", book.genre || "SÁCH HAY"));
  const title = createTextElement("span", "cover-title", book.title);
  cover.append(title, createTextElement("span", "cover-author", book.author.toLocaleUpperCase("vi")));
  const ornament = createTextElement("span", "cover-ornament", "✳");
  ornament.setAttribute("aria-hidden", "true");
  cover.append(ornament);
  const meta = document.createElement("div");
  meta.className = "book-meta";
  const name = createTextElement("button", "book-name", book.title);
  name.dataset.page = "detail";
  name.dataset.bookSlug = book.slug;
  meta.append(name, createTextElement("span", "", `${book.author} · ${book.genre}`));
  const status = createTextElement("small", "", `${book.status === "completed" ? "Đã hoàn thành" : book.status === "paused" ? "Tạm ngưng" : "Đang ra"} · ${Number(book.read_count).toLocaleString("vi")} lượt đọc`);
  meta.append(status);
  card.append(cover, meta);
  return card;
}

function updateCatalogStatus(message) {
  document.getElementById("catalog-status").textContent = message;
}

function updateDateLabel() {
  const date = new Intl.DateTimeFormat("vi-VN", {
    weekday: "long",
    day: "numeric",
    month: "long",
  }).format(new Date());
  document.getElementById("today-label").textContent = date.toLocaleUpperCase("vi");
}

async function loadGenres() {
  try {
    const genres = await apiRequest("/api/genres");
    const select = document.getElementById("genre-filter");
    genres.forEach((genre) => {
      select.add(new Option(genre.name, genre.slug));
      document.getElementById("import-genre").add(new Option(genre.name, genre.slug));
    });
  } catch {
    // Keep the sample catalog usable when the PHP API has not been configured yet.
  }
}

async function loadCatalog(filters = {}) {
  const parameters = new URLSearchParams();
  Object.entries(filters).forEach(([key, value]) => {
    if (value) parameters.set(key, value);
  });
  try {
    const result = await apiRequest(`/api/books${parameters.size ? `?${parameters}` : ""}`);
    const cards = document.getElementById("book-list");
    cards.replaceChildren(...result.map((book, index) => createBookCard(book, index)));
    catalogIsLive = true;
    updateCatalogStatus(`${result.length} truyện · Dữ liệu từ danh mục.`);
  } catch {
    if (!catalogIsLive) updateCatalogStatus("Đang xem nội dung minh họa; kết nối PHP/MySQL để tải danh mục thật.");
  }
}

async function loadDiscoverySections() {
  try {
    const [recent, popular] = await Promise.all([
      apiRequest("/api/books?sort=updated&limit=5"),
      apiRequest("/api/books?sort=popular&limit=5"),
    ]);
    if (recent.length > 0) {
      const featured = recent[0];
      document.getElementById("feature-title").textContent = featured.title;
      document.getElementById("feature-description").textContent = featured.description;
      document.getElementById("feature-byline").textContent = `${featured.author.toLocaleUpperCase("vi")} · ${featured.genre.toLocaleUpperCase("vi")}`;
      document.getElementById("feature-link").dataset.bookSlug = featured.slug;
    }
    const updates = document.getElementById("update-list");
    updates.replaceChildren(...recent.map((book, index) => {
      const item = document.createElement("button");
      item.className = "update-item";
      item.dataset.page = "detail";
      item.dataset.bookSlug = book.slug;
      item.append(
        createTextElement("span", "update-number", String(index + 1).padStart(2, "0")),
        createTextElement("span", "update-title", book.title),
        createTextElement("span", "update-author", book.author),
        createTextElement("span", "update-chapter", book.genre),
        createTextElement("span", "update-time", book.updated_at ? new Date(book.updated_at).toLocaleDateString("vi-VN") : ""),
      );
      return item;
    }));
    const rankings = document.getElementById("ranking-list");
    rankings.replaceChildren(...popular.map((book, index) => {
      const item = document.createElement("li");
      item.append(createTextElement("span", "rank-number", String(index + 1).padStart(2, "0")));
      const link = document.createElement("button");
      link.dataset.page = "detail";
      link.dataset.bookSlug = book.slug;
      link.append(
        createTextElement("strong", "", book.title),
        createTextElement("small", "", `${book.author} · ${book.genre} · ${Number(book.read_count).toLocaleString("vi")} lượt đọc`),
      );
      item.append(link);
      return item;
    }));
  } catch (error) {
    console.warn("[Discovery] Could not load live sections.", error);
  }
}

function setDetailField(selector, value) {
  const element = document.querySelector(selector);
  if (element) element.textContent = value;
}

function renderChapters(chapters, append = false) {
  const list = document.querySelector(".chapter-list");
  if (!append) list.replaceChildren();
  chapters.forEach((chapter) => {
    const button = document.createElement("button");
    button.className = "chapter-item";
    button.dataset.page = "reader";
    button.dataset.chapterId = chapter.id;
    const label = document.createElement("span");
    label.append(createTextElement("small", "", `CHƯƠNG ${chapter.chapter_number}${Number(chapter.part_number) > 1 ? ` · PHẦN ${chapter.part_number}` : ""}`));
    label.append(createTextElement("strong", "", chapter.title));
    button.append(label);
    const date = document.createElement("time");
    date.textContent = chapter.published_at ? new Date(chapter.published_at).toLocaleDateString("vi-VN") : "";
    button.append(date, createTextElement("span", "chapter-arrow", "→"));
    list.append(button);
  });
  const count = document.querySelector(".chapter-count");
  if (count) count.textContent = `${selectedBook?.chapterCount ?? selectedBook?.chapters?.length ?? chapters.length} chương`;
  const moreButton = document.getElementById("more-chapters");
  moreButton.hidden = chapters.length < (selectedBook?.chapterPageSize || 20);
  moreButton.disabled = false;
  if (!moreButton.hidden) moreButton.textContent = "Xem thêm chương ↓";
}

async function loadMoreChapters() {
  if (!selectedBook) {
    showToast("Danh sách chương minh họa chưa được kết nối dữ liệu.");
    return;
  }
  if (selectedBook.chaptersLoading || document.getElementById("more-chapters").hidden) return;
  const bookSelection = selectedBook;
  const button = document.getElementById("more-chapters");
  bookSelection.chaptersLoading = true;
  button.disabled = true;
  button.textContent = "Đang tải chương...";
  try {
    const page = bookSelection.chapterPage + 1;
    const chapters = await apiRequest(
      `/api/books/${encodeURIComponent(bookSelection.slug)}?page=${page}`,
    ).then((book) => book.chapters);
    if (selectedBook !== bookSelection) return;
    if (chapters.length === 0) {
      button.hidden = true;
      updateChapterNavigation();
      return false;
    }
    bookSelection.chapterPage = page;
    bookSelection.chapters.push(...chapters);
    renderChapters(chapters, true);
    updateChapterNavigation();
    return true;
  } catch (error) {
    showToast(error.message || "Không thể tải thêm chương.");
    return false;
  } finally {
    bookSelection.chaptersLoading = false;
    if (selectedBook === bookSelection) {
      button.disabled = false;
      if (!button.hidden) button.textContent = "Xem thêm chương ↓";
    }
  }
}

function updateChapterNavigation() {
  const buttons = document.querySelectorAll(".chapter-navigation button");
  if (!currentChapter || !selectedBook?.chapters?.length) {
    buttons.forEach((button) => { button.disabled = true; });
    return;
  }
  const chapters = [...selectedBook.chapters].sort((a, b) =>
    Number(a.chapter_number) - Number(b.chapter_number) || Number(a.part_number) - Number(b.part_number),
  );
  const index = chapters.findIndex((chapter) => String(chapter.id) === String(currentChapter.id));
  buttons[0].disabled = index <= 0;
  buttons[2].disabled = index < 0
    || (index >= chapters.length - 1 && document.getElementById("more-chapters").hidden);
}

async function navigateChapter(offset) {
  if (!currentChapter || !selectedBook?.chapters?.length) return;
  const bookSelection = selectedBook;
  let chapters = [...bookSelection.chapters].sort((a, b) =>
    Number(a.chapter_number) - Number(b.chapter_number) || Number(a.part_number) - Number(b.part_number),
  );
  let index = chapters.findIndex((chapter) => String(chapter.id) === String(currentChapter.id));
  if (index < 0) return;
  let target = chapters[index + offset];
  if (!target && offset > 0 && !document.getElementById("more-chapters").hidden) {
    const loaded = await loadMoreChapters();
    if (!loaded || selectedBook !== bookSelection) return;
    chapters = [...bookSelection.chapters].sort((a, b) =>
      Number(a.chapter_number) - Number(b.chapter_number) || Number(a.part_number) - Number(b.part_number),
    );
    index = chapters.findIndex((chapter) => String(chapter.id) === String(currentChapter.id));
    target = chapters[index + offset];
  }
  if (target) await openChapter(target.id);
}

async function loadStory(slug) {
  try {
    const book = await apiRequest(`/api/books/${encodeURIComponent(slug)}?page=1`);
    selectedBook = {
      ...book,
      chapterCount: Number(book.chapter_count) || book.chapters.length,
      chapterPage: 1,
      chapterPageSize: 20,
      chaptersLoading: false,
    };
    const submitSection = document.getElementById("chapter-submit-section");
    submitSection.hidden = Number(book.can_submit_chapter) !== 1;
    document.getElementById("chapter-submit-form").reset();
    document.getElementById("chapter-submit-status").textContent = "";
    setDetailField("#detail-title", book.title);
    setDetailField(".detail-author strong", book.author);
    setDetailField(".detail-copy > .eyebrow", `${book.genre} · ${book.status === "completed" ? "ĐÃ HOÀN THÀNH" : book.status === "paused" ? "TẠM NGƯNG" : "ĐANG RA"}`);
    setDetailField(".detail-description", book.description);
    setDetailField(".rating-line", `${Number(book.read_count).toLocaleString("vi")} lượt đọc`);
    setDetailField(".story-facts span:first-child strong", book.status === "completed" ? "Đã hoàn thành" : book.status === "paused" ? "Tạm ngưng" : "Đang ra");
    setDetailField(".story-facts span:nth-child(2) strong", `${selectedBook.chapterCount} chương`);
    setDetailField(".story-facts span:nth-child(3) strong", book.updated_at ? new Date(book.updated_at).toLocaleDateString("vi-VN") : "");
    const cover = document.querySelector(".detail-cover");
    cover.querySelector(".cover-title").textContent = book.title;
    cover.querySelector(".cover-kicker").textContent = book.genre;
    cover.querySelector(".cover-author").textContent = book.author.toLocaleUpperCase("vi");
    document.querySelector(".chapter-heading h2").textContent = `Danh sách chương · ${book.title}`;
    renderChapters(book.chapters);
    await loadCommunity(book.slug);
    if (currentUser) {
      try {
        const status = await apiRequest(`/api/me/library/${encodeURIComponent(book.slug)}`);
        updateSaveButton(status.saved);
      } catch (error) {
        if (error.status !== 401) showToast(error.message || "Không thể tải trạng thái tủ truyện.");
      }
    } else {
      updateSaveButton(false);
    }
    navigateTo("detail");
    history.replaceState(null, "", `#detail/${encodeURIComponent(book.slug)}`);
  } catch (error) {
    showToast(error.message || "Không thể tải chi tiết truyện.");
    navigateTo("detail");
  }
}

async function openChapter(chapterId, progressPercent = 0) {
  if (!currentUser) {
    pendingChapterId = chapterId;
    clearReaderContent();
    document.getElementById("account-dialog").showModal();
    showToast("Đăng nhập để đọc toàn văn chương.");
    return;
  }
  try {
    const chapter = await apiRequest(`/api/chapters/${encodeURIComponent(chapterId)}`);
    currentChapter = chapter;
    lastSavedProgress = -1;
    const partLabel = Number(chapter.part_number) > 1 ? ` · Phần ${chapter.part_number}` : "";
    setDetailField("#reader-title", `Chương ${chapter.chapter_number}${partLabel} · ${chapter.title}`);
    setDetailField(".reader-book", `${chapter.book_title.toLocaleUpperCase("vi")} / CHƯƠNG ${chapter.chapter_number}${partLabel.toLocaleUpperCase("vi")}`);
    setDetailField(".reader-chapter-label", `${chapter.book_title.toLocaleUpperCase("vi")} · ${chapter.author}`);
    const article = document.getElementById("reader-article");
    article.querySelectorAll(".chapter-copy").forEach((paragraph) => paragraph.remove());
    article.querySelector(".chapter-intro")?.remove();
    const marker = article.querySelector(".reader-endnote");
    chapter.content.split(/\n{2,}/).filter((line) => line && !/^— Hết chương —$/.test(line.trim())).forEach((line) => {
      const paragraph = createTextElement("p", "chapter-copy", line);
      article.insertBefore(paragraph, marker);
    });
    updateChapterNavigation();
    document.querySelector(".reader-disclaimer").hidden = false;
    navigateTo("reader");
    history.replaceState(null, "", `#reader/${encodeURIComponent(chapter.book_slug)}/${chapter.id}`);
    const savedProgress = Math.max(0, Math.min(100, Number(progressPercent) || 0));
    if (savedProgress > 0) {
      const articleTop = article.getBoundingClientRect().top + window.scrollY;
      window.scrollTo({
        top: articleTop + Math.max(0, article.offsetHeight - window.innerHeight) * savedProgress / 100,
        behavior: "auto",
      });
    }
    updateReadingProgress(savedProgress);
    await saveReadingProgress(savedProgress);
  } catch (error) {
    if (error.status === 401) {
      pendingChapterId = chapterId;
      currentUser = null;
      updateAccountUI();
      document.getElementById("account-dialog").showModal();
    } else {
      showToast(error.message || "Không thể tải chương truyện.");
    }
  }
}

function clearReaderContent() {
  const article = document.getElementById("reader-article");
  article.querySelectorAll(".chapter-copy").forEach((paragraph) => paragraph.remove());
  if (!article.querySelector(".chapter-intro")) {
    const intro = createTextElement("p", "chapter-intro", "Đăng nhập để mở nội dung chương.");
    article.insertBefore(intro, article.querySelector(".reader-endnote"));
  }
  currentChapter = null;
  updateReadingProgress(0);
}

document.addEventListener("click", (event) => {
  const button = event.target.closest("[data-page], [data-resume-slug]");
  if (!button) return;
  event.preventDefault();
  if (button.dataset.resumeSlug && button.dataset.resumeChapterId) {
    loadStory(button.dataset.resumeSlug).then(() => openChapter(button.dataset.resumeChapterId, Number(button.dataset.progressPercent)));
    return;
  }
  if (button.dataset.page === "library") {
    navigateTo("library");
    loadLibraryPage();
    return;
  }
  if (button.dataset.page === "admin") {
    navigateTo("admin");
    loadAdminPage();
    return;
  }
  if (button.dataset.chapterId) {
    openChapter(button.dataset.chapterId);
    return;
  }
  if (button.dataset.page === "detail") {
    loadStory(button.dataset.bookSlug || selectedBook?.slug || "nguoi-giu-vuon");
  } else if (button.dataset.page === "reader" && !currentUser) {
    pendingChapterId = selectedBook?.chapters?.[0]?.id || null;
    document.getElementById("account-dialog").showModal();
  } else if (button.dataset.page === "reader" && selectedBook?.chapters?.length) {
    const firstChapter = selectedBook.chapters[0];
    openChapter(firstChapter.id);
  } else {
    navigateTo(button.dataset.page);
  }
});

async function loadSession() {
  try {
    const session = await apiRequest("/api/session");
    currentUser = session.user;
    csrfToken = session.csrf_token;
    sessionLoaded = true;
    updateAccountUI();
    if (currentUser) {
      loadNotifications();
      connectRealtimeNotifications();
    }
  } catch {
    currentUser = null;
    sessionLoaded = true;
    updateAccountUI();
    stopRealtimeNotifications();
  }
}

function updateAccountUI() {
  const toggle = document.getElementById("account-toggle");
  const notificationsToggle = document.getElementById("notification-trigger");
  toggle.textContent = currentUser ? currentUser.name.slice(0, 1).toLocaleUpperCase("vi") : "K";
  toggle.setAttribute("aria-label", currentUser ? `Tài khoản ${currentUser.name}` : "Đăng nhập hoặc tạo tài khoản");
  document.getElementById("admin-nav").hidden = !["admin", "moderator"].includes(currentUser?.role);
  document.getElementById("account-title").textContent = currentUser ? `Xin chào, ${currentUser.name}` : authMode === "login" ? "Đăng nhập" : "Tạo tài khoản";
  document.getElementById("account-form").hidden = Boolean(currentUser);
  document.getElementById("account-signout").hidden = !currentUser;
  notificationsToggle.hidden = !currentUser;
  if (!currentUser) {
    stopRealtimeNotifications();
    document.getElementById("notification-panel").hidden = true;
    document.getElementById("notification-trigger").setAttribute("aria-expanded", "false");
    document.getElementById("notification-badge").hidden = true;
  }
  document.getElementById("account-name-row").hidden = currentUser || authMode !== "register";
  document.querySelector(".account-intro").textContent = currentUser ? `Bạn đang đăng nhập với ${currentUser.email}.` : "Đăng nhập để đọc chương truyện và tiếp tục hành trình của bạn.";
  document.querySelector(".account-tabs").hidden = Boolean(currentUser);
  document.querySelector(".account-footnote").hidden = Boolean(currentUser);
  document.querySelectorAll("[data-auth-mode]").forEach((tab) => tab.classList.toggle("is-selected", tab.dataset.authMode === authMode));
  document.querySelector(".account-submit").textContent = authMode === "login" ? "Đăng nhập" : "Tạo tài khoản";
  if (!currentUser) clearReaderContent();
}

document.getElementById("account-toggle").addEventListener("click", () => {
  document.getElementById("account-dialog").showModal();
  if (!sessionLoaded) loadSession();
});
document.getElementById("account-close").addEventListener("click", () => document.getElementById("account-dialog").close());
document.getElementById("account-dialog").addEventListener("click", (event) => {
  if (event.target === event.currentTarget) event.currentTarget.close();
});
document.querySelectorAll("[data-auth-mode]").forEach((tab) => {
  tab.addEventListener("click", () => {
    authMode = tab.dataset.authMode;
    document.getElementById("account-error").textContent = "";
    updateAccountUI();
  });
});
document.getElementById("account-form").addEventListener("submit", async (event) => {
  event.preventDefault();
  const form = new FormData(event.currentTarget);
  const body = Object.fromEntries(form.entries());
  try {
    if (!csrfToken) {
      const session = await apiRequest("/api/session");
      csrfToken = session.csrf_token;
    }
    const result = await apiRequest(`/api/auth/${authMode === "login" ? "login" : "register"}`, {
      method: "POST",
      headers: { "Content-Type": "application/json" },
      body: JSON.stringify(body),
    });
    currentUser = result.user;
    csrfToken = result.csrf_token;
    updateAccountUI();
    loadNotifications();
    connectRealtimeNotifications();
    document.getElementById("account-dialog").close();
    if (pendingChapterId) {
      const chapterId = pendingChapterId;
      pendingChapterId = null;
      await openChapter(chapterId);
    } else if (pendingLibrarySlug) {
      const slug = pendingLibrarySlug;
      pendingLibrarySlug = null;
      await setBookSaved(slug, true);
    } else {
      showToast("Đăng nhập thành công.");
    }
  } catch (error) {
    document.getElementById("account-error").textContent = error.message || "Không thể đăng nhập.";
  }
});
document.getElementById("account-signout").addEventListener("click", async () => {
  try {
    await apiRequest("/api/auth/logout", { method: "POST", headers: { "Content-Type": "application/json" }, body: "{}" });
    currentUser = null;
    csrfToken = "";
    stopRealtimeNotifications();
    document.getElementById("notification-panel").hidden = true;
    document.getElementById("notification-trigger").setAttribute("aria-expanded", "false");
    pendingChapterId = null;
    pendingLibrarySlug = null;
    updateAccountUI();
    navigateTo("detail");
    document.getElementById("account-dialog").close();
  } catch (error) {
    document.getElementById("account-error").textContent = error.message || "Không thể đăng xuất.";
  }
});

document.getElementById("share-story").addEventListener("click", async () => {
  if (!selectedBook) {
    showToast("Hãy kết nối danh mục để chia sẻ đường dẫn truyện.");
    return;
  }
  const url = `${window.location.origin}${window.location.pathname}#detail/${encodeURIComponent(selectedBook.slug)}`;
  try {
    if (navigator.share) {
      await navigator.share({ title: selectedBook.title, url });
    } else {
      await navigator.clipboard.writeText(url);
      showToast("Đã sao chép đường dẫn truyện.");
    }
  } catch (error) {
    if (error.name !== "AbortError") showToast("Không thể chia sẻ đường dẫn trên trình duyệt này.");
  }
});

document.getElementById("search-form").addEventListener("submit", (event) => {
  event.preventDefault();
  const rawQuery = document.getElementById("story-search").value.trim();
  const query = rawQuery.toLocaleLowerCase("vi");
  const filters = {
    q: rawQuery,
    genre: document.getElementById("genre-filter").value,
    status: document.getElementById("status-filter").value,
    sort: document.getElementById("sort-filter").value,
  };
  if (catalogIsLive) {
    loadCatalog(filters);
    navigateTo("home");
    return;
  }
  if (!query) {
    showToast("Tìm kiếm nhiều tiêu chí cần kết nối PHP/MySQL.");
    return;
  }
  navigateTo("home");
  let matches = 0;
  document.querySelectorAll(".book-card").forEach((card) => {
    const visible = card.dataset.search.toLocaleLowerCase("vi").includes(query);
    card.hidden = !visible;
    if (visible) matches += 1;
  });
  showToast(matches ? `Tìm thấy ${matches} truyện trong danh sách minh họa.` : "Chưa tìm thấy truyện trong danh sách minh họa.");
});

["genre-filter", "status-filter", "sort-filter"].forEach((id) => {
  document.getElementById(id).addEventListener("change", () => {
    if (catalogIsLive) {
      loadCatalog({
        q: document.getElementById("story-search").value.trim(),
        genre: document.getElementById("genre-filter").value,
        status: document.getElementById("status-filter").value,
        sort: document.getElementById("sort-filter").value,
      });
    }
  });
});
document.getElementById("clear-filters").addEventListener("click", () => {
  document.getElementById("search-form").reset();
  document.getElementById("genre-filter").value = "";
  document.getElementById("status-filter").value = "";
  document.getElementById("sort-filter").value = "updated";
  document.querySelectorAll(".book-card").forEach((card) => { card.hidden = false; });
  if (catalogIsLive) loadCatalog({ sort: "updated" });
});

document.getElementById("search-trigger").addEventListener("click", () => {
  navigateTo("home");
  document.getElementById("story-search").focus();
});
document.getElementById("notification-trigger").addEventListener("click", async (event) => {
  const panel = document.getElementById("notification-panel");
  panel.hidden = !panel.hidden;
  event.currentTarget.setAttribute("aria-expanded", String(!panel.hidden));
  if (!panel.hidden) await loadNotifications();
});
document.getElementById("notifications-refresh").addEventListener("click", loadNotifications);
document.getElementById("notification-list").addEventListener("click", async (event) => {
  const item = event.target.closest("[data-notification-id]");
  if (!item) return;
  try {
    await apiRequest(`/api/me/notifications/${encodeURIComponent(item.dataset.notificationId)}/read`, { method: "POST" });
    document.getElementById("notification-panel").hidden = true;
    document.getElementById("notification-trigger").setAttribute("aria-expanded", "false");
    if (item.dataset.bookSlug) await loadStory(item.dataset.bookSlug);
    if (item.dataset.chapterId) await openChapter(item.dataset.chapterId);
    await loadNotifications();
  } catch (error) {
    showToast(error.message || "Không thể mở thông báo.");
  }
});

document.getElementById("settings-toggle").addEventListener("click", () => {
  settingsPanel.hidden = !settingsPanel.hidden;
  settingsToggle.setAttribute("aria-expanded", String(!settingsPanel.hidden));
});
document.getElementById("settings-close").addEventListener("click", closeSettings);

document.querySelectorAll("[data-preset]").forEach((button) => {
  button.addEventListener("click", () => {
    const preset = PRESETS[button.dataset.preset];
    preferences = { ...preferences, ...preset };
    applyPreferences();
    persistPreferences();
  });
});
document.getElementById("background-color").addEventListener("input", (event) => {
  updatePreference("background", event.target.value);
});
document.getElementById("text-color").addEventListener("input", (event) => {
  updatePreference("textColor", event.target.value);
});
document.getElementById("font-family").addEventListener("change", (event) => {
  updatePreference("fontFamily", event.target.value);
});
document.getElementById("font-size").addEventListener("input", (event) => {
  updatePreference("fontSize", clampRangeValue(event.target.value, "fontSize"));
});
document.getElementById("line-height").addEventListener("input", (event) => {
  updatePreference("lineHeight", clampRangeValue(event.target.value, "lineHeight"));
});
document.getElementById("reading-width").addEventListener("input", (event) => {
  updatePreference("padding", clampRangeValue(event.target.value, "padding"));
});
document.getElementById("text-shadow").addEventListener("change", (event) => {
  updatePreference("shadow", event.target.checked);
});
document.querySelectorAll("[data-align]").forEach((button) => {
  button.addEventListener("click", () => updatePreference("alignment", button.dataset.align));
});
document.querySelectorAll("[data-theme]").forEach((button) => {
  button.addEventListener("click", () => updatePreference("theme", button.dataset.theme));
});
document.querySelectorAll("[data-adjust]").forEach((button) => {
  button.addEventListener("click", () => {
    const key = button.dataset.adjust;
    const step = Number(button.dataset.step);
    updatePreference(key, clampRangeValue(Number(preferences[key]) + step, key));
  });
});
document.getElementById("reset-settings").addEventListener("click", () => {
  preferences = { ...DEFAULTS };
  applyPreferences();
  persistPreferences();
  showToast("Đã khôi phục cài đặt đọc mặc định.");
});
document.getElementById("save-story").addEventListener("click", toggleBookSaved);
document.getElementById("more-chapters").addEventListener("click", loadMoreChapters);
document.querySelector(".chapter-navigation button:last-child").addEventListener("click", () => {
  navigateChapter(1);
});
document.querySelector(".chapter-navigation button:first-child").addEventListener("click", () => navigateChapter(-1));
window.matchMedia("(prefers-color-scheme: dark)").addEventListener("change", () => {
  if (preferences.theme === "system") setTheme("system");
});

applyPreferences();
updateDateLabel();
updateAccountUI();
async function initialize() {
  await loadSession();
  await Promise.all([loadGenres(), loadCatalog({ sort: "updated" }), loadDiscoverySections()]);
  const [page, slug, chapterId] = window.location.hash.slice(1).split("/");
  if (page === "detail" && slug) {
    await loadStory(slug);
  } else if (page === "reader" && slug && chapterId) {
    await loadStory(slug);
    if (currentUser) {
      await openChapter(chapterId, await savedProgressFor(chapterId));
    } else {
      pendingChapterId = chapterId;
      document.getElementById("account-dialog").showModal();
    }
  } else if (page === "reader") {
    navigateTo("reader");
  } else if (page === "library") {
    navigateTo("library");
    await loadLibraryPage();
  } else if (page === "admin") {
    navigateTo("admin");
    await loadAdminPage();
  }
}
initialize();
