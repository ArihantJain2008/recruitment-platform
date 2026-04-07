const THEME_KEY = "recruitment_theme";
const THEME_LIGHT = "light";
const THEME_DARK = "dark";

function getPreferredTheme() {
  const saved = (localStorage.getItem(THEME_KEY) || "").toLowerCase();
  if (saved === THEME_LIGHT || saved === THEME_DARK) {
    return saved;
  }

  if (window.matchMedia && window.matchMedia("(prefers-color-scheme: dark)").matches) {
    return THEME_DARK;
  }

  return THEME_LIGHT;
}

function applyTheme(theme) {
  const normalized = theme === THEME_DARK ? THEME_DARK : THEME_LIGHT;
  document.documentElement.setAttribute("data-theme", normalized);

  const isDark = normalized === THEME_DARK;
  const toggles = document.querySelectorAll(".theme-toggle");
  toggles.forEach((button) => {
    button.setAttribute("aria-pressed", isDark ? "true" : "false");
    button.setAttribute("title", isDark ? "Switch to light mode" : "Switch to dark mode");
    button.innerHTML = isDark ? "☀" : "🌙";
  });
}

function toggleTheme() {
  const active = document.documentElement.getAttribute("data-theme") === THEME_DARK ? THEME_DARK : THEME_LIGHT;
  const next = active === THEME_DARK ? THEME_LIGHT : THEME_DARK;
  localStorage.setItem(THEME_KEY, next);
  applyTheme(next);
}

function initializeThemeToggle() {
  applyTheme(getPreferredTheme());

  const toggles = document.querySelectorAll(".theme-toggle");
  toggles.forEach((button) => {
    button.addEventListener("click", toggleTheme);
  });
}

document.addEventListener("DOMContentLoaded", initializeThemeToggle);
