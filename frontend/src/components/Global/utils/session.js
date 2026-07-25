const SESSION_KEY = "mutual_freyre_session";

export function readStoredSession() {
  try {
    const value = JSON.parse(sessionStorage.getItem(SESSION_KEY) || "null");
    if (!value?.usuario) return null;
    if (value.expira_en && Date.parse(value.expira_en) <= Date.now()) {
      clearStoredSession();
      return null;
    }
    return value;
  } catch {
    return null;
  }
}

export function saveStoredSession(session) {
  try {
    sessionStorage.setItem(SESSION_KEY, JSON.stringify(session));
  } catch {
    // El estado en memoria sigue funcionando aunque el navegador bloquee storage.
  }
}

export function clearStoredSession() {
  try {
    sessionStorage.removeItem(SESSION_KEY);
  } catch {
    // No debe impedir el cierre local.
  }
}

export function getCsrfToken() {
  return readStoredSession()?.csrf_token || "";
}
