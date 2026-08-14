import { ApiClient, ApiError, routeForRole } from './api-client.js?v=4110';
import { APP_CONFIG } from './config.js?v=4110';

const translations = {
  ms: {
    offline: 'Offline - sila sambung internet untuk log masuk.',
    welcome: 'Selamat datang',
    loginSubtitle: 'Log masuk menggunakan akaun Staff atau Security yang diberikan oleh pihak pengurusan.',
    signIn: 'Log masuk',
    username: 'Nama pengguna',
    password: 'Kata laluan',
    loginButton: 'Log masuk ke CPMS',
    secureNote: 'Selepas login, anda akan dibawa ke dashboard web CPMS sebenar.',
    openingDashboard: 'Membuka dashboard CPMS sebenar...',
    redirectFailed: 'Dashboard web tidak dapat dibuka. Sila cuba semula.',
  },
  en: {
    offline: 'Offline - please connect to the internet to sign in.',
    welcome: 'Welcome',
    loginSubtitle: 'Sign in with the Staff or Security account provided by management.',
    signIn: 'Sign in',
    username: 'Username',
    password: 'Password',
    loginButton: 'Sign in to CPMS',
    secureNote: 'After login, you will be opened in the real CPMS web dashboard.',
    openingDashboard: 'Opening the real CPMS dashboard...',
    redirectFailed: 'The web dashboard could not be opened. Please try again.',
  },
};

const elements = Object.fromEntries([
  'splash', 'offlineBanner', 'loginView', 'appView', 'languageButton', 'appLanguageButton', 'loginForm',
  'username', 'password', 'togglePassword', 'loginButton', 'loginError', 'demoPanel', 'modeBadge',
  'dashboardContent',
].map((id) => [id, document.getElementById(id)]));

const api = new ApiClient();
const state = {
  language: localStorage.getItem('cpms_workforce_language') === 'en' ? 'en' : 'ms',
};

function t(key) {
  return translations[state.language]?.[key] || translations.ms[key] || key;
}

function applyLanguage() {
  document.documentElement.lang = state.language;
  document.querySelectorAll('[data-i18n]').forEach((node) => {
    node.textContent = t(node.dataset.i18n);
  });
}

function toggleLanguage() {
  state.language = state.language === 'ms' ? 'en' : 'ms';
  localStorage.setItem('cpms_workforce_language', state.language);
  applyLanguage();
}

function setLoading(isLoading) {
  elements.loginButton.disabled = isLoading;
  elements.loginButton.firstElementChild.textContent = isLoading
    ? t('openingDashboard')
    : t('loginButton');
}

function showError(message) {
  elements.loginError.textContent = message;
  elements.loginError.hidden = false;
}

function saveSession(session) {
  sessionStorage.setItem('cpms_workforce_session', JSON.stringify(session));
}

function clearSession() {
  sessionStorage.removeItem('cpms_workforce_session');
}

async function openUnifiedWebDashboard() {
  const bridge = await api.createWebSession();
  const redirectUrl = String(bridge?.redirect_url || '');
  if (!redirectUrl.startsWith('/')) {
    throw new ApiError(t('redirectFailed'), 'WEB_DASHBOARD_UNAVAILABLE', 409);
  }
  window.location.replace(redirectUrl);
}

async function login(username, password) {
  if (!navigator.onLine) {
    showError(t('offline'));
    return;
  }
  setLoading(true);
  elements.loginError.hidden = true;
  try {
    const session = await api.login(username, password);
    routeForRole(session.user?.role);
    saveSession(session);
    await openUnifiedWebDashboard();
  } catch (error) {
    showError(error instanceof ApiError ? error.message : t('redirectFailed'));
    setLoading(false);
  }
}

async function restoreSession() {
  const cached = sessionStorage.getItem('cpms_workforce_session');
  if (!cached || !navigator.onLine) return;
  try {
    const session = JSON.parse(cached);
    routeForRole(session.user?.role);
    api.setAccessToken(session.access_token);
    await openUnifiedWebDashboard();
  } catch {
    clearSession();
  }
}

function updateConnectivity() {
  elements.offlineBanner.hidden = navigator.onLine;
}

elements.loginForm.addEventListener('submit', (event) => {
  event.preventDefault();
  login(elements.username.value, elements.password.value);
});

elements.languageButton.addEventListener('click', toggleLanguage);
elements.togglePassword.addEventListener('click', () => {
  elements.password.type = elements.password.type === 'password' ? 'text' : 'password';
});

if (elements.appLanguageButton) {
  elements.appLanguageButton.addEventListener('click', toggleLanguage);
}

if (elements.demoPanel) {
  elements.demoPanel.hidden = true;
}
if (elements.modeBadge) {
  elements.modeBadge.hidden = true;
}
if (elements.appView) {
  elements.appView.hidden = true;
}
if (elements.dashboardContent) {
  elements.dashboardContent.innerHTML = '';
}

window.addEventListener('online', updateConnectivity);
window.addEventListener('offline', updateConnectivity);

applyLanguage();
updateConnectivity();
restoreSession();
setTimeout(() => elements.splash.classList.add('is-hidden'), 650);
